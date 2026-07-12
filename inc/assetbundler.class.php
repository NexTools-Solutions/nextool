<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Asset Bundler
 * -------------------------------------------------------------------------
 * Colapsa os N assets de módulos registrados em $PLUGIN_HOOKS (add_javascript
 * e add_css apontando para front/module_assets.php) em UMA URL de bundle por
 * tipo (front/module_bundle.php), reduzindo ~16-27 requests por page load
 * (cada um com bootstrap completo do GLPI) para 2.
 *
 * Como funciona:
 *  - Os módulos continuam registrando seus assets normalmente no onInit
 *    (getJsPath/getCssPath, com gates por página e stamps fv=).
 *  - Após loadActiveModules(), collapseHooks() varre as entradas, junta as
 *    bundláveis numa lista "mod:file,mod:file,..." e as substitui pela URL
 *    do bundle. O hash h= deriva das URLs originais COMPLETAS (que carregam
 *    os fv= individuais) - qualquer asset que mude muda a URL do bundle e
 *    invalida o cache do browser na hora.
 *  - Entradas com &nobundle=1 (ex.: chat-widget do aiassist, que é no-store
 *    por ter estado dinâmico) e entradas que não passam pelo
 *    module_assets.php (ex.: nextool-tabs.css.php) ficam de fora, intactas.
 *
 * Pré-requisito dos wrappers bundláveis: early-exit com `return;` (NUNCA
 * `exit;`, que mataria o bundle inteiro no meio).
 * -------------------------------------------------------------------------
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAssetBundler {

   /** Mínimo de assets bundláveis para valer o desvio (1 só = mantém direto). */
   private const MIN_BUNDLE = 2;

   /**
    * Substitui as entradas module_assets.php dos hooks por 1 URL de bundle
    * por tipo. Chamar APÓS loadActiveModules() (todos os onInit já rodaram).
    */
   public static function collapseHooks(): void {
      global $PLUGIN_HOOKS;

      $map = [
         'add_javascript' => 'js',
         'add_css'        => 'css',
      ];

      foreach ($map as $hook => $type) {
         $entries = $PLUGIN_HOOKS[$hook]['nextool'] ?? null;
         if (!is_array($entries) || count($entries) < self::MIN_BUNDLE) {
            continue;
         }

         $bundleItems = [];
         $keep        = [];
         $stamps      = [];

         foreach ($entries as $url) {
            if (!is_string($url)
                || strpos($url, 'front/module_assets.php?') === false
                || strpos($url, 'nobundle=1') !== false) {
               $keep[] = $url;
               continue;
            }
            $query = parse_url($url, PHP_URL_QUERY);
            $params = [];
            if (is_string($query)) {
               parse_str($query, $params);
            }
            $mod  = (string) ($params['module'] ?? '');
            $file = (string) ($params['file'] ?? '');
            if ($mod === '' || $file === '') {
               $keep[] = $url;
               continue;
            }
            $bundleItems[] = $mod . ':' . $file;
            $stamps[]      = $url; // URL completa: inclui fv= (todos os fatores)
         }

         if (count($bundleItems) < self::MIN_BUNDLE) {
            continue; // nada (ou só 1) bundlável: mantém como está
         }

         $hash = substr(md5(implode('|', $stamps)), 0, 12);
         $keep[] = 'front/module_bundle.php?type=' . $type
            . '&files=' . rawurlencode(implode(',', $bundleItems))
            . '&h=' . $hash;

         $PLUGIN_HOOKS[$hook]['nextool'] = $keep;
      }
   }
}
