<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module Catalog
 * -------------------------------------------------------------------------
 * Catálogo lê exclusivamente de glpi_plugin_nextool_main_modules (banco).
 * A tabela é populada pela sincronização com o ContainerAPI (aceite dos termos
 * ou botão Sincronizar). Não há lista chumbada no código — novos módulos
 * passam a aparecer após atualização do catálogo no ritecadmin/ContainerAPI
 * e nova sincronização, sem precisar atualizar o plugin.
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

class PluginNextoolModuleCatalog {

   /** Metadados padrão quando a tabela não possui colunas icon/author/has_config/downloadable */
   private const DEFAULT_ICON = 'ti ti-puzzle';
   private const DEFAULT_HAS_CONFIG = true;
   private const DEFAULT_DOWNLOADABLE = true;
   private const DEFAULT_AUTHOR = [
      'name' => 'NexTool Solutions',
      'url'  => 'https://nextoolsolutions.ai',
   ];

   /**
    * Retorna todos os módulos do banco (fonte única da verdade).
    * Tabela vazia ou inexistente → [] (catálogo vem do ContainerAPI no aceite dos termos / sincronizar).
    *
    * @return array
    */
   public static function all(): array {
      global $DB;

      $table = 'glpi_plugin_nextool_main_modules';
      if (!$DB->tableExists($table)) {
         return [];
      }

      $modules = [];
      $iterator = $DB->request([
         'FROM'  => $table,
         'ORDER' => 'name'
      ]);

      if (count($iterator) === 0) {
         return [];
      }

      foreach ($iterator as $row) {
         $moduleKey = $row['module_key'];

         $minVerNextool = $row['min_version_nextools'] ?? null;
         if ($minVerNextool !== null && trim((string)$minVerNextool) === '') {
            $minVerNextool = null;
         } else {
            $minVerNextool = $minVerNextool !== null ? trim((string)$minVerNextool) : null;
         }

         $modules[$moduleKey] = [
            'name'         => $row['name'],
            'description'  => $row['description'] ?? '',
            'version'      => $row['available_version'] ?? $row['version'],
            'icon'         => $row['icon'] ?? self::DEFAULT_ICON,
            'billing_tier' => $row['billing_tier'] ?? 'FREE',
            'has_config'   => isset($row['has_config']) ? (bool)$row['has_config'] : self::DEFAULT_HAS_CONFIG,
            'downloadable' => isset($row['downloadable']) ? (bool)$row['downloadable'] : self::DEFAULT_DOWNLOADABLE,
            'author'       => self::parseAuthor($row['author'] ?? null),
            'is_installed' => (bool)($row['is_installed'] ?? 0),
            'is_enabled'   => (bool)($row['is_enabled'] ?? 0),
            'is_available' => (bool)($row['is_available'] ?? 0),
            'min_version_nextools' => $minVerNextool,
         ];
      }

      return $modules;
   }

   /**
    * @param string|null $authorJson JSON com { "name": "...", "url": "..." }
    * @return array
    */
   private static function parseAuthor($authorJson): array {
      if ($authorJson === null || trim((string)$authorJson) === '') {
         return self::DEFAULT_AUTHOR;
      }
      $decoded = json_decode($authorJson, true);
      if (!is_array($decoded) || empty($decoded['name'])) {
         return self::DEFAULT_AUTHOR;
      }
      return [
         'name' => $decoded['name'],
         'url'  => $decoded['url'] ?? '',
      ];
   }

   /**
    * Busca um módulo específico pelo module_key
    *
    * @param string $moduleKey
    * @return array|null
    */
   public static function find(string $moduleKey): ?array {
      $all = self::all();
      return $all[$moduleKey] ?? null;
   }
}
