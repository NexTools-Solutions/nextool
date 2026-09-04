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

// Resolve o path absoluto do plugin nextool (funciona tanto em plugins/ quanto em marketplace/).
// Plugin::getPhpDir() varre todos os PluginDirectories do GLPI; fallback via __DIR__ cobre boot
// muito cedo (antes da classe Plugin estar autoloaded).
if (!defined('NEXTOOL_PHP_DIR')) {
   $nextoolPhpDir = false;
   if (class_exists('\\Plugin')) {
      $nextoolPhpDir = \Plugin::getPhpDir('nextool');
   }
   if ($nextoolPhpDir === false) {
      // dirname(__DIR__) -- este arquivo vive em <plugin>/inc/ e dirname resolve <plugin>
      $nextoolPhpDir = dirname(__DIR__);
   }
   define('NEXTOOL_PHP_DIR', $nextoolPhpDir);
}

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

// -------------------------------------------------------------------------
// Manifesto de boot (nextool-dev#249, lote B): metadados de descoberta dos módulos +
// class-map, serializados em GLPI_CACHE_DIR/nextool_boot_manifest.cache pelo
// ModuleManager (caminho frio). Lido no máximo 1x por request, AQUI, porque o
// autoloader abaixo precisa dele antes de o ModuleManager existir.
// -------------------------------------------------------------------------
if (!function_exists('plugin_nextool_boot_manifest_path')) {
   function plugin_nextool_boot_manifest_path(): string {
      if (!defined('GLPI_CACHE_DIR') && defined('GLPI_ROOT')) {
         $downstream = GLPI_ROOT . '/inc/downstream.php';
         if (file_exists($downstream)) {
            @include_once $downstream;
         }
      }
      if (defined('GLPI_CACHE_DIR') && is_dir(GLPI_CACHE_DIR)) {
         return GLPI_CACHE_DIR . '/nextool_boot_manifest.cache';
      }
      if (defined('GLPI_ROOT') && is_dir(GLPI_ROOT . '/files/_cache')) {
         return GLPI_ROOT . '/files/_cache/nextool_boot_manifest.cache';
      }
      return sys_get_temp_dir() . '/nextool_boot_manifest.cache';
   }

   /**
    * Conteúdo do manifesto (array) ou null se ausente/inválido. Memoizado por
    * request; $reset = true descarta o memo (após gravar/apagar o arquivo).
    */
   function plugin_nextool_boot_manifest(bool $reset = false): ?array {
      static $data = null;
      static $loaded = false;
      if ($reset) {
         $data   = null;
         $loaded = false;
         return null;
      }
      if ($loaded) {
         return $data;
      }
      $loaded = true;
      $raw = @file_get_contents(plugin_nextool_boot_manifest_path());
      if ($raw === false || $raw === '') {
         return $data = null;
      }
      $decoded = @unserialize($raw, ['allowed_classes' => false]);
      if (!is_array($decoded) || (int) ($decoded['format'] ?? 0) !== 2 || !is_array($decoded['modules'] ?? null)) {
         return $data = null;
      }
      return $data = $decoded;
   }

   /**
    * Mapeamento reverso tabela->itemtype no cache do GLPI para uma classe de módulo
    * recém-carregada. getItemTypeForTable() não resolve tabelas custom (ex: ..._log,
    * que não seguem a pluralização padrão -> deriva "Columnresize_Log", inexistente)
    * e retorna null; o Search então estoura getItemForItemtype(null) (HTTP 500) ao
    * renderizar colunas dessas tabelas com dados. Popular o cache evita isso.
    */
   function plugin_nextool_pin_itemtype_table(string $class): void {
      if (!class_exists($class, false) || !is_subclass_of($class, 'CommonDBTM')) {
         return;
      }
      global $CFG_GLPI;
      $tbl = $class::getTable();
      if (is_string($tbl) && $tbl !== '' && !isset($CFG_GLPI['glpiitemtypetables'][$tbl])) {
         $CFG_GLPI['glpiitemtypetables'][$tbl] = $class;
      }
   }
}

// -------------------------------------------------------------------------
// Autoloader das classes dos módulos NexTool (modules/<mk>/inc/<x>.class.php).
// Endpoints genéricos do GLPI que resolvem o itemtype via autoload do "namespace global"
// -- notadamente /ajax/search.php (Search::show das abas) -- não encontram essas classes
// (vivem fora de plugins/.../inc) e retornam HTTP 500, fazendo o JS recarregar a página
// inteira (a busca nunca filtra). Registrar aqui cobre TODOS os módulos, ativos ou não, em
// qualquer requisição, sem depender de cada módulo registrar a classe manualmente no onInit.
// Defensivo: só age em PluginNextool*, nunca lança, e revalida class_exists após o require.
if (!defined('NEXTOOL_MODULE_AUTOLOADER')) {
   define('NEXTOOL_MODULE_AUTOLOADER', true);
   spl_autoload_register(static function (string $class): void {
      if (strncmp($class, 'PluginNextool', 13) !== 0
          || class_exists($class, false)
          || interface_exists($class, false)) {
         return;
      }
      if (!defined('NEXTOOL_MODULES_BASE')) {
         return;
      }
      $rest = substr($class, 13);
      if ($rest === '') {
         return;
      }
      // 1) Class-map do manifesto de boot (lote B, #249): resolve qualquer classe de
      //    módulo -- inclusive subpastas (workflow/inc/nodetype, aiassist/inc/providers)
      //    e nomes fora do padrão (class.config.php) -- sem sondar o disco. Entrada
      //    obsoleta (classe movida) só cai na heurística abaixo.
      $nxManifest = plugin_nextool_boot_manifest();
      if ($nxManifest !== null && isset($nxManifest['classmap'][$class]) && is_string($nxManifest['classmap'][$class])) {
         $mapped = NEXTOOL_MODULES_BASE . '/' . $nxManifest['classmap'][$class];
         if (is_file($mapped)) {
            require_once $mapped;
            if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
               plugin_nextool_pin_itemtype_table($class);
               return;
            }
         }
      }
      // 2) Heurística por nome (módulos sem manifesto ainda, ou entrada obsoleta).
      static $modules = null;
      if ($modules === null) {
         $modules = [];
         foreach (glob(NEXTOOL_MODULES_BASE . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $modules[basename($dir)] = $dir;
         }
         // Casa o prefixo de módulo mais longo primeiro (evita ambiguidade de prefixos).
         uksort($modules, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
         });
      }
      foreach ($modules as $mk => $dir) {
         $uc = ucfirst($mk);
         if (strncmp($rest, $uc, strlen($uc)) !== 0) {
            continue;
         }
         $suffix = strtolower(substr($rest, strlen($uc)));
         if ($suffix === '') {
            continue;
         }
         // Dois padrões de nome observados: <suffix>.class.php e <mk><suffix>.class.php.
         // 3o candidato: PluginNextool<Mk>PageConfig vive em <mk>config.class.php
         // (autentique, digitalsignature, mailanalyzer, mailinteractions) -- antes so
         // era encontrado pelo loop de config standalone do setup.php, removido na
         // 6.13.0 (nextool-dev#249): o autoloader resolve sob demanda (common.tabs.php).
         $candidates = [$dir . '/inc/' . $suffix . '.class.php', $dir . '/inc/' . $mk . $suffix . '.class.php'];
         if ($suffix === 'pageconfig') {
            $candidates[] = $dir . '/inc/' . $mk . 'config.class.php';
         }
         foreach ($candidates as $file) {
            if (is_file($file)) {
               require_once $file;
               if (class_exists($class, false) || interface_exists($class, false)) {
                  plugin_nextool_pin_itemtype_table($class);
                  return;
               }
            }
         }
      }
   });
}
