<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Confirmação de preço de uma operação de IA paga (window.NexToolAiConfirm)
 * -------------------------------------------------------------------------
 * Plataforma multi-módulo dos créditos de IA (2026-10-29): quando a NexTool responde E_CONFIRM_REQUIRED (a faixa de
 * preço da função exige confirmação), o módulo pergunta ao usuário com o valor e, se ele aceitar, repete a chamada com o
 * valor aceito. O preço vem SEMPRE do servidor; aqui só se mostra.
 *
 *   NexToolAiConfirm.ask({ priceBrl: 'R$ 2,25', detail: '6.120 caracteres' }).then(function (ok) { ... });
 *
 * Usa o diálogo do GLPI (glpi_confirm) e, sem ele, o confirm() do navegador. O MÓDULO registra este arquivo só nas
 * páginas onde há operação paga (add_javascript 'front/ai-confirm.js.php'), nunca globalmente. Servido via front/*.php
 * porque o GLPI 11 não serve .js estático de plugin (mesmo padrão do nextool-session.js.php).
 * -------------------------------------------------------------------------
 * @since pós-6.49.0 (a versão sai no /release)
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   define('GLPI_ROOT', dirname(__FILE__, 4));
}
require_once GLPI_ROOT . '/inc/includes.php';

if (session_status() === PHP_SESSION_ACTIVE) {
   session_write_close();
}
header_remove('Expires');
header_remove('Pragma');
header('Content-Type: application/javascript; charset=UTF-8');
// private: as strings saem no idioma do usuário e a URL não varia por idioma
header('Cache-Control: private, max-age=3600');

$i18n = [
   'title'   => __('Confirmar operação paga', 'nextool'),
   'message' => __('Esta operação custa %s em créditos de IA.', 'nextool'),
   'confirm' => __('Confirmar', 'nextool'),
   'cancel'  => __('Cancelar', 'nextool'),
];
?>
(function () {
   'use strict';
   if (window.NexToolAiConfirm) { return; }
   var T = <?php echo json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
   function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
         return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
   }
   window.NexToolAiConfirm = {
      /** @param {{priceBrl: string, detail?: string}} o  @returns {Promise<boolean>} */
      ask: function (o) {
         var texto = T.message.replace('%s', String((o && o.priceBrl) || ''));
         var detalhe = o && o.detail ? String(o.detail) : '';
         return new Promise(function (resolve) {
            if (typeof window.glpi_confirm === 'function') {
               var feito = false;
               var fim = function (v) { if (!feito) { feito = true; resolve(v); } };
               window.glpi_confirm({
                  title: T.title,
                  message: '<p class="mb-1">' + esc(texto) + '</p>' + (detalhe ? '<p class="text-muted small mb-0">' + esc(detalhe) + '</p>' : ''),
                  confirm_label: T.confirm,
                  cancel_label: T.cancel,
                  confirm_callback: function () { fim(true); },
                  cancel_callback: function () { fim(false); },
                  close_callback: function () { fim(false); }
               });
               return;
            }
            resolve(window.confirm(texto + (detalhe ? '\n' + detalhe : '')));
         });
      }
   };
})();
