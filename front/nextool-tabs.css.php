<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - CSS global das abas de modulo
 * -------------------------------------------------------------------------
 * Servido via PHP (e nao .css estatico) porque o GLPI 11 (Symfony) NAO serve
 * arquivos estaticos de plugin por path direto (/plugins/X/css/*.css -> 404);
 * apenas front/*.php passa pelo front controller. Mesmo padrao dos modulos
 * (front/module_assets.php + *.css.php). CSS publico, sem dados sensiveis e
 * sem input do usuario -> nao exige sessao.
 *
 * Escopo .nextool-tab-card: oculta os controles de "pesquisa salva" (SavedSearch)
 * nas grades Search::show embarcadas em abas de modulo (bugados fora de pagina de
 * busca pura) e remove a 2a barra de rolagem. NAO afeta telas puras nem o core.
 * -------------------------------------------------------------------------
 * @license GPLv3+
 */
header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
?>
.nextool-tab-card .bookmark_record,
.nextool-tab-card .saved-searches-panel,
.nextool-tab-card .show-saved-searches,
.nextool-tab-card button[name="save_bookmark_record"] {
   display: none !important;
}
.nextool-tab-card .search-container {
   overflow: visible !important;
   height: auto !important;
}
