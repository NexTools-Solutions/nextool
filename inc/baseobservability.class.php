<?php
/**
 * NexTool Solutions - Base de Observabilidade dos módulos
 * -------------------------------------------------------------------------
 * Motor GENÉRICO da observabilidade padrão NexTool, promovido ao plugin base
 * (2026-08-15) para ser a FONTE ÚNICA das partes de SEGURANÇA e diagnóstico que
 * estavam copiadas em cada módulo (finding /audit-deep HI-03). Centraliza:
 *   - mascaramento de segredo (`maskSecret`/`jaMascarado`) -- byte-idêntico em
 *     vários módulos e AUSENTE em outros; um fix de segurança aqui vale para todos;
 *   - `stripSecretsFromText` (padrões default; o módulo pode ESTENDER);
 *   - gate de nível (`isDebug`/`isLevelLogged`) e as duas vias de log
 *     (`log()` -> tabela do módulo, `fileLog()` -> arquivo gateado);
 *   - o esqueleto de diagnóstico (`snapshot`/`snapshotAsText`/`urlAbrirChamado`/
 *     `truncarParaUrl`) e a retenção (`purgeOldLogs`).
 *
 * O que VARIA por módulo entra por hooks sobrescrevíveis: `moduleKey()`,
 * `moduleClass()`, `logClass()`, `camposExtras()`, `logDateColumn()`. Módulos com
 * contrato de log próprio (ex.: whatsappbot, com níveis minúsculos + FATAL +
 * catálogo de códigos) sobrescrevem o que diverge e HERDAM o resto -- NÃO se
 * força um vocabulário único (WARN vs WARNING, FATAL vs CRITICAL são de domínios
 * distintos) nem migração de dados.
 *
 * REGRAS INVIOLÁVEIS
 *   - NENHUM segredo no diagnóstico (use `maskSecret`); o texto vai para chamado/print/e-mail.
 *   - Observabilidade NUNCA derruba o fluxo observado: tudo é fail-silent.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

abstract class PluginNextoolBaseObservability {

   /**
    * Níveis canônicos (MAIÚSCULO -- o padrão da base). Módulos com vocabulário
    * próprio (ex.: whatsappbot 'error'/'fatal' minúsculo) sobrescrevem as
    * constantes e `defaultLevels()`; herdam o motor.
    */
   public const LEVEL_ERROR = 'ERROR';
   public const LEVEL_WARN  = 'WARN';
   public const LEVEL_INFO  = 'INFO';
   public const LEVEL_DEBUG = 'DEBUG';

   // ─────────────────────────────────────────
   // Hooks por módulo (a casca fina implementa)
   // ─────────────────────────────────────────

   /** Chave do módulo (ex.: 'workflow'). */
   abstract protected static function moduleKey(): string;

   /** FQCN da classe do módulo (getConfig/getVersion/isEnabled). */
   abstract protected static function moduleClass(): string;

   /** FQCN da classe de log do módulo (getTable). */
   abstract protected static function logClass(): string;

   /** Domínio i18n do módulo. */
   protected static function i18nDomain(): string {
      return 'nextool_' . static::moduleKey();
   }

   /** Níveis sempre gravados no perfil padrão (DEBUG só com log_level=debug). */
   protected static function defaultLevels(): array {
      return [static::LEVEL_ERROR, static::LEVEL_WARN, static::LEVEL_INFO];
   }

   /** Coluna de data da tabela de log (whatsappbot usa 'log_date'). */
   protected static function logDateColumn(): string {
      return 'date_creation';
   }

   /**
    * Campos ESPECÍFICOS do módulo no diagnóstico (endpoint, fila, contadores...).
    * É o único método que costuma mudar de módulo para módulo. Nada de segredo.
    *
    * @return array<string, scalar>
    */
   protected static function camposExtras(array $cfg): array {
      return ['reten_logs' => (string) ($cfg['logs_retention_days'] ?? 30) . ' dia(s)'];
   }

   // ─────────────────────────────────────────
   // Nível de log
   // ─────────────────────────────────────────

   /** O módulo está em nível DEBUG? Cacheado por request e por módulo (gate quente). */
   public static function isDebug(): bool {
      static $cache = [];
      $key = static::moduleKey();
      if (array_key_exists($key, $cache)) {
         return $cache[$key];
      }
      $debug = false;
      try {
         require_once NEXTOOL_PHP_DIR . '/inc/basemodule.class.php';
         $moduleClass = static::moduleClass();
         $cfg   = (new $moduleClass())->getConfig();
         $debug = (($cfg['log_level'] ?? 'padrao') === 'debug');
      } catch (\Throwable $e) {
         // sem config legível (install/boot): permanece no perfil padrão
      }
      return $cache[$key] = $debug;
   }

   public static function isLevelLogged(string $level): bool {
      if ($level === static::LEVEL_DEBUG) {
         return static::isDebug();
      }
      return in_array($level, static::defaultLevels(), true);
   }

   // ─────────────────────────────────────────
   // Escrita: tabela de logs do módulo
   // ─────────────────────────────────────────

   /**
    * Grava uma linha na tabela de logs do módulo, respeitando o nível.
    * Fail-silent. Contrato de colunas default: level/action/detail/users_id +
    * coluna de data. Módulo com colunas diferentes (whatsappbot) sobrescreve.
    *
    * @param mixed $detail array (vira JSON) ou string
    */
   public static function log(string $level, string $action, $detail = null): void {
      global $DB;

      if (!static::isLevelLogged($level)) {
         return;
      }
      try {
         $logClass = static::logClass();
         $table    = $logClass::getTable();
         if (!$DB->tableExists($table)) {
            return;
         }
         $DB->insert($table, [
            'level'                   => $level,
            'action'                  => mb_substr($action, 0, 50),
            'detail'                  => is_array($detail)
               ? json_encode($detail, JSON_UNESCAPED_UNICODE)
               : ($detail !== null && $detail !== '' ? (string) $detail : null),
            'users_id'                => (int) Session::getLoginUserID() ?: null,
            static::logDateColumn()   => date('Y-m-d H:i:s'),
         ]);
      } catch (\Throwable $e) {
         // fail-silent
      }
   }

   public static function info(string $action, $detail = null): void {
      static::log(static::LEVEL_INFO, $action, $detail);
   }

   public static function warn(string $action, $detail = null): void {
      static::log(static::LEVEL_WARN, $action, $detail);
   }

   public static function error(string $action, $detail = null): void {
      static::log(static::LEVEL_ERROR, $action, $detail);
   }

   /** Só grava com log_level=debug. Detail: IDs/chaves/portas, nunca conteúdo. */
   public static function debug(string $action, $detail = null): void {
      static::log(static::LEVEL_DEBUG, $action, $detail);
   }

   // ─────────────────────────────────────────
   // Escrita: arquivo (Toolbox::logInFile gateado por nível)
   // ─────────────────────────────────────────

   public static function fileLog(string $level, string $message): void {
      if (!static::isLevelLogged($level)) {
         return;
      }
      try {
         Toolbox::logInFile('plugin_nextool_' . static::moduleKey(), '[' . $level . '] ' . $message . "\n");
      } catch (\Throwable $e) {
         // fail-silent
      }
   }

   // ─────────────────────────────────────────
   // Segredos (FONTE ÚNICA -- antes copiado em cada módulo, HI-03)
   // ─────────────────────────────────────────

   /**
    * Mascara um segredo preservando o que serve ao diagnóstico: comprimento e os
    * 4 últimos caracteres. Idempotente.
    */
   public static function maskSecret(?string $value): string {
      $value = (string) $value;
      $len   = strlen($value);
      if ($len === 0) {
         return '<vazio>';
      }
      if (static::jaMascarado($value)) {
         return $value;
      }
      if ($len < 8) {
         return '<curto len=' . $len . '>';
      }
      return '...' . substr($value, -4) . ' (len=' . $len . ')';
   }

   protected static function jaMascarado(string $value): bool {
      return $value === '<vazio>'
         || preg_match('/^<curto len=\d+>$/', $value) === 1
         || preg_match('/^\.\.\..{4} \(len=\d+\)$/', $value) === 1;
   }

   /**
    * Última linha de defesa: remove padrões de credencial de um texto livre.
    * O DEFAULT cobre a UNIÃO dos padrões em uso no ecossistema (6.10.1) --
    * tokens de provedor com prefixo conhecido, chave AWS, header Authorization
    * e pares chave=valor de credencial. O módulo pode sobrescrever para
    * acrescentar padrões próprios; um default forte evita que quem delega
    * herde redação fraca.
    */
   public static function stripSecretsFromText(string $texto): string {
      // Tokens com prefixo de provedor conhecido: o valor inteiro some.
      $texto = preg_replace('/\b(?:xkeysib|xsmtpsib|sk-ant|sk_live|sk-proj|ghp|xoxb)[-_][A-Za-z0-9_\-]{8,}/i', '<oculto>', $texto);
      $texto = preg_replace('/\bAKIA[0-9A-Z]{16}\b/', '<oculto>', $texto);
      // Headers de autenticação (a linha inteira, antes dos pares chave=valor).
      // Whitespace HORIZONTAL apenas ([ \t]): \s cruzaria \n e engoliria a linha seguinte.
      $texto = preg_replace('/(Authorization:[ \t]*)\S+([ \t]+\S+)?/i', '$1<oculto>', $texto);
      $texto = preg_replace('/(Bearer[ \t]+)\S+/i', '$1<oculto>', $texto);
      // Pares chave=valor / chave: valor (prefixos cobrem user_token, app_token,
      // webhook_token, evolution_api_key, client_secret etc.).
      $texto = preg_replace('/(\w*api[_-]?key[ \t]*[:=][ \t]*)\S+/i', '$1<oculto>', $texto);
      $texto = preg_replace('/(\w*token[ \t]*[:=][ \t]*)\S+/i', '$1<oculto>', $texto);
      $texto = preg_replace('/(\w*(?:senha|password|secret)[ \t]*[:=][ \t]*)\S+/i', '$1<oculto>', $texto);
      return (string) $texto;
   }

   // ─────────────────────────────────────────
   // Diagnóstico para o suporte
   // ─────────────────────────────────────────

   /** Retrato do ambiente, sem nenhum segredo. */
   public static function snapshot(): array {
      $dados = ['gerado_em' => date('c')];

      try {
         require_once NEXTOOL_PHP_DIR . '/inc/basemodule.class.php';
         $moduleClass = static::moduleClass();

         $module = new $moduleClass();
         $cfg    = $module->getConfig();

         $dados['modulo']       = static::moduleKey() . ' ' . $module->getVersion();
         $dados['plugin_base']  = class_exists('PluginNextoolConfig')
            ? PluginNextoolConfig::getPluginVersion() : '?';
         $dados['glpi']         = defined('GLPI_VERSION') ? GLPI_VERSION : '?';
         $dados['php']          = PHP_VERSION;
         $dados['modulo_ativo'] = $module->isEnabled() ? 'sim' : 'nao';
         $dados['nivel_log']    = (string) ($cfg['log_level'] ?? 'padrao');

         foreach (static::camposExtras($cfg) as $chave => $valor) {
            $dados[$chave] = $valor;
         }

         $dados['ultimos_erros'] = static::ultimosErros(5);
      } catch (\Throwable $e) {
         $dados['erro_ao_montar'] = $e->getMessage();
      }

      return $dados;
   }

   /**
    * Últimos ERROs do log do módulo, já sem segredo. Contrato default:
    * action/detail/date_creation, filtrando level=ERROR quando a coluna existir.
    * Módulo com esquema diferente sobrescreve.
    */
   public static function ultimosErros(int $limite = 5): array {
      global $DB;
      $saida = [];
      try {
         $logClass = static::logClass();
         $table    = $logClass::getTable();
         if (!$DB->tableExists($table)) {
            return $saida;
         }
         $where = $DB->fieldExists($table, 'level') ? ['level' => static::LEVEL_ERROR] : [];
         foreach ($DB->request([
            'FROM'  => $table,
            'WHERE' => $where,
            'ORDER' => static::logDateColumn() . ' DESC',
            'LIMIT' => $limite,
         ]) as $row) {
            $saida[] = trim(sprintf(
               '%s [%s] %s',
               $row[static::logDateColumn()] ?? '',
               $row['action'] ?? '',
               static::stripSecretsFromText(mb_substr((string) ($row['detail'] ?? ''), 0, 160))
            ));
         }
      } catch (\Throwable $e) {
         // diagnóstico parcial é melhor que nenhum
      }
      return $saida;
   }

   /** Snapshot em texto plano, pronto para colar no chamado. */
   public static function snapshotAsText(): string {
      $linhas = ['=== Diagnostico ' . static::moduleKey() . ' (NexTool) ==='];
      foreach (static::snapshot() as $k => $v) {
         if (is_array($v)) {
            $linhas[] = $k . ':';
            foreach ($v as $item) {
               $linhas[] = '  - ' . (is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE));
            }
            continue;
         }
         $linhas[] = $k . ': ' . (string) $v;
      }
      return implode("\n", $linhas);
   }

   /** URL do portal NexTool para abrir chamado já com o diagnóstico no corpo. */
   public static function urlAbrirChamado(string $assunto, ?string $diagnostico = null): string {
      $params = [
         'categoria' => 'modulos',
         'tipo'      => 'problema',
         'assunto'   => $assunto,
      ];

      $corpo = $diagnostico ?? static::snapshotAsText();
      if ($corpo !== '') {
         $params['corpo'] = static::truncarParaUrl($corpo);
      }

      return 'https://app.nextoolsolutions.com/painel/solicitacoes/nova?' . http_build_query($params);
   }

   protected const MAX_CORPO_URL = 1800;

   protected static function truncarParaUrl(string $texto): string {
      if (mb_strlen($texto) <= static::MAX_CORPO_URL) {
         return $texto;
      }
      return mb_substr($texto, 0, static::MAX_CORPO_URL)
         . "\n\n[... diagnostico truncado -- use o botao \"Copiar diagnostico\" e cole o restante ...]";
   }

   // ─────────────────────────────────────────
   // Retenção
   // ─────────────────────────────────────────

   /**
    * Remove logs mais antigos que N dias. null = lê logs_retention_days da
    * config; 0 = não limpar. Retorna quantos apagou. Sem cron no motor: chamar
    * na abertura da aba Logs e no upgrade (nunca no caminho quente).
    */
   public static function purgeOldLogs(?int $dias = null): int {
      global $DB;

      try {
         if ($dias === null) {
            require_once NEXTOOL_PHP_DIR . '/inc/basemodule.class.php';
            $moduleClass = static::moduleClass();
            $cfg  = (new $moduleClass())->getConfig();
            $dias = max(0, (int) ($cfg['logs_retention_days'] ?? 30));
         }
         if ($dias <= 0) {
            return 0;
         }
         $logClass = static::logClass();
         $table    = $logClass::getTable();
         if (!$DB->tableExists($table)) {
            return 0;
         }
         $col    = static::logDateColumn();
         $limite = date('Y-m-d H:i:s', strtotime("-{$dias} days"));
         $antes  = count($DB->request([
            'SELECT' => ['id'], 'FROM' => $table, 'WHERE' => [$col => ['<', $limite]],
         ]));
         if ($antes === 0) {
            return 0;
         }
         $DB->delete($table, [$col => ['<', $limite]]);
         return $antes;
      } catch (\Throwable $e) {
         return 0;
      }
   }
}
