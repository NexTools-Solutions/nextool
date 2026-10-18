<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module Catalog
 * -------------------------------------------------------------------------
 * Catálogo lê exclusivamente de glpi_plugin_nextool_main_modules (banco).
 * A tabela é populada pela sincronização com o ContainerAPI (aceite dos termos
 * ou botão Sincronizar). Não há lista chumbada no código - novos módulos
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
      'url'  => 'https://nextoolsolutions.com',
   ];

   /**
    * Módulos INSTALADOS com atualização disponível (#162): available_version > version.
    * Mesma semântica do render do catálogo (config.form.php), incluindo o guard de
    * pasta ausente (sem os arquivos no disco = download pendente, não "update").
    * Nota: all() NÃO serve para este diff -- ele colapsa version/available_version.
    *
    * @return array<string, array{name: string, installed: string, available: string, min_version_nextools: string}>
    */
   public static function getPendingUpdates(): array {
      global $DB;

      $table = 'glpi_plugin_nextool_main_modules';
      if (!$DB->tableExists($table)) {
         return [];
      }
      require_once NEXTOOL_PHP_DIR . '/inc/modulespath.inc.php';

      $pending = [];
      foreach ($DB->request(['FROM' => $table, 'WHERE' => ['is_installed' => 1], 'ORDER' => 'name']) as $row) {
         $moduleKey = (string)($row['module_key'] ?? '');
         $installed = trim((string)($row['version'] ?? ''));
         $available = trim((string)($row['available_version'] ?? ''));
         // Descontinuado não tem atualização a cobrar (saiu de linha).
         if ($moduleKey === '' || $installed === '' || $available === '' || !empty($row['is_discontinued'])
             || !version_compare($available, $installed, '>')
             || !is_dir(NEXTOOL_MODULES_BASE . '/' . $moduleKey)) {
            continue;
         }
         $pending[$moduleKey] = [
            'name'                 => self::localized($row['name_i18n'] ?? null, (string)($row['name'] ?? $moduleKey)),
            'installed'            => $installed,
            'available'            => $available,
            // Base mínima exigida pela versão disponível (PrereqCheck deriva o alerta
            // "atualize o NexTool" daqui, nextool-dev#260). Vazio = sem exigência.
            'min_version_nextools' => trim((string)($row['min_version_nextools'] ?? '')),
         ];
      }
      return $pending;
   }

   /**
    * Retorna todos os módulos do banco (fonte única da verdade).
    * Tabela vazia ou inexistente → [] (catálogo vem do ContainerAPI no aceite dos termos / sincronizar).
    *
    * @return array
    */
   /**
    * Texto no idioma da sessão a partir do JSON {locale: texto} do catálogo (nextool-dev#262). Ordem: idioma exato,
    * mesma família (es_CO usa es_ES; a variante "xx_XX" da família vem antes das outras), inglês (en_US, en_GB)
    * e por fim $fallback (o nome/descrição únicos de sempre, em pt_BR). JSON ausente ou inválido = $fallback.
    */
   public static function localized($json, string $fallback): string {
      if (!is_string($json) || $json === '') {
         return $fallback;
      }
      $map = json_decode($json, true);
      if (!is_array($map) || $map === []) {
         return $fallback;
      }
      $text = self::pickLocale($map, static fn($v): bool => is_string($v) && $v !== '');
      return $text ?? $fallback;
   }

   /**
    * Destaques do card no idioma da sessão: JSON {locale: [linhas]} (features_i18n), mesma ordem de
    * idiomas do localized(); sem tradução usa $fallback (o features_json em pt_BR).
    *
    * @param array<int, string> $fallback
    * @return array<int, string>
    */
   public static function localizedList($json, array $fallback): array {
      if (!is_string($json) || $json === '') {
         return $fallback;
      }
      $map = json_decode($json, true);
      if (!is_array($map) || $map === []) {
         return $fallback;
      }
      $lines = self::pickLocale($map, static fn($v): bool => is_array($v) && $v !== [] && array_is_list($v));
      if ($lines === null) {
         return $fallback;
      }
      return array_values(array_filter(array_map(
         static fn($line): string => is_scalar($line) ? (string) $line : '',
         $lines
      ), static fn(string $line): bool => $line !== ''));
   }

   /**
    * Ordem: idioma exato, mesma família (es_CO usa es_ES; a variante "xx_XX" vem antes das outras) e
    * inglês (en_US, en_GB). Null = nenhum valor aceito por $valid.
    *
    * @param array<mixed> $map
    */
   private static function pickLocale(array $map, callable $valid) {
      $lang = (string) ($_SESSION['glpilanguage'] ?? ($GLOBALS['CFG_GLPI']['language'] ?? ''));
      if ($lang !== '' && isset($map[$lang]) && $valid($map[$lang])) {
         return $map[$lang];
      }
      $prefix = strtolower(substr($lang, 0, 2));
      if ($prefix !== '') {
         $preferred = $prefix . '_' . strtoupper($prefix);
         if (isset($map[$preferred]) && $valid($map[$preferred])) {
            return $map[$preferred];
         }
         foreach ($map as $locale => $value) {
            if (is_string($locale) && strncmp($locale, $prefix . '_', 3) === 0 && $valid($value)) {
               return $value;
            }
         }
      }
      // Idioma sem tradução (nl_NL, uk_UA...): inglês antes do original em pt_BR.
      foreach (['en_US', 'en_GB'] as $english) {
         if (isset($map[$english]) && $valid($map[$english])) {
            return $map[$english];
         }
      }
      return null;
   }

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
            'name'         => self::localized($row['name_i18n'] ?? null, (string) $row['name']),
            'description'  => self::localized($row['description_i18n'] ?? null, (string) ($row['description'] ?? '')),
            'version'      => $row['available_version'] ?? $row['version'],
            'icon'         => $row['icon'] ?? self::DEFAULT_ICON,
            'billing_tier' => $row['billing_tier'] ?? 'FREE',
            'has_config'   => isset($row['has_config']) ? (bool)$row['has_config'] : self::DEFAULT_HAS_CONFIG,
            'downloadable' => isset($row['downloadable']) ? (bool)$row['downloadable'] : self::DEFAULT_DOWNLOADABLE,
            'author'       => self::parseAuthor($row['author'] ?? null),
            'is_installed' => (bool)($row['is_installed'] ?? 0),
            'is_enabled'   => (bool)($row['is_enabled'] ?? 0),
            'is_available' => (bool)($row['is_available'] ?? 0),
            // Descontinuado (catálogo, 2026-10-14). Coluna ausente (sync ainda não rodou) = em linha.
            'is_discontinued' => (bool)($row['is_discontinued'] ?? 0),
            'min_version_nextools' => $minVerNextool,
            'website_url' => isset($row['website_url']) && $row['website_url'] !== '' ? $row['website_url'] : null,
            'icon'         => isset($row['icon']) && $row['icon'] !== '' ? $row['icon'] : self::DEFAULT_ICON,
            'price_cents'  => isset($row['price_cents']) ? (int)$row['price_cents'] : null,
            'category'     => $row['category'] ?? null,
            'features'     => self::localizedList(
               $row['features_i18n'] ?? null,
               !empty($row['features_json']) ? (array) json_decode($row['features_json'], true) : []
            ),
            'screenshot_url' => isset($row['screenshot_url']) && $row['screenshot_url'] !== '' ? $row['screenshot_url'] : null,
            'download_count' => (int)($row['download_count'] ?? 0),
            'compat_glpi_majors' => isset($row['compat_glpi_majors']) && $row['compat_glpi_majors'] !== '' ? $row['compat_glpi_majors'] : null,
            'date_creation'  => $row['date_creation'] ?? null,
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
