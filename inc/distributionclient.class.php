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

require_once GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/licenseconfig.class.php';

class PluginNextoolDistributionClient {

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
    * @return array{secret: ?string, error: ?string, http_code: int, message: ?string, retry_after: ?int}
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
         ];
      }

      $payload = json_encode([
         'client_identifier' => $clientIdentifier,
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

      $ch = curl_init($baseUrl . '/api/distribution/bootstrap');
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload ?: '');
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
         'Content-Type: application/json',
      ]);

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
            'Bootstrap HMAC falhou — erro de rede (curl errno %d): %s',
            $curlErrno,
            $curlError
         ));

         return [
            'secret'      => null,
            'error'       => 'network_error',
            'http_code'   => 0,
            'message'     => $networkMessage,
            'retry_after' => null,
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
            $httpCode === 400
               => sprintf(__('Requisição inválida para o servidor de licenciamento: %s', 'nextool'), $serverMessage ?? 'payload inválido'),
            $httpCode >= 500
               => sprintf(__('Erro interno no servidor de licenciamento (HTTP %d). Tente novamente em instantes.', 'nextool'), $httpCode),
            default
               => sprintf(__('O servidor de licenciamento retornou um erro inesperado (HTTP %d): %s', 'nextool'), $httpCode, $serverMessage ?? 'sem detalhes'),
         };

         Toolbox::logInFile('plugin_nextool', sprintf(
            'Bootstrap HMAC falhou — HTTP %d, error: %s, message: %s',
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
         ];
      }

      // Resposta 2xx mas JSON inválido
      if (!is_array($data)) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            'Bootstrap HMAC falhou — resposta não é JSON válido (HTTP %d): %s',
            $httpCode,
            substr((string) $response, 0, 500)
         ));
         return [
            'secret'      => null,
            'error'       => 'invalid_response',
            'http_code'   => $httpCode,
            'message'     => __('O servidor de licenciamento retornou uma resposta inválida. Tente novamente em instantes.', 'nextool'),
            'retry_after' => null,
         ];
      }

      // Resposta 2xx, JSON válido, mas sem client_secret
      $secret = $data['client_secret'] ?? null;
      if (!is_string($secret) || $secret === '') {
         Toolbox::logInFile('plugin_nextool', sprintf(
            'Bootstrap HMAC falhou — resposta JSON sem client_secret (HTTP %d): %s',
            $httpCode,
            substr(json_encode($data), 0, 500)
         ));
         return [
            'secret'      => null,
            'error'       => 'missing_secret',
            'http_code'   => $httpCode,
            'message'     => __('O servidor de licenciamento não retornou a chave de segurança esperada. Tente novamente em instantes.', 'nextool'),
            'retry_after' => null,
         ];
      }

      return [
         'secret'      => $secret,
         'error'       => null,
         'http_code'   => $httpCode,
         'message'     => null,
         'retry_after' => null,
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
      require_once GLPI_ROOT . '/plugins/nextool/inc/filehelper.class.php';
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

      require_once GLPI_ROOT . '/plugins/nextool/inc/modulespath.inc.php';
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

      $timestamp = (string) time();
      $signature = $this->generateSignature($body, $timestamp);

      $requestHeaders = [
         'Content-Type: application/json',
         'X-Client-Identifier: ' . $this->clientIdentifier,
         'X-Timestamp: ' . $timestamp,
         'X-Signature: ' . $signature,
      ];
      if (isset($GLOBALS['nextool_request_group_id'])) {
         $requestHeaders[] = 'X-Request-Group-Id: ' . $GLOBALS['nextool_request_group_id'];
      }

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
      $headers = [];
      if ($this->clientIdentifier !== '') {
         $headers[] = 'X-Client-Identifier: ' . $this->clientIdentifier;
      }
      if (isset($GLOBALS['nextool_request_group_id'])) {
         $headers[] = 'X-Request-Group-Id: ' . $GLOBALS['nextool_request_group_id'];
      }
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

      if (str_ends_with($filePath, '.tar.gz')) {
         // Formato preferencial — PharData (built-in, sem dependência externa)
         try {
            $phar = new PharData($filePath);
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
         // Fallback — ZipArchive (requer ext-zip)
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
      require_once GLPI_ROOT . '/plugins/nextool/inc/filehelper.class.php';
      return PluginNextoolFileHelper::performHttpRequest($url, $options);
   }

   private function supportsSignedRequests(): bool {
      return $this->clientIdentifier !== '' && $this->clientSecret !== '';
   }

   private function extractManifestData(array $response): array {
      $data = json_decode($response['body'], true);
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
            $message .= ' ' . __('Atualize em:', 'nextool') . ' https://nextoolsolutions.ai/produtos/plugin-nextools-glpi';
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

      $timestamp = (string) time();
      $signature = $this->generateSignature($body, $timestamp);

      $response = $this->performRequest($this->baseUrl . '/api/contact/leads', [
         'method' => 'POST',
         'body' => $body,
         'headers' => [
            'Content-Type: application/json',
            'X-Client-Identifier: ' . $this->clientIdentifier,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature,
         ],
         'timeout' => 60,
      ]);

      return $this->decodeJsonResponse($response, __('Falha ao enviar o formulário de contato.', 'nextool'));
   }

   private function decodeJsonResponse(array $response, string $errorPrefix): array {
      $data = json_decode($response['body'], true);
      if (!is_array($data)) {
         throw new RuntimeException($errorPrefix . ' ' . __('Resposta inválida do ContainerAPI.', 'nextool'));
      }
      if ($response['http_code'] >= 300) {
         $message = $data['message'] ?? $data['error'] ?? __('Erro desconhecido', 'nextool');
         throw new RuntimeException($errorPrefix . ' ' . $message);
      }
      return $data;
   }

   private function generateSignature(string $body, string $timestamp): string {
      return hash_hmac('sha256', $body . '|' . $timestamp, $this->clientSecret);
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
      require_once GLPI_ROOT . '/plugins/nextool/inc/filehelper.class.php';
      PluginNextoolFileHelper::deleteDirectory($dir, true);
   }

   private function recursiveCopy(string $source, string $dest): void {
      require_once GLPI_ROOT . '/plugins/nextool/inc/filehelper.class.php';
      PluginNextoolFileHelper::recursiveCopy($source, $dest);
   }

   private function invalidateOpcache(string $destination, string $moduleKey): void {
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

   public static function getEnvSecretRow(?string $clientIdentifier): ?array {
      global $DB;

      $clientIdentifier = trim((string)$clientIdentifier);
      if ($clientIdentifier === '' || !$DB->tableExists('glpi_plugin_nextool_containerapi_env_secrets')) {
         return null;
      }

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_containerapi_env_secrets',
         'WHERE' => ['environment_identifier' => $clientIdentifier],
         'LIMIT' => 1,
      ]);

      foreach ($iterator as $row) {
         return $row;
      }

      return null;
   }

   /**
    * Obtém ou reutiliza o segredo HMAC de um ambiente.
    *
    * Tenta bootstrap via ContainerAPI; se falhar, reutiliza segredo existente
    * na tabela de segredos do ambiente.
    *
    * @param string $baseUrl URL base do ContainerAPI
    * @param string $clientIdentifier Identificador do ambiente
    * @param bool|null &$reused Preenchido com true se reutilizou segredo existente
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
         ];
      }

      // Bootstrap falhou — tentar fallback na tabela local
      $row = self::getEnvSecretRow($clientIdentifier);
      if ($row && !empty($row['client_secret'])) {
         $reused = true;
         Toolbox::logInFile('plugin_nextool', sprintf('HMAC reutilizado a partir do registro existente para %s.', $clientIdentifier));
         return [
            'secret'      => (string) $row['client_secret'],
            'reused'      => true,
            'error'       => null,
            'http_code'   => 0,
            'message'     => null,
            'retry_after' => null,
         ];
      }

      // Ambos falharam — propagar o motivo do bootstrap
      return [
         'secret'      => null,
         'reused'      => false,
         'error'       => $bootstrapResult['error'],
         'http_code'   => $bootstrapResult['http_code'],
         'message'     => $bootstrapResult['message'],
         'retry_after' => $bootstrapResult['retry_after'],
      ];
   }

   public static function deleteEnvSecret(?string $clientIdentifier): bool {
      global $DB;

      $clientIdentifier = trim((string)$clientIdentifier);
      if ($clientIdentifier === '' || !$DB->tableExists('glpi_plugin_nextool_containerapi_env_secrets')) {
         return false;
      }

      $DB->delete(
         'glpi_plugin_nextool_containerapi_env_secrets',
         ['environment_identifier' => $clientIdentifier]
      );

      return true;
   }
}

