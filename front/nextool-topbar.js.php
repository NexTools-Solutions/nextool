<?php
/**
 * NexTool - Barra superior administrada pela base (window.NextoolTopbar) -- nextool-dev#297.
 *
 * Os módulos NÃO põem mais ícone no cabeçalho por conta própria: registram o ícone pela própria chave
 * e a base cuida de posição, ordem, geometria, indicadores (badge, ponto, estados) e da moldura do
 * painel (popover ancorado: Esc, clique fora, foco devolvido, um aberto por vez). O módulo continua
 * dono dos dados (polling, som) e do conteúdo do painel.
 *
 *    const h = NextoolTopbar.register('livechat', {
 *       icon: 'ti ti-messages', label: 'Conversas',
 *       panel: { title: 'Conversas', width: 440, render(body, api) {…}, onOpen(api) {…} }
 *    });
 *    h.setBadge(3); h.setDot('#206bc4'); h.pulse();
 *
 * A POSIÇÃO de cada ícone é da base (tabela ORDER abaixo), não do módulo. Chave fora da tabela entra
 * antes do último da lista (o status de disponibilidade fica sempre colado no bloco do usuário).
 *
 * Servido sem query string (o GLPI 10 valida o path do hook com file_exists): vale nos 3 majors.
 * Registrado em setup.php ANTES do loadActiveModules, então existe quando o JS dos módulos roda.
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

$nxTopbarI18n = [
   'close' => __('Fechar', 'nextool'),
   'bar'   => __('Atalhos do NexTool', 'nextool'),
];
?>
(function () {
   'use strict';
   if (window.NextoolTopbar) { return; }

   var I18N = <?php echo json_encode($nxTopbarI18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

   // Posição de cada ícone (esquerda -> direita, terminando no menu do usuário). Decisão do owner
   // (2026-11-09): acessibilidade na ponta oposta, depois conversas, avisos e IA; a disponibilidade é o
   // status do usuário e fica colada no nome dele (como no Teams/Slack). Só a base mexe nesta lista.
   var ORDER = ['accessibility', 'livechat', 'smartnotify', 'aiassist', 'smartassign'];
   var WIDTHS = {sm: 380, md: 460, lg: 560};
   // Transição: ícones de módulos que ainda não migraram para a barra. Eles entram sozinhos antes do bloco
   // do usuário; a barra fica DEPOIS deles para não inverter a ordem. Sai desta lista quem migrar.
   var LEGACY = '.nxsa-tb, .nxlc-tb, .smartnotify-bell-wrapper';

   var items = {};
   var bar = null;
   var openKey = null;
   var styleDone = false;

   function el(tag, cls, attrs) {
      var n = document.createElement(tag);
      if (cls) { n.className = cls; }
      Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
      return n;
   }
   function visible(n) { return !!n && n.offsetWidth > 0 && n.offsetHeight > 0; }
   // Chave fora da tabela entra antes do último (o status), nunca entre ele e o bloco do usuário.
   function rank(key) { var i = ORDER.indexOf(key); return i < 0 ? ORDER.length - 1.5 : i; }

   function injectStyle() {
      if (styleDone) { return; }
      styleDone = true;
      var css = ''
         + '.nx-topbar{display:flex;align-items:center;align-self:center;gap:1rem;margin:0 1rem}'
         + '.nx-topbar.nav-item{margin:0 .25rem;gap:.5rem}'
         // O bloco do usuário do GLPI tem margem própria (ms-md-4 = 1.5rem) que somava à da barra: o vão à direita
         // ficava ~2.5x o da esquerda. Colada nele, a barra assume o espaçamento e o .75rem compensa o p-1 do link
         // do perfil, para os dois vãos visuais ficarem iguais.
         + '.nx-topbar:not(.nav-item):not(:empty){margin-right:.75rem}'
         + '.nx-topbar:not(.nav-item):not(:empty) + *{margin-left:0 !important}'
         // Transição: o sino antigo tem 1rem fixo à direita e não pareia; colada nele, a barra não soma margem.
         + '.smartnotify-bell-wrapper + .nx-topbar{margin-left:0 !important}'
         + '.nx-topbar:empty{display:none}'
         + '.nx-tb-item{position:relative;display:flex;align-items:center;justify-content:center}'
         + '.nx-tb-btn{position:relative;display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;padding:0;border:0;'
         + 'border-radius:50%;background:transparent;color:var(--nx-tb-color,var(--tblr-navbar-color,#6c7a91));font-size:1.25rem;line-height:1;cursor:pointer;text-decoration:none}'
         + '.nx-tb-btn:hover,.nx-tb-btn[aria-expanded="true"]{background:var(--tblr-bg-surface-secondary,#f6f8fb);color:var(--nx-tb-color,var(--tblr-primary,#206bc4))}'
         + '.nx-tb-btn:focus-visible{outline:2px solid var(--tblr-primary,#206bc4);outline-offset:2px}'
         + '.nx-tb-btn svg{width:1.25rem;height:1.25rem}'
         + '.nx-tb-badge{position:absolute;top:-4px;right:-6px;min-width:17px;height:17px;padding:0 4px;border-radius:9px;background:#d63939;color:#fff;'
         + 'font-size:10.5px;font-weight:700;line-height:17px;text-align:center;pointer-events:none}'
         + '.nx-tb-dot{position:absolute;right:0;bottom:1px;width:9px;height:9px;border-radius:50%;border:2px solid var(--tblr-bg-surface,#fff);pointer-events:none}'
         + '.nx-tb-critical .nx-tb-btn{color:#d63939}'
         + '@media (prefers-reduced-motion: no-preference){'
         + '.nx-tb-pulse .nx-tb-btn{animation:nx-tb-pulse 2s ease-out 3}'
         + '.nx-tb-ring .nx-tb-btn{animation:nx-tb-ring .8s ease-in-out 1}'
         + '.nx-tb-critical .nx-tb-btn{animation:nx-tb-sway 2.4s ease-in-out infinite}'
         + '}'
         + '@keyframes nx-tb-pulse{0%{box-shadow:0 0 0 0 rgba(32,107,196,.45)}100%{box-shadow:0 0 0 10px rgba(32,107,196,0)}}'
         + '@keyframes nx-tb-ring{0%,100%{transform:rotate(0)}20%{transform:rotate(14deg)}40%{transform:rotate(-12deg)}60%{transform:rotate(8deg)}80%{transform:rotate(-4deg)}}'
         + '@keyframes nx-tb-sway{0%,100%{transform:rotate(0)}10%{transform:rotate(10deg)}20%{transform:rotate(-8deg)}30%{transform:rotate(0)}}'
         + '.nx-tb-panel{position:fixed;z-index:1056;display:flex;flex-direction:column;max-height:min(72vh,640px);max-width:calc(100vw - 16px);'
         + 'background:var(--tblr-bg-surface,#fff);color:var(--tblr-body-color,#1d273b);border:1px solid var(--tblr-border-color,#dce1e7);border-radius:8px;box-shadow:0 8px 28px rgba(0,0,0,.18)}'
         + '.nx-tb-panel[hidden]{display:none}'
         + '.nx-tb-panel:focus{outline:none}'
         + '.nx-tb-head{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid var(--tblr-border-color,#e6e8eb)}'
         + '.nx-tb-head h2{flex:1;margin:0;font-size:1rem;font-weight:600}'
         + '.nx-tb-actions{display:flex;align-items:center;gap:4px}'
         + '.nx-tb-act{display:inline-flex;align-items:center;justify-content:center;border:0;background:transparent;color:inherit;width:28px;height:28px;border-radius:6px;font-size:1.1rem;line-height:1;cursor:pointer;text-decoration:none}'
         + '.nx-tb-act:hover{background:var(--tblr-bg-surface-secondary,#f6f8fb);color:var(--tblr-primary,#206bc4)}'
         + '.nx-tb-act:focus-visible{outline:2px solid var(--tblr-primary,#206bc4)}'
         + '.nx-tb-close{border:0;background:transparent;color:inherit;width:28px;height:28px;border-radius:6px;font-size:1.1rem;line-height:1;cursor:pointer}'
         + '.nx-tb-close:hover{background:var(--tblr-bg-surface-secondary,#f6f8fb)}'
         + '.nx-tb-close:focus-visible{outline:2px solid var(--tblr-primary,#206bc4)}'
         + '.nx-tb-body{flex:1;overflow:auto;padding:12px 14px}'
         // Rodapé fixo (fora da rolagem): link do módulo à esquerda, ações (ex.: Salvar) à direita.
         + '.nx-tb-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 14px;border-top:1px solid var(--tblr-border-color,#e6e8eb);font-size:.875rem}'
         + '.nx-tb-foot:empty{display:none}'
         + '.nx-tb-foot > :last-child:not(:first-child){margin-left:auto}'
         + '@media (max-width:575.98px){.nx-tb-panel.nx-tb-m-full{inset:0 !important;width:auto !important;max-width:none;max-height:none;border-radius:0}}';
      var st = el('style', '', {id: 'nx-topbar-style'});
      st.textContent = css;
      document.head.appendChild(st);
   }

   // ------------------------------------------------------------------ contêiner e posição
   function findAnchor() {
      var header = document.querySelector('header.navbar .header-container');
      if (header) {
         for (var i = 0; i < header.children.length; i++) {
            var ch = header.children[i];
            if (ch !== bar && visible(ch) && ch.querySelector('.user-menu')) { return {parent: header, before: ch, navItem: false}; }
         }
      }
      var menus = document.querySelectorAll('.navbar-nav.user-menu');
      for (var j = 0; j < menus.length; j++) {
         if (visible(menus[j])) { return {parent: menus[j], before: menus[j].firstChild, navItem: true}; }
      }
      return null;
   }

   function placeBar() {
      if (bar && bar.isConnected && !legacyAfterBar()) { return true; }
      var a = findAnchor();
      if (!a) { return false; }
      injectStyle();
      if (!bar) { bar = el('div', 'nx-topbar', {'aria-label': I18N.bar, role: 'group'}); }
      bar.classList.toggle('nav-item', a.navItem);
      a.parent.insertBefore(bar, a.before);
      sortBar();
      return true;
   }

   // Algum ícone antigo ficou entre a barra e o bloco do usuário? (ele entrou depois da barra)
   function legacyAfterBar() {
      if (!bar || !bar.parentNode) { return false; }
      for (var n = bar.nextElementSibling; n; n = n.nextElementSibling) {
         if (n.matches && n.matches(LEGACY)) { return true; }
      }
      return false;
   }

   function sortBar() {
      if (!bar) { return; }
      Object.keys(items).sort(function (x, y) { return (rank(x) - rank(y)) || (x < y ? -1 : 1); })
         .forEach(function (k) { if (items[k].wrap) { bar.appendChild(items[k].wrap); } });
   }

   // O GLPI monta parte do cabeçalho depois e alguns fluxos o redesenham: recoloca a barra.
   var watching = false;
   function watch() {
      if (watching || !('MutationObserver' in window)) { return; }
      watching = true;
      var pending = false;
      new MutationObserver(function () {
         if (pending) { return; }
         pending = true;
         window.requestAnimationFrame(function () {
            pending = false;
            if (!bar || !bar.isConnected || legacyAfterBar()) { placeBar(); }
         });
      }).observe(document.body, {childList: true, subtree: true});
   }

   // ------------------------------------------------------------------ painel (popover ancorado)
   function position(it) {
      var p = it.panel, r = it.btn.getBoundingClientRect();
      var w = Math.min(it.width, window.innerWidth - 16);
      p.style.width = w + 'px';
      var left = Math.round(r.right - w);
      left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
      p.style.left = left + 'px';
      p.style.top = Math.round(r.bottom + 8) + 'px';
   }

   function buildPanel(key, it) {
      var o = it.opts.panel;
      var id = 'nx-tb-panel-' + key;
      var p = el('div', 'nx-tb-panel', {id: id, role: 'dialog', 'aria-labelledby': id + '-title', tabindex: '-1'});
      if (o.mobile === 'full') { p.classList.add('nx-tb-m-full'); }
      p.hidden = true;
      var head = el('div', 'nx-tb-head');
      var h2 = el('h2', '', {id: id + '-title'});
      h2.textContent = o.title || it.opts.label || '';
      var actions = el('div', 'nx-tb-actions');
      head.appendChild(h2); head.appendChild(actions);
      // Sem "X" por padrão, como o painel do sino: fecha por Esc, clique fora ou no próprio ícone.
      if (o.closeButton) {
         var x = el('button', 'nx-tb-close', {type: 'button', 'aria-label': I18N.close, title: I18N.close});
         x.innerHTML = '<i class="ti ti-x" aria-hidden="true"></i>';
         x.addEventListener('click', function () { close(true); });
         head.appendChild(x);
      }
      var body = el('div', 'nx-tb-body');
      var foot = el('div', 'nx-tb-foot');
      var footLink = null;
      if (o.footer && o.footer.href) {
         footLink = el('a', '', {href: o.footer.href});
         footLink.textContent = o.footer.label || '';
      }
      p.appendChild(head); p.appendChild(body); p.appendChild(foot);
      document.body.appendChild(p);
      it.panel = p;
      it.api = {
         key: key, panel: p, body: body, actions: actions, footer: foot,
         setTitle: function (t) { h2.textContent = t; },
         // Ação no cabeçalho (ícone), no mesmo formato para todos: ex. engrenagem de preferências.
         addAction: function (ac) {
            var n = ac.href ? el('a', 'nx-tb-act', {href: ac.href}) : el('button', 'nx-tb-act', {type: 'button'});
            n.setAttribute('title', ac.label || '');
            n.setAttribute('aria-label', ac.label || '');
            n.innerHTML = '<i class="' + (ac.icon || 'ti ti-point') + '" aria-hidden="true"></i>';
            if (typeof ac.onClick === 'function') { n.addEventListener('click', function (e) { ac.onClick(e, it.api); }); }
            actions.appendChild(n);
            return n;
         },
         close: function () { close(true); },
         // O render pode pôr ações no rodapé: refaz o rodapé só com o link da base antes de chamar de novo.
         rerender: function () {
            body.innerHTML = '';
            actions.innerHTML = '';
            foot.innerHTML = '';
            if (footLink) { foot.appendChild(footLink); }
            if (typeof o.render === 'function') { o.render(body, it.api); }
         }
      };
      it.width = typeof o.width === 'number' ? o.width : (WIDTHS[o.width] || WIDTHS.md);
      it.api.rerender();
   }

   function open(key) {
      var it = items[key];
      if (!it || !it.opts.panel) { return; }
      if (openKey && openKey !== key) { close(false); }
      if (!it.panel) { buildPanel(key, it); }
      it.panel.hidden = false;
      position(it);
      it.btn.setAttribute('aria-expanded', 'true');
      openKey = key;
      if (typeof it.opts.panel.onOpen === 'function') { it.opts.panel.onOpen(it.api); }
      // Foco no PRÓPRIO painel (o leitor de tela anuncia o diálogo; o 1º Tab vai ao 1º controle). Focar o 1º
      // controle acendia o anel de foco dele ao abrir com o mouse (o switch do Bootstrap usa :focus), como se
      // estivesse selecionado.
      it.panel.focus({preventScroll: true});
   }

   function close(returnFocus) {
      if (!openKey) { return; }
      var it = items[openKey];
      openKey = null;
      if (!it || !it.panel) { return; }
      it.panel.hidden = true;
      it.btn.setAttribute('aria-expanded', 'false');
      if (typeof it.opts.panel.onClose === 'function') { it.opts.panel.onClose(it.api); }
      if (returnFocus) { it.btn.focus(); }
   }

   // Um modal do módulo aberto (aria-modal visível) tem prioridade: Esc e clique são dele.
   function modalOpen() {
      return Array.prototype.some.call(document.querySelectorAll('[aria-modal="true"]'), function (m) { return visible(m); });
   }

   document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || !openKey || e.defaultPrevented || modalOpen()) { return; }
      e.preventDefault();
      close(true);
   });
   document.addEventListener('mousedown', function (e) {
      if (!openKey || modalOpen()) { return; }
      var it = items[openKey];
      if (!it) { return; }
      var t = e.target;
      if (it.panel.contains(t) || it.wrap.contains(t)) { return; }
      var keep = it.opts.panel.keepOpenSelector;
      if (keep && t.closest && t.closest(keep)) { return; }
      close(false);
   }, true);
   window.addEventListener('resize', function () { if (openKey && items[openKey]) { position(items[openKey]); } });

   // ------------------------------------------------------------------ registro
   function register(key, opts) {
      if (typeof key !== 'string' || !/^[a-z0-9_]+$/.test(key) || !opts) { return null; }
      if (items[key]) { return items[key].handle; }
      var it = {key: key, opts: opts};
      var wrap = el('div', 'nx-tb-item', {'data-nx-topbar': key});
      var hasPanel = !!opts.panel;
      var btn;
      if (opts.href && !hasPanel) {
         btn = el('a', 'nx-tb-btn', {href: opts.href});
      } else {
         btn = el('button', 'nx-tb-btn', {type: 'button'});
         if (hasPanel) {
            btn.setAttribute('aria-haspopup', 'dialog');
            btn.setAttribute('aria-expanded', 'false');
            btn.setAttribute('aria-controls', 'nx-tb-panel-' + key);
         }
      }
      if (opts.color) { btn.style.setProperty('--nx-tb-color', opts.color); }
      var iconBox = el('span', 'nx-tb-icon', {'aria-hidden': 'true'});
      btn.appendChild(iconBox);
      var badge = el('span', 'nx-tb-badge', {'aria-hidden': 'true'});
      badge.hidden = true;
      var dot = el('span', 'nx-tb-dot', {'aria-hidden': 'true'});
      dot.hidden = true;
      btn.appendChild(badge); btn.appendChild(dot);
      wrap.appendChild(btn);

      function setIcon(icon, svg) {
         if (svg) { iconBox.innerHTML = svg; return; }
         iconBox.innerHTML = '';
         iconBox.appendChild(el('i', icon || 'ti ti-point'));
      }
      setIcon(opts.icon, opts.svg);
      btn.setAttribute('title', opts.label || key);
      btn.setAttribute('aria-label', opts.label || key);

      btn.addEventListener('click', function (e) {
         if (hasPanel) {
            e.preventDefault();
            if (openKey === key) { close(true); } else { open(key); }
         } else if (typeof opts.action === 'function') {
            e.preventDefault();
            opts.action(e);
         }
      });

      it.wrap = wrap; it.btn = btn;
      var timers = {};
      function flash(cls, ms) {
         clearTimeout(timers[cls]);
         wrap.classList.remove(cls);
         void wrap.offsetWidth;
         wrap.classList.add(cls);
         timers[cls] = setTimeout(function () { wrap.classList.remove(cls); }, ms);
      }
      it.handle = {
         setBadge: function (n) {
            n = parseInt(n, 10) || 0;
            badge.hidden = n <= 0;
            badge.textContent = n > 99 ? '99+' : String(n);
         },
         setDot: function (color) {
            dot.hidden = !color;
            if (color) { dot.style.background = color; }
         },
         setState: function (state, o) {
            wrap.classList.toggle('nx-tb-critical', state === 'critical');
            if (state === 'critical' && o && (o.icon || o.svg)) { setIcon(o.icon, o.svg); } else if (!state) { setIcon(opts.icon, opts.svg); }
         },
         setLabel: function (t) { btn.setAttribute('title', t); btn.setAttribute('aria-label', t); },
         pulse: function () { flash('nx-tb-pulse', 6000); },
         ring: function () { flash('nx-tb-ring', 800); },
         open: function () { open(key); },
         close: function () { if (openKey === key) { close(true); } },
         rerender: function () { if (it.api) { it.api.rerender(); } },
         remove: function () {
            if (openKey === key) { close(false); }
            if (it.panel) { it.panel.remove(); }
            wrap.remove();
            delete items[key];
         },
         element: btn
      };
      items[key] = it;

      if (placeBar()) { sortBar(); } else {
         // Cabeçalho ainda não montado: entra quando aparecer.
         var tries = 0, t = setInterval(function () {
            if (placeBar() || ++tries > 60) { clearInterval(t); }
         }, 250);
      }
      watch();
      return it.handle;
   }

   window.NextoolTopbar = {
      version: 1,
      register: register,
      get: function (key) { return items[key] ? items[key].handle : null; },
      open: open,
      close: function () { close(true); }
   };
})();
