<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Modo diagnóstico dos módulos (ligar, desligar, estado)
 * -------------------------------------------------------------------------
 * Uma fonte só para a tela e para o servidor: o `can_enable` que o aviso e o painel
 * mostram sai da MESMA função que autoriza a ativação (PERMISSIONS.md §7).
 *   - ver o painel (últimos erros, que vêm de ações de outros usuários): VIEW_LOGS do módulo;
 *   - ligar/desligar: CONFIGURE do módulo (no helpdesk o canAdmin nega sempre).
 * Ligar grava `log_level=debug` + `diagnostic_until` (24 h) pelo saveConfig (merge), sem
 * passar pelo formulário inteiro do módulo, e deixa trilha no log de configuração.
 * -------------------------------------------------------------------------
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolModuleDiagnostic {

   public static function cleanKey(string $key): string {
      $key = strtolower(trim($key));
      return preg_match('/^[a-z0-9_-]{1,64}$/', $key) === 1 ? $key : '';
   }

   /** Instância do módulo carregado (null = chave desconhecida). */
   public static function module(string $key): ?PluginNextoolBaseModule {
      require_once NEXTOOL_PHP_DIR . '/inc/modulemanager.class.php';
      $key = self::cleanKey($key);
      if ($key === '') {
         return null;
      }
      $module = PluginNextoolModuleManager::getInstance()->getModule($key);
      return $module instanceof PluginNextoolBaseModule ? $module : null;
   }

   /**
    * O módulo segue o modo diagnóstico da base? Só quando tem a casca
    * PluginNextool<X>Observability e NÃO sobrescreve isDebug(): mailanalyzer, mailinteractions,
    * ssologin e whatsappbot guardam o debug em outro lugar, e o kbsuggest não tem a casca.
    * Ligar por aqui nesses daria "ativo" sem efeito nenhum (fase 2 trata cada um).
    */
   public static function supported(string $key): bool {
      $key = self::cleanKey($key);
      if ($key === '' || self::module($key) === null) {
         return false;
      }
      $class = 'PluginNextool' . ucfirst($key) . 'Observability';
      $file  = NEXTOOL_MODULES_BASE . '/' . $key . '/inc/observability.class.php';
      try {
         if (!class_exists($class, false) && is_file($file)) {
            require_once $file;
         }
         if (!class_exists($class, false) || !is_subclass_of($class, 'PluginNextoolBaseObservability')) {
            return false;
         }
         return (new \ReflectionMethod($class, 'isDebug'))->getDeclaringClass()->getName() === 'PluginNextoolBaseObservability';
      } catch (\Throwable $e) {
         return false;
      }
   }

   public static function canEnable(string $key): bool {
      require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
      $key = self::cleanKey($key);
      return $key !== '' && self::supported($key)
         && PluginNextoolPermissionManager::canAdmin($key, PluginNextoolPermissionManager::CONFIGURE);
   }

   public static function canViewLogs(string $key): bool {
      require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
      $key = self::cleanKey($key);
      return $key !== '' && PluginNextoolPermissionManager::canViewModuleLogs($key);
   }

   /**
    * Estado para o aviso e o painel. Quem não tem direito nenhum no módulo recebe só "não pode".
    *
    * @return array{supported: bool, can_enable: bool, can_view_logs: bool, active: bool, until: string, config_url: string}
    */
   public static function status(string $key): array {
      $out = ['supported' => false, 'can_enable' => false, 'can_view_logs' => false, 'active' => false, 'until' => '', 'config_url' => ''];
      $module = self::module($key);
      if ($module === null) {
         return $out;
      }
      $out['supported'] = self::supported($key);
      $out['can_enable']    = self::canEnable($key);
      $out['can_view_logs'] = self::canViewLogs($key);
      if (!$out['can_enable'] && !$out['can_view_logs']) {
         return $out;
      }
      require_once NEXTOOL_PHP_DIR . '/inc/baseobservability.class.php';
      $cfg = $module->getConfig();
      $cfg = is_array($cfg) ? $cfg : [];
      $out['active'] = PluginNextoolBaseObservability::diagnosticActive($cfg);
      $until = PluginNextoolBaseObservability::diagnosticUntil($cfg);
      $out['until'] = ($out['active'] && $until !== null) ? date('d/m/Y H:i', $until) : '';
      $out['config_url'] = (string) ($module->getConfigPage() ?? '');
      return $out;
   }

   /** Liga (true) ou desliga (false). Retorna false sem direito ou sem módulo. */
   public static function setActive(string $key, bool $on): bool {
      $module = self::module($key);
      if ($module === null || !self::canEnable($key)) {
         return false;
      }
      $payload = $on
         ? ['log_level' => 'debug', 'diagnostic_until' => date('Y-m-d H:i:s', time() + PluginNextoolBaseModule::DIAGNOSTIC_WINDOW_SECONDS)]
         : ['log_level' => 'padrao'];
      $ok = (bool) $module->saveConfig($payload);
      try {
         require_once NEXTOOL_PHP_DIR . '/inc/eventlog.class.php';
         PluginNextoolEventLog::logConfig([
            'section' => 'diagnostic',
            'action'  => $on ? 'diagnostic_enable' : 'diagnostic_disable',
            'result'  => $ok,
            'message' => $on ? 'Modo diagnóstico do módulo ativado por 24 horas' : 'Modo diagnóstico do módulo desativado',
            'details' => ['module' => self::cleanKey($key)],
         ]);
      } catch (\Throwable $e) {
      }
      return $ok;
   }

   /** Nunca é chamado: só leva as mensagens fixas ao POT da base. */
   private static function messagesForExtraction(): void {
      __('Modo diagnóstico do módulo ativado por 24 horas', 'nextool');
      __('Modo diagnóstico do módulo desativado', 'nextool');
   }

   /**
    * Volta ao Padrão os módulos cujo prazo venceu e dá prazo aos que estão em debug sem ele
    * (ligados antes desta versão ou gravados fora do saveConfig). Roda na manutenção de logs.
    */
   public static function expireWindows(): int {
      global $DB;
      $changed = 0;
      try {
         if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
            return 0;
         }
         foreach ($DB->request([
            'SELECT' => ['module_key', 'config'],
            'FROM'   => 'glpi_plugin_nextool_main_modules',
            // Filtro largo: JSON gravado pelo MySQL (JSON_SET) tem espaço depois do ':'.
            // Quem decide é o json_decode logo abaixo.
            'WHERE'  => ['config' => ['LIKE', '%debug%']],
         ]) as $row) {
            $cfg = json_decode((string) ($row['config'] ?? ''), true);
            if (!is_array($cfg) || ($cfg['log_level'] ?? '') !== 'debug') {
               continue;
            }
            $module = self::module((string) $row['module_key']);
            if ($module === null || !self::supported((string) $row['module_key'])) {
               continue;
            }
            require_once NEXTOOL_PHP_DIR . '/inc/baseobservability.class.php';
            $until = PluginNextoolBaseObservability::diagnosticUntil($cfg);
            if ($until === null) {
               $module->saveConfig(['log_level' => 'debug', 'diagnostic_until' => date('Y-m-d H:i:s', time() + PluginNextoolBaseModule::DIAGNOSTIC_WINDOW_SECONDS)]);
               $changed++;
            } elseif ($until <= time()) {
               $module->saveConfig(['log_level' => 'padrao']);
               $changed++;
            }
         }
      } catch (\Throwable $e) {
      }
      return $changed;
   }
}
