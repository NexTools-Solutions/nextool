<?php
/**
 * NexTool -- Store key-value por modulo (tabela `glpi_plugin_nextool_<mod>_configs`).
 *
 * Ate a 6.15.0 cada modulo que precisava de configuracao alem do JSON do catalogo
 * (segredos cifrados, estado de saude, marcadores de migracao) copiava a mesma
 * classe: `ensureTable` + `loadAll` + `saveAll`. As copias divergiram -- uma
 * perdeu os overrides de ACL, outra fazia 2 queries por chave mesmo sem mudanca
 * (audit-deep do digitalsignature 2026-09-06, nextool-dev#255). Aqui fica a
 * versao unica; o modulo declara so a chave:
 *
 *    class PluginNextoolFooSetting extends PluginNextoolModuleSettings {
 *       protected const MODULE_KEY = 'foo';
 *    }
 *
 * Garantias:
 *  - ACL correta mesmo se a classe for instanciada por search/form do GLPI:
 *    ler/gravar exige CONFIGURE do modulo; purgar exige PURGE_DATA (P1).
 *  - cache por REQUEST e por modulo: N chamadas a loadAll() custam 1 SELECT.
 *    Quem escreve na tabela por fora (E2E, restore) chama resetCache().
 *  - saveAll() so toca no banco o que MUDOU (1 UPDATE/INSERT por chave alterada,
 *    zero para valor igual) e atualiza o cache em vez de invalida-lo.
 *
 * @since 6.16.0
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

abstract class PluginNextoolModuleSettings extends CommonDBTM {

   /** Direito tratado pelo PermissionManager do modulo, nao pelo core. */
   public static $rightname = '';

   /** A subclasse DECLARA a chave do modulo (pasta = chave). */
   protected const MODULE_KEY = '';

   /** Cache por request, POR MODULO (propriedade estatica e compartilhada entre subclasses). */
   private static array $cache = [];

   /**
    * Chave do modulo. Vazia na propria classe abstrata -- e NUNCA lanca: o
    * plugin_init_nextool varre as classes CommonDBTM declaradas chamando
    * getTable() de cada uma (mapa tabela->itemtype), e uma excecao ali cai no
    * try/catch do init e derruba a instalacao de TODOS os hooks do dispatcher
    * em silencio (aconteceu em 2026-09-07: aprovacao do workflow parou no CLI).
    */
   public static function moduleKey(): string {
      return (string)static::MODULE_KEY;
   }

   public static function getTable($classname = null) {
      $key = static::moduleKey();
      return $key === '' ? '' : 'glpi_plugin_nextool_' . $key . '_configs';
   }

   /** Guarda dos metodos que precisam da chave (subclasse sem MODULE_KEY e bug de programacao). */
   private static function requireKey(): string {
      $key = static::moduleKey();
      if ($key === '') {
         throw new LogicException(static::class . ' precisa declarar MODULE_KEY');
      }
      return $key;
   }

   // --- ACL (P1): configuracao e ADMIN do modulo -------------------------------
   public static function canView(): bool   { return self::adminBit(PluginNextoolPermissionManager::CONFIGURE); }
   public static function canCreate(): bool { return self::adminBit(PluginNextoolPermissionManager::CONFIGURE); }
   public static function canUpdate(): bool { return self::adminBit(PluginNextoolPermissionManager::CONFIGURE); }
   public static function canDelete(): bool { return self::adminBit(PluginNextoolPermissionManager::CONFIGURE); }
   public static function canPurge(): bool  { return self::adminBit(PluginNextoolPermissionManager::PURGE_DATA); }

   private static function adminBit(int $bit): bool {
      require_once __DIR__ . '/permissionmanager.class.php';
      return PluginNextoolPermissionManager::canAdmin(static::requireKey(), $bit);
   }

   /** Para o E2E e para quem escreve na tabela por fora (restore, migracao SQL). */
   public static function resetCache(): void {
      unset(self::$cache[static::moduleKey()]);
   }

   /** @return array<string,?string> nome => valor (cru, como esta no banco) */
   public static function loadAll(): array {
      global $DB;

      $key = static::requireKey();
      if (isset(self::$cache[$key])) {
         return self::$cache[$key];
      }

      static::ensureTable();

      $settings = [];
      foreach ($DB->request(['FROM' => static::getTable()]) as $row) {
         $settings[(string)$row['name']] = $row['value'];
      }
      self::$cache[$key] = $settings;
      return $settings;
   }

   /** Uma chave, com default. */
   public static function get(string $name, $default = null) {
      $all = static::loadAll();
      return array_key_exists($name, $all) ? $all[$name] : $default;
   }

   /**
    * Grava um lote. So o que mudou vai ao banco; devolve quantas chaves foram escritas.
    */
   public static function saveAll(array $values): int {
      global $DB;

      static::ensureTable();

      $key   = static::requireKey();
      $atual = static::loadAll();
      $now   = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
      $n     = 0;

      foreach ($values as $name => $value) {
         $name = (string)$name;
         if (array_key_exists($name, $atual)) {
            if ((string)($atual[$name] ?? '') === (string)($value ?? '')) {
               continue;   // nada mudou: nenhuma escrita
            }
            $DB->update(static::getTable(), ['value' => $value, 'updated_at' => $now], ['name' => $name]);
         } else {
            $DB->insert(static::getTable(), ['name' => $name, 'value' => $value, 'updated_at' => $now]);
         }
         self::$cache[$key][$name] = $value;
         $n++;
      }
      return $n;
   }

   /** Remove chaves (ex.: limpar marcador). */
   public static function deleteKeys(array $names): void {
      global $DB;
      if ($names === [] || !$DB->tableExists(static::getTable())) {
         return;
      }
      $DB->delete(static::getTable(), ['name' => array_values(array_map('strval', $names))]);
      $key = static::moduleKey();
      foreach ($names as $name) {
         unset(self::$cache[$key][(string)$name]);
      }
   }

   /**
    * Cria a tabela se nao existir. O install.sql do modulo normalmente ja a cria;
    * isto cobre instalacao antiga que nunca teve a tabela.
    */
   protected static function ensureTable(): void {
      global $DB;

      $table = static::getTable();
      if ($DB->tableExists($table)) {
         return;
      }

      $charset   = class_exists('DBConnection') ? DBConnection::getDefaultCharset() : 'utf8mb4';
      $collation = class_exists('DBConnection') ? DBConnection::getDefaultCollation() : 'utf8mb4_unicode_ci';

      $sql = "CREATE TABLE IF NOT EXISTS `$table` (
         `id` int unsigned NOT NULL AUTO_INCREMENT,
         `name` varchar(191) NOT NULL,
         `value` longtext NULL,
         `updated_at` timestamp NULL DEFAULT NULL,
         PRIMARY KEY (`id`),
         UNIQUE KEY `unicity` (`name`)
      ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation";

      if (!$DB->doQuery($sql)) {
         $error = method_exists($DB, 'error') ? $DB->error() : 'erro desconhecido';
         Toolbox::logInFile('plugin_nextool_' . static::moduleKey(),
            sprintf("Erro ao criar tabela %s (ModuleSettings): %s\n", $table, $error));
      }
   }
}
