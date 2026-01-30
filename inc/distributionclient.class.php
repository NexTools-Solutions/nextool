<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Distribution Client
 * -------------------------------------------------------------------------
 * Cliente responsável por conversar com o ContainerAPI para distribuição
 * remota de módulos (manifestos, download de pacotes, bootstrap de
 * segredo HMAC, etc.).
 * -------------------------------------------------------------------------
 * @author    Richard Loureiro
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
   public static function bootstrapClientSecret(string $baseUrl, string $clientIdentifier): ?string {
      $baseUrl = rtrim($baseUrl, '/');
      if ($baseUrl === '' || $clientIdentifier === '') {
         return null;
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
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err = curl_error($ch);
      curl_close($ch);

      if ($response === false || $httpCode >= 300) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            'Falha ao solicitar client_secret no ContainerAPI (HTTP %s): %s',
            $httpCode,
            $err
         ));
         return null;
      }

      $data = json_decode($response, true);
      if (!is_array($data)) {
         return null;
      }

      $secret = $data['client_secret'] ?? null;
      return is_string($secret) && $secret !== '' ? $secret : null;
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

      $zipPath = $this->downloadPackage($downloadUrl, $moduleKey, $version);
      $this->verifyHash($zipPath, $hashExpected);
      $destination = GLPI_ROOT . '/plugins/nextool/modules/' . $moduleKey;
      $this->extractPackage($zipPath, $destination, $moduleKey);

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

      $response = $this->performRequest($this->baseUrl . '/api/distribution/install-request', [
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

      return $this->extractManifestData($response);
   }

   private function downloadPackage(string $url, string $moduleKey, string $version): string {
      $tmpDir = GLPI_TMP_DIR . '/nextool_remote';
      if (!is_dir($tmpDir)) {
         mkdir($tmpDir, 0755, true);
      }

      $zipPath = $tmpDir . '/' . $moduleKey . '-' . $version . '-' . uniqid() . '.zip';
      $fp = fopen($zipPath, 'w+');
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
      if (!empty($headers)) {
         curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      }
      $result = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $error    = curl_error($ch);
      curl_close($ch);
      fclose($fp);

      if (!$result || $httpCode >= 300) {
         @unlink($zipPath);
         throw new RuntimeException(sprintf(__('Falha ao baixar módulo (HTTP %s): %s', 'nextool'), $httpCode, $error));
      }

      return $zipPath;
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
      $zip = new ZipArchive();
      if ($zip->open($filePath) !== true) {
         throw new RuntimeException(__('Não foi possível abrir o pacote do módulo.', 'nextool'));
      }

      $tmpExtract = GLPI_TMP_DIR . '/nextool_remote/extracted_' . uniqid();
      if (!is_dir($tmpExtract)) {
         mkdir($tmpExtract, 0755, true);
      }

      if (!$zip->extractTo($tmpExtract)) {
         $zip->close();
         throw new RuntimeException(__('Falha ao extrair pacote do módulo.', 'nextool'));
      }
      $zip->close();

      $candidate = $tmpExtract . '/' . $moduleKey;
      if (!is_dir($candidate)) {
         // Caso o zip não contenha pasta raiz, usa diretório temporário mesmo
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
      $ch = curl_init($url);
      $method = strtoupper($options['method'] ?? 'GET');
      $bodyPayload = $options['body'] ?? null;

      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 30);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

      if ($method === 'POST') {
         curl_setopt($ch, CURLOPT_POST, true);
         curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyPayload ?? '');
      }

      if (!empty($options['headers'])) {
         curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
      }

      $body = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err = curl_error($ch);
      curl_close($ch);

      if ($body === false) {
         throw new RuntimeException(sprintf(__('Erro ao comunicar com ContainerAPI: %s', 'nextool'), $err));
      }

      return [
         'body' => $body,
         'http_code' => $httpCode,
      ];
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
         throw new RuntimeException(sprintf(__('Falha ao solicitar manifesto de distribuição: %s', 'nextool'), $message));
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
         'plugin_version' => $this->getPluginVersion(),
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

   private function getServerDomain(): string {
      if (!empty($_SERVER['HTTP_HOST'])) {
         return (string) $_SERVER['HTTP_HOST'];
      }

      if (!empty($_SERVER['SERVER_NAME'])) {
         return (string) $_SERVER['SERVER_NAME'];
      }

      return '';
   }

   private function getPluginVersion(): ?string {
      if (function_exists('plugin_version_nextool')) {
         $info = plugin_version_nextool();
         if (isset($info['version'])) {
            return (string) $info['version'];
         }
      }

      return null;
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
            throw new RuntimeException(sprintf(
               __('O diretório %s não é gravável pelo GLPI. Ajuste o proprietário/permissões (ex.: chown apache:apache).', 'nextool'),
               $dir
            ));
         }
      }
   }

   private function deleteDir(string $dir): void {
      if (!is_dir($dir)) {
         return;
      }
      $items = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($items as $item) {
         if ($item->isDir()) {
            if (!@rmdir($item->getRealPath())) {
               throw new RuntimeException(sprintf(
                  __('Falha ao remover diretório %s. Verifique permissões.', 'nextool'),
                  $item->getRealPath()
               ));
            }
         } else {
            if (!@unlink($item->getRealPath())) {
               throw new RuntimeException(sprintf(
                  __('Falha ao remover arquivo %s. Verifique permissões.', 'nextool'),
                  $item->getRealPath()
               ));
            }
         }
      }
      if (!@rmdir($dir)) {
         throw new RuntimeException(sprintf(
            __('Falha ao limpar diretório %s. Verifique permissões.', 'nextool'),
            $dir
         ));
      }
   }

   private function recursiveCopy(string $source, string $dest): void {
      if (!is_dir($source)) {
         throw new RuntimeException(sprintf(__('Diretório de origem inválido: %s', 'nextool'), $source));
      }
      if (!is_dir($dest)) {
         mkdir($dest, 0755, true);
      }

      $items = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::SELF_FIRST
      );

      foreach ($items as $item) {
         $targetPath = $dest . DIRECTORY_SEPARATOR . $items->getSubPathName();
         if ($item->isDir()) {
            if (!is_dir($targetPath) && !@mkdir($targetPath, 0755, true)) {
               throw new RuntimeException(sprintf(
                  __('Falha ao criar diretório %s. Ajuste permissões.', 'nextool'),
                  $targetPath
               ));
            }
         } else {
            if (!@copy($item->getRealPath(), $targetPath)) {
               throw new RuntimeException(sprintf(
                  __('Falha ao copiar arquivo para %s. O diretório é gravável?', 'nextool'),
                  $targetPath
               ));
            }
         }
      }
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

