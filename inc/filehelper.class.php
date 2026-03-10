<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - File Helper
 * -------------------------------------------------------------------------
 * Funções utilitárias para operações de arquivo (ex.: remoção recursiva de
 * diretórios). Centraliza lógica usada em hook.php e distributionclient.
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

class PluginNextoolFileHelper {

   /**
    * Remove um diretório e todo seu conteúdo recursivamente.
    *
    * @param string $dir Caminho do diretório
    * @param bool $throwOnFailure Se true, lança RuntimeException em falha de permissão; se false, ignora silenciosamente
    * @return void
    * @throws RuntimeException quando $throwOnFailure é true e rmdir/unlink falhar
    */
   public static function deleteDirectory(string $dir, bool $throwOnFailure = false): void {
      if (!is_dir($dir)) {
         return;
      }

      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );

      foreach ($iterator as $item) {
         $path = $item->getRealPath();
         if ($item->isDir()) {
            if (!@rmdir($path)) {
               if ($throwOnFailure) {
                  throw new RuntimeException(sprintf(
                     __('Falha ao remover diretório %s. Verifique permissões.', 'nextool'),
                     $path
                  ));
               }
            }
         } else {
            if (!@unlink($path)) {
               if ($throwOnFailure) {
                  throw new RuntimeException(sprintf(
                     __('Falha ao remover arquivo %s. Verifique permissões.', 'nextool'),
                     $path
                  ));
               }
            }
         }
      }

      if (!@rmdir($dir)) {
         if ($throwOnFailure) {
            throw new RuntimeException(sprintf(
               __('Falha ao limpar diretório %s. Verifique permissões.', 'nextool'),
               $dir
            ));
         }
      }
   }

   /**
    * Copia um diretório recursivamente.
    *
    * @param string $source Diretório de origem
    * @param string $dest Diretório de destino
    * @return void
    * @throws RuntimeException em caso de falha
    */
   public static function recursiveCopy(string $source, string $dest): void {
      if (!is_dir($source)) {
         throw new RuntimeException(sprintf(__('Diretório de origem inválido: %s', 'nextool'), $source));
      }
      if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
         throw new RuntimeException(sprintf(__('Não foi possível criar diretório de destino: %s', 'nextool'), $dest));
      }

      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::SELF_FIRST
      );

      foreach ($iterator as $item) {
         $targetPath = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
         if ($item->isDir()) {
            if (!is_dir($targetPath) && !@mkdir($targetPath, 0755, true)) {
               throw new RuntimeException(sprintf(__('Falha ao criar diretório %s.', 'nextool'), $targetPath));
            }
         } else {
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
               throw new RuntimeException(sprintf(__('Falha ao preparar diretório %s.', 'nextool'), $targetDir));
            }
            $srcPath = $item->getRealPath();
            if (!@copy($srcPath, $targetPath)) {
               throw new RuntimeException(sprintf(__('Falha ao copiar arquivo para %s.', 'nextool'), $targetPath));
            }
            // Preserve original file permissions
            $perms = @fileperms($srcPath);
            if ($perms !== false) {
               @chmod($targetPath, $perms & 0x1FF); // lower 9 bits (rwxrwxrwx)
            }
         }
      }
   }

   /**
    * Executa uma requisição HTTP via cURL.
    *
    * @param string $url URL de destino
    * @param array $options {method?: string, timeout?: int, body?: string, headers?: string[]}
    * @return array{body: string, http_code: int}
    * @throws RuntimeException em caso de falha de comunicação
    */
   public static function performHttpRequest(string $url, array $options = []): array {
      $method = strtoupper((string)($options['method'] ?? 'GET'));
      $ch = curl_init($url);

      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, (int)($options['timeout'] ?? 30));
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

      if ($method === 'POST') {
         curl_setopt($ch, CURLOPT_POST, true);
         curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body'] ?? '');
      }

      if (!empty($options['headers']) && is_array($options['headers'])) {
         curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
      }

      $body = curl_exec($ch);
      $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err = curl_error($ch);
      curl_close($ch);

      if ($body === false) {
         throw new RuntimeException(sprintf(__('Erro ao comunicar com ContainerAPI: %s', 'nextool'), $err));
      }

      return [
         'body' => (string)$body,
         'http_code' => $httpCode,
      ];
   }
}
