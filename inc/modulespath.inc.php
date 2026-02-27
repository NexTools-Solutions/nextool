<?php
/**
 * Nextools - Path dos módulos (GLPI_PLUGIN_DOC_DIR)
 *
 * Define NEXTOOL_MODULES_DIR e NEXTOOL_DOC_DIR para que o plugin grave módulos
 * baixados em files/_plugins/nextool/ (sem pedir permissão em plugins/).
 * Incluir este arquivo antes de usar os paths (setup.php, hook.php, module_ajax, etc.).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT') || defined('NEXTOOL_MODULES_DIR')) {
   return;
}

$base = defined('GLPI_PLUGIN_DOC_DIR')
   ? GLPI_PLUGIN_DOC_DIR
   : null;

// Em alguns ambientes (ex.: containers), GLPI_ROOT fica em /usr/share/glpi,
// mas o diretório de arquivos fica em /var/lib/glpi/files. Quando includes.php
// ainda não foi incluído, GLPI_PLUGIN_DOC_DIR pode não estar definido; então
// precisamos de um fallback que encontre o _plugins real.
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
   // preferir um _plugins que seja gravável pelo PHP (mesma lógica do marketplace),
   // evitando cair em um path não persistente ou não gravável em containers.
   if ($base === null) {
      foreach ($candidates as $candidate) {
         if (is_dir($candidate) && @is_writable($candidate)) {
            $base = $candidate;
            break;
         }
      }
   }

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
