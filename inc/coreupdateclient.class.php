<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Core Update Client
 * -------------------------------------------------------------------------
 * Cliente para o self-updater do core nextool (ContainerAPI plugin endpoints).
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once GLPI_ROOT . '/plugins/nextool/inc/config.class.php';

class PluginNextoolCoreUpdateClient {

   private string $baseUrl;

   public function __construct(
      string $baseUrl,
      private string $clientIdentifier = '',
      private string $clientSecret = ''
   ) {
      $this->baseUrl = rtrim(trim($baseUrl), '/');
   }

   public static function fromDistributionSettings(): self {
      $settings = PluginNextoolConfig::getDistributionSettings();
      return new self(
         (string)($settings['base_url'] ?? ''),
         (string)($settings['client_identifier'] ?? ''),
         (string)($settings['client_secret'] ?? '')
      );
   }

   public function healthCheck(int $timeout = 10): array {
      if ($this->baseUrl === '') {
         return [
            'success' => false,
            'http_code' => 0,
            'latency_ms' => null,
            'message' => __('URL do ContainerAPI não configurada.', 'nextool'),
         ];
      }

      $started = microtime(true);
      $response = $this->performRequest($this->baseUrl . '/health', [
         'method' => 'GET',
         'timeout' => max(3, $timeout),
      ]);
      $latency = (int)round((microtime(true) - $started) * 1000);

      if ($response['http_code'] >= 400) {
         return [
            'success' => false,
            'http_code' => $response['http_code'],
            'latency_ms' => $latency,
            'message' => sprintf(__('ContainerAPI respondeu com HTTP %d no health-check.', 'nextool'), $response['http_code']),
         ];
      }

      return [
         'success' => true,
         'http_code' => $response['http_code'],
         'latency_ms' => $latency,
         'message' => __('Conectividade com ContainerAPI OK.', 'nextool'),
      ];
   }

   /**
    * @throws RuntimeException
    */
   public function requestManifest(string $channel = 'stable', string $origin = 'core_update_check'): array {
      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdateClient] requestManifest() channel=%s origin=%s base_url=%s\n",
         $channel,
         $origin,
         $this->baseUrl
      ));
      $this->assertSignedRequestReady();

      $payload = [
         'channel' => $channel,
         'client_info' => [
            'plugin_version' => PluginNextoolConfig::getPluginVersion(),
            'glpi_version' => defined('GLPI_VERSION') ? GLPI_VERSION : null,
            'php_version' => PHP_VERSION,
            'origin' => $origin,
         ],
      ];

      if ($this->clientIdentifier !== '') {
         $payload['client_info']['environment_id'] = $this->clientIdentifier;
      }

      $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (!is_string($body)) {
         throw new RuntimeException(__('Falha ao serializar payload de manifesto do core.', 'nextool'));
      }

      $timestamp = (string)time();
      $signature = hash_hmac('sha256', $body . '|' . $timestamp, $this->clientSecret);

      $response = $this->performRequest($this->baseUrl . '/api/distribution/plugin/install-request', [
         'method' => 'POST',
         'timeout' => 60,
         'headers' => [
            'Content-Type: application/json',
            'X-Client-Identifier: ' . $this->clientIdentifier,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature,
         ],
         'body' => $body,
      ]);

      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdateClient] requestManifest() response http_code=%d body_len=%d\n",
         $response['http_code'],
         strlen($response['body'] ?? '')
      ));

      $data = json_decode($response['body'], true);
      if (!is_array($data)) {
         throw new RuntimeException(__('Resposta inválida do ContainerAPI ao solicitar manifesto do core.', 'nextool'));
      }

      if ($response['http_code'] >= 300) {
         $message = $data['message'] ?? $data['error'] ?? __('Erro desconhecido', 'nextool');
         $exception = new RuntimeException((string)$message, (int)$response['http_code']);
         throw $exception;
      }

      $requiredFields = [
         'version',
         'download_url',
         'hash_sha256',
         'signature',
         'signature_key_id',
         'min_glpi',
         'max_glpi',
         'release_notes_url',
         'token_expires_at',
      ];
      foreach ($requiredFields as $field) {
         if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            throw new RuntimeException(sprintf(__('Manifesto do core inválido: campo obrigatório ausente (%s).', 'nextool'), $field));
         }
      }

      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdateClient] requestManifest() sucesso: version=%s\n",
         $data['version'] ?? 'n/a'
      ));

      return $data;
   }

   /**
    * @throws RuntimeException
    */
   public function downloadPackage(string $downloadUrl, string $targetPath): void {
      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdateClient] downloadPackage() target=%s url_len=%d\n",
         $targetPath,
         strlen($downloadUrl)
      ));
      if ($downloadUrl === '') {
         throw new RuntimeException(__('URL de download do core inválida.', 'nextool'));
      }

      $targetDir = dirname($targetPath);
      if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
         throw new RuntimeException(sprintf(__('Não foi possível criar diretório temporário: %s', 'nextool'), $targetDir));
      }

      $fp = @fopen($targetPath, 'wb');
      if ($fp === false) {
         throw new RuntimeException(sprintf(__('Não foi possível criar arquivo temporário: %s', 'nextool'), $targetPath));
      }

      $ch = curl_init($downloadUrl);
      curl_setopt($ch, CURLOPT_FILE, $fp);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 180);
      if ($this->clientIdentifier !== '') {
         curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Client-Identifier: ' . $this->clientIdentifier,
         ]);
      }

      $result = curl_exec($ch);
      $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $error = curl_error($ch);
      curl_close($ch);
      fclose($fp);

      if (!$result || $httpCode >= 300) {
         @unlink($targetPath);
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[DEBUG] [CoreUpdateClient] downloadPackage() falhou: http=%d error=%s\n",
            $httpCode,
            $error
         ));
         throw new RuntimeException(sprintf(__('Falha ao baixar pacote do core (HTTP %d): %s', 'nextool'), $httpCode, $error));
      }
      Toolbox::logInFile('plugin_nextool', sprintf(
         "[DEBUG] [CoreUpdateClient] downloadPackage() sucesso: http=%d size=%d\n",
         $httpCode,
         file_exists($targetPath) ? filesize($targetPath) : 0
      ));
   }

   /**
    * @throws RuntimeException
    */
   private function performRequest(string $url, array $options = []): array {
      require_once GLPI_ROOT . '/plugins/nextool/inc/filehelper.class.php';
      return PluginNextoolFileHelper::performHttpRequest($url, $options);
   }

   private function assertSignedRequestReady(): void {
      if ($this->baseUrl === '') {
         throw new RuntimeException(__('URL do ContainerAPI não configurada.', 'nextool'));
      }
      if ($this->clientIdentifier === '' || $this->clientSecret === '') {
         throw new RuntimeException(__('Integração HMAC do ContainerAPI não configurada para atualização do core.', 'nextool'));
      }
   }

}
