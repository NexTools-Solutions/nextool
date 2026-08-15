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
 * <p class="small"> do modal de auto-update, etc.). Restauramos a largura em TODO
 * container do plugin -- casado por `nextool` na classe ([class*=]) ou no id
 * ([id*=], ex.: #nextool-core-update-modal) e pelas abas ([id^="rt-tab-"]) -- e
 * apenas para o que NAO for celula de tabela (`:not(td):not(th)` -> o encolhimento
 * de coluna do core em <td class="small"> das grades Search embarcadas fica intacto).
 * O seletor do core tem ID (#page), entao precisamos de !important para venca-lo.
 * No GLPI 11 a regra do core nao existe -> no-op. (Escopo amplo de proposito: evita
 * o whack-a-mole de listar container por container -- ver 6.0.1 que perdeu o modal.)
 */
[class*="nextool"] .small:not(td):not(th),
[id*="nextool"] .small:not(td):not(th),
[id^="rt-tab-"] .small:not(td):not(th),
[id$="-tab"] .small:not(td):not(th) {
   width: auto !important;
}
/* O seletor [id$="-tab"] cobre os containers de aba de modulo que nao carregam
 * "nextool" no id (ex.: #ticketflow-fluxos-tab, #problemflow-rules-tab). Ele
 * substitui os blocos <style> "nextool-unified-small-fix" que cada modulo
 * duplicava inline (removidos em 2026-08-15) -- as secoes colapsaveis internas
 * (#<mod>_api_collapse etc.) sao descendentes do container e ficam cobertas. */

/*
 * Indicador de colapso (chevron) das secoes de config dos modulos: animacao de
 * rotacao ao expandir/recolher. Era um <style> inline duplicado em ~48 abas de
 * modulo (blueprint de config); centralizado aqui em 2026-08-15. A classe e
 * prefixada (so os modulos NexTool a usam), entao a regra e global por classe.
 * Modulos que dependem desta regra exigem base >= 6.9.1 (min_plugin_version).
 */
.nextool-collapse-indicator {
   transition: transform .2s ease;
}
[aria-expanded="true"] .nextool-collapse-indicator {
   transform: rotate(180deg);
}

/*
 * Hover UNIFORME nos itens do dropdown de opções do módulo. No GLPI 10 o hover
 * do Bootstrap fica inconsistente entre item de ação (a.nextool-module-action) e
 * link simples (a.dropdown-item), dando "efeito" só em alguns. Aplica o mesmo
 * realce e cursor a TODOS os itens (exceto os desabilitados). Escopado ao dropdown
 * dos cards do plugin. No GLPI 11 apenas reforça o comportamento nativo (idempotente).
 */
[class*="nextool"] .dropdown-menu .dropdown-item:not(.disabled):hover,
[class*="nextool"] .dropdown-menu .dropdown-item:not(.disabled):focus {
   background-color: rgba(0, 0, 0, 0.06);
   cursor: pointer;
}
