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
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

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

   /**
    * Detecta o formato de um arquivo compactado pelos magic bytes.
    *
    * @param string $filePath Caminho do arquivo
    * @return string 'tar.gz' | 'zip' | 'unknown'
    */
   public static function detectArchiveFormat(string $filePath): string {
      $magic = @file_get_contents($filePath, false, null, 0, 2);
      if ($magic === false || strlen($magic) < 2) {
         return 'unknown';
      }
      if ($magic === "\x1f\x8b") {
         return 'tar.gz';
      }
      if ($magic === "PK") {
         return 'zip';
      }
      return 'unknown';
   }

   /**
    * Valida que nenhuma entrada de um archive (PharData ou ZipArchive) escapa
    * do diretório de extração (path traversal). Lança exceção se encontrar
    * caminho absoluto, sequência ".." ou byte nulo. Port do hardening do GLPI 11
    * (LO-04), com a chamada correta de getSubPathName no ITERADOR.
    *
    * @param PharData|ZipArchive $archive
    * @param string $contextLabel Rótulo amigável usado na mensagem de erro
    * @param string|null $archivePath Caminho do arquivo no disco (usado p/ PharData via tar)
    * @throws RuntimeException quando alguma entrada é insegura
    */
   public static function assertSecureArchiveEntries($archive, string $contextLabel = 'pacote', ?string $archivePath = null): void {
      $check = static function (string $entryName) use ($contextLabel): void {
         $normalized = str_replace('\\', '/', $entryName);
         if ($normalized === '' || str_contains($normalized, "\0")) {
            throw new RuntimeException(sprintf(
               __('Entrada inválida no %s (nome vazio ou byte nulo).', 'nextool'),
               $contextLabel
            ));
         }
         if (str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
            throw new RuntimeException(sprintf(
               __('Entrada insegura no %s: %s', 'nextool'),
               $contextLabel,
               $entryName
            ));
         }
      };

      if ($archive instanceof PharData) {
         // O PharData::extractTo já contém path traversal e caminhos absolutos
         // nativamente -- esta checagem é defesa em profundidade. NÃO usar
         // RecursiveIteratorIterator: ele lança "Cannot access phar file entry"
         // ao acessar entradas com path > 100 chars (ex.: vendor/ de
         // dependências Composer). Listamos via `tar -tzf`, que lê o formato
         // PAX/paths longos corretamente. Se exec/tar não estiver disponível,
         // confiamos na proteção nativa do extractTo (fallback seguro).
         $path  = $archivePath ?? self::resolvePharFilePath($archive);
         $names = ($path !== null) ? self::listTarEntries($path) : null;
         if ($names !== null) {
            foreach ($names as $name) {
               $check($name);
            }
         }
         return;
      }

      if ($archive instanceof ZipArchive) {
         for ($i = 0; $i < $archive->numFiles; $i++) {
            $check((string)$archive->getNameIndex($i));
         }
         return;
      }

      throw new InvalidArgumentException('assertSecureArchiveEntries: tipo de archive não suportado.');
   }

   /**
    * Lista os nomes das entradas de um .tar.gz via `tar -tzf`. Robusto para
    * paths > 100 chars (formato PAX), ao contrário do PharData iterado.
    *
    * @return string[]|null Lista de nomes, ou null se exec/tar indisponível ou falhar
    */
   private static function listTarEntries(string $tarPath): ?array {
      if (!is_file($tarPath) || !function_exists('exec')) {
         return null;
      }
      $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
      if (in_array('exec', $disabled, true)) {
         return null;
      }
      $out = [];
      $rc  = 1;
      exec('tar -tzf ' . escapeshellarg($tarPath) . ' 2>/dev/null', $out, $rc);
      if ($rc !== 0) {
         return null;
      }
      return $out;
   }

   /**
    * Resolve o caminho do arquivo no disco a partir de um PharData.
    * getPathname() retorna "phar://<arquivo>/<subpath>"; isolamos o arquivo.
    *
    * @return string|null Caminho no disco, ou null se não for resolúvel
    */
   private static function resolvePharFilePath(PharData $archive): ?string {
      $p = $archive->getPathname();
      if (strpos($p, 'phar://') === 0) {
         $p = substr($p, strlen('phar://'));
      }
      foreach (['.tar.gz', '.tgz', '.tar'] as $ext) {
         $pos = strpos($p, $ext);
         if ($pos !== false) {
            $candidate = substr($p, 0, $pos + strlen($ext));
            return is_file($candidate) ? $candidate : null;
         }
      }
      return is_file($p) ? $p : null;
   }
}
