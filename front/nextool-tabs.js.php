<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - JS global das abas de modulo (Enter nao recarrega)
 * -------------------------------------------------------------------------
 * Servido via PHP (e nao .js estatico) porque o GLPI 11 (Symfony) NAO serve
 * arquivos estaticos de plugin por path direto (/plugins/X/js/*.js -> 404);
 * apenas front/*.php passa pelo front controller. Mesmo padrao do CSS
 * (front/nextool-tabs.css.php). JS publico, sem dados sensiveis e sem input
 * do usuario -> nao exige sessao.
 *
 * Dois cenarios de "Enter recarrega" tratados:
 *  1) Grades Search::show embarcadas em aba (Logs/Registros): form de busca faz
 *     submit GET nativo (o "Pesquisar" e type=button/AJAX). Enter -> reload.
 *  2) Forms de configuracao em aba (ex.: "minimo de caracteres"): Enter num input
 *     submete o form -> POST/redirect -> reload.
 * Interceptamos o ENTER no keydown em FASE DE CAPTURA (antes do submit implicito).
 * -------------------------------------------------------------------------
 * @license GPLv3+
 */
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
?>
(function () {
   'use strict';
   if (window.__nextoolTabsSearchGuard) {
      return;
   }
   window.__nextoolTabsSearchGuard = true;

   function isNextoolSearchForm(form) {
      if (!form || typeof form.matches !== 'function') {
         return false;
      }
      var name = (form.getAttribute('name') || '').toLowerCase();
      if (name.indexOf('searchformpluginnextool') === 0) {
         return true;
      }
      return form.matches('.search-form-container, [data-glpi-search-form]')
          && !!(form.closest && form.closest('.nextool-tab-card'));
   }

   function triggerSearch(form) {
      var scope = (form.closest && form.closest('.nextool-tab-card')) || document;
      var btn = form.querySelector('button[name="search"]') || scope.querySelector('button[name="search"]');
      if (btn) {
         btn.click();
      }
   }

   // ENTER (captura): impede o submit implicito que recarrega a pagina.
   document.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.keyCode !== 13) {
         return;
      }
      var el = e.target;
      if (!el || el.tagName !== 'INPUT') {
         return; // textarea/select: Enter tem comportamento proprio
      }
      var type = (el.getAttribute('type') || 'text').toLowerCase();
      if (type === 'submit' || type === 'button' || type === 'checkbox' || type === 'radio') {
         return;
      }
      var form = el.form || (el.closest ? el.closest('form') : null);
      if (!form) {
         return;
      }
      if (isNextoolSearchForm(form)) {
         e.preventDefault();
         triggerSearch(form);
         return;
      }
      if (el.closest && el.closest('.nextool-tab-card')) {
         e.preventDefault();
      }
   }, true);

   // SUBMIT (defesa em profundidade): grades de busca NexTool viram AJAX, nunca GET nativo.
   document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!isNextoolSearchForm(form)) {
         return;
      }
      e.preventDefault();
      triggerSearch(form);
   });
})();
