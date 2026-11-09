<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Regra do portão das rotas de API de módulo (HTTP e em processo)
 * -------------------------------------------------------------------------
 * nextool-dev#291 + #295. A MESMA regra vale para a chamada pelo router HTTP
 * (PluginNextoolApiGuardMiddleware) e para a chamada dentro do processo (PluginNextoolApiService,
 * usada pelo assistente de IA e pelo MyDashboards): quem tira um passo daqui tira dos dois canais.
 *
 * Ordem: controller registrado e dono da rota (404) -> módulo ativo (404) -> licença (403
 * LICENSE_INACTIVE; FREE passa) -> #[PluginNextoolApiRight] declarado (403) -> cada bit (403).
 *
 * Sem dependência da API v2 do GLPI: carrega no GLPI 10 sem efeito.
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

final class PluginNextoolApiGuard {

   /** Recusa que responde 404 (não revela que a rota existe). */
   public const DENY_NOT_FOUND = 'not_found';
   /** Recusa de licença (403 com motivo). */
   public const DENY_LICENSE = 'license';
   /** Recusa de direito (403). */
   public const DENY_RIGHT = 'right';

   /**
    * Confere se o usuário da sessão pode chamar o método da rota.
    *
    * @return array{reason: string, kind: string, status: int, module: string}|null null = liberado
    */
   public static function check(string $class, ReflectionMethod $method, ?string $pathModule): ?array {
      $moduleKey = PluginNextoolApiRegistry::moduleOf($class);
      if ($moduleKey === null || $moduleKey !== $pathModule) {
         return self::deny('NOT_REGISTERED', self::DENY_NOT_FOUND, 404, (string) $pathModule);
      }

      $module = self::activeModule($moduleKey);
      if ($module === null) {
         return self::deny('MODULE_INACTIVE', self::DENY_NOT_FOUND, 404, $moduleKey);
      }

      $denied = PluginNextoolApiRegistry::accessDenyReason($module);
      if ($denied !== null) {
         return self::deny($denied, self::DENY_LICENSE, 403, $moduleKey);
      }

      $attrs = $method->getAttributes(PluginNextoolApiRight::class);
      if ($attrs === []) {
         return self::deny('RIGHT_UNDECLARED', self::DENY_RIGHT, 403, $moduleKey);
      }
      $right = $attrs[0]->newInstance();
      if (!self::hasRights($moduleKey, $right->bits, $right->kind)) {
         return self::deny('RIGHT_MISSING', self::DENY_RIGHT, 403, $moduleKey);
      }
      return null;
   }

   /**
    * TODOS os bits exigidos (AND), pelo PermissionManager. Lista vazia = recusado.
    *
    * @param int[] $bits
    */
   public static function hasRights(string $moduleKey, array $bits, string $kind): bool {
      if ($bits === []) {
         return false;
      }
      require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
      foreach ($bits as $bit) {
         $bit = (int) $bit;
         $ok = $kind === PluginNextoolApiRight::KIND_USE
            ? PluginNextoolPermissionManager::canUse($moduleKey, $bit)
            : ($kind === PluginNextoolApiRight::KIND_ADMIN && PluginNextoolPermissionManager::canAdmin($moduleKey, $bit));
         if (!$ok) {
            return false;
         }
      }
      return true;
   }

   /** Módulo ativo pela chave, ou null. */
   public static function activeModule(string $moduleKey): ?PluginNextoolBaseModule {
      if ($moduleKey === '' || !class_exists('PluginNextoolModuleManager')) {
         return null;
      }
      $active = PluginNextoolModuleManager::getInstance()->getActiveModules();
      $module = is_array($active) ? ($active[$moduleKey] ?? null) : null;
      return $module instanceof PluginNextoolBaseModule ? $module : null;
   }

   /** @return array{reason: string, kind: string, status: int, module: string} */
   private static function deny(string $reason, string $kind, int $status, string $moduleKey): array {
      return ['reason' => $reason, 'kind' => $kind, 'status' => $status, 'module' => $moduleKey];
   }
}
