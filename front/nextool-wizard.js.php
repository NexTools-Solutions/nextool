<?php
/**
 * NexTool - Assistente de primeira configuração dos módulos (window.NextoolWizard), modal Bootstrap 5.
 *
 *    NextoolWizard.open('aiassist');           // abre do passo em que parou
 *    NextoolWizard.open('aiassist', true);     // reabre do início (menu do card)
 *
 * Nas páginas de módulo (front/modules.php?module=X) pergunta ao servidor se há assistente pendente e abre sozinho;
 * `&nextool_wizard=1` na URL reabre. Fechar só pausa. Endpoint: ajax/module_wizard.php.
 *
 * Servido sem query string (o GLPI 10 valida o path do hook com file_exists): vale nos 3 majors.
 *
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
header('Cache-Control: private, max-age=3600');

$nxWizCfg = [
   'endpoint' => PluginNextoolCompat::webDir() . '/ajax/module_wizard.php',
   'm' => [
      'title'    => __('Configuração inicial', 'nextool'),
      'next'     => __('Salvar e continuar', 'nextool'),
      'back'     => __('Voltar', 'nextool'),
      'skip'     => __('Pular', 'nextool'),
      'test'     => __('Testar', 'nextool'),
      'finish'   => __('Concluir', 'nextool'),
      'close'    => __('Fechar e continuar depois', 'nextool'),
      'step'     => __('Passo %1$s de %2$s', 'nextool'),
      'loading'  => __('Carregando...', 'nextool'),
      'error'    => __('Não foi possível concluir. Tente de novo.', 'nextool'),
      'done'     => __('Configuração concluída.', 'nextool'),
   ],
];
?>
(function () {
   'use strict';
   if (window.NextoolWizard) { return; }
   var CFG = <?php echo json_encode($nxWizCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
   var M = CFG.m;
   var st = { key: '', outline: null, current: '', busy: false, modal: null };

   function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
   function csrf() {
      var meta = document.querySelector('meta[property="glpi:csrf_token"]');
      if (meta && meta.getAttribute('content')) { return meta.getAttribute('content'); }
      var inp = document.querySelector('input[name="_glpi_csrf_token"]');
      return inp ? inp.value : '';
   }
   function post(params, form) {
      var body = form ? new FormData(form) : new FormData();
      Object.keys(params).forEach(function (k) { body.set(k, params[k]); });
      var t = csrf();
      if (t) { body.set('_glpi_csrf_token', t); }
      return fetch(CFG.endpoint, { method: 'POST', credentials: 'same-origin', body: body,
         headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': t } })
         .then(function (r) { return r.json().catch(function () { return { success: false }; }).then(function (j) {
            if (j && j.next_csrf_token) { document.querySelectorAll('input[name="_glpi_csrf_token"]').forEach(function (i) { i.value = j.next_csrf_token; }); }
            return j || { success: false };
         }); });
   }

   function shell() {
      var el = document.getElementById('nx-wizard');
      if (el) { return el; }
      el = document.createElement('div');
      el.id = 'nx-wizard';
      el.className = 'modal fade';
      el.tabIndex = -1;
      el.setAttribute('aria-hidden', 'true');
      el.innerHTML = '<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">'
         + '<div class="modal-header"><div><h5 class="modal-title mb-0"></h5><div class="small text-muted nx-wiz-sub"></div></div>'
         + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' + esc(M.close) + '"></button></div>'
         + '<div class="px-3 pt-2"><div class="progress progress-sm"><div class="progress-bar nx-wiz-bar" style="width:0%"></div></div>'
         + '<div class="nx-wiz-steps d-flex flex-wrap gap-1 mt-2"></div></div>'
         + '<div class="modal-body"><form class="nx-wiz-form" onsubmit="return false"></form><div class="alert mt-3 mb-0 nx-wiz-msg" hidden></div></div>'
         + '<div class="modal-footer justify-content-between">'
         + '<button type="button" class="btn btn-link nx-wiz-back">' + esc(M.back) + '</button>'
         + '<div class="d-flex gap-2"><button type="button" class="btn btn-outline-secondary nx-wiz-test" hidden>' + esc(M.test) + '</button>'
         + '<button type="button" class="btn btn-outline-secondary nx-wiz-skip" hidden>' + esc(M.skip) + '</button>'
         + '<button type="button" class="btn btn-primary nx-wiz-next">' + esc(M.next) + '</button></div></div>'
         + '</div></div>';
      document.body.appendChild(el);
      el.querySelector('.nx-wiz-next').addEventListener('click', function () { save(false); });
      el.querySelector('.nx-wiz-skip').addEventListener('click', function () { save(true); });
      el.querySelector('.nx-wiz-back').addEventListener('click', back);
      el.querySelector('.nx-wiz-test').addEventListener('click', test);
      el.addEventListener('hidden.bs.modal', function () {
         if (st.key && !st.finished) { post({ action: 'pause', module: st.key }); }
      });
      return el;
   }

   function msg(text, ok) {
      var box = shell().querySelector('.nx-wiz-msg');
      box.hidden = !text;
      box.className = 'alert mt-3 mb-0 nx-wiz-msg ' + (ok ? 'alert-success' : 'alert-warning');
      box.textContent = text || '';
   }

   function idx() {
      var s = st.outline ? st.outline.steps : [];
      for (var i = 0; i < s.length; i++) { if (s[i].name === st.current) { return i; } }
      return 0;
   }

   function paint() {
      var el = shell();
      var steps = st.outline.steps, i = idx(), cur = steps[i] || {};
      el.querySelector('.modal-title').textContent = M.title + ': ' + st.outline.module_name;
      el.querySelector('.nx-wiz-sub').textContent = M.step.replace('%1$s', i + 1).replace('%2$s', steps.length) + ' · ' + (cur.title || '');
      el.querySelector('.nx-wiz-bar').style.width = Math.round((i + 1) * 100 / steps.length) + '%';
      el.querySelector('.nx-wiz-steps').innerHTML = steps.map(function (s, n) {
         var cls = n === i ? 'bg-primary text-white' : (s.done ? 'bg-success text-white' : 'bg-secondary text-white');
         return '<span class="badge ' + cls + '">' + (n + 1) + '. ' + esc(s.title) + '</span>';
      }).join('');
      el.querySelector('.nx-wiz-back').hidden = i === 0;
      el.querySelector('.nx-wiz-skip').hidden = !cur.optional;
      el.querySelector('.nx-wiz-test').hidden = !cur.testable;
      el.querySelector('.nx-wiz-next').textContent = i === steps.length - 1 ? M.finish : M.next;
   }

   function load(name) {
      st.current = name;
      paint();
      msg('');
      var form = shell().querySelector('.nx-wiz-form');
      form.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>' + esc(M.loading) + '</div>';
      return post({ action: 'step', module: st.key, step: name }).then(function (j) {
         form.innerHTML = j.success ? (j.html || '') : '';
         if (!j.success) { msg(j.message || M.error, false); }
         // scripts inline dos passos (ex.: mostrar campos do provedor escolhido)
         form.querySelectorAll('script').forEach(function (s) { var n = document.createElement('script'); n.textContent = s.textContent; s.replaceWith(n); });
      });
   }

   function save(skip) {
      if (st.busy) { return; }
      var steps = st.outline.steps, i = idx();
      var last = i === steps.length - 1;
      st.busy = true;
      var form = skip ? null : shell().querySelector('.nx-wiz-form');
      post({ action: 'save', module: st.key, step: st.current }, form).then(function (j) {
         if (!j.success || !j.ok) { msg((j && j.message) || M.error, false); return; }
         if (j.outline) { st.outline = j.outline; }
         if (last || !j.next) {
            st.finished = true;
            return post({ action: 'finish', module: st.key }).then(function () {
               msg(M.done, true);
               if (window.glpi_toast_info) { window.glpi_toast_info(M.done); }
               window.setTimeout(function () { var mm = bsModal(); if (mm) { mm.hide(); } window.location.reload(); }, 900);
            });
         }
         return load(j.next);
      }).catch(function () { msg(M.error, false); }).finally(function () { st.busy = false; });
   }

   function back() {
      var i = idx();
      if (i > 0) { load(st.outline.steps[i - 1].name); }
   }

   function test() {
      var form = shell().querySelector('.nx-wiz-form');
      msg(M.loading, true);
      post({ action: 'test', module: st.key, step: st.current }, form).then(function (j) {
         msg((j && j.message) || M.error, !!(j && j.ok));
      });
   }

   function bsModal() {
      var el = shell();
      if (!window.bootstrap || !window.bootstrap.Modal) { return null; }
      return window.bootstrap.Modal.getOrCreateInstance(el, { backdrop: 'static', keyboard: true });
   }

   function open(key, fromStart) {
      st.key = key; st.finished = false;
      return post({ action: fromStart ? 'reopen' : 'outline', module: key }).then(function (j) {
         if (!j.success || !j.outline || !j.outline.steps || !j.outline.steps.length) { return false; }
         st.outline = j.outline;
         var mm = bsModal();
         if (!mm) { return false; }
         mm.show();
         load(fromStart ? j.outline.steps[0].name : j.outline.current);
         return true;
      });
   }

   window.NextoolWizard = { open: open };

   // Páginas de módulo: abre sozinho se estiver pendente; `nextool_wizard=1` reabre do início.
   function auto() {
      var u;
      try { u = new URL(window.location.href); } catch (e) { return; }
      if (!/\/front\/modules\.php$/.test(u.pathname) && !/\/front\/config\.form\.php$/.test(u.pathname)) { return; }
      var key = (u.searchParams.get('module') || u.searchParams.get('nextool_wizard_module') || '').toLowerCase();
      if (!/^[a-z0-9_-]+$/.test(key)) { return; }
      if (u.searchParams.get('nextool_wizard') === '1') { open(key, true); return; }
      post({ action: 'pending', module: key }).then(function (j) { if (j && j.pending) { open(key, false); } });
   }
   if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', auto); } else { auto(); }
})();
