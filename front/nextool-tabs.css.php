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

/*
 * Fix GLPI 10: a palette do core (auror.scss) aplica `#page .small { width: 1% }`
 * -- regra pensada para <td class="small"> encolher colunas de tabela. Ela tambem
 * sequestra a utility `.small` (font-size) do Bootstrap que usamos em <div> nas
 * telas do base (hero do plano, abas), colapsando-os a ~1 caractere de largura:
 * o texto quebra 1 char por linha e a pagina chega a ~34000px de altura.
 * Afeta QUALQUER bloco com `.small` (div do hero, ul.nextool-features dos cards,
 * p, etc.). Restauramos a largura APENAS dentro dos nossos containers e apenas
 * para o que NAO for celula de tabela (`:not(td):not(th)` -> o encolhimento de
 * coluna do core em <td class="small"> das grades Search embarcadas fica intacto).
 * O seletor do core tem ID (#page), entao precisamos de !important para venca-lo.
 * No GLPI 11 a regra do core nao existe -> no-op.
 */
.nextool-hero-actions .small:not(td):not(th),
.nextool-tab-card .small:not(td):not(th),
[id^="rt-tab-"] .small:not(td):not(th) {
   width: auto !important;
}
