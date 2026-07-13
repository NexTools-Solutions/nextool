<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Permission Manager
 * -------------------------------------------------------------------------
 * Modelo de permissoes NexTool (2026-07). Ver spec normativa em PERMISSIONS.md.
 *
 * Estrutura:
 *   - nextool_admin_global : super-direito (acesso total ao ecossistema).
 *   - nextool_base         : plugin base (catalogo, licenca, logs, core, contato).
 *   - plugin_nextool_module_<key> : 1 direito por modulo, acoes = bits (colunas).
 *
 * Bits do modulo: ACCESS(=READ) + uso proprio (1<<10..1<<19) + admin FIXO
 * (CONFIGURE=UPDATE, PURGE_DATA=PURGE, MANAGE_ITEMS, TOGGLE, MOD_UPDATE, UNINSTALL, VIEW_LOGS).
 *
 * Compat: os metodos canViewModule/canManageModule/... sao mantidos como SHIMS
 * mapeados para os bits novos, para os 36 modulos migrarem um a um (Fase B).
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

class PluginNextoolPermissionManager {

   // ---- Direitos ----
   public const RIGHT_ADMIN_GLOBAL = 'nextool_admin_global';
   public const RIGHT_BASE         = 'nextool_base';
   private const MODULE_RIGHT_PREFIX = 'plugin_nextool_module_';

   // Direitos legados (consolidados no base; mantidos so para a migracao removê-los)
   public const RIGHT_MODULES    = 'plugin_nextool_modules';
   public const RIGHT_ADMIN_TABS = 'plugin_nextool_admin';

   // ---- Bits do super-direito ----
   public const GLOBAL_BIT = 1; // READ em nextool_admin_global

   // ---- Bits: Usabilidade (direito de modulo) ----
   public const ACCESS = 1;            // = READ. "Acessar o recurso"
   // Acoes de uso do modulo: 1<<10 .. 1<<19 (declaradas pelo modulo em getProfileRights())
   public const USE_MASK = 0x3FF << 10; // bits 10..19

   // ---- Bits: Administracao do modulo (FIXOS, todos >= 1<<20) ----
   // IMPORTANTE (ordem das colunas): Profile::displayRightsChoiceMatrix faz uksort das
   // colunas -> bits < 1024 vem PRIMEIRO, ordenados por valor; bits >= 1024 vem depois,
   // na ORDEM DE INSERCAO do array. Por isso admin fica todo em bits altos (>= 1<<20) e
   // ACCESS=1 e o unico < 1024 (1a coluna). Assim getProfileRights() (uso inserido antes
   // do admin) rende a matriz agrupada: uso-esquerda (azul) / admin-direita (ambar).
   public const CONFIGURE    = 1 << 20; // "Configurar"
   public const MANAGE_ITEMS = 1 << 21; // "Gerenciar itens" (so modulos com catalogo)
   public const TOGGLE       = 1 << 22; // "Ativar / Desativar"
   public const MOD_UPDATE   = 1 << 23; // "Atualizar"
   public const UNINSTALL    = 1 << 24; // "Desinstalar"
   public const VIEW_LOGS    = 1 << 25; // "Ver logs"
   public const PURGE_DATA   = 1 << 26; // "Apagar dados"

   // Legado de uso ainda em circulacao (autentique/digitalsignature): CREATE=4, DELETE=8
   private const LEGACY_USE = 4 | 8;

   // Mascara completa de bits validos num direito de modulo.
   public const MODULE_MASK = self::ACCESS | self::CONFIGURE | self::PURGE_DATA
      | self::MANAGE_ITEMS | self::TOGGLE | self::MOD_UPDATE | self::UNINSTALL | self::VIEW_LOGS
      | self::USE_MASK | self::LEGACY_USE;

   // ---- Bits do direito base (nextool_base) ----
   public const BASE_VIEW_CATALOG  = 1 << 0;
   public const BASE_INSTALL       = 1 << 1;
   public const BASE_SYNC_CATALOG  = 1 << 2;
   public const BASE_VIEW_LICENSE  = 1 << 3;
   public const BASE_SYNC_LICENSE  = 1 << 4;
   public const BASE_ACCEPT_POLICY = 1 << 5;
   public const BASE_VIEW_LOGS     = 1 << 6;
   public const BASE_EXPORT_LOGS   = 1 << 7;
   public const BASE_VIEW_VERSION  = 1 << 8;
   public const BASE_APPLY_UPDATE  = 1 << 9;
   public const BASE_SEND_CONTACT  = 1 << 10;
   public const BASE_MASK = (1 << 11) - 1; // bits 0..10

   /** @var array<string,bool> */
   private static array $syncedModuleRights = [];

   /**
    * Cache local de direitos carregados do banco (fallback helpdesk).
    * Chave = "profiles_id:rightName", valor = int (bitmask).
    * @var array<string,int>
    */
   private static array $helpdeskRightsCache = [];

   // =====================================================================
   // Super-direito e base
   // =====================================================================

   /**
    * Acesso administrativo global ao ecossistema NexTool.
    * NOVO: usa o direito proprio nextool_admin_global (NAO mais config/UPDATE).
    * A migracao concede admin_global a quem tinha config/UPDATE (Super-Admin intacto).
    */
   public static function hasGlobalAdminAccess(): bool {
      return self::haveRight(self::RIGHT_ADMIN_GLOBAL, self::GLOBAL_BIT);
   }

   /** Alias semantico. */
   public static function canGlobalAdmin(): bool {
      return self::hasGlobalAdminAccess();
   }

   /** Verifica um bit do direito base (nextool_base). */
   public static function canBase(int $bit): bool {
      return self::hasGlobalAdminAccess() || self::haveRight(self::RIGHT_BASE, $bit);
   }

   /** Possui qualquer permissao no bloco base. */
   public static function canBaseAny(): bool {
      return self::canBase(self::BASE_MASK);
   }

   public static function assertCanBase(int $bit): void {
      if (!self::canBase($bit)) {
         self::deny(__('Você não tem permissão para esta ação do NexTool.', 'nextool'));
      }
   }

   // =====================================================================
   // Modulos: usabilidade e administracao
   // =====================================================================

   private static function haveModuleRight(string $moduleKey, int $bit): bool {
      return self::haveRight(self::getModuleRightName($moduleKey), $bit);
   }

   /** Usabilidade: o perfil pode USAR (bit de uso) este modulo. */
   public static function canUse(string $moduleKey, int $bit = self::ACCESS): bool {
      return self::hasGlobalAdminAccess() || self::haveModuleRight($moduleKey, $bit);
   }

   /** Administracao: o perfil pode ADMINISTRAR (bit de admin) este modulo. */
   public static function canAdmin(string $moduleKey, int $bit): bool {
      return self::hasGlobalAdminAccess() || self::haveModuleRight($moduleKey, $bit);
   }

   public static function assertCanUse(string $moduleKey, int $bit = self::ACCESS): void {
      if (!self::canUse($moduleKey, $bit)) {
         self::deny(__('Você não tem permissão para usar este módulo.', 'nextool'));
      }
   }

   public static function assertCanAdmin(string $moduleKey, int $bit): void {
      if (!self::canAdmin($moduleKey, $bit)) {
         self::deny(__('Você não tem permissão para administrar este módulo.', 'nextool'));
      }
   }

   /**
    * O perfil tem acesso a PELO MENOS um modulo (para decidir se mostra o menu/aba).
    */
   public static function canViewAnyModule(): bool {
      if (self::hasGlobalAdminAccess()) {
         return true;
      }
      foreach (self::getModuleKeysFromDatabase() as $moduleKey) {
         if (self::haveModuleRight($moduleKey, self::ACCESS)) {
            return true;
         }
      }
      return false;
   }

   // =====================================================================
   // SHIMS de compatibilidade (Fase B migra os modulos para canUse/canAdmin)
   // =====================================================================

   /** @deprecated usar canUse(key, ACCESS) */
   public static function canViewModule(string $moduleKey): bool {
      return self::canUse($moduleKey, self::ACCESS);
   }
   /** @deprecated usar canAdmin(key, CONFIGURE) */
   public static function canManageModule(string $moduleKey): bool {
      return self::canAdmin($moduleKey, self::CONFIGURE);
   }
   /** @deprecated uso legado CREATE; migrar para bit de uso proprio */
   public static function canCreateInModule(string $moduleKey): bool {
      return self::hasGlobalAdminAccess() || self::haveModuleRight($moduleKey, 4 /*CREATE*/);
   }
   /** @deprecated uso legado DELETE; migrar para bit de uso proprio */
   public static function canDeleteInModule(string $moduleKey): bool {
      return self::hasGlobalAdminAccess() || self::haveModuleRight($moduleKey, 8 /*DELETE*/);
   }
   public static function canPurgeModuleDataForModule(string $moduleKey): bool {
      return self::canAdmin($moduleKey, self::PURGE_DATA);
   }
   /** @deprecated o "modulos global" antigo nao existe mais; equivale a ter acesso a algum */
   public static function canViewModules(): bool {
      return self::canViewAnyModule();
   }
   /** @deprecated gerenciar "todos" agora e admin global */
   public static function canManageModules(): bool {
      return self::hasGlobalAdminAccess();
   }
   public static function canPurgeModuleData(): bool {
      return self::hasGlobalAdminAccess();
   }
   /** @deprecated "abas administrativas" viraram o bloco base */
   public static function canAccessAdminTabs(): bool {
      return self::canBaseAny();
   }
   public static function canManageAdminTabs(): bool {
      return self::hasGlobalAdminAccess()
         || self::haveRight(self::RIGHT_BASE, self::BASE_SYNC_LICENSE | self::BASE_ACCEPT_POLICY | self::BASE_APPLY_UPDATE);
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
   public static function assertCanCreateInModule(string $moduleKey): void {
      if (!self::canCreateInModule($moduleKey)) {
         self::deny(__('Você não tem permissão para criar registros neste módulo.', 'nextool'));
      }
   }
   public static function assertCanAccessAdminTabs(): void {
      if (!self::canAccessAdminTabs()) {
         self::deny(__('Você não tem permissão para as configurações do NexTool.', 'nextool'));
      }
   }
   public static function assertCanManageAdminTabs(): void {
      if (!self::canManageAdminTabs()) {
         self::deny(__('Você não tem permissão para gerenciar configurações do NexTool.', 'nextool'));
      }
   }

   // =====================================================================
   // Install / Sync / Remove / Migracao
   // =====================================================================

   public static function installRights(): void {
      $migration = new Migration(plugin_version_nextool()['version'] ?? '1.0.0');

      // Cria os direitos novos. admin_global e base herdam de quem tem config/UPDATE.
      self::ensureRightExists(self::RIGHT_ADMIN_GLOBAL, $migration, [Config::$rightname => UPDATE], self::GLOBAL_BIT);
      self::ensureRightExists(self::RIGHT_BASE, $migration, [Config::$rightname => UPDATE], self::BASE_MASK);

      $migration->executeMigration();
      self::migrateLegacyRights();

      // Apos conceder os direitos no banco, recarrega-os na sessao ATIVA: o admin que
      // acabou de instalar recebe o super-direito no perfil, mas na interface central o
      // gate le da sessao (nao do banco) -- sem isto so valeria apos deslogar/relogar.
      self::reloadActiveProfileRights();
   }

   /**
    * Recarrega os direitos NexTool do perfil ativo do banco para a sessao, apos
    * concede-los no install/upgrade. Cirurgico: atualiza so os direitos 'nextool*' e
    * 'plugin_nextool*' -- NAO reseta entidades ativas nem dispara hooks (ao contrario de
    * Session::changeProfile). No-op quando nao ha sessao (ex.: instalacao via CLI).
    */
   public static function reloadActiveProfileRights(): void {
      $pid = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
      if ($pid <= 0) {
         return;
      }
      global $DB;
      $rows = $DB->request([
         'SELECT' => ['name', 'rights'],
         'FROM'   => 'glpi_profilerights',
         'WHERE'  => [
            'profiles_id' => $pid,
            'OR'          => [
               ['name' => ['LIKE', 'nextool%']],
               ['name' => ['LIKE', 'plugin_nextool%']],
            ],
         ],
      ]);
      foreach ($rows as $row) {
         $_SESSION['glpiactiveprofile'][$row['name']] = (int) $row['rights'];
      }
   }

   public static function removeRights(): void {
      ProfileRight::deleteProfileRights([
         self::RIGHT_ADMIN_GLOBAL, self::RIGHT_BASE,
         self::RIGHT_MODULES, self::RIGHT_ADMIN_TABS,
      ]);
      self::removeAllModuleRights();
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
         // Novos direitos de modulo herdam admin/uso de quem tem admin_global.
         $changes = self::ensureRightExists($rightName, $migration, [
            self::RIGHT_ADMIN_GLOBAL => self::GLOBAL_BIT,
            Config::$rightname       => UPDATE,
         ], self::MODULE_MASK) || $changes;
         self::$syncedModuleRights[$rightName] = true;
      }
      if ($changes) {
         $migration->executeMigration();
      }
   }

   /**
    * Migracao dos direitos legados para o novo esquema (idempotente).
    * - Concede nextool_admin_global a todo perfil com config/UPDATE (Super-Admin intacto).
    * - Migra plugin_nextool_admin/modules -> nextool_base (aproximado, conservador).
    * Os direitos de modulo NAO precisam migracao de bits (READ/UPDATE/PURGE reaproveitados).
    */
   public static function migrateLegacyRights(): void {
      global $DB;
      if (!$DB->tableExists('glpi_profilerights')) {
         return;
      }

      // 1) admin_global para quem tem config/UPDATE
      $iterator = $DB->request([
         'SELECT' => ['profiles_id'],
         'FROM'   => 'glpi_profilerights',
         'WHERE'  => ['name' => Config::$rightname, 'RAW' => ['(rights & ' . UPDATE . ')' => UPDATE]],
      ]);
      foreach ($iterator as $row) {
         self::grantBit((int) $row['profiles_id'], self::RIGHT_ADMIN_GLOBAL, self::GLOBAL_BIT);
      }

      // 2) plugin_nextool_admin -> nextool_base (licenca/logs/core/contato)
      $adminMap = READ | UPDATE; // qualquer nivel antigo -> acesso amplo ao base
      self::copyRightBits(self::RIGHT_ADMIN_TABS, self::RIGHT_BASE,
         static function (int $old): int {
            $out = 0;
            if ($old & READ) {
               $out |= self::BASE_VIEW_LICENSE | self::BASE_VIEW_LOGS | self::BASE_VIEW_VERSION;
            }
            if ($old & UPDATE) {
               $out |= self::BASE_SYNC_LICENSE | self::BASE_ACCEPT_POLICY | self::BASE_APPLY_UPDATE
                  | self::BASE_SEND_CONTACT | self::BASE_EXPORT_LOGS;
            }
            return $out;
         });

      // 3) plugin_nextool_modules -> nextool_base (catalogo)
      self::copyRightBits(self::RIGHT_MODULES, self::RIGHT_BASE,
         static function (int $old): int {
            $out = 0;
            if ($old & READ)   { $out |= self::BASE_VIEW_CATALOG; }
            if ($old & UPDATE) { $out |= self::BASE_INSTALL | self::BASE_SYNC_CATALOG; }
            return $out;
         });

      // 3b) Migracao concluida: remove os direitos LEGADOS (plugin_nextool_admin e
      //     plugin_nextool_modules), ja copiados para nextool_base via grantBit/OR nos passos
      //     2-3. Sem isso ficam orfaos na matriz de perfil. Os copyRightBits acima rodam ANTES,
      //     entao nenhum bit se perde; idempotente (no-op se ja removidos). Nao ha uso ativo
      //     desses direitos no runtime -- o modelo P1 usa nextool_base.
      $DB->delete('glpi_profilerights', ['name' => [self::RIGHT_ADMIN_TABS, self::RIGHT_MODULES]]);

      // 4) Realinha bits de admin dos direitos de modulo ao contrato atual (admin >= 1<<20).
      //    Versoes anteriores usaram CONFIGURE=2 e PURGE_DATA=16 (bits < 1024), que o GLPI
      //    ordena ANTES do uso na matriz. Move 2 -> CONFIGURE(1<<20) e 16 -> PURGE_DATA(1<<26).
      self::realignModuleAdminBits();
   }

   /**
    * Move os bits de admin legados (CONFIGURE=2, PURGE_DATA=16) dos direitos de modulo
    * para as posicoes atuais (1<<20 / 1<<26). Idempotente. Preserva ACCESS/uso/ciclo de vida.
    */
   private static function realignModuleAdminBits(): void {
      global $DB;
      if (!$DB->tableExists('glpi_profilerights')) {
         return;
      }
      $oldConfigure = 2;
      $oldPurge     = 16;
      $iterator = $DB->request([
         'SELECT' => ['id', 'rights'],
         'FROM'   => 'glpi_profilerights',
         'WHERE'  => ['name' => ['LIKE', self::MODULE_RIGHT_PREFIX . '%']],
      ]);
      foreach ($iterator as $row) {
         $current = (int) $row['rights'];
         $new = $current;
         if ($new & $oldConfigure) { $new = ($new & ~$oldConfigure) | self::CONFIGURE; }
         if ($new & $oldPurge)     { $new = ($new & ~$oldPurge) | self::PURGE_DATA; }
         if ($new !== $current) {
            $DB->update('glpi_profilerights', ['rights' => $new], ['id' => (int) $row['id']]);
         }
      }
   }

   // =====================================================================
   // Metadata (consumido pela tela de perfil)
   // =====================================================================

   /**
    * @return array<int,array{key:string,label:string,right:string}>
    */
   public static function getModuleRightsMetadata(): array {
      $entries = [];
      foreach (self::getModulesMetadata() as $module) {
         $entries[] = [
            'key'   => $module['key'],
            'label' => $module['name'],
            'right' => self::getModuleRightName($module['key']),
         ];
      }
      return $entries;
   }

   /** Rotulos dos bits do direito base (para a matriz do bloco base). */
   public static function getBaseRights(): array {
      return [
         self::BASE_VIEW_CATALOG  => __('Ver catálogo', 'nextool'),
         self::BASE_INSTALL       => __('Instalar módulo', 'nextool'),
         self::BASE_SYNC_CATALOG  => __('Sincronizar catálogo', 'nextool'),
         self::BASE_VIEW_LICENSE  => __('Ver licença', 'nextool'),
         self::BASE_SYNC_LICENSE  => __('Sincronizar licença', 'nextool'),
         self::BASE_ACCEPT_POLICY => __('Aceitar políticas', 'nextool'),
         self::BASE_VIEW_LOGS     => __('Ver logs', 'nextool'),
         self::BASE_EXPORT_LOGS   => __('Exportar logs', 'nextool'),
         self::BASE_VIEW_VERSION  => __('Ver versão', 'nextool'),
         self::BASE_APPLY_UPDATE  => __('Aplicar atualização', 'nextool'),
         self::BASE_SEND_CONTACT  => __('Enviar contato', 'nextool'),
      ];
   }

   /**
    * Rotulos padrao de administracao (contrato fixo). Consumido pelo BaseModule.
    * @param bool $hasCatalog inclui "Gerenciar itens"
    * @return array<int,string>
    */
   public static function getAdminRightLabels(bool $hasCatalog): array {
      $rights = [self::CONFIGURE => __('Configurar', 'nextool')];
      if ($hasCatalog) {
         $rights[self::MANAGE_ITEMS] = __('Gerenciar itens', 'nextool');
      }
      $rights[self::TOGGLE]     = __('Ativar / Desativar', 'nextool');
      $rights[self::MOD_UPDATE] = __('Atualizar', 'nextool');
      $rights[self::UNINSTALL]  = __('Desinstalar', 'nextool');
      $rights[self::VIEW_LOGS]  = __('Ver logs', 'nextool');
      $rights[self::PURGE_DATA] = __('Apagar dados', 'nextool');
      return $rights;
   }

   /**
    * Classifica um bit de um direito NexTool em familia visual: 'use' | 'admin' | 'global'.
    * Consumido pela tela de perfil para colorir as colunas (uso=azul, admin=ambar).
    */
   public static function bitFamily(string $field, int $bit): string {
      if ($field === self::RIGHT_ADMIN_GLOBAL) {
         return 'global';
      }
      if ($field === self::RIGHT_BASE) {
         $useBits = self::BASE_VIEW_CATALOG | self::BASE_VIEW_LICENSE | self::BASE_VIEW_LOGS
            | self::BASE_VIEW_VERSION | self::BASE_SEND_CONTACT;
         return ($bit & $useBits) ? 'use' : 'admin';
      }
      // Direito de modulo: admin = Configurar/Apagar dados/ciclo de vida; resto = uso.
      $adminBits = self::CONFIGURE | self::PURGE_DATA | self::MANAGE_ITEMS | self::TOGGLE
         | self::MOD_UPDATE | self::UNINSTALL | self::VIEW_LOGS;
      return ($bit & $adminBits) ? 'admin' : 'use';
   }

   // =====================================================================
   // Internos
   // =====================================================================

   public static function getModuleRightName(string $moduleKey): string {
      return self::MODULE_RIGHT_PREFIX . self::normalizeModuleKey($moduleKey);
   }

   /**
    * Verifica um bit de um direito NexTool no perfil ativo, com fallback helpdesk.
    * (cleanProfile() remove direitos de plugin da sessao helpdesk; consultamos o banco.)
    */
   public static function haveRight(string $rightName, int $rightBit): bool {
      if (Session::haveRight($rightName, $rightBit)) {
         return true;
      }
      if (($_SESSION['glpiactiveprofile']['interface'] ?? 'central') !== 'helpdesk') {
         return false;
      }
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
            'WHERE'  => ['profiles_id' => $profilesId, 'name' => $rightName],
            'LIMIT'  => 1,
         ])->current();
         self::$helpdeskRightsCache[$cacheKey] = (int) ($row['rights'] ?? 0);
      }
      return (bool) (self::$helpdeskRightsCache[$cacheKey] & $rightBit);
   }

   private static function deny(string $message): void {
      Session::addMessageAfterRedirect($message, false, ERROR);
      Html::back();
      exit;
   }

   private static function rightExists(string $rightName): bool {
      return (bool) countElementsInTable('glpi_profilerights', ['name' => $rightName]);
   }

   /**
    * Garante que o direito exista em glpi_profilerights. Se novo, aplica heranca.
    * $mask limita o valor herdado aos bits validos.
    */
   private static function ensureRightExists(string $rightName, Migration $migration, array $inherit, int $mask): bool {
      if (self::rightExists($rightName)) {
         return false;
      }
      try {
         ProfileRight::addProfileRights([$rightName]);
      } catch (\Throwable $e) {
         $code = method_exists($e, 'getCode') ? (int) $e->getCode() : 0;
         if ($code !== 1062 && strpos($e->getMessage(), '1062') === false && strpos($e->getMessage(), 'Duplicate entry') === false) {
            throw $e;
         }
         Toolbox::logInFile('plugin_nextool', "PermissionManager: direito {$rightName} já existia (1062 ignorado).\n");
         return false;
      }
      $migration->addRight($rightName, $mask, $inherit);
      return true;
   }

   private static function removeAllModuleRights(): void {
      global $DB;
      if (!$DB->tableExists('glpi_profilerights')) {
         return;
      }
      $DB->delete('glpi_profilerights', ['name' => ['LIKE', self::MODULE_RIGHT_PREFIX . '%']]);
   }

   /**
    * Remove o direito unico de UM modulo (`plugin_nextool_module_<key>`) de todos os
    * perfis. Chamado no uninstall do modulo para o direito nao ficar orfao na matriz de
    * perfil quando o modulo sai. Os DADOS/tabelas do modulo sao preservados (design de
    * uninstall) -- so a permissao e removida; o syncModuleRights recria o direito (bits
    * zerados) se o modulo for reinstalado. Idempotente (no-op se ja nao existe).
    */
   public static function removeModuleRight(string $moduleKey): void {
      global $DB;
      if (!$DB->tableExists('glpi_profilerights')) {
         return;
      }
      $normalized = self::normalizeModuleKey($moduleKey);
      if ($normalized === '') {
         return;
      }
      $rightName = self::getModuleRightName($normalized);
      $DB->delete('glpi_profilerights', ['name' => $rightName]);
      unset(self::$syncedModuleRights[$rightName]);
   }

   /** Concede (OR) um bit a um direito de um perfil, sem apagar bits existentes. */
   private static function grantBit(int $profilesId, string $rightName, int $bit): void {
      global $DB;
      $row = $DB->request([
         'SELECT' => ['id', 'rights'],
         'FROM'   => 'glpi_profilerights',
         'WHERE'  => ['profiles_id' => $profilesId, 'name' => $rightName],
         'LIMIT'  => 1,
      ])->current();
      if ($row === null) {
         $DB->insert('glpi_profilerights', ['profiles_id' => $profilesId, 'name' => $rightName, 'rights' => $bit]);
         return;
      }
      $current = (int) $row['rights'];
      if (($current & $bit) !== $bit) {
         $DB->update('glpi_profilerights', ['rights' => $current | $bit], ['id' => (int) $row['id']]);
      }
   }

   /** Copia bits de um direito antigo para um novo, por perfil, via callback de mapeamento. */
   private static function copyRightBits(string $from, string $to, callable $map): void {
      global $DB;
      $iterator = $DB->request([
         'SELECT' => ['profiles_id', 'rights'],
         'FROM'   => 'glpi_profilerights',
         'WHERE'  => ['name' => $from],
      ]);
      foreach ($iterator as $row) {
         $mapped = (int) $map((int) $row['rights']);
         if ($mapped !== 0) {
            self::grantBit((int) $row['profiles_id'], $to, $mapped);
         }
      }
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
         $metadata[] = ['key' => $key, 'name' => $row['name'] ?: ucfirst($key)];
      }
      return $metadata;
   }

   private static function normalizeModuleKey(string $moduleKey): string {
      return strtolower(trim($moduleKey));
   }
}
