<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Path dos módulos (GLPI_PLUGIN_DOC_DIR)
 * -------------------------------------------------------------------------
 * Define NEXTOOL_MODULES_DIR e NEXTOOL_DOC_DIR para que o plugin grave
 * módulos baixados em files/_plugins/nextool/ (sem pedir permissão em plugins/).
 * Incluir este arquivo antes de usar os paths (setup.php, hook.php, module_ajax, etc.).
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT') || defined('NEXTOOL_MODULES_DIR')) {
   return;
}

$base = defined('GLPI_PLUGIN_DOC_DIR')
   ? GLPI_PLUGIN_DOC_DIR
   : (GLPI_ROOT . '/files/_plugins');

define('NEXTOOL_DOC_DIR', $base . '/nextool');
define('NEXTOOL_MODULES_DIR', $base . '/nextool/modules');

// Base resolvida para includes: evita .//var/... quando NEXTOOL_MODULES_DIR já é absoluto
if (!defined('NEXTOOL_MODULES_BASE')) {
   define('NEXTOOL_MODULES_BASE', (strpos(NEXTOOL_MODULES_DIR, '/') === 0 || (strlen(NEXTOOL_MODULES_DIR) > 1 && substr(NEXTOOL_MODULES_DIR, 1, 1) === ':'))
      ? rtrim(NEXTOOL_MODULES_DIR, '/')
      : (rtrim(GLPI_ROOT, '/') . '/' . ltrim(NEXTOOL_MODULES_DIR, '/')));
}
