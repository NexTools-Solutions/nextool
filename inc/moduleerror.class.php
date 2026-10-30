<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Falhas técnicas dos módulos (log_type = module_error)
 * -------------------------------------------------------------------------
 * Quem grava é o SERVIDOR: os roteadores da base (rota não resolvida, exceção e
 * erro fatal no arquivo do módulo) e o próprio módulo quando quiser. O navegador
 * não relata nada: ele só reage ao cabeçalho X-NexTool-Error que o roteador manda
 * (front/nextool-session.js.php). Assim não há endpoint de relato para abusar, e
 * resposta 4xx de regra de negócio (409, 422, 403...) nunca vira alarme.
 *
 * Gravação mínima (LGPD): módulo validado contra a lista instalada, tipo, código
 * HTTP, motivo fixo, arquivo relativo e linha, mensagem sem segredo nem dado
 * pessoal (200 caracteres), sem IP. Retenção de 30 dias (PluginNextoolLogMaintenance).
 * Limites: 1 linha por módulo+tipo+código+motivo por minuto (as repetições viram
 * contador) e no máximo 200 linhas por hora no ambiente.
 *
 * Nunca lança: falha de diagnóstico não pode virar outra falha.
 * -------------------------------------------------------------------------
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolModuleError {

   public const LOG_TYPE = 'module_error';

   public const KIND_ROUTER    = 'router';
   public const KIND_EXCEPTION = 'exception';
   public const KIND_FATAL     = 'fatal';
   public const KIND_MODULE    = 'module';

   public const HEADER = 'X-NexTool-Error';

   private const DEDUP_SECONDS   = 60;
   private const HOURLY_CAP      = 200;
   /** Cota própria das rejeições anônimas do roteador: um robô não pode esgotar a dos erros reais. */
   private const ANON_HOURLY_CAP = 20;
   public const ORIGIN_ANONYMOUS = 'anonimo';
   private const MESSAGE_MAX     = 200;
   private const UNKNOWN_MODULE  = 'desconhecido';

   /** Mensagem fixa (msgid) por tipo: a grade de logs traduz para o idioma de quem lê. */
   private static function messageFor(string $kind): string {
      return match ($kind) {
         self::KIND_ROUTER    => 'Requisição do módulo recusada pelo roteador do NexTool',
         self::KIND_EXCEPTION => 'Exceção não tratada no arquivo do módulo',
         self::KIND_FATAL     => 'Erro fatal no arquivo do módulo',
         default              => 'Erro registrado pelo módulo',
      };
   }

   /** Nunca é chamado: só leva as mensagens fixas ao POT da base. */
   private static function messagesForExtraction(): void {
      __('Requisição do módulo recusada pelo roteador do NexTool', 'nextool');
      __('Exceção não tratada no arquivo do módulo', 'nextool');
      __('Erro fatal no arquivo do módulo', 'nextool');
      __('Erro registrado pelo módulo', 'nextool');
      __('Falhas técnicas suprimidas (limite por hora)', 'nextool');
   }

   public static function newRequestId(): string {
      try {
         return bin2hex(random_bytes(6));
      } catch (\Throwable $e) {
         return substr(md5(uniqid('', true)), 0, 12);
      }
   }

   /**
    * Cabeçalho que o JS global da base observa. Só sai antes do envio dos headers.
    */
   public static function sendHeader(string $kind, string $moduleKey, string $requestId = ''): void {
      if (headers_sent()) {
         return;
      }
      $kind = in_array($kind, [self::KIND_ROUTER, self::KIND_EXCEPTION, self::KIND_FATAL], true) ? $kind : self::KIND_ROUTER;
      header(self::HEADER . ': ' . $kind);
      $module = self::cleanKey($moduleKey);
      if ($module !== '') {
         header('X-NexTool-Module: ' . $module);
      }
      if ($requestId !== '' && preg_match('/^[a-f0-9]{8,32}$/', $requestId) === 1) {
         header('X-NexTool-Request: ' . $requestId);
      }
   }

   /**
    * Grava uma falha técnica. Campos aceitos em $extra (o resto é descartado):
    *   file (nome do arquivo do módulo), class (classe da exceção), where (arquivo:linha),
    *   message (texto livre, sai sanitizado), request_id.
    *
    * $anonymous = rejeição do roteador sem sessão válida (robô, URL inventada). Só o motivo
    * "rota_ausente" (defeito de servidor, como o PATH_INFO do nginx) é gravado, numa cota
    * própria, e as repetições não geram escrita.
    */
   public static function record(string $moduleKey, string $kind, int $httpCode, string $reason, array $extra = [], bool $anonymous = false): void {
      global $DB;
      try {
         if (!isset($DB) || !$DB->tableExists('glpi_plugin_nextool_main_logs')) {
            return;
         }
         $kind   = in_array($kind, [self::KIND_ROUTER, self::KIND_EXCEPTION, self::KIND_FATAL, self::KIND_MODULE], true)
            ? $kind : self::KIND_MODULE;
         $reason = preg_match('/^[a-z0-9_.-]{1,64}$/', $reason) === 1 ? $reason : 'outro';
         $anonymous = $anonymous && $kind === self::KIND_ROUTER;
         if ($anonymous && $reason !== 'rota_ausente') {
            return;
         }
         $module = self::knownModule($moduleKey);
         $httpCode = ($httpCode >= 100 && $httpCode <= 599) ? $httpCode : 0;

         $details = ['tipo' => $kind, 'motivo' => $reason, 'vezes' => 1, 'primeira' => date('Y-m-d H:i:s')];
         if (isset($extra['file']) && is_string($extra['file'])) {
            $file = basename($extra['file']);
            if (preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $file) === 1) {
               $details['arquivo'] = $file;
            }
         }
         if (isset($extra['class']) && is_string($extra['class'])
             && preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]{0,150}$/', $extra['class']) === 1) {
            $details['classe'] = ltrim($extra['class'], '\\');
         }
         if (isset($extra['where']) && is_string($extra['where'])) {
            $details['onde'] = self::relativePath($extra['where']);
         }
         if (isset($extra['message']) && is_string($extra['message']) && $extra['message'] !== '') {
            $details['mensagem'] = self::sanitize($extra['message']);
         }
         if (isset($extra['request_id']) && is_string($extra['request_id'])
             && preg_match('/^[a-f0-9]{8,32}$/', $extra['request_id']) === 1) {
            $details['request_id'] = $extra['request_id'];
         }

         // Repetição dentro da janela: só soma no contador da linha existente.
         $since = date('Y-m-d H:i:s', time() - self::DEDUP_SECONDS);
         $same = $DB->request([
            'SELECT' => ['id', 'details'],
            'FROM'   => 'glpi_plugin_nextool_main_logs',
            'WHERE'  => [
               'log_type'   => self::LOG_TYPE,
               'module_key' => $module,
               'http_code'  => $httpCode,
               'action'     => $kind . ':' . $reason,
               'date'       => ['>=', $since],
            ],
            'ORDER'  => 'id DESC',
            'LIMIT'  => 1,
         ]);
         if (count($same) > 0) {
            if (!$anonymous) {
               $row = $same->current();
               self::bumpCounter((int) $row['id'], (string) ($row['details'] ?? ''));
            }
            return;
         }

         $hourAgo = date('Y-m-d H:i:s', time() - 3600);
         // Marca explícita em `origin`: no G11 a rejeição de quem está logado também sai com
         // users_id=0 (a sessão só é restaurada depois), então users_id não separa robô de usuário.
         $anonWhere = ['log_type' => self::LOG_TYPE, 'origin' => self::ORIGIN_ANONYMOUS, 'date' => ['>=', $hourAgo]];
         if ($anonymous) {
            $anonCount = $DB->request(['COUNT' => 'cpt', 'FROM' => 'glpi_plugin_nextool_main_logs', 'WHERE' => $anonWhere])->current();
            if ((int) ($anonCount['cpt'] ?? 0) >= self::ANON_HOURLY_CAP) {
               return;
            }
         }

         // Teto do ambiente (fora as anônimas, que têm cota própria): acima dele, uma linha só
         // de "suprimidos" por hora.
         $count = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_nextool_main_logs',
            'WHERE' => ['log_type' => self::LOG_TYPE, 'date' => ['>=', $hourAgo], 'NOT' => ['origin' => self::ORIGIN_ANONYMOUS]],
         ])->current();
         if (!$anonymous && (int) ($count['cpt'] ?? 0) >= self::HOURLY_CAP) {
            $sup = $DB->request([
               'SELECT' => ['id', 'details'],
               'FROM'   => 'glpi_plugin_nextool_main_logs',
               'WHERE'  => ['log_type' => self::LOG_TYPE, 'action' => 'suprimidos', 'date' => ['>=', $hourAgo]],
               'LIMIT'  => 1,
            ]);
            if (count($sup) > 0) {
               $row = $sup->current();
               self::bumpCounter((int) $row['id'], (string) ($row['details'] ?? ''));
               return;
            }
            $module = self::UNKNOWN_MODULE;
            $kind = self::KIND_MODULE;
            $reason = 'suprimidos';
            $details = ['tipo' => 'suprimidos', 'vezes' => 1];
         }

         require_once __DIR__ . '/eventlog.class.php';
         PluginNextoolEventLog::logModuleError([
            'module_key' => $module,
            'action'     => $reason === 'suprimidos' ? 'suprimidos' : $kind . ':' . $reason,
            'http_code'  => $httpCode,
            'origin'     => $anonymous ? self::ORIGIN_ANONYMOUS : null,
            'message'    => $reason === 'suprimidos' ? 'Falhas técnicas suprimidas (limite por hora)' : self::messageFor($kind),
            'details'    => $details,
         ]);
      } catch (\Throwable $e) {
         // fail-silent: o diagnóstico nunca substitui a falha original por outra
      }
   }

   /**
    * Exceção capturada pelo roteador: grava o mínimo (nunca o trace; mensagem de exceção de
    * banco fica de fora, porque costuma trazer o SQL com os valores).
    */
   public static function recordThrowable(string $moduleKey, string $filename, \Throwable $e, string $requestId, bool $withMessage = true): void {
      $isDb = $e instanceof \PDOException || $e instanceof \mysqli_sql_exception
         || preg_match('/DB|Database|Statement|Sql/i', get_class($e)) === 1
         || self::looksLikeSql($e->getMessage());
      self::record($moduleKey, self::KIND_EXCEPTION, 500, 'excecao', [
         'file'       => $filename,
         'class'      => get_class($e),
         'where'      => $e->getFile() . ':' . $e->getLine(),
         'message'    => ($withMessage && !$isDb) ? $e->getMessage() : '',
         'request_id' => $requestId,
      ]);
   }

   /**
    * Erro fatal visto no shutdown (error_get_last). Só conta se o arquivo do erro for do
    * próprio módulo: fatal do core ou do kernel depois do include não é falha do módulo.
    *
    * @return bool true se gravou
    */
   public static function recordFatal(string $moduleKey, string $filename, $err, string $requestId): bool {
      if (!is_array($err) || !in_array($err['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
         return false;
      }
      $file = (string) ($err['file'] ?? '');
      $key  = self::cleanKey($moduleKey);
      if ($key === '' || !str_contains($file, '/modules/' . $key . '/')) {
         return false;
      }
      $msg = (string) ($err['message'] ?? '');
      self::record($moduleKey, self::KIND_FATAL, 500, 'fatal', [
         'file'       => $filename,
         'where'      => $file . ':' . (int) ($err['line'] ?? 0),
         'message'    => self::looksLikeSql($msg) ? '' : $msg,
         'request_id' => $requestId,
      ]);
      return true;
   }

   /**
    * Exceção capturada FORA dos roteadores, onde a base engole a falha para o GLPI seguir (hooks do
    * HookDispatcher, rotinas do JobDispatcher): grava para o painel do módulo, sem mudar o fluxo.
    * Sem `$moduleKey`, o módulo dono sai do trace (primeiro arquivo em /modules/<chave>/); falha
    * que não passou por código de módulo não é gravada. Exceções que são resposta (403, redirect,
    * sessão expirada) não contam. Nunca lança.
    *
    * @param string $reason motivo fixo (`hook`, `rotina`)
    */
   public static function recordCaught(\Throwable $e, string $reason, string $moduleKey = ''): void {
      try {
         if (self::mustRethrow($e)) {
            return;
         }
         $where = $e->getFile() . ':' . $e->getLine();
         if ($moduleKey === '') {
            [$moduleKey, $where] = self::moduleFrameOf($e);
            if ($moduleKey === '') {
               return;
            }
         }
         $isDb = $e instanceof \PDOException || $e instanceof \mysqli_sql_exception
            || preg_match('/DB|Database|Statement|Sql/i', get_class($e)) === 1
            || self::looksLikeSql($e->getMessage());
         self::record($moduleKey, self::KIND_MODULE, 0, $reason, [
            'class'   => get_class($e),
            'where'   => $where,
            'message' => $isDb ? '' : $e->getMessage(),
         ]);
      } catch (\Throwable $ignored) {
      }
   }

   /**
    * Primeiro ponto do trace (começando pelo lugar da exceção) dentro de /modules/<chave>/.
    *
    * @return array{0:string, 1:string} [chave do módulo ou '', "arquivo:linha"]
    */
   private static function moduleFrameOf(\Throwable $e): array {
      $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());
      foreach ($frames as $frame) {
         $file = (string) ($frame['file'] ?? '');
         if (preg_match('#/modules/([a-z0-9_-]{1,64})/#', $file, $m) === 1) {
            return [$m[1], $file . ':' . (int) ($frame['line'] ?? 0)];
         }
      }
      return ['', ''];
   }

   public static function looksLikeSql(string $text): bool {
      return preg_match('/\b(SQL|SELECT|INSERT|UPDATE|DELETE|mysqli|PDO)\b/', $text) === 1;
   }

   /**
    * A requisição traz uma sessão válida? Usado ANTES da restauração da sessão no roteador
    * stateless do G11 (no G10 a sessão já vem viva). Só decide a cota do registro, nunca acesso.
    */
   public static function hasValidSession(): bool {
      try {
         if (class_exists('Session') && Session::getLoginUserID()) {
            return true;
         }
         $id = $_COOKIE[session_name()] ?? null;
         if (!is_string($id) || preg_match('/^[A-Za-z0-9,\-]{1,128}$/', $id) !== 1) {
            return false;
         }
         require_once __DIR__ . '/ajaxsessionguard.inc.php';
         return function_exists('plugin_nextool_session_exists') && plugin_nextool_session_exists($id);
      } catch (\Throwable $e) {
         return false;
      }
   }

   /**
    * Exceções que o roteador NÃO pode engolir: são a resposta certa (403, redirect, sessão
    * expirada) e o core sabe tratá-las. Nomes por string: no GLPI 10 as classes não existem,
    * e instanceof com classe inexistente é só falso.
    */
   public static function mustRethrow(\Throwable $e): bool {
      foreach ([
         'Symfony\Component\HttpKernel\Exception\HttpExceptionInterface',
         'Glpi\Exception\SessionExpiredException',
         'Glpi\Exception\RedirectException',
         'Glpi\Exception\Http\HttpExceptionInterface',
         'Glpi\Exception\AuthenticationFailedException',
      ] as $class) {
         if ($e instanceof $class) {
            return true;
         }
      }
      return false;
   }

   /**
    * Registra no log de erros do core (que é o que o handler do GLPI faria sem o catch).
    */
   public static function logToCore(\Throwable $e): void {
      try {
         if (class_exists('Glpi\Error\ErrorHandler') && method_exists('Glpi\Error\ErrorHandler', 'logCaughtException')) {
            \Glpi\Error\ErrorHandler::logCaughtException($e);
            return;
         }
         if (class_exists('Glpi\Application\ErrorHandler') && method_exists('Glpi\Application\ErrorHandler', 'getInstance')) {
            \Glpi\Application\ErrorHandler::getInstance()->handleException($e, true);
            return;
         }
         error_log('[NEXTOOL] ' . get_class($e) . ' em ' . self::relativePath($e->getFile() . ':' . $e->getLine()));
      } catch (\Throwable $ignored) {
      }
   }

   /**
    * Últimas falhas do módulo para o painel (mais recente primeiro).
    *
    * @return array<int, array{date: string, kind: string, http_code: int, text: string, count: int}>
    */
   public static function latest(string $moduleKey, int $limit = 10): array {
      global $DB;
      $out = [];
      try {
         if (!$DB->tableExists('glpi_plugin_nextool_main_logs')) {
            return [];
         }
         foreach ($DB->request([
            'FROM'  => 'glpi_plugin_nextool_main_logs',
            'WHERE' => ['log_type' => self::LOG_TYPE, 'module_key' => self::cleanKey($moduleKey)],
            'ORDER' => ['date DESC', 'id DESC'],
            'LIMIT' => max(1, min(50, $limit)),
         ]) as $row) {
            $d = json_decode((string) ($row['details'] ?? ''), true);
            $d = is_array($d) ? $d : [];
            $parts = [];
            foreach (['motivo', 'arquivo', 'classe', 'onde', 'mensagem', 'request_id'] as $k) {
               if (isset($d[$k]) && is_scalar($d[$k]) && (string) $d[$k] !== '') {
                  $parts[] = $k . '=' . (string) $d[$k];
               }
            }
            $out[] = [
               'date'      => (string) $row['date'],
               'kind'      => (string) ($d['tipo'] ?? ''),
               'http_code' => (int) ($row['http_code'] ?? 0),
               'text'      => implode(' ', $parts),
               'count'     => max(1, (int) ($d['vezes'] ?? 1)),
            ];
         }
      } catch (\Throwable $e) {
      }
      return $out;
   }

   /** Quantas falhas o módulo teve nas últimas N horas (para o resumo que vai na URL do chamado). */
   public static function countRecent(string $moduleKey, int $hours = 24): int {
      global $DB;
      try {
         if (!$DB->tableExists('glpi_plugin_nextool_main_logs')) {
            return 0;
         }
         $row = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_nextool_main_logs',
            'WHERE' => [
               'log_type'   => self::LOG_TYPE,
               'module_key' => self::cleanKey($moduleKey),
               'date'       => ['>=', date('Y-m-d H:i:s', time() - $hours * 3600)],
            ],
         ])->current();
         return (int) ($row['cpt'] ?? 0);
      } catch (\Throwable $e) {
         return 0;
      }
   }

   /** Texto livre -> sem caractere de controle, sem segredo, sem dado pessoal, curto. */
   public static function sanitize(string $text, int $max = self::MESSAGE_MAX): string {
      require_once __DIR__ . '/baseobservability.class.php';
      $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
      $text = PluginNextoolBaseObservability::stripSecretsFromText($text);
      $text = PluginNextoolBaseObservability::maskPersonalData($text);
      $text = trim(preg_replace('/\s{2,}/', ' ', $text) ?? '');
      return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
   }

   /** Caminho absoluto -> relativo ao módulo ou ao GLPI (sem revelar a árvore do servidor). */
   public static function relativePath(string $path): string {
      $path = preg_replace('/[\x00-\x1F\x7F]+/', '', $path) ?? '';
      if (preg_match('#/modules/([a-z0-9_-]+/.*)$#', $path, $m) === 1) {
         $path = 'modules/' . $m[1];
      } elseif (preg_match('#/plugins/(nextool/.*)$#', $path, $m) === 1) {
         $path = 'plugins/' . $m[1];
      } elseif (defined('GLPI_ROOT') && str_starts_with($path, GLPI_ROOT . '/')) {
         $path = substr($path, strlen(GLPI_ROOT) + 1);
      } else {
         $path = basename($path);
      }
      return mb_substr($path, 0, 180);
   }

   private static function cleanKey(string $key): string {
      $key = strtolower($key);
      return preg_match('/^[a-z0-9_-]{1,64}$/', $key) === 1 ? $key : '';
   }

   /** Só grava chave de módulo instalado: qualquer outra vira "desconhecido" (rota inventada). */
   private static function knownModule(string $key): string {
      global $DB;
      $key = self::cleanKey($key);
      if ($key === '') {
         return self::UNKNOWN_MODULE;
      }
      static $known = [];
      if (array_key_exists($key, $known)) {
         return $known[$key];
      }
      try {
         $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_nextool_main_modules',
            'WHERE'  => ['module_key' => $key],
            'LIMIT'  => 1,
         ]);
         return $known[$key] = (count($row) > 0 ? $key : self::UNKNOWN_MODULE);
      } catch (\Throwable $e) {
         return $known[$key] = self::UNKNOWN_MODULE;
      }
   }

   private static function bumpCounter(int $id, string $detailsJson): void {
      global $DB;
      $d = json_decode($detailsJson, true);
      $d = is_array($d) ? $d : [];
      $d['vezes'] = max(1, (int) ($d['vezes'] ?? 1)) + 1;
      require_once __DIR__ . '/dbcompat.class.php';
      $DB->update(
         'glpi_plugin_nextool_main_logs',
         PluginNextoolDbCompat::row([
            'details' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'date'    => date('Y-m-d H:i:s'),
         ]),
         ['id' => $id]
      );
   }
}
