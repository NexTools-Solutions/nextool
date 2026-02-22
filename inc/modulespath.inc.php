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
   : null;

// Fallback para ambientes/container onde GLPI_PLUGIN_DOC_DIR ainda não está definido
// no momento em que este include roda. O objetivo é manter os módulos em
// files/_plugins/nextool/modules (gravável pelo PHP), como plugins de marketplace.
if ($base === null) {
   $candidates = [
      '/var/lib/glpi/files/_plugins',
      GLPI_ROOT . '/files/_plugins',
   ];

   // Preferir o candidato que realmente contém o diretório do NexTool,
   // evitando escolher um _plugins "vazio" existente em alguns builds.
   foreach ($candidates as $candidate) {
      if (is_dir($candidate . '/nextool/modules')) {
         $base = $candidate;
         break;
      }
   }

   // Primeira instalação (a pasta nextool/modules ainda não existe):
   // preferir um _plugins gravável pelo PHP.
   if ($base === null) {
      foreach ($candidates as $candidate) {
         if (is_dir($candidate) && @is_writable($candidate)) {
            $base = $candidate;
            break;
         }
      }
   }

   // Último fallback: primeiro candidato existente.
   if ($base === null) {
      foreach ($candidates as $candidate) {
         if (is_dir($candidate)) {
            $base = $candidate;
            break;
         }
      }
   }
}

if ($base === null) {
   $base = GLPI_ROOT . '/files/_plugins';
}

define('NEXTOOL_DOC_DIR', $base . '/nextool');
define('NEXTOOL_MODULES_DIR', $base . '/nextool/modules');

// Base resolvida para includes: evita .//var/... quando NEXTOOL_MODULES_DIR já é absoluto
if (!defined('NEXTOOL_MODULES_BASE')) {
   define('NEXTOOL_MODULES_BASE', (strpos(NEXTOOL_MODULES_DIR, '/') === 0 || (strlen(NEXTOOL_MODULES_DIR) > 1 && substr(NEXTOOL_MODULES_DIR, 1, 1) === ':'))
      ? rtrim(NEXTOOL_MODULES_DIR, '/')
      : (rtrim(GLPI_ROOT, '/') . '/' . ltrim(NEXTOOL_MODULES_DIR, '/')));
}
