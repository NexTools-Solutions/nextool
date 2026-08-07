<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Distribution Client
 * -------------------------------------------------------------------------
 * Cliente responsável por conversar com o ContainerAPI para distribuição
 * remota de módulos (manifestos, download de pacotes, bootstrap de
 * segredo HMAC, etc.).
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/licenseconfig.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/hmacsignaturetrait.class.php';

class PluginNextoolDistributionClient {

   use PluginNextoolHmacSignatureTrait;

   public function __construct(
      private string $baseUrl,
      private string $clientIdentifier = '',
      private string $clientSecret = ''
   ) {
      $this->baseUrl = rtrim($this->baseUrl, '/');
   }
   /**
    * Tenta obter o client_secret via bootstrap no ContainerAPI.
    *
    * Anuncia a capability `reenroll-v1`: se o identifier já estiver provisionado no servidor
    * (segredo local perdido ou identidade colidida) e o ambiente for comprovadamente FREE, o
    * servidor cunha uma identidade NOVA e a retorna em `environment_id` + `reenrolled: true`
    * (o segredo antigo nunca trafega). O chamador DEVE adotar a identidade nova quando
    * `reenrolled` vier true (PluginNextoolConfig::adoptIdentity).
    *
    * @return array{secret: ?string, error: ?string, http_code: int, message: ?string, retry_after: ?int, environment_id: ?string, reenrolled: bool}
    */
   public static function bootstrapClientSecret(string $baseUrl, string $clientIdentifier): array {
      $baseUrl = rtrim($baseUrl, '/');
      if ($baseUrl === '' || $clientIdentifier === '') {
         return [
            'secret'      => null,
            'error'       => 'invalid_config',
            'http_code'   => 0,
            'message'     => __('URL do ContainerAPI ou identificador do ambiente não configurados.', 'nextool'),
            'retry_after' => null,
            'environment_id' => null,
            'reenrolled'  => false,
         ];
      }

      // domain/glpi_version alimentam a telemetria inicial caso o servidor cunhe identidade nova.
      $domain = '';
      if (!empty($GLOBALS['CFG_GLPI']['url_base'])) {
         $domain = (string) (parse_url((string) $GLOBALS['CFG_GLPI']['url_base'], PHP_URL_HOST) ?: '');
      }
      $payload = json_encode(array_filter([
         'client_identifier' => $clientIdentifier,
         'domain'            => $domain !== '' ? $domain : null,
         'glpi_version'      => defined('GLPI_VERSION') ? GLPI_VERSION : null,
      ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

      $ch = curl_init($baseUrl . '/api/distribution/bootstrap');
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload ?: '');
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
      $bootstrapHeaders = [
         'Content-Type: application/json',
         'X-Nextool-Caps: reenroll-v1',
      ];
      if (!isset($GLOBALS['nextool_request_group_id'])) {
         require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';
         $GLOBALS['nextool_request_group_id'] = PluginNextoolConfig::generateRequestGroupId();
      }
      $bootstrapHeaders[] = 'X-Request-Group-Id: ' . $GLOBALS['nextool_request_group_id'];
      curl_setopt($ch, CURLOPT_HTTPHEADER, $bootstrapHeaders);

      $response = curl_exec($ch);
      $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);
      $curlErrno = curl_errno($ch);
      curl_close($ch);

      // Falha de conexão (DNS, timeout, SSL, rede)
      if ($response === false) {
         $networkMessage = match (true) {
            $curlErrno === CURLE_OPERATION_TIMEDOUT
               => __('Tempo limite excedido ao conectar com o servidor de licenciamento. Verifique se o servidor está acessível.', 'nextool'),
            $curlErrno === CURLE_COULDNT_RESOLVE_HOST
               => sprintf(__('Não foi possível resolver o endereço do servidor de licenciamento (%s). Verifique a configuração de DNS.', 'nextool'), parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl),
            in_array($curlErrno, [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER, CURLE_SSL_CACERT], true)
               => __('Erro de certificado SSL ao conectar com o servidor de licenciamento. Verifique se o certificado do servidor é válido.', 'nextool'),
            $curlErrno === CURLE_COULDNT_CONNECT
               => sprintf(__('Não foi possível conectar com o servidor de licenciamento (%s). Verifique se há um firewall bloqueando a conexão.', 'nextool'), parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl),
            default
               => sprintf(__('Erro de rede ao conectar com o servidor de licenciamento: %s', 'nextool'), $curlError),
         };

         Toolbox::logInFile('plugin_nextool', sprintf(
            'Bootstrap HMAC falhou - erro de rede (curl errno %d): %s',
            $curlErrno,
            $curlError
         ));

         return [
            'secret'      => null,
            'error'       => 'network_error',
            'http_code'   => 0,
            'message'     => $networkMessage,
            'retry_after' => null,
            'environment_id' => null,
            'reenrolled'  => false,
         ];
      }

      $data = json_decode($response, true);

      // Resposta HTTP com erro
      if ($httpCode >= 300) {
         $serverError = is_array($data) ? ($data['error'] ?? null) : null;
         $serverMessage = is_array($data) ? ($data['message'] ?? null) : null;
         $retryAfter = is_array($data) ? (isset($data['retry_after']) ? (int) $data['retry_after'] : null) : null;

         $userMessage = match (true) {
            $httpCode === 429
               => sprintf(__('O servidor de licenciamento está temporariamente limitando requisições. Tente novamente em %d segundos.', 'nextool'), $retryAfter ?? 60),
            $httpCode === 503
               => __('O servidor de licenciamento está temporariamente indisponível por medida de segurança. Tente novamente em alguns minutos.', 'nextool'),
            // 409 = ambiente já provisionado: a mensagem do servidor é orientativa (LICENSED ->
            // contato com o suporte). Exibe-a direto, sem o prefixo "erro inesperado".
            $httpCode === 409 && is_string($serverMessage) && $serverMessage !== ''
               => $serverMessage,
            $httpCode === 400
               => sprintf(__('Requisição inválida para o servidor de licenciamento: %s', 'nextool'), $serverMessage ?? 'payload inválido'),
            $httpCode >= 500
               => sprintf(__('Erro interno no servidor de licenciamento (HTTP %d). Tente novamente em instantes.', 'nextool'), $httpCode),
            default
               => sprintf(__('O servidor de licenciamento retornou um erro inesperado (HTTP %d): %s', 'nextool'), $httpCode, $serverMessage ?? 'sem detalhes'),
         };

         Toolbox::logInFile('plugin_nextool', sprintf(
            'Bootstrap HMAC falhou - HTTP %d, error: %s, message: %s',
            $httpCode,
            $serverError ?? '(none)',
            $serverMessage ?? '(none)'
         ));

         return [
            'secret'      => null,
            'error'       => $serverError ?? 'http_error',
            'http_code'   => $httpCode,
            'message'     => $userMessage,
            'retry_after' => $retryAfter,
            'environment_id' => null,
            'reenrolled'  => false,
         ];
      }

      // Resposta 2xx mas JSON inválido
      if (!is_array($data)) {
         $snippet = trim(preg_replace('/\s+/', ' ', substr((string) $response, 0, 500)));
         $contentHint = '';
         if ($snippet === '') {
            $contentHint = 'body vazio';
         } elseif (preg_match('/<(html|br|b|div|!DOCTYPE)/i', $snippet)) {
            $contentHint = 'HTML detectado (provável erro PHP ou página de proxy/WAF)';
         } else {
            $contentHint = 'conteúdo não-JSON (json_last_error: ' . json_last_error_msg() . ')';
         }
         Toolbox::logInFile('plugin_nextool', sprintf(
            'Bootstrap HMAC falhou - %s (HTTP %d). Body: %s',
            $contentHint,
            $httpCode,
            $snippet
         ));
         return [
            'secret'      => null,
            'error'       => 'invalid_response',
            'http_code'   => $httpCode,
            'message'     => __('O servidor de licenciamento retornou uma resposta inválida. Tente novamente em instantes.', 'nextool'),
            'retry_after' => null,
            'environment_id' => null,
            'reenrolled'  => false,
         ];
      }

      // Resposta 2xx, JSON válido, mas sem client_secret
      $secret = $data['client_secret'] ?? null;
      if (!is_string($secret) || $secret === '') {
         Toolbox::logInFile('plugin_nextool', sprintf(
            'Bootstrap HMAC falhou - resposta JSON sem client_secret (HTTP %d): %s',
            $httpCode,
            substr(json_encode($data), 0, 500)
         ));
         return [
            'secret'      => null,
            'error'       => 'missing_secret',
            'http_code'   => $httpCode,
            'message'     => __('O servidor de licenciamento não retornou a chave de segurança esperada. Tente novamente em instantes.', 'nextool'),
            'retry_after' => null,
            'environment_id' => null,
            'reenrolled'  => false,
         ];
      }

      // Re-enroll por substituição: o servidor cunhou identidade NOVA para este ambiente
      // (FREE órfão). O environment_id retornado difere do enviado; o chamador deve adotá-lo.
      $reenrolled = ($data['reenrolled'] ?? false) === true;
      $newEnvironmentId = isset($data['environment_id']) && is_string($data['environment_id']) && $data['environment_id'] !== ''
         ? $data['environment_id']
         : null;
      if ($reenrolled && $newEnvironmentId !== null) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            'Bootstrap: servidor re-registrou o ambiente (identidade órfã %s substituída por %s).',
            $clientIdentifier,
            $newEnvironmentId
         ));
      }

      return [
         'secret'      => $secret,
         'error'       => null,
         'http_code'   => $httpCode,
         'message'     => null,
         'retry_after' => null,
         'environment_id' => $newEnvironmentId,
         'reenrolled'  => $reenrolled && $newEnvironmentId !== null,
      ];
   }

   /**
    * Baixa o pacote do módulo, valida o hash e extrai para o diretório local
    *
    * @throws Exception
    */
   public function downloadModule(string $moduleKey): array {
      $manifest = $this->requestManifest($moduleKey);

      $downloadUrl = $manifest['download_url'] ?? '';
      $hashExpected = $manifest['hash_sha256'] ?? '';
      $version = $manifest['version'] ?? 'unknown';

      if ($downloadUrl === '' || $hashExpected === '') {
         throw new RuntimeException(__('Manifesto inválido retornado pelo ContainerAPI.', 'nextool'));
      }

      $downloadPath = $this->downloadPackage($downloadUrl, $moduleKey, $version);
      $this->verifyHash($downloadPath, $hashExpected);

      // Detectar formato do artefato e renomear com extensão correta (PharData exige)
      require_once NEXTOOL_PHP_DIR . '/inc/filehelper.class.php';
      $format = PluginNextoolFileHelper::detectArchiveFormat($downloadPath);
      if ($format === 'tar.gz') {
         $packagePath = $downloadPath . '.tar.gz';
      } elseif ($format === 'zip') {
         $packagePath = $downloadPath . '.zip';
      } else {
         @unlink($downloadPath);
         throw new RuntimeException(__('Formato de artefato não reconhecido.', 'nextool'));
      }
      if (!rename($downloadPath, $packagePath)) {
         @unlink($downloadPath);
         throw new RuntimeException(__('Falha ao preparar artefato para extração.', 'nextool'));
      }

      require_once NEXTOOL_PHP_DIR . '/inc/modulespath.inc.php';
      $destination = NEXTOOL_MODULES_BASE . '/' . $moduleKey;
      $this->extractPackage($packagePath, $destination, $moduleKey);

      return [
         'module'  => $moduleKey,
         'version' => $version,
      ];
   }

   private function requestManifest(string $moduleKey): array {
      if (!$this->supportsSignedRequests()) {
         throw new RuntimeException(__('Integração HMAC não configurada. Informe o identificador e o segredo na aba de distribuição.', 'nextool'));
      }

      return $this->requestManifestSigned($moduleKey);
   }

   private function requestManifestSigned(string $moduleKey): array {
      $payload = $this->buildSignedPayload($moduleKey);
      $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      if ($body === false) {
        throw new RuntimeException(__('Falha ao montar payload de manifesto.', 'nextool'));
      }

      $requestHeaders = array_merge(
         ['Content-Type: application/json'],
         self::buildHmacHeadersV2($this->clientIdentifier, '/api/distribution/install-request', $body, $this->clientSecret)
      );
      if (!isset($GLOBALS['nextool_request_group_id'])) {
         $GLOBALS['nextool_request_group_id'] = PluginNextoolConfig::generateRequestGroupId();
      }
      $requestHeaders[] = 'X-Request-Group-Id: ' . $GLOBALS['nextool_request_group_id'];

      $response = $this->performRequest($this->baseUrl . '/api/distribution/install-request', [
         'method' => 'POST',
         'body' => $body,
         'headers' => $requestHeaders,
         'timeout' => 60,
      ]);

      return $this->extractManifestData($response);
   }

   private function downloadPackage(string $url, string $moduleKey, string $version): string {
      $tmpDir = GLPI_TMP_DIR . '/nextool_remote';
      if (!is_dir($tmpDir)) {
         mkdir($tmpDir, 0755, true);
      }

      $downloadPath = $tmpDir . '/' . $moduleKey . '-' . $version . '-' . uniqid() . '.download';
      $fp = fopen($downloadPath, 'w+');
      if ($fp === false) {
         throw new RuntimeException(__('Não foi possível criar arquivo temporário para download.', 'nextool'));
      }

      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_FILE, $fp);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 120);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
      $headers = [];
      if ($this->clientIdentifier !== '') {
         $headers[] = 'X-Client-Identifier: ' . $this->clientIdentifier;
      }
      if (!isset($GLOBALS['nextool_request_group_id'])) {
         $GLOBALS['nextool_request_group_id'] = PluginNextoolConfig::generateRequestGroupId();
      }
      $headers[] = 'X-Request-Group-Id: ' . $GLOBALS['nextool_request_group_id'];
      if (!empty($headers)) {
         curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      }
      $result = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $error    = curl_error($ch);
      curl_close($ch);
      fclose($fp);

      if (!$result || $httpCode >= 300) {
         @unlink($downloadPath);
         throw new RuntimeException(sprintf(__('Falha ao baixar módulo (HTTP %s): %s', 'nextool'), $httpCode, $error));
      }

      return $downloadPath;
   }

   private function verifyHash(string $filePath, string $expected): void {
      $real = hash_file('sha256', $filePath);
      $expected = strtolower(trim($expected));
      if (strpos($expected, ' ') !== false) {
         $expected = explode(' ', $expected)[0];
      }

      if (!hash_equals($expected, $real)) {
         throw new RuntimeException(__('Hash SHA256 inválido para o pacote baixado.', 'nextool'));
      }
   }

   private function extractPackage(string $filePath, string $destination, string $moduleKey): void {
      $tmpExtract = GLPI_TMP_DIR . '/nextool_remote/extracted_' . uniqid();
      if (!is_dir($tmpExtract)) {
         mkdir($tmpExtract, 0755, true);
      }

      require_once NEXTOOL_PHP_DIR . '/inc/filehelper.class.php';

      if (str_ends_with($filePath, '.tar.gz')) {
         // Formato preferencial - PharData (built-in, sem dependência externa)
         try {
            $phar = new PharData($filePath);
            PluginNextoolFileHelper::assertSecureArchiveEntries(
               $phar,
               sprintf('pacote do módulo %s', $moduleKey),
               $filePath
            );
            $phar->extractTo($tmpExtract, null, true);
         } catch (Throwable $e) {
            @unlink($filePath);
            throw new RuntimeException(sprintf(
               __('Falha ao extrair pacote do módulo %s: %s', 'nextool'),
               $moduleKey,
               $e->getMessage()
            ));
         }
      } elseif (str_ends_with($filePath, '.zip')) {
         // Fallback - ZipArchive (requer ext-zip)
         if (!class_exists('ZipArchive')) {
            @unlink($filePath);
            throw new RuntimeException(
               __('A extensão php-zip não está instalada neste servidor. Solicite ao administrador que instale a extensão (ex: apt install php-zip ou yum install php-zip) e reinicie o PHP.', 'nextool')
            );
         }
         $zip = new ZipArchive();
         if ($zip->open($filePath) !== true) {
            @unlink($filePath);
            throw new RuntimeException(__('Não foi possível abrir o pacote do módulo.', 'nextool'));
         }
         try {
            PluginNextoolFileHelper::assertSecureArchiveEntries(
               $zip,
               sprintf('pacote do módulo %s', $moduleKey)
            );
         } catch (Throwable $e) {
            $zip->close();
            @unlink($filePath);
            throw $e;
         }
         if (!$zip->extractTo($tmpExtract)) {
            $zip->close();
            @unlink($filePath);
            throw new RuntimeException(__('Falha ao extrair pacote do módulo.', 'nextool'));
         }
         $zip->close();
      } else {
         @unlink($filePath);
         throw new RuntimeException(sprintf(
            __('Formato de artefato não suportado: %s', 'nextool'),
            pathinfo($filePath, PATHINFO_EXTENSION)
         ));
      }

      $candidate = $tmpExtract . '/' . $moduleKey;
      if (!is_dir($candidate)) {
         // Caso o artefato não contenha pasta raiz, usa diretório temporário
         $candidate = $tmpExtract;
      }

      $this->ensureWritableDirectory(dirname($destination));
      if (is_dir($destination)) {
         $this->ensureWritableDirectory($destination);
      }

      $this->deleteDir($destination);
      $this->recursiveCopy($candidate, $destination);
      $this->invalidateOpcache($destination, $moduleKey);
      $this->deleteDir($tmpExtract);
      @unlink($filePath);
   }

   private function performRequest(string $url, array $options = []): array {
      require_once NEXTOOL_PHP_DIR . '/inc/filehelper.class.php';
      return PluginNextoolFileHelper::performHttpRequest($url, $options);
   }

   private function supportsSignedRequests(): bool {
      return $this->clientIdentifier !== '' && $this->clientSecret !== '';
   }

   private function extractManifestData(array $response): array {
      $data = json_decode($response['body'], true);
      $this->registerCommOutcome($response, is_array($data) ? $data : null);
      if (!is_array($data)) {
         throw new RuntimeException(__('Resposta inválida do ContainerAPI.', 'nextool'));
      }

      if ($response['http_code'] >= 300) {
         $message = $data['message'] ?? $data['error'] ?? __('Erro desconhecido', 'nextool');
         if (($data['error'] ?? '') === 'nextool_upgrade_required') {
            $minVer = $data['min_version_nextools'] ?? null;
            $message = $minVer !== null && $minVer !== ''
               ? sprintf(
                  __('Para atualizar é necessário estar utilizando o Nextool versão %s ou superior.', 'nextool'),
                  $minVer
               )
               : __('É necessário atualizar o plugin Nextool para a versão mais recente para baixar ou atualizar módulos.', 'nextool');
            $message .= ' ' . __('Atualize em:', 'nextool') . ' https://nextoolsolutions.com/produtos/plugin-nextools-glpi';
         } else {
            $message = sprintf(__('Falha ao solicitar manifesto de distribuição: %s', 'nextool'), $message);
         }
         throw new RuntimeException($message);
      }

      return $data;
   }

   private function buildSignedPayload(string $moduleKey): array {
      $payload = [
         'module_key' => $moduleKey,
      ];

      $licenseConfig = PluginNextoolLicenseConfig::getDefaultConfig();
      if (!empty($licenseConfig['license_key'])) {
         $payload['license_key'] = $licenseConfig['license_key'];
      }

      $domain = $this->getServerDomain();
      if ($domain !== '') {
         $payload['domain'] = $domain;
      }

      $clientInfo = [
         'plugin_version' => PluginNextoolConfig::getPluginVersion(),
         'glpi_version'   => defined('GLPI_VERSION') ? GLPI_VERSION : null,
         'php_version'    => PHP_VERSION,
         'origin'         => 'module_download',
      ];

      $globalConfig = PluginNextoolConfig::getConfig();
      if (!empty($globalConfig['client_identifier'])) {
         $clientInfo['environment_id'] = $globalConfig['client_identifier'];
      }

      $payload['client_info'] = $clientInfo;

      return $payload;
   }

   public function submitContactLead(array $leadData): array {
      if (!$this->supportsSignedRequests()) {
         throw new RuntimeException(__('Integração HMAC não configurada. Informe o identificador e o segredo na aba de distribuição.', 'nextool'));
      }

      $body = json_encode($leadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      if ($body === false) {
         throw new RuntimeException(__('Falha ao montar payload do formulário de contato.', 'nextool'));
      }

      $response = $this->performRequest($this->baseUrl . '/api/contact/leads', [
         'method' => 'POST',
         'body' => $body,
         'headers' => array_merge(
            ['Content-Type: application/json'],
            self::buildHmacHeadersV2($this->clientIdentifier, '/api/contact/leads', $body, $this->clientSecret)
         ),
         'timeout' => 60,
      ]);

      return $this->decodeJsonResponse($response, __('Falha ao enviar o formulário de contato.', 'nextool'));
   }

   /**
    * F3 -- solicita um código de vínculo de conta ao ContainerAPI (assinado HMAC do ambiente).
    * O código é exibido/abre o portal; sua posse prova controle do ambiente (anti-spoofing).
    *
    * @return array{link_code:string,expires_in:int,portal_link_url:string}
    */
   public function requestLinkCode(): array {
      if (!$this->supportsSignedRequests()) {
         throw new RuntimeException(__('Ambiente ainda não provisionado. Sincronize a licença antes de vincular a conta.', 'nextool'));
      }
      $body = '{}';
      $response = $this->performRequest($this->baseUrl . '/api/account/link-code', [
         'method' => 'POST',
         'body' => $body,
         'headers' => array_merge(
            ['Content-Type: application/json'],
            self::buildHmacHeadersV2($this->clientIdentifier, '/api/account/link-code', $body, $this->clientSecret)
         ),
         'timeout' => 30,
      ]);
      return $this->decodeJsonResponse($response, __('Falha ao gerar o código de vínculo.', 'nextool'));
   }

   /**
    * F3 -- consulta o estado do vínculo de conta do ambiente (para a UI).
    *
    * @return array{linked:bool,verified:bool,portal_email:?string,linked_at:?string}
    */
   public function getLinkStatus(): array {
      if (!$this->supportsSignedRequests()) {
         return ['linked' => false, 'verified' => false, 'portal_email' => null, 'linked_at' => null];
      }
      $body = '{}';
      $response = $this->performRequest($this->baseUrl . '/api/account/link-status', [
         'method' => 'POST',
         'body' => $body,
         'headers' => array_merge(
            ['Content-Type: application/json'],
            self::buildHmacHeadersV2($this->clientIdentifier, '/api/account/link-status', $body, $this->clientSecret)
         ),
         'timeout' => 20,
      ]);
      return $this->decodeJsonResponse($response, __('Falha ao consultar o vínculo de conta.', 'nextool'));
   }

   /**
    * F3 -- desfaz o vínculo de conta do ambiente.
    */
   public function unlinkAccount(): array {
      if (!$this->supportsSignedRequests()) {
         throw new RuntimeException(__('Ambiente não provisionado.', 'nextool'));
      }
      $body = '{}';
      $response = $this->performRequest($this->baseUrl . '/api/account/unlink', [
         'method' => 'POST',
         'body' => $body,
         'headers' => array_merge(
            ['Content-Type: application/json'],
            self::buildHmacHeadersV2($this->clientIdentifier, '/api/account/unlink', $body, $this->clientSecret)
         ),
         'timeout' => 20,
      ]);
      return $this->decodeJsonResponse($response, __('Falha ao desvincular a conta.', 'nextool'));
   }

   private function decodeJsonResponse(array $response, string $errorPrefix): array {
      $data = json_decode($response['body'], true);
      $this->registerCommOutcome($response, is_array($data) ? $data : null);
      if (!is_array($data)) {
         throw new RuntimeException($errorPrefix . ' ' . __('Resposta inválida do ContainerAPI.', 'nextool'));
      }
      if ($response['http_code'] >= 300) {
         $message = $data['message'] ?? $data['error'] ?? __('Erro desconhecido', 'nextool');
         throw new RuntimeException($errorPrefix . ' ' . $message);
      }
      return $data;
   }

   /**
    * Alimenta o backoff/estado de comunicação (#243/#244) a partir de qualquer
    * resposta assinada deste cliente (link-code, link-status, unlink, manifesto).
    * Estas chamadas são user-triggered e por isso NÃO são suprimidas aqui -- quem
    * suprime o polling automático é o ajax/account_action.php.
    */
   private function registerCommOutcome(array $response, ?array $data): void {
      require_once NEXTOOL_PHP_DIR . '/inc/commbackoff.class.php';
      $httpCode = isset($response['http_code']) ? (int) $response['http_code'] : null;
      if ($httpCode === null || $httpCode >= 500 || $data === null) {
         PluginNextoolCommBackoff::registerNetworkFailure($httpCode);
      } elseif (PluginNextoolCommBackoff::isAuthFailure($httpCode, $data)) {
         PluginNextoolCommBackoff::registerAuthFailure(
            $httpCode,
            isset($data['code']) ? (string) $data['code'] : null
         );
      } else {
         PluginNextoolCommBackoff::registerSuccess($httpCode);
      }
   }


   /**
    * Obtém o domínio do servidor para envio ao ContainerAPI (identificação do ambiente).
    * Best effort: em proxies/load balancers, HTTP_HOST ou SERVER_NAME podem ser
    * configurados ou manipulados; em setups críticos, considerar variável de ambiente.
    *
    * @return string
    */
   private function getServerDomain(): string {
      if (!empty($_SERVER['HTTP_HOST'])) {
         return (string) $_SERVER['HTTP_HOST'];
      }

      if (!empty($_SERVER['SERVER_NAME'])) {
         return (string) $_SERVER['SERVER_NAME'];
      }

      return '';
   }


   private function ensureWritableDirectory(string $dir): void {
      if (!is_dir($dir)) {
         if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf(
               __('Não foi possível criar o diretório %s. Ajuste permissões/ownership.', 'nextool'),
               $dir
            ));
         }
      }

      if (!is_writable($dir)) {
         if (!@chmod($dir, 0775)) {
            $parent = dirname($dir);
            $hint = $parent !== $dir && $parent !== '.'
               ? sprintf(__(' Ajuste o proprietário em toda a árvore, ex.: chown -R %s %s', 'nextool'), 'www-data:www-data', $parent)
               : sprintf(__(' Ajuste o proprietário/permissões (ex.: chown %s).', 'nextool'), 'www-data:www-data');
            throw new RuntimeException(sprintf(
               __('O diretório %s não é gravável pelo GLPI (pode ter sido criado por outro usuário, ex.: root).', 'nextool') . $hint,
               $dir
            ));
         }
      }
   }

   private function deleteDir(string $dir): void {
      require_once NEXTOOL_PHP_DIR . '/inc/filehelper.class.php';
      PluginNextoolFileHelper::deleteDirectory($dir, true);
   }

   private function recursiveCopy(string $source, string $dest): void {
      require_once NEXTOOL_PHP_DIR . '/inc/filehelper.class.php';
      PluginNextoolFileHelper::recursiveCopy($source, $dest);
   }

   private function invalidateOpcache(string $destination, string $moduleKey): void {
      if (function_exists('opcache_reset')) {
         @opcache_reset();
         return;
      }

      if (!function_exists('opcache_invalidate')) {
         return;
      }

      $classFile = rtrim($destination, DIRECTORY_SEPARATOR)
         . DIRECTORY_SEPARATOR . 'inc'
         . DIRECTORY_SEPARATOR . $moduleKey . '.class.php';

      if (is_file($classFile)) {
         @opcache_invalidate($classFile, true);
      }
   }

   /**
    * Obtém o segredo HMAC de um ambiente via bootstrap na ContainerAPI.
    *
    * O fallback local (tabela admin `glpi_plugin_nextool_containerapi_env_secrets`)
    * foi removido: era um atalho de co-locação que só existia no GLPI co-residente
    * com a ContainerAPI; o plugin cliente nunca tem essa tabela admin (regra de
    * ouro: nextool não acessa o banco admin direto). O segredo resiliente vive no
    * context `plugin:nextool_provisioning`.
    *
    * @param string $baseUrl URL base do ContainerAPI
    * @param string $clientIdentifier Identificador do ambiente
    * @param bool|null &$reused Mantido por compatibilidade (sempre false)
    * @return array{secret: ?string, reused: bool, error: ?string, http_code: int, message: ?string, retry_after: ?int}
    */
   public static function obtainOrReuseClientSecret(string $baseUrl, string $clientIdentifier, ?bool &$reused = null): array {
      $reused = false;
      $bootstrapResult = self::bootstrapClientSecret($baseUrl, $clientIdentifier);

      if ($bootstrapResult['secret'] !== null) {
         return [
            'secret'      => $bootstrapResult['secret'],
            'reused'      => false,
            'error'       => null,
            'http_code'   => $bootstrapResult['http_code'],
            'message'     => null,
            'retry_after' => null,
            'environment_id' => $bootstrapResult['environment_id'],
            'reenrolled'  => $bootstrapResult['reenrolled'],
         ];
      }

      // Bootstrap falhou -- sem fallback local (a tabela admin não existe no cliente).
      return [
         'secret'      => null,
         'reused'      => false,
         'error'       => $bootstrapResult['error'],
         'http_code'   => $bootstrapResult['http_code'],
         'message'     => $bootstrapResult['message'],
         'retry_after' => $bootstrapResult['retry_after'],
         'environment_id' => null,
         'reenrolled'  => false,
      ];
   }

   /**
    * F1a -- enroll server-issued: pede ao ContainerAPI que CUNHE um environment_id opaco e único
    * (em vez de derivar localmente). Retorna {environment_id, client_secret} juntos. Usado em
    * install NOVO (sem identidade) -- mata a colisão localhost e garante unicidade por GLPI mesmo
    * na mesma máquina/IP. Envia a capability 'enroll-v2' (D1) + telemetria (domain/glpi_version)
    * para o registro inicial do ambiente no servidor.
    *
    * @return array{environment_id: ?string, client_secret: ?string, error: ?string, http_code: int, message: ?string, retry_after: ?int}
    */
   public static function enrollEnvironment(string $baseUrl): array {
      $baseUrl = rtrim($baseUrl, '/');
      if ($baseUrl === '') {
         return [
            'environment_id' => null, 'client_secret' => null,
            'error' => 'invalid_config', 'http_code' => 0,
            'message' => __('URL do ContainerAPI não configurada.', 'nextool'),
            'retry_after' => null,
         ];
      }

      require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';

      $domain = '';
      if (!empty($GLOBALS['CFG_GLPI']['url_base'])) {
         $domain = (string) (parse_url((string) $GLOBALS['CFG_GLPI']['url_base'], PHP_URL_HOST) ?: '');
      }
      $glpiVersion = defined('GLPI_VERSION') ? GLPI_VERSION : null;
      $payload = json_encode(array_filter([
         'domain'       => $domain !== '' ? $domain : null,
         'glpi_version' => $glpiVersion,
         'client_info'  => array_filter([
            'plugin_version' => PluginNextoolConfig::getPluginVersion(),
            'glpi_version'   => $glpiVersion,
         ]),
      ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

      $ch = curl_init($baseUrl . '/api/distribution/enroll');
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload ?: '{}');
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
      if (!isset($GLOBALS['nextool_request_group_id'])) {
         $GLOBALS['nextool_request_group_id'] = PluginNextoolConfig::generateRequestGroupId();
      }
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
         'Content-Type: application/json',
         'X-Nextool-Caps: enroll-v2',
         'X-Request-Group-Id: ' . $GLOBALS['nextool_request_group_id'],
      ]);

      $response  = curl_exec($ch);
      $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);
      $curlErrno = curl_errno($ch);
      curl_close($ch);

      if ($response === false) {
         Toolbox::logInFile('plugin_nextool', sprintf('Enroll falhou - erro de rede (curl errno %d): %s', $curlErrno, $curlError));
         return [
            'environment_id' => null, 'client_secret' => null,
            'error' => 'network_error', 'http_code' => 0,
            'message' => __('Erro de rede ao conectar com o servidor de licenciamento. Tente novamente em instantes.', 'nextool'),
            'retry_after' => null,
         ];
      }

      $data = json_decode($response, true);

      if ($httpCode >= 300) {
         $retryAfter = is_array($data) && isset($data['retry_after']) ? (int) $data['retry_after'] : null;
         Toolbox::logInFile('plugin_nextool', sprintf('Enroll falhou - HTTP %d: %s', $httpCode, substr((string) $response, 0, 300)));
         $userMessage = $httpCode === 429
            ? sprintf(__('O servidor de licenciamento está limitando requisições. Tente novamente em %d segundos.', 'nextool'), $retryAfter ?? 60)
            : __('Não foi possível registrar o ambiente no servidor de licenciamento. Tente novamente em instantes.', 'nextool');
         return [
            'environment_id' => null, 'client_secret' => null,
            'error' => is_array($data) ? ($data['error'] ?? 'http_error') : 'http_error',
            'http_code' => $httpCode, 'message' => $userMessage, 'retry_after' => $retryAfter,
         ];
      }

      $environmentId = is_array($data) ? ($data['environment_id'] ?? null) : null;
      $secret        = is_array($data) ? ($data['client_secret'] ?? null) : null;
      if (!is_string($environmentId) || $environmentId === '' || !is_string($secret) || $secret === '') {
         Toolbox::logInFile('plugin_nextool', sprintf('Enroll falhou - resposta sem environment_id/client_secret (HTTP %d)', $httpCode));
         return [
            'environment_id' => null, 'client_secret' => null,
            'error' => 'invalid_response', 'http_code' => $httpCode,
            'message' => __('O servidor de licenciamento não retornou a identidade esperada. Tente novamente em instantes.', 'nextool'),
            'retry_after' => null,
         ];
      }

      return [
         'environment_id' => $environmentId, 'client_secret' => $secret,
         'error' => null, 'http_code' => $httpCode, 'message' => null, 'retry_after' => null,
      ];
   }

   /**
    * Fluxo vinculo-first: identifica o ambiente SOB DEMANDA. Enrola no ContainerAPI (cunha NX2- +
    * segredo HMAC) e PERSISTE a identidade nos 3 lugares (main_configs, context distribution,
    * provisioning resiliente que sobrevive ao uninstall). Usado quando o usuario clica "Vincular
    * conta" num ambiente ainda nao identificado -- o aceite formal dos termos acontece no portal,
    * no momento do vinculo. So a base_url e pre-requisito.
    *
    * @return array{success: bool, client_identifier: string, message: ?string, http_code: int, retry_after: ?int}
    */
   public static function enrollAndPersist(string $baseUrl): array {
      $enroll = self::enrollEnvironment($baseUrl);
      if ($enroll['environment_id'] === null || $enroll['client_secret'] === null) {
         return [
            'success' => false, 'client_identifier' => '',
            'message' => $enroll['message'], 'http_code' => $enroll['http_code'],
            'retry_after' => $enroll['retry_after'] ?? null,
         ];
      }
      $clientIdentifier = (string) $enroll['environment_id'];
      PluginNextoolConfig::setClientIdentifier($clientIdentifier);
      $dist = PluginNextoolConfig::getDistributionSettings();
      Config::setConfigurationValues('plugin:nextool_distribution', array_merge($dist, [
         'client_identifier' => $clientIdentifier,
         'client_secret'     => $enroll['client_secret'],
      ]));
      PluginNextoolConfig::setProvisioning($clientIdentifier, (string) $enroll['client_secret']);
      if (class_exists('PluginNextoolConfigAudit')) {
         PluginNextoolConfigAudit::log([
            'section' => 'distribution',
            'action'  => 'enroll',
            'result'  => 1,
            'message' => __('Ambiente identificado no servidor de licenciamento (vínculo de conta).', 'nextool'),
            'details' => ['base_url' => $baseUrl],
         ]);
      }
      return [
         'success' => true, 'client_identifier' => $clientIdentifier,
         'message' => null, 'http_code' => $enroll['http_code'], 'retry_after' => null,
      ];
   }

   /**
    * F5 -- migração de identidade legada (RITECH-) -> server-issued (NX2-). Assina com o segredo
    * ATUAL (prova de posse) e deixa o SERVIDOR decidir o modo: rename in-place (único, preserva
    * histórico/licença), fresh (clone FREE), defer (PAID ambíguo -> fork manual), noop/not_eligible.
    * Retorna {environment_id, client_secret, mode}: se client_secret vier null, o chamador MANTÉM a
    * identidade atual (defer/noop/not_eligible) ou retenta (erro). NUNCA cunha id local.
    *
    * @return array{environment_id: ?string, client_secret: ?string, mode: ?string, error: ?string, http_code: int}
    */
   public static function migrateEnvironment(string $baseUrl, string $currentIdentifier, string $currentSecret): array {
      $baseUrl = rtrim(trim($baseUrl), '/');
      $currentIdentifier = trim($currentIdentifier);
      $currentSecret = trim($currentSecret);
      $fail = static function (string $error, int $code = 0): array {
         return ['environment_id' => null, 'client_secret' => null, 'mode' => null, 'error' => $error, 'http_code' => $code];
      };
      if ($baseUrl === '' || $currentIdentifier === '' || $currentSecret === '') {
         return $fail('invalid_config');
      }

      require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';

      $domain = '';
      if (!empty($GLOBALS['CFG_GLPI']['url_base'])) {
         $domain = (string) (parse_url((string) $GLOBALS['CFG_GLPI']['url_base'], PHP_URL_HOST) ?: '');
      }
      $glpiVersion = defined('GLPI_VERSION') ? GLPI_VERSION : null;
      $payload = json_encode(array_filter([
         'domain'       => $domain !== '' ? $domain : null,
         'glpi_version' => $glpiVersion,
         'client_info'  => array_filter([
            'plugin_version' => PluginNextoolConfig::getPluginVersion(),
            'glpi_version'   => $glpiVersion,
         ]),
      ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

      $headers = array_merge(
         ['Content-Type: application/json'],
         self::buildHmacHeadersV2($currentIdentifier, '/api/distribution/migrate', $payload, $currentSecret)
      );
      if (!isset($GLOBALS['nextool_request_group_id'])) {
         $GLOBALS['nextool_request_group_id'] = PluginNextoolConfig::generateRequestGroupId();
      }
      $headers[] = 'X-Request-Group-Id: ' . $GLOBALS['nextool_request_group_id'];

      $ch = curl_init($baseUrl . '/api/distribution/migrate');
      curl_setopt_array($ch, [
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_POST           => true,
         CURLOPT_POSTFIELDS     => $payload,
         CURLOPT_HTTPHEADER     => $headers,
         CURLOPT_TIMEOUT        => 30,
         CURLOPT_SSL_VERIFYPEER => true,
         CURLOPT_SSL_VERIFYHOST => 2,
      ]);
      $response  = curl_exec($ch);
      $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);
      curl_close($ch);

      if ($response === false) {
         Toolbox::logInFile('plugin_nextool', sprintf('Migrate falhou - erro de rede: %s', $curlError));
         return $fail('network_error');
      }
      $data = json_decode($response, true);
      if ($httpCode >= 300 || !is_array($data)) {
         Toolbox::logInFile('plugin_nextool', sprintf('Migrate falhou - HTTP %d: %s', $httpCode, substr((string) $response, 0, 300)));
         return $fail(is_array($data) ? (string) ($data['error'] ?? 'http_error') : 'http_error', $httpCode);
      }

      return [
         'environment_id' => isset($data['environment_id']) && is_string($data['environment_id']) ? $data['environment_id'] : null,
         'client_secret'  => isset($data['client_secret']) && is_string($data['client_secret']) ? $data['client_secret'] : null,
         'mode'           => isset($data['mode']) ? (string) $data['mode'] : null,
         'error'          => null,
         'http_code'      => $httpCode,
      ];
   }

}

