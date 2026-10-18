<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Event Log (log unificado da base)
 * -------------------------------------------------------------------------
 * Tabela única dos logs do plugin base (glpi_plugin_nextool_main_logs), exibida
 * na aba Logs com a grade nativa do GLPI (Search::show). Junta as três trilhas
 * que antes tinham tabela própria:
 *  - validation: tentativas de validação de licença (antes main_validation_attempts)
 *  - config:     alterações de configuração/licença (antes main_config_audit)
 *  - module:     ações em módulos -- instalar, ativar, atualizar... (antes main_module_audit)
 *
 * As classes PluginNextoolValidationAttempt, PluginNextoolConfigAudit e
 * PluginNextoolModuleAudit continuam como fachadas de gravação e delegam para cá.
 * A migração das três tabelas antigas roda no install/upgrade (migrateLegacyTables).
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

use Glpi\Search\DefaultSearchRequestInterface;

require_once __DIR__ . '/baseauditlog.class.php';

class PluginNextoolEventLog extends PluginNextoolBaseAuditLog implements DefaultSearchRequestInterface {

   public const TABLE = 'glpi_plugin_nextool_main_logs';

   public const TYPE_VALIDATION = 'validation';
   public const TYPE_CONFIG     = 'config';
   public const TYPE_MODULE     = 'module';

   /**
    * Tabelas antigas absorvidas pela tabela única. A remoção só acontece depois que todas as
    * linhas de cada uma estão na tabela nova (ver migrateLegacyTables).
    */
   private const LEGACY_TABLES = [
      self::TYPE_VALIDATION => 'glpi_plugin_nextool_main_validation_attempts',
      self::TYPE_CONFIG     => 'glpi_plugin_nextool_main_config_audit',
      self::TYPE_MODULE     => 'glpi_plugin_nextool_main_module_audit',
   ];

   public $dont_pass_handled = true;

   public static function getTable($classname = null) {
      return self::TABLE;
   }

   public static function getTypeName($nb = 0) {
      return _n('Log do NexTool', 'Logs do NexTool', $nb, 'nextool');
   }

   public static function getIcon() {
      return 'ti ti-report-analytics';
   }

   /**
    * Gate do itemtype -- OBRIGATORIO, e nao cosmetico.
    *
    * O export nativo do core (`front/report.dynamic.php`) e o `/ajax/search.php` aplicam o
    * `canView()` do ITEMTYPE e mais nada. Sem este override qualquer perfil com acesso central
    * baixaria o log inteiro por `?item_type=PluginNextoolEventLog&display_type=-4` (vazamento
    * real medido em 2026-09-03 na antiga grade de validações). O gate é o MESMO da aba que
    * renderiza a grade (`front/tabs/config.logs.tab.inc.php` -> `canAccessAdminTabs()`).
    */
   public static function canView(): bool {
      return PluginNextoolPermissionManager::canAccessAdminTabs();
   }

   public static function getSearchURL($full = true) {
      if (!empty($GLOBALS['nextool_eventlog_forcetab_url'])) {
         return $GLOBALS['nextool_eventlog_forcetab_url'];
      }
      return PluginNextoolCompat::webDir()
         . '/front/nextoolconfig.form.php?id=1&forcetab=PluginNextoolMainConfig$5';
   }

   public static function getDefaultSearchRequest(): array {
      return [
         'sort'  => 2,
         'order' => 'DESC',
      ];
   }

   /**
    * @return array<string,string>
    */
   public static function getLogTypes(): array {
      return [
         self::TYPE_VALIDATION => __('Validação de licença', 'nextool'),
         self::TYPE_CONFIG     => __('Configuração', 'nextool'),
         self::TYPE_MODULE     => __('Módulo', 'nextool'),
      ];
   }

   public function rawSearchOptions() {
      $table = $this->getTable();
      $tab = [];

      $tab[] = [
         'id'   => 'common',
         'name' => __('Logs do NexTool', 'nextool'),
      ];

      $plain = static function (int $id, string $field, string $name, string $datatype = 'string', array $searchtype = ['contains', 'equals']) use ($table): array {
         return [
            'id'            => (string) $id,
            'table'         => $table,
            'field'         => $field,
            'name'          => $name,
            'searchtype'    => $searchtype,
            'datatype'      => $datatype,
            'massiveaction' => false,
         ];
      };

      $tab[] = $plain(1, 'id', __('ID', 'nextool'), 'number', ['equals', 'notequals']);
      $tab[] = $plain(2, 'date', __('Data', 'nextool'), 'datetime', ['contains', 'equals', 'notequals']);
      $tab[] = $plain(3, 'log_type', __('Tipo', 'nextool'), 'specific', ['equals', 'notequals']);
      $tab[] = $plain(4, 'action', __('Ação', 'nextool'));
      $tab[] = $plain(5, 'module_key', __('Módulo', 'nextool'));
      $tab[] = $plain(6, 'result', __('Resultado', 'nextool'), 'specific', ['equals', 'notequals'])
         + ['additionalfields' => ['log_type', 'plan', 'http_code']];
      $tab[] = [
         'id'            => '7',
         'table'         => 'glpi_users',
         'field'         => 'name',
         'name'          => __('Usuário', 'nextool'),
         'searchtype'    => ['contains', 'equals'],
         'datatype'      => 'dropdown',
         'massiveaction' => false,
         'linkfield'     => 'users_id',
      ];
      // 'specific': a grade traduz a mensagem para o idioma de quem lê (PluginNextoolLogMessage).
      $tab[] = $plain(8, 'message', __('Mensagem', 'nextool'), 'specific', ['contains']);
      $tab[] = $plain(9, 'details', __('Detalhes', 'nextool'), 'specific', ['contains']);
      $tab[] = $plain(10, 'origin', __('Origem', 'nextool'));
      $tab[] = $plain(11, 'section', __('Seção', 'nextool'));
      $tab[] = $plain(12, 'http_code', __('Código HTTP', 'nextool'), 'number', ['equals', 'notequals']);
      $tab[] = $plain(13, 'response_time_ms', __('Tempo (ms)', 'nextool'), 'number', ['equals', 'notequals']);
      $tab[] = $plain(14, 'plan', __('Plano', 'nextool'));
      $tab[] = $plain(15, 'license_status', __('Status da licença', 'nextool'));
      $tab[] = $plain(16, 'client_identifier', __('Ambiente', 'nextool'));
      $tab[] = $plain(17, 'source_ip', __('IP de origem', 'nextool'));
      $tab[] = $plain(18, 'requested_modules', __('Módulos solicitados', 'nextool'), 'text', ['contains']);
      $tab[] = $plain(19, 'allowed_modules', __('Módulos liberados', 'nextool'), 'text', ['contains']);

      return $tab;
   }

   public static function getSpecificValueToDisplay($field, $values, array $options = []) {
      if (!is_array($values)) {
         $values = [$field => $values];
      }

      if ($field === 'log_type') {
         $type = (string) ($values['log_type'] ?? '');
         $label = self::getLogTypes()[$type] ?? $type;
         $class = [
            self::TYPE_VALIDATION => 'bg-blue-lt',
            self::TYPE_CONFIG     => 'bg-indigo-lt',
            self::TYPE_MODULE     => 'bg-teal-lt',
         ][$type] ?? 'bg-secondary-lt';
         return "<span class='badge " . $class . "'>" . PluginNextoolCompat::escape($label) . "</span>";
      }

      if ($field === 'result') {
         return self::renderResultBadge($values);
      }

      if ($field === 'details') {
         return self::formatDetails($values['details'] ?? null);
      }

      if ($field === 'message') {
         $message = PluginNextoolLogMessage::toSession(isset($values['message']) ? (string) $values['message'] : null);
         return nl2br(PluginNextoolCompat::escape($message));
      }

      return parent::getSpecificValueToDisplay($field, $values, $options);
   }

   public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {
      if (!is_array($values)) {
         $values = [$field => $values];
      }
      $options['display'] = false;

      if ($field === 'log_type') {
         $options['value'] = $values[$field] ?? '';
         return Dropdown::showFromArray($name, self::getLogTypes(), $options);
      }
      if ($field === 'result') {
         $options['value'] = $values[$field] ?? '';
         return Dropdown::showFromArray($name, [
            1 => __('Sucesso', 'nextool'),
            0 => __('Falha', 'nextool'),
         ], $options);
      }

      return parent::getSpecificValueToSelect($field, $name, $values, $options);
   }

   /**
    * Validação de licença: "falha" só é erro de comunicação. HTTP 2xx-4xx sem licença ativa é
    * resposta de negócio (plano FREE, sem licença), não erro -- mesma leitura da grade antiga.
    */
   private static function renderResultBadge(array $values): string {
      $raw = $values['result'] ?? null;
      if ($raw === null || $raw === '') {
         return '-';
      }
      $result = (int) $raw;

      if (($values['log_type'] ?? '') === self::TYPE_VALIDATION) {
         $plan = strtoupper((string) ($values['plan'] ?? ''));
         $httpCode = (int) ($values['http_code'] ?? 0);
         if ($result === 1) {
            return "<span class='badge text-white bg-green'>" . __('Válida', 'nextool') . "</span>";
         }
         if ($plan === 'FREE') {
            return "<span class='badge text-white bg-teal'>" . __('Free Tier', 'nextool') . "</span>";
         }
         if ($httpCode >= 200 && $httpCode < 500) {
            return "<span class='badge text-white bg-orange'>" . __('Sem Licença', 'nextool') . "</span>";
         }
         return "<span class='badge text-white bg-red'>" . __('Erro', 'nextool') . "</span>";
      }

      return $result === 1
         ? "<span class='badge text-white bg-green'>" . __('Sucesso', 'nextool') . "</span>"
         : "<span class='badge text-white bg-red'>" . __('Falha', 'nextool') . "</span>";
   }

   private static function formatDetails(mixed $details): string {
      if ($details === null || $details === '') {
         return '';
      }
      $decoded = json_decode((string) $details, true);
      if (!is_array($decoded)) {
         return PluginNextoolCompat::escape((string) $details);
      }
      $chunks = [];
      foreach ($decoded as $key => $value) {
         if ($value === null || $value === '') {
            continue;
         }
         $text = is_array($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
         $chunks[] = PluginNextoolCompat::escape((string) $key) . ': ' . PluginNextoolCompat::escape((string) $text);
      }
      return implode(' • ', $chunks);
   }

   public static function ensureDisplayPreferences(): void {
      global $DB;

      $d_pref = new DisplayPreference();
      $found = $d_pref->find(['itemtype' => self::class, 'users_id' => 0]);
      if (count($found) > 0 || !isset($DB)) {
         return;
      }

      $default_cols = [
         2 => 1, // Data
         3 => 2, // Tipo
         4 => 3, // Ação
         5 => 4, // Módulo
         6 => 5, // Resultado
         7 => 6, // Usuário
         8 => 7, // Mensagem
         9 => 8, // Detalhes
      ];
      foreach ($default_cols as $num => $rank) {
         $DB->insert(DisplayPreference::getTable(), [
            'itemtype' => self::class,
            'num'      => $num,
            'rank'     => $rank,
            'users_id' => 0,
         ]);
      }
   }

   // ---------------------------------------------------------------------------------------
   // Gravação (chamada pelas fachadas ValidationAttempt / ConfigAudit / ModuleAudit)
   // ---------------------------------------------------------------------------------------

   /**
    * Tentativa de validação de licença. Usuário só o informado (a chamada de cron não tem dono).
    *
    * @param array $data result, message, http_code, response_time_ms, origin, requested_modules,
    *                    client_identifier, license_status, plan, force_refresh, cache_hit, user_id
    */
   public static function logValidation(array $data) {
      // GLPI 10: a mensagem e os detalhes podem trazer valor de formulário (já escapado) e texto de fora (cru).
      // Tudo vira cru aqui e a linha é escapada na gravação (PluginNextoolDbCompat::row).
      $data = PluginNextoolDbCompat::unescapeIncoming($data);
      $origin = self::truncate($data['origin'] ?? null, 64);

      return self::write([
         'log_type'          => self::TYPE_VALIDATION,
         'action'            => ($origin !== null && $origin !== '') ? $origin : 'license_check',
         'origin'            => $origin,
         'result'            => !empty($data['result']) ? 1 : 0,
         'message'           => $data['message'] ?? null,
         'users_id'          => isset($data['user_id']) ? (int) $data['user_id'] : 0,
         'http_code'         => isset($data['http_code']) ? (int) $data['http_code'] : null,
         'response_time_ms'  => isset($data['response_time_ms']) ? (int) $data['response_time_ms'] : null,
         'client_identifier' => self::truncate($data['client_identifier'] ?? null, 191),
         'license_status'    => self::truncate($data['license_status'] ?? null, 32),
         'plan'              => self::truncate($data['plan'] ?? null, 32),
         'force_refresh'     => !empty($data['force_refresh']) ? 1 : 0,
         'cache_hit'         => !empty($data['cache_hit']) ? 1 : 0,
         'requested_modules' => self::jsonEncodeIfArray($data['requested_modules'] ?? null),
      ]);
   }

   /**
    * Alteração de configuração/licença.
    *
    * @param array $data section, action, result (bool|null), message, user_id, source_ip, details (array)
    */
   public static function logConfig(array $data) {
      $data = PluginNextoolDbCompat::unescapeIncoming($data);
      $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;

      $result = self::write([
         'log_type'  => self::TYPE_CONFIG,
         'section'   => self::truncate($data['section'] ?? 'global', 32),
         'action'    => self::truncate($data['action'] ?? null, 64),
         'result'    => array_key_exists('result', $data) && $data['result'] !== null ? ($data['result'] ? 1 : 0) : null,
         'message'   => $data['message'] ?? null,
         'users_id'  => (int) (self::resolveUserId($userId) ?? 0),
         'source_ip' => self::truncate(self::resolveSourceIp(isset($data['source_ip']) ? (string) $data['source_ip'] : null), 45),
         'details'   => self::jsonEncodeIfArray($data['details'] ?? null),
      ]);

      self::recordHistory(
         (string) ($data['section'] ?? ''),
         sprintf('%s - %s', $data['action'] ?? '', $data['message'] ?? '')
      );

      return $result;
   }

   /**
    * Ação em módulo (install/enable/disable/uninstall/update...).
    *
    * @param array $data module_key, action, result, message, user_id, origin, source_ip,
    *                    license_status, plan, allowed_modules, requested_modules
    */
   public static function logModule(array $data) {
      $data = PluginNextoolDbCompat::unescapeIncoming($data);
      $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;

      $result = self::write([
         'log_type'          => self::TYPE_MODULE,
         'action'            => self::truncate($data['action'] ?? null, 64),
         'module_key'        => self::truncate($data['module_key'] ?? null, 100),
         'origin'            => self::truncate($data['origin'] ?? null, 64),
         'result'            => !empty($data['result']) ? 1 : 0,
         'message'           => $data['message'] ?? null,
         'users_id'          => (int) (self::resolveUserId($userId) ?? 0),
         'source_ip'         => self::truncate($data['source_ip'] ?? null, 45),
         'license_status'    => self::truncate($data['license_status'] ?? null, 32),
         'plan'              => self::truncate($data['plan'] ?? null, 32),
         'allowed_modules'   => self::jsonEncodeIfArray($data['allowed_modules'] ?? null),
         'requested_modules' => self::jsonEncodeIfArray($data['requested_modules'] ?? null),
      ]);

      self::recordHistory(
         (string) ($data['action'] ?? ''),
         sprintf('%s - %s', $data['module_key'] ?? '', $data['message'] ?? '')
      );

      return $result;
   }

   /**
    * @param array<string,mixed> $record
    * @return int|false
    */
   private static function write(array $record) {
      global $DB;

      // Ambiente que ainda não rodou a migração desta versão: não grava (o boot roda o install
      // pela rede de segurança do schema e a tabela aparece no request seguinte).
      if (!$DB->tableExists(self::TABLE)) {
         return false;
      }

      // Grava a frase original (msgid), não a tradução de quem fez a ação: a grade traduz
      // para o idioma de quem lê.
      if (isset($record['message']) && is_string($record['message'])) {
         $record['message'] = PluginNextoolLogMessage::toSource($record['message']);
      }

      $log = new self();
      return $log->add(PluginNextoolDbCompat::row($record));
   }

   // ---------------------------------------------------------------------------------------
   // Migração das três tabelas antigas (install/upgrade)
   // ---------------------------------------------------------------------------------------

   /**
    * Copia as tabelas antigas para a tabela única e remove cada uma SÓ depois de conferir que
    * nenhuma linha dela ficou de fora. Idempotente: a cópia pula a linha que já está na tabela
    * nova (mesmo tipo, mesmo id antigo e mesma data), então uma execução interrompida entre a
    * cópia e a remoção é retomada no próximo install sem duplicar. A data entra na comparação
    * porque a volta para uma versão antiga (rollback do update) recria a tabela antiga com ids a
    * partir de 1: comparando só o id, linha nova seria descartada. Nunca lança: falha vira log e
    * a tabela antiga fica onde está para a próxima tentativa.
    *
    * As validações manuais da trilha de configuração ("manual_validation") NÃO vêm: cada uma
    * tem a tentativa de validação correspondente, com o mesmo resultado e usuário.
    */
   public static function migrateLegacyTables(): void {
      global $DB;

      if (!$DB->tableExists(self::TABLE)) {
         return;
      }

      // Coluna da tabela nova => expressão sobre a antiga (alias `o`). `date`, `log_type` e
      // `legacy_id` são comuns às três e entram abaixo.
      $copies = [
         self::TYPE_VALIDATION => [
            'date'    => 'attempt_date',
            'columns' => [
               'action'            => "COALESCE(NULLIF(o.`origin`, ''), 'license_check')",
               'origin'            => 'o.`origin`',
               'result'            => 'o.`result`',
               'message'           => 'o.`message`',
               'users_id'          => 'COALESCE(o.`user_id`, 0)',
               'http_code'         => 'o.`http_code`',
               'response_time_ms'  => 'o.`response_time_ms`',
               'client_identifier' => 'o.`client_identifier`',
               'license_status'    => 'o.`license_status`',
               'plan'              => 'o.`plan`',
               'force_refresh'     => 'o.`force_refresh`',
               'cache_hit'         => 'o.`cache_hit`',
               'requested_modules' => 'o.`requested_modules`',
            ],
            'filter'  => '',
         ],
         self::TYPE_CONFIG => [
            'date'    => 'event_date',
            'columns' => [
               'section'   => 'o.`section`',
               'action'    => 'o.`action`',
               'result'    => 'o.`result`',
               'message'   => 'o.`message`',
               'users_id'  => 'COALESCE(o.`user_id`, 0)',
               'source_ip' => 'o.`source_ip`',
               'details'   => 'o.`details`',
            ],
            'filter'  => "AND (o.`action` IS NULL OR o.`action` <> 'manual_validation')",
         ],
         self::TYPE_MODULE => [
            'date'    => 'action_date',
            'columns' => [
               'action'            => 'o.`action`',
               'module_key'        => 'o.`module_key`',
               'origin'            => 'o.`origin`',
               'result'            => 'o.`result`',
               'message'           => 'o.`message`',
               'users_id'          => 'COALESCE(o.`user_id`, 0)',
               'source_ip'         => 'o.`source_ip`',
               'license_status'    => 'o.`license_status`',
               'plan'              => 'o.`plan`',
               'allowed_modules'   => 'o.`allowed_modules`',
               'requested_modules' => 'o.`requested_modules`',
            ],
            'filter'  => '',
         ],
      ];

      $t = self::TABLE;
      // doQuery só a partir do GLPI 10.0.7; o GLPI 12 não tem mais query().
      $method = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';

      foreach ($copies as $type => $copy) {
         $legacy = self::LEGACY_TABLES[$type];
         // Linha antiga que ainda não está na tabela nova (mesmo tipo, id antigo e data).
         $notCopied = "LEFT JOIN `{$t}` n ON n.`log_type` = '{$type}' AND n.`legacy_id` = o.`id`
                          AND n.`date` = o.`{$copy['date']}`
                       WHERE n.`id` IS NULL {$copy['filter']}";
         try {
            if (!$DB->tableExists($legacy, false)) {
               continue;
            }

            $targets = array_merge(['date', 'log_type', 'legacy_id'], array_keys($copy['columns']));
            $sources = array_merge(["o.`{$copy['date']}`", "'{$type}'", 'o.`id`'], array_values($copy['columns']));
            $DB->{$method}(
               "INSERT INTO `{$t}` (`" . implode('`, `', $targets) . "`)
                SELECT " . implode(', ', $sources) . "
                FROM `{$legacy}` o {$notCopied}"
            );

            $res = $DB->{$method}("SELECT COUNT(*) AS c FROM `{$legacy}` o {$notCopied}");
            $row = $res ? $DB->fetchAssoc($res) : null;
            $missing = is_array($row) ? (int) $row['c'] : -1;

            if ($missing !== 0) {
               Toolbox::logInFile('plugin_nextool', sprintf(
                  "[LOGS] %s não removida: %s linha(s) sem cópia na tabela única\n",
                  $legacy,
                  $missing < 0 ? '?' : (string) $missing
               ));
               continue;
            }

            $DB->dropTable($legacy, true);
            Toolbox::logInFile('plugin_nextool', sprintf(
               "[LOGS] %s copiada para %s e removida\n",
               $legacy,
               $t
            ));
         } catch (\Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               "[LOGS] migração de %s falhou (tabela mantida): %s\n",
               $legacy,
               $e->getMessage()
            ));
         }
      }

      // A grade antiga de validações saiu: preferências de colunas dela ficariam órfãs.
      try {
         $DB->delete(DisplayPreference::getTable(), ['itemtype' => 'PluginNextoolValidationAttempt']);
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool', '[LOGS] limpeza das preferências da grade antiga falhou: ' . $e->getMessage() . "\n");
      }
   }
}
