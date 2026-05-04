<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Path dos módulos (GLPI_PLUGIN_DOC_DIR)
 * -------------------------------------------------------------------------
 * Define NEXTOOL_MODULES_DIR e NEXTOOL_DOC_DIR para que o plugin grave
 * módulos baixados em files/_plugins/nextool/ (sem pedir permissão em plugins/).
 * Incluir este arquivo antes de usar os paths (setup.php, hook.php, module_ajax, etc.).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT') || defined('NEXTOOL_MODULES_DIR')) {
   return;
}

// Se GLPI_PLUGIN_DOC_DIR não está definido, tenta carregar downstream.php
// para obter os paths corretos. Isso é essencial em instalações com diretórios
// não-padrão (ex.: GLPI_VAR_DIR customizado via local_define.php).
// downstream.php é idempotente com require_once; @ suprime warnings de
// define() duplicado caso o Symfony kernel já tenha definido as constantes.
if (!defined('GLPI_PLUGIN_DOC_DIR')) {
   $downstreamFile = GLPI_ROOT . '/inc/downstream.php';
   if (file_exists($downstreamFile)) {
      @include_once $downstreamFile;
   }
}

$base = defined('GLPI_PLUGIN_DOC_DIR')
   ? GLPI_PLUGIN_DOC_DIR
   : null;

// Fallback para ambientes/container onde GLPI_PLUGIN_DOC_DIR ainda não está definido
// no momento em que este include roda. O objetivo é manter os módulos em
// files/_plugins/nextool/modules (gravável pelo PHP), como plugins de marketplace.
if ($base === null) {
   $candidates = [];

   // GLPI_VAR_DIR pode ter sido definido pelo downstream.php ou pelo Symfony kernel
   // e aponta para o diretório correto de dados (mesmo em instalações customizadas)
   if (defined('GLPI_VAR_DIR')) {
      $candidates[] = GLPI_VAR_DIR . '/_plugins';
   }

   $candidates[] = '/var/lib/glpi/files/_plugins';
   $candidates[] = GLPI_ROOT . '/files/_plugins';

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
