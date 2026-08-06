<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Guarda de sessao do front (window.NexToolSession)
 * -------------------------------------------------------------------------
 * Coordena o que os modulos com polling fazem quando a sessao do GLPI expira.
 *
 * Por que e compartilhado e nao copiado em cada modulo: hoje ha 5 pollers
 * (smartnotify, contracthours navbar, contracthours timeline, aiassist chat,
 * kbsuggest). O comportamento correto e GLOBAL - se a sessao expirou, todos
 * param e o usuario ve UM aviso, nao cinco. E o backoff evita que N modulos
 * martelem um servidor que ja esta com problema.
 *
 * Servido via front/*.php porque o GLPI 11 (Symfony) NAO serve .js estatico de
 * plugin por path direto - mesmo padrao do nextool-tabs.js.php. Carregado como
 * add_javascript global, ANTES do bundle de modulos (o AssetBundler so colapsa
 * o que passa por module_assets.php), entao a API existe antes de qualquer JS
 * de modulo rodar.
 *
 * Os modulos consomem sempre com guarda (`window.NexToolSession && ...`): em
 * ambiente com plugin base antigo o modulo degrada para o comportamento de
 * antes, sem quebrar.
 * -------------------------------------------------------------------------
 * @license GPLv3+
 */
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$i18n = [
   'title'   => __('Sessão expirada', 'nextool'),
   'message' => __('Sua sessão do GLPI expirou. Recarregue a página para continuar.', 'nextool'),
   'reload'  => __('Recarregar', 'nextool'),
   'dismiss' => __('Agora não', 'nextool'),
];
?>
(function () {
   'use strict';

   if (window.NexToolSession) {
      return;
   }

   var I18N = <?php echo json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

   var expired   = false;
   var callbacks = [];
   var channel   = null;
   var banner    = null;

   try {
      if (typeof BroadcastChannel === 'function') {
         channel = new BroadcastChannel('nextool-session');
         channel.onmessage = function (ev) {
            if (ev && ev.data === 'expired') {
               // Veio de outra aba: mesma sessao, mesmo cookie. Marca local sem
               // republicar, senao as abas ficam ecoando uma para a outra.
               applyExpired(false);
            }
         };
      }
   } catch (e) { /* navegador sem suporte: degrada para aviso por aba */ }

   function buildBanner() {
      var box = document.createElement('div');
      box.className = 'nextool-session-banner';
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      box.style.cssText = [
         'position:fixed', 'left:50%', 'bottom:1.25rem', 'transform:translateX(-50%)',
         'z-index:2147483000', 'display:flex', 'align-items:center', 'gap:.75rem',
         'max-width:min(38rem,calc(100vw - 2rem))', 'padding:.75rem 1rem',
         'border-radius:.5rem', 'border:1px solid rgba(214,57,57,.35)',
         'background:#fff5f5', 'color:#4a1c1c',
         'box-shadow:0 .5rem 1.5rem rgba(0,0,0,.18)',
         'font-size:.875rem', 'line-height:1.35'
      ].join(';');

      var icon = document.createElement('i');
      icon.className = 'ti ti-alert-triangle';
      icon.style.cssText = 'font-size:1.25rem;color:#d63939;flex-shrink:0';

      var text = document.createElement('div');
      text.style.cssText = 'flex:1 1 auto';
      var strong = document.createElement('strong');
      strong.textContent = I18N.title;
      var msg = document.createElement('div');
      msg.textContent = I18N.message;
      text.appendChild(strong);
      text.appendChild(msg);

      var reload = document.createElement('button');
      reload.type = 'button';
      reload.className = 'btn btn-sm btn-danger';
      reload.textContent = I18N.reload;
      reload.style.flexShrink = '0';
      // Recarga e SEMPRE manual: um reload automatico destruiria formulario
      // aberto (a sessao expira justamente em aba parada, que costuma ter algo
      // meio preenchido).
      reload.addEventListener('click', function () { window.location.reload(); });

      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'btn btn-sm btn-ghost-secondary';
      close.textContent = I18N.dismiss;
      close.style.flexShrink = '0';
      close.addEventListener('click', function () {
         if (box.parentNode) { box.parentNode.removeChild(box); }
      });

      box.appendChild(icon);
      box.appendChild(text);
      box.appendChild(reload);
      box.appendChild(close);
      return box;
   }

   function showBanner() {
      if (banner || !document.body) { return; }
      banner = buildBanner();
      document.body.appendChild(banner);
   }

   function applyExpired(publish) {
      if (expired) { return; }
      expired = true;

      if (publish && channel) {
         try { channel.postMessage('expired'); } catch (e) { /* aba fechando */ }
      }

      callbacks.forEach(function (cb) {
         try { cb(); } catch (e) { /* um listener quebrado nao impede os outros */ }
      });

      if (document.readyState === 'loading') {
         document.addEventListener('DOMContentLoaded', showBanner);
      } else {
         showBanner();
      }
   }

   window.NexToolSession = {
      isExpired: function () {
         return expired;
      },

      /** Idempotente: N pollers x N abas produzem UM aviso so. */
      notifyExpired: function () {
         applyExpired(true);
      },

      /**
       * Registra quem deve parar quando a sessao cair. Se ja expirou, chama na
       * hora - evita corrida com a ordem de carga dos modulos.
       */
      onExpire: function (cb) {
         if (typeof cb !== 'function') { return; }
         callbacks.push(cb);
         if (expired) {
            try { cb(); } catch (e) { /* idem */ }
         }
      },

      /**
       * Recebe um Response de fetch. Devolve true se a sessao expirou (e ja
       * disparou o aviso), para o chamador abortar sem tratar como erro comum.
       *
       * 403 NAO entra aqui: 403 e falha de CSRF, tem outro tratamento e nao
       * deve parar poller nem falar em "sessao expirada".
       */
      check: function (response) {
         if (!response) { return false; }
         var flagged = false;
         try {
            flagged = response.headers && response.headers.get('X-NexTool-Session') === 'expired';
         } catch (e) { /* headers opacos */ }
         if (response.status === 401 || flagged) {
            applyExpired(true);
            return true;
         }
         return false;
      },

      /**
       * Backoff por PULO DE TICK: o poller mantem o setInterval que ja tem e so
       * pergunta shouldSkip() no topo. Evita reescrever agendamento em cada
       * modulo. Usar so para 5xx/rede - 401 e terminal, nao tem retentativa.
       */
      createBackoff: function (baseMs, maxMs) {
         var skip = 0, streak = 0;
         var max = Math.max(1, Math.floor((maxMs || 300000) / Math.max(1, baseMs || 30000)));
         return {
            shouldSkip: function () {
               if (skip > 0) { skip--; return true; }
               return false;
            },
            ok: function () { streak = 0; skip = 0; },
            fail: function () {
               streak = streak ? Math.min(streak * 2, max) : 1;
               skip = streak;
            }
         };
      }
   };
})();
