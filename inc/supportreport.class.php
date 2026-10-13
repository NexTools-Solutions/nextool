<?php
declare(strict_types=1);
/**
 * Relatório técnico do ambiente para o suporte NexTool (2026-10-09).
 *
 * Só é montado e enviado quando o usuário marca "Anexar relatório técnico" no popup de suporte (caixa
 * desmarcada por padrão) ou clica em "Baixar relatório". A mesma montagem alimenta a prévia, o arquivo e o
 * envio: o que o usuário vê é exatamente o que sai.
 *
 * Minimização (LGPD): só dados técnicos do ambiente e erros ligados ao NexTool (plugin e módulos), dos
 * últimos 7 dias, lidos do FIM dos arquivos, agrupados por assinatura e com teto de tamanho. Todo texto livre
 * passa por sanitize(): e-mail, IP, tokens, querystrings e pares senha/segredo/token são mascarados; nas mensagens de
 * erro, nomes e logins dos usuários do GLPI também (maskPeople). Nunca
 * entram segredos da config (HMAC, chaves) nem conteúdo de chamado.
 *
 * Desenho: /data/relatorios/suporte-no-plugin-2026-10-09/desenho.md
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

final class PluginNextoolSupportReport {

   public const SCHEMA = 1;

   /** Janela dos erros. */
   private const ERROR_WINDOW_DAYS = 7;
   /** Quanto do fim de cada arquivo de log é lido. */
   private const TAIL_BYTES = 2097152;
   /** Grupos de erro no relatório (os mais recentes). */
   private const MAX_ERROR_GROUPS = 40;
   /** Linhas de pilha guardadas por grupo. */
   private const MAX_TRACE_LINES = 3;
   /** Tamanho máximo de uma mensagem de erro. */
   private const MAX_MESSAGE_CHARS = 600;
   /** Teto do relatório serializado (o servidor recusa acima disso). */
   public const MAX_BYTES = 262144;

   /** Extensões que o suporte costuma precisar conferir. */
   private const EXTENSIONS = [
      'curl', 'gd', 'intl', 'json', 'mbstring', 'mysqli', 'openssl', 'sodium', 'zip', 'zlib',
      'fileinfo', 'ldap', 'imap', 'xml', 'simplexml', 'bcmath', 'exif', 'opcache', 'apcu', 'redis',
   ];

   /** Logins padrão do GLPI: aparecem em caminhos e mensagens do sistema, não identificam pessoa. */
   private const DEFAULT_LOGINS = ['glpi', 'tech', 'normal', 'post-only', 'glpi-system'];
   /** Teto de nomes carregados para a máscara (instância muito grande: os mais recentes). */
   private const MAX_PEOPLE_TOKENS = 50000;

   /** @var array{logins:array<string,true>,names:array<string,true>}|null cache do request */
   private static ?array $peopleTokens = null;

   /** Tags do log do plugin que entram como erro. */
   private const PLUGIN_LOG_ERROR = '/^\[(ERROR|ERRO|WARN|WARNING|CRITICAL|FATAL)\]|Exception|Fatal error|Uncaught/i';

   /**
    * Monta o relatório.
    *
    * @param int|null $now timestamp de referência (injetável no teste)
    * @return array<string, mixed>
    */
   public static function build(?int $now = null): array {
      $now = $now ?? time();
      $report = [
         'schema'       => self::SCHEMA,
         'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
         'environment'  => self::safe([self::class, 'environment']),
         'nextool'      => self::safe([self::class, 'nextool']),
         'modules'      => self::safe([self::class, 'modules']),
         'plugins'      => self::safe([self::class, 'plugins']),
         'crons'        => self::safe([self::class, 'crons']),
         'prereq'       => self::safe([self::class, 'prereq']),
         'errors'       => self::safe(static fn(): array => self::errors($now)),
      ];
      return self::fitToLimit($report);
   }

   /**
    * Texto legível do relatório (prévia e arquivo baixado). JSON indentado: o suporte lê e a máquina também.
    */
   public static function toText(array $report): string {
      return (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
   }

   /**
    * Mascara dados pessoais e segredos em texto livre (mensagens de erro e pilhas).
    */
   public static function sanitize(string $text): string {
      $rules = [
         // Bearer antes dos pares: em "Authorization: Bearer <token>" a regra de pares pegaria só a palavra Bearer
         '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i'                                    => 'Bearer [mascarado]',
         // pares chave=valor sensíveis (antes das regras genéricas, para não deixar o valor pela metade)
         '/\b(pass(word|wd)?|senha|secret|segredo|token|api[_-]?key|apikey|authorization|auth|cookie|session(id)?|hmac|signature)\b(\s*["\']?\s*[:=]\s*["\']?)[^\s"\',;&)]+/i' => '$1$4[mascarado]',
         '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i'                             => '[e-mail]',
         '/\b(?:\d{1,3}\.){3}\d{1,3}\b/'                                          => '[ip]',
         '/\b(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}\b/i'                           => '[ip]',
         // querystring de URL: o caminho fica, os parâmetros não
         '/(https?:\/\/[^\s?#"\']+)\?[^\s"\'<>]*/i'                               => '$1?[parametros]',
         // hex/base64 longos (tokens, hashes, segredos)
         '/\b[0-9a-f]{32,}\b/i'                                                   => '[hash]',
         // sem "/" na classe: um caminho longo sem ponto não pode virar [token]; exige letra E dígito
         '/\b(?=[A-Za-z0-9+_-]*\d)(?=[A-Za-z0-9+_-]*[A-Za-z])[A-Za-z0-9+_-]{40,}={0,2}/' => '[token]',
      ];
      foreach ($rules as $pattern => $replacement) {
         $out = preg_replace($pattern, $replacement, $text);
         if (is_string($out)) {
            $text = $out;
         }
      }
      return $text;
   }

   /**
    * Mascara logins e nomes completos de usuários do GLPI em texto livre (mensagens de erro do relatório, RoPA RT-09).
    *
    * Regras (afinadas no relatório real do DEV, onde contas de serviço têm logins como "nextool" e "api"):
    * - login inteiro ou nome completo ("Nome Sobrenome"); nome ou sobrenome sozinho não conta (colide com palavra comum);
    * - palavra colada a caminho ou código ("/", "\\", "::", "->", "(") nunca é mascarada;
    * - nomes técnicos (módulos, plugins, glpi, nextool, api) ficam de fora do conjunto (peopleTokens).
    *
    * @param array{logins?:array<string,true>,names?:array<string,true>} $people
    */
   public static function maskPeople(string $text, array $people): string {
      $logins = $people['logins'] ?? [];
      $names = $people['names'] ?? [];
      if ($logins === [] && $names === []) {
         return $text;
      }
      $lower = static fn(string $v): string => function_exists('mb_strtolower') ? mb_strtolower($v) : strtolower($v);
      if ($names !== []) {
         $out = preg_replace_callback('/(?<![\p{L}\p{N}\/\\\\:>._-])([\p{L}][\p{L}\'-]+)\s+([\p{L}][\p{L}\'-]+(?:\s+[\p{L}][\p{L}\'-]+)?)(?![\p{L}\p{N}\/\\\\(:._-])/u',
            static function (array $m) use ($names, $lower): string {
               return isset($names[$lower($m[1] . ' ' . $m[2])]) ? '[usuario]' : $m[0];
            }, $text);
         $text = is_string($out) ? $out : $text;
      }
      if ($logins !== []) {
         $out = preg_replace_callback('/(?<![\p{L}\p{N}\/\\\\:>._-])[\p{L}\p{N}][\p{L}\p{N}._@-]*(?<![._-])(?![\p{L}\p{N}\/\\\\(:])/u',
            static function (array $m) use ($logins, $lower): string {
               return isset($logins[$lower($m[0])]) ? '[usuario]' : $m[0];
            }, $text);
         $text = is_string($out) ? $out : $text;
      }
      return $text;
   }

   /**
    * Logins e nomes completos ("nome sobrenome", minúsculos) dos usuários do GLPI, sem os nomes técnicos da instância
    * (módulos, plugins, logins padrão). Cache do request.
    *
    * @return array{logins:array<string,true>,names:array<string,true>}
    */
   public static function peopleTokens(): array {
      if (self::$peopleTokens !== null) {
         return self::$peopleTokens;
      }
      global $DB;
      $lower = static fn(string $v): string => function_exists('mb_strtolower') ? mb_strtolower($v) : strtolower($v);
      $len = static fn(string $v): int => function_exists('mb_strlen') ? mb_strlen($v) : strlen($v);
      $technical = array_fill_keys(array_merge(self::DEFAULT_LOGINS, ['nextool', 'api', 'admin', 'root', 'system', 'cron']), true);
      $logins = [];
      $names = [];
      try {
         foreach ($DB->request(['SELECT' => ['directory'], 'FROM' => 'glpi_plugins']) as $row) {
            $technical[$lower((string) $row['directory'])] = true;
         }
         if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
            foreach ($DB->request(['SELECT' => ['module_key'], 'FROM' => 'glpi_plugin_nextool_main_modules']) as $row) {
               $technical[$lower((string) $row['module_key'])] = true;
            }
         }
         $iterator = $DB->request([
            'SELECT' => ['name', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'ORDER'  => 'id DESC',
            'LIMIT'  => self::MAX_PEOPLE_TOKENS,
         ]);
         foreach ($iterator as $row) {
            $login = $lower(trim((string) ($row['name'] ?? '')));
            if ($len($login) >= 3 && !isset($technical[$login])) {
               $logins[$login] = true;
            }
            $first = $lower(trim((string) ($row['firstname'] ?? '')));
            $last = $lower(trim((string) ($row['realname'] ?? '')));
            if ($first !== '' && $last !== '') {
               $names[preg_replace('/\s+/u', ' ', $first . ' ' . $last)] = true;
            }
         }
      } catch (Throwable $e) {
         $logins = [];
         $names = [];
      }
      return self::$peopleTokens = ['logins' => $logins, 'names' => $names];
   }

   /**
    * Estimativa LOCAL de que o item tem licença ativa (só para a UI escolher os tipos e o texto do prazo).
    * Quem decide o nível de verdade é o servidor, com as licenças reais.
    */
   public static function isItemLicensed(string $item): bool {
      if ($item === '' || $item === 'plugin') {
         return false;
      }
      global $DB;
      $table = 'glpi_plugin_nextool_main_modules';
      if (!$DB->tableExists($table)) {
         return false;
      }
      $row = $DB->request(['SELECT' => ['billing_tier'], 'FROM' => $table, 'WHERE' => ['module_key' => $item], 'LIMIT' => 1])->current();
      $tier = strtoupper((string) ($row['billing_tier'] ?? 'FREE'));
      if ($row === null || $tier === 'FREE') {
         return false;
      }
      foreach (self::activeLicenses() as $license) {
         $mods = is_array($license['allowed_modules'] ?? null) ? $license['allowed_modules'] : [];
         if (in_array('*', $mods, true) || in_array($item, $mods, true)) {
            return true;
         }
      }
      return false;
   }

   /** Ambiente com alguma licença ativa (mostra o atalho de urgência). */
   public static function hasActiveLicense(): bool {
      return self::activeLicenses() !== [];
   }

   /**
    * Itens que o usuário pode escolher: o plugin base e os módulos instalados.
    *
    * @return array<int, array{key:string,name:string,licensed:bool}>
    */
   public static function supportItems(): array {
      global $DB;
      $items = [['key' => 'plugin', 'name' => __('Plugin NexTool', 'nextool'), 'licensed' => false]];
      $table = 'glpi_plugin_nextool_main_modules';
      if (!$DB->tableExists($table)) {
         return $items;
      }
      $rows = [];
      foreach ($DB->request(['FROM' => $table, 'WHERE' => ['is_installed' => 1]]) as $row) {
         $name = class_exists('PluginNextoolModuleCatalog')
            ? PluginNextoolModuleCatalog::localized($row['name_i18n'] ?? null, (string) $row['name'])
            : (string) $row['name'];
         $rows[] = ['key' => (string) $row['module_key'], 'name' => $name, 'licensed' => self::isItemLicensed((string) $row['module_key'])];
      }
      usort($rows, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
      return array_merge($items, $rows);
   }

   // ----------------------------------------------------------------------------------------------
   // Seções
   // ----------------------------------------------------------------------------------------------

   private static function environment(): array {
      global $DB, $CFG_GLPI;
      $dbVersion = '';
      try {
         $dbVersion = method_exists($DB, 'getVersion') ? (string) $DB->getVersion() : '';
      } catch (Throwable $e) {
         $dbVersion = '';
      }
      $extensions = [];
      foreach (self::EXTENSIONS as $ext) {
         $extensions[$ext] = extension_loaded($ext) ? (string) (phpversion($ext) ?: 'on') : false;
      }
      $lang = '';
      try {
         $lang = (string) ($_SESSION['glpilanguage'] ?? ($CFG_GLPI['language'] ?? ''));
      } catch (Throwable $e) {
         $lang = '';
      }
      return [
         'glpi_version'        => defined('GLPI_VERSION') ? GLPI_VERSION : '',
         'php_version'         => PHP_VERSION,
         'php_sapi'            => PHP_SAPI,
         'os'                  => PHP_OS_FAMILY . ' ' . php_uname('r'),
         'database'            => $dbVersion,
         'memory_limit'        => (string) ini_get('memory_limit'),
         'max_execution_time'  => (string) ini_get('max_execution_time'),
         'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
         'post_max_size'       => (string) ini_get('post_max_size'),
         'opcache_enabled'     => function_exists('opcache_get_status') && (bool) ini_get('opcache.enable'),
         'timezone'            => date_default_timezone_get(),
         'language'            => $lang,
         'extensions'          => $extensions,
      ];
   }

   private static function nextool(): array {
      global $CFG_GLPI;
      $settings = class_exists('PluginNextoolConfig') ? PluginNextoolConfig::getDistributionSettings() : [];
      $license  = class_exists('PluginNextoolLicenseConfig') ? PluginNextoolLicenseConfig::getDefaultConfig() : [];
      $link     = Config::getConfigurationValues('plugin:nextool_account_link');
      $proxy    = '';
      try {
         $proxy = (string) ($CFG_GLPI['proxy_name'] ?? '');
      } catch (Throwable $e) {
         $proxy = '';
      }
      $licenses = [];
      foreach (self::snapshot() as $lic) {
         $licenses[] = [
            'plan'       => (string) ($lic['plan'] ?? ''),
            'status'     => (string) ($lic['license_status'] ?? ''),
            'is_active'  => !empty($lic['is_active']),
            'expires_at' => $lic['expires_at'] ?? null,
         ];
      }
      return [
         'version'                => class_exists('PluginNextoolConfig') ? PluginNextoolConfig::getPluginVersion() : '',
         'environment_id'         => (string) ($settings['client_identifier'] ?? ''),
         'plan'                   => (string) ($license['plan'] ?? ''),
         'license_status'         => (string) ($license['license_status'] ?? ''),
         'last_validation_date'   => $license['last_validation_date'] ?? null,
         'last_validation_result' => $license['last_validation_result'] ?? null,
         'last_validation_message'=> self::sanitize((string) ($license['last_validation_message'] ?? '')),
         'consecutive_failures'   => (int) ($license['consecutive_failures'] ?? 0),
         'licenses'               => $licenses,
         'account_linked'         => ((string) ($link['linked'] ?? '0')) === '1',
         'proxy_configured'       => $proxy !== '',
         'modules_dir_writable'   => defined('NEXTOOL_MODULES_BASE') && is_writable(NEXTOOL_MODULES_BASE),
      ];
   }

   private static function modules(): array {
      global $DB;
      $table = 'glpi_plugin_nextool_main_modules';
      if (!$DB->tableExists($table)) {
         return [];
      }
      $out = [];
      $iterator = $DB->request([
         'SELECT' => ['module_key', 'version', 'available_version', 'is_installed', 'is_enabled', 'billing_tier', 'compat_glpi_majors', 'min_version_nextools'],
         'FROM'   => $table,
         'WHERE'  => ['OR' => ['is_installed' => 1, 'is_enabled' => 1]],
         'ORDER'  => 'module_key ASC',
      ]);
      foreach ($iterator as $row) {
         $key = (string) $row['module_key'];
         $out[] = [
            'key'               => $key,
            'version'           => (string) ($row['version'] ?? ''),
            'available_version' => (string) ($row['available_version'] ?? ''),
            'installed'         => (bool) $row['is_installed'],
            'enabled'           => (bool) $row['is_enabled'],
            'tier'              => (string) ($row['billing_tier'] ?? ''),
            'compat_glpi'       => (string) ($row['compat_glpi_majors'] ?? ''),
            'min_base'          => (string) ($row['min_version_nextools'] ?? ''),
            'on_disk'           => defined('NEXTOOL_MODULES_BASE') && is_dir(NEXTOOL_MODULES_BASE . '/' . $key),
         ];
      }
      return $out;
   }

   private static function plugins(): array {
      global $DB;
      $out = [];
      foreach ($DB->request(['SELECT' => ['directory', 'version', 'state'], 'FROM' => 'glpi_plugins', 'ORDER' => 'directory ASC']) as $row) {
         $out[] = ['directory' => (string) $row['directory'], 'version' => (string) $row['version'], 'state' => (int) $row['state']];
      }
      return $out;
   }

   private static function crons(): array {
      global $DB;
      $out = [];
      $iterator = $DB->request([
         'SELECT' => ['itemtype', 'name', 'state', 'mode', 'frequency', 'lastrun'],
         'FROM'   => 'glpi_crontasks',
         'WHERE'  => ['itemtype' => ['LIKE', 'PluginNextool%']],
         'ORDER'  => ['itemtype ASC', 'name ASC'],
      ]);
      foreach ($iterator as $row) {
         $out[] = [
            'task'      => (string) $row['itemtype'] . '::' . (string) $row['name'],
            'state'     => (int) $row['state'],
            'mode'      => (int) $row['mode'],
            'frequency' => (int) $row['frequency'],
            'lastrun'   => $row['lastrun'],
         ];
      }
      return $out;
   }

   private static function prereq(): array {
      $file = NEXTOOL_PHP_DIR . '/inc/prereqcheck.class.php';
      if (!is_file($file)) {
         return [];
      }
      require_once $file;
      $facts = PluginNextoolPrereqCheck::gatherFacts();
      // Caminhos absolutos dizem a estrutura do servidor, não o problema: fica só o nome do diretório.
      array_walk_recursive($facts, static function (&$value): void {
         if (is_string($value)) {
            $value = self::sanitize($value);
         }
      });
      return $facts;
   }

   // ----------------------------------------------------------------------------------------------
   // Erros
   // ----------------------------------------------------------------------------------------------

   /**
    * @return array{window_days:int,groups:array<int,array<string,mixed>>,files:array<int,string>}
    */
   private static function errors(int $now): array {
      $since = $now - self::ERROR_WINDOW_DAYS * 86400;
      $dir = defined('GLPI_LOG_DIR') ? rtrim((string) GLPI_LOG_DIR, '/') : '';
      $groups = [];
      $files = [];
      if ($dir !== '' && is_dir($dir)) {
         $php = $dir . '/php-errors.log';
         if (is_readable($php)) {
            $files[] = 'php-errors.log';
            foreach (self::parseMonolog(self::tail($php)) as $entry) {
               if ($entry['ts'] >= $since && stripos($entry['text'], 'nextool') !== false) {
                  self::addToGroups($groups, 'php-errors.log', $entry);
               }
            }
         }
         foreach (glob($dir . '/plugin_nextool*.log') ?: [] as $path) {
            if (!is_readable($path)) {
               continue;
            }
            $name = basename($path);
            $files[] = $name;
            foreach (self::parseToolboxLog(self::tail($path)) as $entry) {
               if ($entry['ts'] >= $since && preg_match(self::PLUGIN_LOG_ERROR, ltrim($entry['text']))) {
                  self::addToGroups($groups, $name, $entry);
               }
            }
         }
      }
      uasort($groups, static fn(array $a, array $b): int => strcmp((string) $b['last_at'], (string) $a['last_at']));
      return [
         'window_days' => self::ERROR_WINDOW_DAYS,
         'files'       => $files,
         'groups'      => array_values(array_slice($groups, 0, self::MAX_ERROR_GROUPS)),
      ];
   }

   /** Lê só o fim do arquivo, descartando a primeira linha (cortada no meio). */
   private static function tail(string $path): string {
      $size = (int) @filesize($path);
      $fh = @fopen($path, 'rb');
      if ($fh === false) {
         return '';
      }
      $offset = max(0, $size - self::TAIL_BYTES);
      fseek($fh, $offset);
      $data = (string) stream_get_contents($fh);
      fclose($fh);
      if ($offset > 0) {
         $nl = strpos($data, "\n");
         $data = $nl === false ? '' : substr($data, $nl + 1);
      }
      return $data;
   }

   /**
    * Formato do php-errors.log (Monolog do GLPI): "[AAAA-MM-DD HH:MM:SS] glpi.NIVEL: mensagem", seguido de linhas
    * indentadas da pilha.
    *
    * @return array<int, array{ts:int,level:string,text:string,trace:array<int,string>}>
    */
   public static function parseMonolog(string $data): array {
      $entries = [];
      $current = null;
      foreach (preg_split('/\r?\n/', $data) ?: [] as $line) {
         if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+\S+?\.([A-Z]+):\s*(.*)$/', $line, $m)) {
            if ($current !== null) {
               $entries[] = $current;
            }
            $current = ['ts' => (int) strtotime($m[1]), 'level' => $m[2], 'text' => $m[3], 'trace' => []];
         } elseif ($current !== null && trim($line) !== '') {
            if (stripos(trim($line), 'Backtrace') === 0) {
               continue; // cabeçalho da pilha, não informa nada
            }
            $current['trace'][] = trim($line);
            // a pilha entra na busca por "nextool" (o erro pode nascer no core e passar pelo módulo)
            $current['text'] .= "\n" . trim($line);
         }
      }
      if ($current !== null) {
         $entries[] = $current;
      }
      return $entries;
   }

   /**
    * Formato do Toolbox::logInFile: "AAAA-MM-DD HH:MM:SS [@host]" numa linha e a mensagem nas seguintes.
    *
    * @return array<int, array{ts:int,level:string,text:string,trace:array<int,string>}>
    */
   public static function parseToolboxLog(string $data): array {
      $entries = [];
      $current = null;
      foreach (preg_split('/\r?\n/', $data) ?: [] as $line) {
         // a data pode vir colada no fim da mensagem anterior (mensagem sem \n final)
         if (preg_match('/^(.*?)(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[@[^\]]*\]\s*$/', $line, $m)) {
            if ($current !== null) {
               if (trim($m[1]) !== '') {
                  $current['text'] .= ($current['text'] === '' ? '' : "\n") . $m[1];
               }
               $entries[] = $current;
            }
            $current = ['ts' => (int) strtotime($m[2]), 'level' => '', 'text' => '', 'trace' => []];
         } elseif ($current !== null && trim($line) !== '') {
            $current['text'] .= ($current['text'] === '' ? '' : "\n") . $line;
         }
      }
      if ($current !== null && trim($current['text']) !== '') {
         $entries[] = $current;
      }
      foreach ($entries as &$entry) {
         if (preg_match('/^\[([A-Z]+)\]/', ltrim($entry['text']), $m)) {
            $entry['level'] = $m[1];
         }
      }
      unset($entry);
      return $entries;
   }

   /** Agrupa por assinatura (mensagem sem números, ids e caminhos variáveis). */
   private static function addToGroups(array &$groups, string $source, array $entry): void {
      $lines = explode("\n", trim($entry['text']));
      $people = self::peopleTokens();
      $message = self::maskPeople(self::sanitize(trim((string) ($lines[0] ?? ''))), $people);
      if (function_exists('mb_substr')) {
         $message = mb_substr($message, 0, self::MAX_MESSAGE_CHARS);
      } else {
         $message = substr($message, 0, self::MAX_MESSAGE_CHARS);
      }
      $signature = $source . '|' . preg_replace('/\d+/', '#', $message);
      $at = gmdate('Y-m-d\TH:i:s\Z', $entry['ts']);
      if (!isset($groups[$signature])) {
         $trace = $entry['trace'] !== [] ? $entry['trace'] : array_slice($lines, 1);
         $groups[$signature] = [
            'source'   => $source,
            'level'    => $entry['level'],
            'message'  => $message,
            'trace'    => array_map(static fn(string $l): string => self::maskPeople(self::sanitize($l), $people), array_slice($trace, 0, self::MAX_TRACE_LINES)),
            'count'    => 0,
            'first_at' => $at,
            'last_at'  => $at,
         ];
      }
      $groups[$signature]['count']++;
      if ($at < $groups[$signature]['first_at']) {
         $groups[$signature]['first_at'] = $at;
      }
      if ($at > $groups[$signature]['last_at']) {
         $groups[$signature]['last_at'] = $at;
      }
   }

   // ----------------------------------------------------------------------------------------------
   // Apoio
   // ----------------------------------------------------------------------------------------------

   /** Uma seção que falha não derruba o relatório: vira o nome da exceção (sem mensagem, que pode ter dado). */
   private static function safe(callable $section): array {
      try {
         $out = $section();
         return is_array($out) ? $out : [];
      } catch (Throwable $e) {
         return ['error' => get_class($e)];
      }
   }

   /** Corta grupos de erro até caber no teto. */
   private static function fitToLimit(array $report): array {
      while (strlen(self::toText($report)) > self::MAX_BYTES && !empty($report['errors']['groups'])) {
         array_pop($report['errors']['groups']);
         $report['errors']['truncated'] = true;
      }
      return $report;
   }

   /** @return array<int, array<string, mixed>> */
   private static function snapshot(): array {
      if (!class_exists('PluginNextoolLicenseConfig')) {
         return [];
      }
      $config = PluginNextoolLicenseConfig::getDefaultConfig();
      $decoded = json_decode((string) ($config['licenses_snapshot'] ?? ''), true);
      return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
   }

   /** @return array<int, array<string, mixed>> */
   private static function activeLicenses(): array {
      return array_values(array_filter(self::snapshot(), static fn(array $l): bool => !empty($l['is_active'])
         && strtoupper((string) ($l['plan'] ?? '')) !== 'FREE'));
   }
}
