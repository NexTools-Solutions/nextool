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
      // Link simbólico nunca é seguido: remove-se o LINK, não o destino. Até a 6.59.0 o caminho vinha
      // de getRealPath(), que resolve o link: um link dentro da pasta apagava o arquivo de FORA dela
      // (e um link quebrado abortava a operação com TypeError).
      if (is_link($dir)) {
         if (!@unlink($dir) && $throwOnFailure) {
            throw new RuntimeException(sprintf(
               __('Falha ao remover arquivo %s. Verifique permissões.', 'nextool'),
               $dir
            ));
         }
         return;
      }
      if (!is_dir($dir)) {
         return;
      }

      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );

      foreach ($iterator as $item) {
         $path = $item->getPathname();
         if ($item->isLink()) {
            if (!@unlink($path) && $throwOnFailure) {
               throw new RuntimeException(sprintf(
                  __('Falha ao remover arquivo %s. Verifique permissões.', 'nextool'),
                  $path
               ));
            }
            continue;
         }
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
    * Com `resign` (callable que recebe os cabeçalhos e devolve os reassinados), um 401 de horário
    * fora da janela ensina o PluginNextoolClock e a chamada é repetida UMA vez com timestamp novo.
    *
    * @param string $url URL de destino
    * @param array $options {method?: string, timeout?: int, body?: string, headers?: string[], resign?: callable}
    * @return array{body: string, http_code: int}
    * @throws RuntimeException em caso de falha de comunicação
    */
   public static function performHttpRequest(string $url, array $options = []): array {
      $response = self::performHttpRequestOnce($url, $options);
      $resign = $options['resign'] ?? null;
      if ($response['http_code'] === 401 && is_callable($resign) && !empty($options['headers'])) {
         require_once NEXTOOL_PHP_DIR . '/inc/clock.class.php';
         $data = json_decode($response['body'], true);
         if (PluginNextoolClock::syncAfterRejection(401, is_array($data) ? $data : null, $url, $response['date'])) {
            $options['headers'] = $resign($options['headers']);
            $response = self::performHttpRequestOnce($url, $options);
         }
      }
      return ['body' => $response['body'], 'http_code' => $response['http_code']];
   }

   /**
    * @return array{body: string, http_code: int, date: ?string}
    */
   private static function performHttpRequestOnce(string $url, array $options): array {
      try {
         return self::httpSend($url, $options + ['follow_redirects' => true]);
      } catch (RuntimeException $e) {
         throw new RuntimeException(sprintf(__('Erro ao comunicar com ContainerAPI: %s', 'nextool'), $e->getMessage()), (int) $e->getCode(), $e);
      }
   }

   /**
    * Requisição HTTP pelo cliente do core (`PluginNextoolCompat::httpClient`), com o PROXY de
    * Configurar > Geral nos três majors (Guzzle do GLPI 10/11, adaptador sobre o HttpClient do 12).
    *
    * Ponto único de saída HTTP do licenciamento e da distribuição. Até a 6.59.0 validate, bootstrap,
    * enroll, migrate, downloads e a consulta de horário montavam cURL próprio em 9 lugares e nenhum
    * aplicava o proxy: um GLPI que só sai por proxy nunca licenciava nem baixava módulo.
    *
    * 3xx (quando não segue), 4xx e 5xx são RESPOSTA, não exceção. Sem resposta (rede, DNS, TLS, proxy
    * fora) lança RuntimeException cujo código é o errno do cURL quando conhecido (0 se não); a mensagem
    * nunca leva a URL (links de download são assinados).
    *
    * @param array{method?: string, timeout?: int, body?: string, headers?: string[], follow_redirects?: bool} $options
    * @return array{body: string, http_code: int, date: ?string}
    * @throws RuntimeException
    */
   public static function httpSend(string $url, array $options = []): array {
      $method = strtoupper((string) ($options['method'] ?? 'GET'));
      try {
         $client = self::httpClientFor($url, $options);
         $request = ['headers' => self::headerLinesToMap($options['headers'] ?? [])];
         if ($method !== 'GET' && $method !== 'HEAD') {
            $request['body'] = (string) ($options['body'] ?? '');
         }
         $response = $client->request($method, $url, $request);
         $date = trim((string) $response->getHeaderLine('Date'));
         return [
            'body'      => $method === 'HEAD' ? '' : (string) $response->getBody(),
            'http_code' => (int) $response->getStatusCode(),
            'date'      => $date !== '' ? $date : null,
         ];
      } catch (Throwable $e) {
         throw self::transportException($e, $url);
      }
   }

   /**
    * Baixa uma URL para um arquivo, pelo mesmo cliente de `httpSend()` (com o proxy do GLPI).
    * Resposta fora de 2xx NÃO deixa arquivo: devolve o código e quem chama decide a mensagem.
    *
    * @param array{timeout?: int, headers?: string[]} $options
    * @return array{http_code: int, bytes: int}
    * @throws RuntimeException sem resposta ou falha ao gravar o arquivo
    */
   public static function downloadToFile(string $url, string $targetPath, array $options = []): array {
      $bytes = 0;
      try {
         $client = self::httpClientFor($url, $options + ['follow_redirects' => true]);
         $request = ['headers' => self::headerLinesToMap($options['headers'] ?? [])];
         $isGuzzle = $client instanceof \GuzzleHttp\ClientInterface;
         if ($isGuzzle) {
            // Guzzle grava direto no arquivo (não carrega o pacote em memória).
            $request['sink'] = $targetPath;
         }
         $response = $client->request('GET', $url, $request);
         $code = (int) $response->getStatusCode();
         if ($code < 200 || $code >= 300) {
            @unlink($targetPath);
            return ['http_code' => $code, 'bytes' => 0];
         }
         if (!$isGuzzle) {
            // Adaptador do GLPI 12: sem `sink`; lê o corpo em blocos.
            $fp = @fopen($targetPath, 'wb');
            if ($fp === false) {
               throw new \UnexpectedValueException(sprintf(__('Não foi possível criar arquivo temporário: %s', 'nextool'), $targetPath));
            }
            try {
               $body = $response->getBody();
               while (!$body->eof()) {
                  $chunk = $body->read(65536);
                  if ($chunk === '') {
                     continue;
                  }
                  if (fwrite($fp, $chunk) === false) {
                     throw new \UnexpectedValueException(sprintf(__('Não foi possível criar arquivo temporário: %s', 'nextool'), $targetPath));
                  }
               }
            } finally {
               fclose($fp);
            }
         }
         clearstatcache(true, $targetPath);
         $bytes = is_file($targetPath) ? (int) filesize($targetPath) : 0;
         return ['http_code' => $code, 'bytes' => $bytes];
      } catch (\UnexpectedValueException $e) {
         // falha LOCAL ao gravar o arquivo (não é falha de rede): segue como está
         @unlink($targetPath);
         throw $e;
      } catch (Throwable $e) {
         @unlink($targetPath);
         throw self::transportException($e, $url);
      }
   }

   /** Cliente do core já com timeout, redirecionamento e (no GLPI 12) a liberação de `http://`. */
   private static function httpClientFor(string $url, array $options) {
      require_once NEXTOOL_PHP_DIR . '/inc/compat.class.php';
      $timeout = max(1, (int) ($options['timeout'] ?? 30));
      $clientOptions = [
         'timeout'         => $timeout,
         // o padrão do core (5 s) é curto para link lento; nunca maior que o tempo total
         'connect_timeout' => min($timeout, 15),
         'http_errors'     => false,
         'allow_redirects' => !empty($options['follow_redirects']),
      ];
      // GLPI 12: o adaptador só aceita https por padrão; ContainerAPI de laboratório em http segue aceito.
      if (stripos($url, 'http://') === 0 && defined('PluginNextoolCompat::HTTP_ALLOW_HTTP_OPTION')) {
         $clientOptions[PluginNextoolCompat::HTTP_ALLOW_HTTP_OPTION] = true;
      }
      return PluginNextoolCompat::httpClient($clientOptions);
   }

   /**
    * Converte cabeçalhos no formato do cURL (`['Nome: valor', ...]`) para o mapa que o cliente usa.
    *
    * @param string[] $lines
    * @return array<string,string>
    */
   private static function headerLinesToMap(array $lines): array {
      $map = [];
      foreach ($lines as $name => $line) {
         if (is_string($name) && $name !== '') {
            $map[$name] = (string) $line;
            continue;
         }
         $pos = strpos((string) $line, ':');
         if ($pos === false || $pos === 0) {
            continue;
         }
         $map[trim(substr((string) $line, 0, $pos))] = trim(substr((string) $line, $pos + 1));
      }
      return $map;
   }

   /** Normaliza a falha de transporte: código = errno do cURL (se houver), mensagem sem a URL. */
   private static function transportException(Throwable $e, string $url): RuntimeException {
      $errno = 0;
      $message = $e->getMessage();
      if (method_exists($e, 'getHandlerContext')) {
         $context = (array) $e->getHandlerContext();
         $errno = (int) ($context['errno'] ?? 0);
         if (!empty($context['error']) && is_string($context['error'])) {
            $message = $context['error'];
         }
      }
      $message = trim(str_replace($url, '', $message));
      if ($message === '') {
         $message = get_class($e);
      }
      return new RuntimeException($message, $errno, $e);
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
    * Valida que nenhuma entrada de archive tente path traversal (../) ou
    * caminho absoluto (/). Lança RuntimeException no primeiro problema.
    *
    * Defesa em profundidade: o extractTo (PharData e ZipArchive) no destino é
    * a barreira primária. Para PharData a listagem é feita via `tar -tzf`
    * (robusto a paths > 100 chars); para ZipArchive, via numFiles+getNameIndex.
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
         // O PharData::extractTo (PHP 8.x) já contém path traversal e caminhos
         // absolutos nativamente -- esta checagem é defesa em profundidade.
         // NÃO usar RecursiveIteratorIterator: ele lança "Cannot access phar
         // file entry" ao acessar entradas com path > 100 chars (ex.: vendor/
         // de dependências Composer). Listamos via `tar -tzf`, que lê o formato
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
