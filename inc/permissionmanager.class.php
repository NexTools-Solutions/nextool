<?php
/**
 * Nextools - Permission Manager
 *
 * Camada de conveniência para registrar e validar os direitos nativos do GLPI
 * utilizados pelo Nextools. Centraliza a definição dos direitos e expõe
 * helpers reutilizáveis na UI e nos endpoints.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolPermissionManager {

   public const RIGHT_MODULES    = 'plugin_nextool_modules';
   public const RIGHT_ADMIN_TABS = 'plugin_nextool_admin';

   private const MODULE_RIGHT_PREFIX = 'plugin_nextool_module_';
   private const NEXTTOOL_RIGHT_MASK = CREATE | READ | UPDATE | DELETE | PURGE;

   /** @var array<string,bool> */
   private static array $syncedModuleRights = [];

   /**
    * Cache local de direitos carregados do banco para perfis helpdesk.
    * Chave = "profiles_id:rightName", valor = int (bitmask).
    * @var array<string,int>
    */
   private static array $helpdeskRightsCache = [];

   /**
    * Registra o direito principal do Nextool e garante que perfis com acesso
    * à configuração global recebam o direito completo durante o upgrade/install.
    */
   private static function hasGlobalAdminAccess(): bool {
      return Session::haveRight('config', UPDATE);
   }

   public static function installRights(): void {
      $versionInfo = plugin_version_nextool();
      $migration = new Migration($versionInfo['version'] ?? '1.0.0');
      $rights = [self::RIGHT_MODULES, self::RIGHT_ADMIN_TABS];

      foreach ($rights as $right) {
         self::ensureRightExists($right, $migration, [Config::$rightname => UPDATE]);
      }

      $migration->executeMigration();
      self::sanitizeRegisteredRights();
   }

   /**
    * Remove os direitos do plugin durante a desinstalação.
    */
   public static function removeRights(): void {
      ProfileRight::deleteProfileRights([self::RIGHT_MODULES, self::RIGHT_ADMIN_TABS]);
      self::removeAllModuleRights();
   }

   public static function canViewModules(): bool {
      return self::haveRight(self::RIGHT_MODULES, READ) || self::hasGlobalAdminAccess();
   }

   public static function canManageModules(): bool {
      return self::haveRight(self::RIGHT_MODULES, UPDATE) || self::hasGlobalAdminAccess();
   }

   public static function canPurgeModuleData(): bool {
      return self::haveRight(self::RIGHT_MODULES, DELETE)
         || self::haveRight(self::RIGHT_MODULES, PURGE)
         || self::hasGlobalAdminAccess();
   }

   public static function canAccessAdminTabs(): bool {
      return self::haveRight(self::RIGHT_ADMIN_TABS, READ) || self::hasGlobalAdminAccess();
   }

   public static function canManageAdminTabs(): bool {
      return self::haveRight(self::RIGHT_ADMIN_TABS, UPDATE) || self::hasGlobalAdminAccess();
   }

   public static function assertCanViewModules(): void {
      if (!self::canViewModules()) {
         self::deny(__('Você não tem permissão para visualizar os módulos do NexTool.', 'nextool'));
      }
   }

   public static function assertCanManageModules(): void {
      if (!self::canManageModules()) {
         self::deny(__('Você não tem permissão para gerenciar os módulos do NexTool.', 'nextool'));
      }
   }

   public static function assertCanPurgeModuleData(): void {
      if (!self::canPurgeModuleData()) {
         self::deny(__('Você não tem permissão para apagar os dados do módulo.', 'nextool'));
      }
   }

   public static function assertCanAccessAdminTabs(): void {
      if (!self::canAccessAdminTabs()) {
         self::deny(__('Você não tem permissão para visualizar as abas administrativas do NexTool.', 'nextool'));
      }
   }

   public static function assertCanManageAdminTabs(): void {
      if (!self::canManageAdminTabs()) {
         self::deny(__('Você não tem permissão para gerenciar configurações administrativas do NexTool.', 'nextool'));
      }
   }

   /**
    * Verifica se o usuário tem permissão READ no módulo específico.
    * Usa apenas o bit READ do direito por módulo + bypass admin.
    * NÃO faz fallback para permissões globais de visualização.
    */
   public static function canViewModule(string $moduleKey): bool {
      $right = self::getModuleRightName($moduleKey);
      return self::canViewModules()
         || self::haveRight($right, READ)
         || self::hasGlobalAdminAccess();
   }

   /**
    * Verifica se o usuário tem permissão UPDATE no módulo específico.
    * Usa apenas o bit UPDATE do direito por módulo + bypass admin.
    * NÃO faz fallback para permissões globais de gerenciamento.
    */
   public static function canManageModule(string $moduleKey): bool {
      $right = self::getModuleRightName($moduleKey);
      return self::canManageModules()
         || self::haveRight($right, UPDATE)
         || self::hasGlobalAdminAccess();
   }

   /**
    * Verifica se o usuário tem permissão CREATE no módulo específico.
    * Usa apenas o bit CREATE do direito por módulo + bypass admin.
    * NÃO faz fallback para permissões globais de gerenciamento.
    */
   public static function canCreateInModule(string $moduleKey): bool {
      $right = self::getModuleRightName($moduleKey);
      return self::haveRight($right, CREATE)
         || self::hasGlobalAdminAccess();
   }

   /**
    * Verifica se o usuário tem permissão DELETE no módulo específico.
    * Usa apenas o bit DELETE do direito por módulo + bypass admin.
    * NÃO faz fallback para permissões globais de gerenciamento.
    */
   public static function canDeleteInModule(string $moduleKey): bool {
      $right = self::getModuleRightName($moduleKey);
      return self::haveRight($right, DELETE)
         || self::hasGlobalAdminAccess();
   }

   /**
    * Verifica se o usuário pode visualizar ALGUM módulo (global ou específico).
    * Usado para decidir se a tab de módulos deve ser exibida.
    * 
    * @return bool TRUE se tem permissão global OU permissão em pelo menos 1 módulo
    */
   public static function canViewAnyModule(): bool {
      // Se tem permissão global, retorna TRUE imediatamente
      if (self::canViewModules()) {
         return true;
      }

      // Verifica se tem permissão em algum módulo específico
      $moduleKeys = self::getModuleKeysFromDatabase();
      foreach ($moduleKeys as $moduleKey) {
         $right = self::getModuleRightName($moduleKey);
         if (self::haveRight($right, READ)) {
            return true;
         }
      }

      return false;
   }

   public static function canPurgeModuleDataForModule(string $moduleKey): bool {
      $right = self::getModuleRightName($moduleKey);
      return self::canPurgeModuleData()
         || self::haveRight($right, DELETE)
         || self::haveRight($right, PURGE)
         || self::hasGlobalAdminAccess();
   }

   public static function assertCanViewModule(string $moduleKey): void {
      if (!self::canViewModule($moduleKey)) {
         self::deny(__('Você não tem permissão para visualizar este módulo.', 'nextool'));
      }
   }

   public static function assertCanManageModule(string $moduleKey): void {
      if (!self::canManageModule($moduleKey)) {
         self::deny(__('Você não tem permissão para gerenciar este módulo.', 'nextool'));
      }
   }

   public static function assertCanPurgeModuleDataForModule(string $moduleKey): void {
      if (!self::canPurgeModuleDataForModule($moduleKey)) {
         self::deny(__('Você não tem permissão para apagar os dados deste módulo.', 'nextool'));
      }
   }

   public static function syncModuleRights(?array $moduleKeys = null): void {
      $moduleKeys = $moduleKeys ?? self::getModuleKeysFromDatabase();
      if (empty($moduleKeys)) {
         return;
      }

      $migration = new Migration(plugin_version_nextool()['version'] ?? '1.0.0');
      $changes = false;
      foreach ($moduleKeys as $moduleKey) {
         $normalized = self::normalizeModuleKey($moduleKey);
         if ($normalized === '') {
            continue;
         }
         $rightName = self::getModuleRightName($normalized);
         if (isset(self::$syncedModuleRights[$rightName])) {
            continue;
         }

         $changes = $changes || self::ensureRightExists($rightName, $migration, [
             self::RIGHT_MODULES => UPDATE,
             Config::$rightname  => UPDATE,
          ]);
          self::$syncedModuleRights[$rightName] = true;
      }
      if ($changes) {
         $migration->executeMigration();
      }
      self::sanitizeRegisteredRights();
   }

   /**
    * @return array<int,array{key:string,label:string,right:string}>
    */
   public static function getModuleRightsMetadata(): array {
      $modules = self::getModulesMetadata();
      $entries = [];
      foreach ($modules as $module) {
         $entries[] = [
            'key'   => $module['key'],
            'label' => $module['name'],
            'right' => self::getModuleRightName($module['key']),
         ];
      }

      return $entries;
   }

   private static function deny(string $message): void {
      Session::addMessageAfterRedirect($message, false, ERROR);
      Html::back();
      exit;
   }

   private static function rightExists(string $rightName): bool {
      return (bool) countElementsInTable('glpi_profilerights', ['name' => $rightName]);
   }

   private static function ensureRightExists(string $rightName, Migration $migration, array $inherit = []): bool {
      $isNew = false;
      if (!self::rightExists($rightName)) {
         ProfileRight::addProfileRights([$rightName]);
         $isNew = true;
      }

      if ($isNew) {
         $migration->addRight($rightName, self::NEXTTOOL_RIGHT_MASK, $inherit);
      }

      return $isNew;
   }

   private static function removeAllModuleRights(): void {
      global $DB;
      if (!$DB->tableExists('glpi_profilerights')) {
         return;
      }

      $DB->delete(
         'glpi_profilerights',
         ['name' => ['LIKE', self::MODULE_RIGHT_PREFIX . '%']]
      );
   }

   private static function getModuleKeysFromDatabase(): array {
      global $DB;
      $keys = [];

      if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         return $keys;
      }

      $iterator = $DB->request([
         'SELECT' => ['module_key'],
         'FROM'   => 'glpi_plugin_nextool_main_modules',
         'WHERE'  => [],
      ]);

      foreach ($iterator as $row) {
         $normalized = self::normalizeModuleKey($row['module_key'] ?? '');
         if ($normalized !== '') {
            $keys[] = $normalized;
         }
      }

      return $keys;
   }

   /**
    * @return array<int,array{key:string,name:string}>
    */
   private static function getModulesMetadata(): array {
      global $DB;
      $metadata = [];

      if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         return $metadata;
      }

      $iterator = $DB->request([
         'SELECT' => ['module_key', 'name'],
         'FROM'   => 'glpi_plugin_nextool_main_modules',
         'ORDER'  => 'name ASC',
      ]);

      foreach ($iterator as $row) {
         $key = self::normalizeModuleKey($row['module_key'] ?? '');
         if ($key === '') {
            continue;
         }
         $metadata[] = [
            'key'  => $key,
            'name' => $row['name'] ?: ucfirst($key),
         ];
      }

      return $metadata;
   }

   public static function getModuleRightName(string $moduleKey): string {
      return self::MODULE_RIGHT_PREFIX . self::normalizeModuleKey($moduleKey);
   }

   /**
    * Verifica se o perfil ativo possui determinado direito do NexTool.
    *
    * Para perfis "central", Session::haveRight() funciona normalmente.
    * Para perfis "helpdesk", o GLPI remove da sessão todos os rights que
    * não estejam na whitelist Profile::$helpdesk_rights (cleanProfile()),
    * então os direitos de plugins são perdidos. Nesse caso, consultamos
    * diretamente a tabela glpi_profilerights com cache local.
    *
    * @param string $rightName Nome do direito (ex: plugin_nextool_module_[module_key])
    * @param int    $rightBit  Bitmask a verificar (READ, UPDATE, DELETE, PURGE)
    * @return bool
    */
   private static function haveRight(string $rightName, int $rightBit): bool {
      // Tentativa rápida via sessão (funciona para perfis central)
      if (Session::haveRight($rightName, $rightBit)) {
         return true;
      }

      // Se não é helpdesk, não há fallback — realmente não tem permissão
      if (($_SESSION['glpiactiveprofile']['interface'] ?? 'central') !== 'helpdesk') {
         return false;
      }

      // Helpdesk: consulta diretamente o banco (com cache)
      $profilesId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
      if ($profilesId <= 0) {
         return false;
      }

      $cacheKey = $profilesId . ':' . $rightName;
      if (!isset(self::$helpdeskRightsCache[$cacheKey])) {
         global $DB;
         $row = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
               'profiles_id' => $profilesId,
               'name'        => $rightName,
            ],
            'LIMIT'  => 1,
         ])->current();
         self::$helpdeskRightsCache[$cacheKey] = (int) ($row['rights'] ?? 0);
      }

      return (bool) (self::$helpdeskRightsCache[$cacheKey] & $rightBit);
   }

   private static function normalizeModuleKey(string $moduleKey): string {
      return strtolower(trim($moduleKey));
   }

   /**
    * Garante que nenhum direito do Nextool tenha bits fora da máscara NEXTTOOL_RIGHT_MASK.
    */
   private static function sanitizeRegisteredRights(): void {
      self::sanitizeBits(self::getAllRegisteredRightNames());
   }

   /**
    * @return array<int,string>
    */
   private static function getAllRegisteredRightNames(): array {
      global $DB;

      $names = [self::RIGHT_MODULES, self::RIGHT_ADMIN_TABS];
      if (!$DB->tableExists('glpi_profilerights')) {
         return $names;
      }

      $iterator = $DB->request([
         'SELECT'   => ['name'],
         'DISTINCT' => true,
         'FROM'     => 'glpi_profilerights',
         'WHERE'    => [
            'name' => ['LIKE', self::MODULE_RIGHT_PREFIX . '%'],
         ],
      ]);

      foreach ($iterator as $row) {
         $name = (string) ($row['name'] ?? '');
         if ($name !== '') {
            $names[] = $name;
         }
      }

      return array_values(array_unique($names));
   }

   /**
    * Remove bits fora da máscara NEXTTOOL_RIGHT_MASK dos direitos Nextool em glpi_profilerights.
    * Uso de $DB->update: ProfileRight não expõe API para alterar bits individuais;
    * esta atualização em lote é exceção documentada (ver PERMISSIONS.md / knowledge base).
    *
    * @param array<int,string> $rightNames
    */
   private static function sanitizeBits(array $rightNames): void {
      global $DB;

      if (
         empty($rightNames)
         || !$DB->tableExists('glpi_profilerights')
      ) {
         return;
      }

      $iterator = $DB->request([
         'SELECT' => ['id', 'name', 'rights'],
         'FROM'   => 'glpi_profilerights',
         'WHERE'  => ['name' => $rightNames],
      ]);

      foreach ($iterator as $row) {
         $currentRights = (int) ($row['rights'] ?? 0);
         $normalized    = $currentRights & self::NEXTTOOL_RIGHT_MASK;
         if ($normalized === $currentRights) {
            continue;
         }

         $DB->update(
            'glpi_profilerights',
            ['rights' => $normalized],
            ['id' => $row['id']]
         );
      }
   }
}

