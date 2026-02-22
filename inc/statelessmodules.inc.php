<?php
/**
 * Nextools - Stateless Modules
 *
 * Fornece o mapa de módulos com endpoints stateless (sem sessão/login).
 * Usado por setup.php (boot) e module_ajax.php para decidir se inclui includes.php.
 * Carregado antes da sessão GLPI — não pode depender de Session, $DB ou classes GLPI.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Retorna o caminho do arquivo de cache JSON dos módulos stateless.
 *
 * @return string
 */
function plugin_nextool_stateless_cache_path(): string {
   // Garante que GLPI_CACHE_DIR esteja disponível (pode não estar no boot)
   if (!defined('GLPI_CACHE_DIR') && defined('GLPI_ROOT')) {
      $downstreamFile = GLPI_ROOT . '/inc/downstream.php';
      if (file_exists($downstreamFile)) {
         require_once $downstreamFile;
      }
   }

   // Tenta diretório de cache do GLPI, fallback para /tmp
   if (defined('GLPI_CACHE_DIR') && is_dir(GLPI_CACHE_DIR)) {
      return GLPI_CACHE_DIR . '/nextool_stateless.json';
   }
   // Fallback: /tmp (sempre disponível)
   return sys_get_temp_dir() . '/nextool_stateless.json';
}

/**
 * Retorna o mapa de arquivos stateless por módulo.
 * Lê do cache JSON gerado pelo ModuleManager::refreshStatelessCache().
 *
 * @return array<string, list<string>> [module_key => ['file1.php', ...], ...]
 */
function plugin_nextool_stateless_files(): array {
   static $cache = null;
   if ($cache !== null) {
      return $cache;
   }

   $cacheFile = plugin_nextool_stateless_cache_path();
   if (file_exists($cacheFile)) {
      $json = @file_get_contents($cacheFile);
      if ($json !== false) {
         $data = @json_decode($json, true);
         if (is_array($data)) {
            $cache = $data;
            return $cache;
         }
      }
   }

   // Cache não existe ainda (primeira instalação) — retorna vazio
   $cache = [];
   return $cache;
}

/**
 * Retorna as chaves dos módulos que possuem endpoints stateless.
 *
 * @return string[]
 */
function plugin_nextool_stateless_module_keys(): array {
   return array_keys(plugin_nextool_stateless_files());
}
