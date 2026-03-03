<?php
/**
 * Nextools - Config Form Scripts
 *
 * Scripts compartilhados do formulário de configuração (contato, abas, validação).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
?>
<script type="text/javascript">
function nextoolActivateDefaultTab() {
   var tabsContainer = document.getElementById('nextool-config-tabs');
   if (!tabsContainer) return;
   var firstTab = tabsContainer.querySelector('button.nav-link');
   if (!firstTab) return;
   if (window.bootstrap && bootstrap.Tab) {
      bootstrap.Tab.getOrCreateInstance(firstTab).show();
   } else {
      firstTab.classList.add('active');
      var targetSelector = firstTab.getAttribute('data-bs-target');
      var target = targetSelector ? document.querySelector(targetSelector) : null;
      if (target) {
         target.classList.add('show', 'active');
         target.style.display = 'block';
      }
   }
}
if (document.readyState === 'loading') {
   document.addEventListener('DOMContentLoaded', nextoolActivateDefaultTab);
} else {
   nextoolActivateDefaultTab();
}
document.addEventListener('glpi.load', nextoolActivateDefaultTab);

// --- Persistência de aba ativa ---
// Quando o GLPI troca de aba via dropdown (AJAX), a URL não muda.
// Se forcetab ficasse na URL, um refresh forçaria a aba original do sidebar.
// Solução: limpar forcetab da URL após o load e rastrear a aba ativa em JS.
(function() {
   // 1. Limpar forcetab da URL para que refreshes respeitem a sessão do GLPI
   if (window.history && window.history.replaceState) {
      var url = new URL(window.location.href);
      if (url.searchParams.has('forcetab')) {
         url.searchParams.delete('forcetab');
         window.history.replaceState(null, '', url.toString());
      }
   }

   // 2. Rastrear aba ativa: quando GLPI troca aba, extrair _glpi_tab do link
   function getTabKeyFromLink(link) {
      var ajaxUrl = link.getAttribute('data-glpi-ajax-content') || '';
      var match = ajaxUrl.match(/_glpi_tab=([^&]+)/);
      return match ? decodeURIComponent(match[1]) : null;
   }

   document.addEventListener('shown.bs.tab', function(e) {
      var tabKey = getTabKeyFromLink(e.target);
      if (!tabKey) return;

      // Guardar globalmente para ações de módulo e formulários
      window._nextoolCurrentTab = tabKey;

      // Atualizar hidden forcetab no nextoolSyncForm
      var syncForm = document.getElementById('nextoolSyncForm');
      if (syncForm) {
         var field = syncForm.querySelector('input[name="forcetab"]');
         if (field) field.value = tabKey;
      }

      // Atualizar hidden forcetab em configForm (se existir)
      var configForm = document.getElementById('configForm');
      if (configForm) {
         var cfField = configForm.querySelector('input[name="forcetab"]');
         if (cfField) cfField.value = tabKey;
      }
   });
})();

function nextoolGetAjaxCsrfToken() {
   // GLPI 10 expõe getAjaxCsrfToken() via common.js; manter fallback por segurança.
   if (typeof window.getAjaxCsrfToken === 'function') {
      return window.getAjaxCsrfToken();
   }
   var meta = document.querySelector('meta[property="glpi:csrf_token"]');
   return meta ? meta.getAttribute('content') : '';
}

function nextoolInitModuleActions() {
   if (document.documentElement.dataset.nextoolModuleActionsBound === '1') return;
   document.documentElement.dataset.nextoolModuleActionsBound = '1';

   var endpoint = <?php echo json_encode(Plugin::getWebDir('nextool') . '/ajax/module_action.php'); ?>;

   document.addEventListener('click', function (event) {
      var btn = event.target && typeof event.target.closest === 'function'
         ? event.target.closest('button.nextool-module-action')
         : null;
      if (!btn) return;

      event.preventDefault();
      event.stopPropagation();

      if (btn.disabled) return;
      var action = (btn.dataset && btn.dataset.action) ? String(btn.dataset.action) : '';
      var moduleKey = (btn.dataset && btn.dataset.module) ? String(btn.dataset.module) : '';
      if (action === '' || moduleKey === '') return;

      var confirmMsg = (btn.dataset && btn.dataset.confirm) ? String(btn.dataset.confirm) : '';
      if (confirmMsg !== '' && !window.confirm(confirmMsg)) return;

      var csrfToken = nextoolGetAjaxCsrfToken();
      if (!csrfToken) {
         // Sem token: a página está inconsistente; recarregar resolve.
         window.location.reload();
         return;
      }

      // Mantém a aba atual ao voltar do redirect (usa aba rastreada pelo listener shown.bs.tab)
      var forcetab = window._nextoolCurrentTab || new URLSearchParams(window.location.search || '').get('forcetab') || 'PluginNextoolMainConfig$1';

      var originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="ti ti-loader-2 me-1"></i><?php echo Html::entities_deep(__('Processando...', 'nextool')); ?>';

      fetch(endpoint, {
         method: 'POST',
         headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': csrfToken
         },
         body: new URLSearchParams({
            action: action,
            module: moduleKey,
            forcetab: forcetab
         }).toString(),
         credentials: 'same-origin'
      })
         .then(function (r) { return r.json().catch(function () { return {}; }); })
         .then(function (data) {
            if (data && data.redirect_url) {
               window.location.assign(String(data.redirect_url));
               return;
            }
            // Fallback: recarregar para exibir mensagens (se existirem)
            window.location.reload();
         })
         .catch(function () {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
         });
   });
}

if (document.readyState === 'loading') {
   document.addEventListener('DOMContentLoaded', nextoolInitModuleActions);
} else {
   nextoolInitModuleActions();
}
document.addEventListener('glpi.load', nextoolInitModuleActions);

function nextoolValidateLicense(btn) {
   var form = (btn && btn.form) ? btn.form : document.getElementById('configForm') || document.getElementById('nextoolSyncForm');
   if (!form) return false;
   var hasAcceptedPolicies = <?php echo !empty($hasAcceptedPolicies) ? 'true' : 'false'; ?>;
   if (!hasAcceptedPolicies) {
      var msg = 'Ao sincronizar a licença do NexTool pela primeira vez, serão enviados apenas dados técnicos do ambiente (domínio, código do ambiente, chave da licença, IP do servidor e versões do sistema) para a plataforma de licenciamento NexTool. Nenhum dado de chamados, usuários finais ou anexos é coletado.\n\nVocê concorda com esta política de uso e coleta de dados?';
      if (!window.confirm(msg)) return false;
   }
   var actionInput = form.querySelector('input[name="action"]');
   if (!actionInput) {
      actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      form.appendChild(actionInput);
   }
   actionInput.value = hasAcceptedPolicies ? 'validate_license' : 'accept_policies';
   form.submit();
   return false;
}

function nextoolRegenerateHmac(btn) {
   var form = document.getElementById('configForm');
   if (!form) return false;
   if (!window.confirm('Gerar uma nova chave de segurança invalida a chave atual imediatamente. Todas as integrações que usam a chave antiga deixarão de funcionar até que o novo valor seja atualizado. Deseja continuar?')) return false;
   var actionInput = form.querySelector('input[name="action"]');
   if (!actionInput) {
      actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      form.appendChild(actionInput);
   }
   actionInput.value = 'regenerate_hmac';
   form.submit();
   return false;
}

function nextoolInitContactForm() {
   var form = document.getElementById('nextool-contact-form');
   if (!form || form.dataset.bound === '1') return;
   form.dataset.bound = '1';
   var feedback = document.getElementById('nextool-contact-feedback');
   var submitButton = form.querySelector('button[type="submit"]');
   form.addEventListener('submit', function (event) {
      event.preventDefault();
      event.stopPropagation();
      form.classList.add('was-validated');
      if (!form.checkValidity()) return;
      var formData = new FormData(form);
      // GLPI 10: para requisições AJAX, usar o token do meta `glpi:csrf_token` no header.
      // O token do formulário continua indo no body via FormData.
      var csrfToken = nextoolGetAjaxCsrfToken();
      if (!csrfToken) {
         // Fallback: se o meta não existir por algum motivo, tentar o token do formulário.
         try {
            csrfToken = String(formData.get('_glpi_csrf_token') || '');
         } catch (e) {
            csrfToken = '';
         }
      }
      if (submitButton) submitButton.disabled = true;
      if (feedback) { feedback.classList.remove('text-danger', 'text-success'); feedback.classList.add('text-muted'); feedback.textContent = 'Enviando contato...'; }
      fetch(form.action, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrfToken }, credentials: 'same-origin' })
         .then(function (r) { return r.json().catch(function () { return {}; }); })
         .then(function (data) {
            if (feedback) {
               feedback.classList.remove('text-muted');
               if (data && data.success) {
                  form.reset();
                  form.classList.remove('was-validated');
                  feedback.classList.add('text-success');
                  feedback.textContent = data.message || 'Contato enviado com sucesso! Nossa equipe retornará em breve.';
               } else {
                  feedback.classList.add('text-danger');
                  feedback.textContent = (data && data.message) ? data.message : 'Não foi possível enviar o contato. Tente novamente em instantes.';
               }
            }
         })
         .catch(function () {
            if (feedback) { feedback.classList.remove('text-muted'); feedback.classList.add('text-danger'); feedback.textContent = 'Erro inesperado ao enviar o formulário.'; }
         })
         .finally(function () { if (submitButton) submitButton.disabled = false; });
   });
}

function nextoolInitContactSourceField() {
   var wrapper = document.getElementById('contact-source-other-wrapper');
   if (!wrapper || wrapper.dataset.bound === '1') return;
   wrapper.dataset.bound = '1';
   var selectEl = document.getElementById('contact-source');
   if (!selectEl) return;
   function refresh() {
      wrapper.classList.toggle('d-none', selectEl.value !== 'outros');
   }
   selectEl.addEventListener('change', refresh);
   refresh();
}

function nextoolInitContactModulesField() {
   var wrapper = document.getElementById('contact-modules-other-wrapper');
   if (!wrapper || wrapper.dataset.bound === '1') return;
   wrapper.dataset.bound = '1';
   var cb = document.getElementById('contact-module-outros');
   if (cb) {
      cb.addEventListener('change', function () { wrapper.classList.toggle('d-none', !cb.checked); });
      wrapper.classList.toggle('d-none', !cb.checked);
   }
}

function _nextoolInitContactAll() {
   nextoolInitContactForm();
   nextoolInitContactSourceField();
   nextoolInitContactModulesField();
}
if (document.readyState === 'loading') {
   document.addEventListener('DOMContentLoaded', _nextoolInitContactAll);
} else {
   _nextoolInitContactAll();
}
document.addEventListener('glpi.load', _nextoolInitContactAll);

// ─── Module Filter/Search (event delegation — robusto para AJAX) ────────────
(function() {
   var activeFilters = new Set();
   var debounceTimer = null;

   function applyFilters() {
      var searchInput = document.getElementById('nextool-module-search');
      var noResults = document.getElementById('nextool-module-no-results');
      var cards = document.querySelectorAll('.nextool-module-card');
      if (!searchInput || !cards.length) return;

      var query = searchInput.value.toLowerCase().trim();
      var visibleCount = 0;

      cards.forEach(function(card) {
         var matchesText = true;
         if (query) {
            matchesText = (card.dataset.moduleName || '').indexOf(query) !== -1
                       || (card.dataset.moduleDesc || '').indexOf(query) !== -1;
         }

         var matchesChip = true;
         if (activeFilters.size > 0) {
            matchesChip = false;
            if (activeFilters.has('enabled') && card.dataset.moduleEnabled === '1') matchesChip = true;
            if (activeFilters.has('disabled') && card.dataset.moduleInstalled === '1' && card.dataset.moduleEnabled === '0') matchesChip = true;
            if (activeFilters.has('download') && card.dataset.moduleDownloaded === '0') matchesChip = true;
            if (activeFilters.has('update') && card.dataset.moduleUpdate === '1') matchesChip = true;
            if (activeFilters.has('free') && card.dataset.moduleTier === 'FREE') matchesChip = true;
            if (activeFilters.has('licensed') && card.dataset.moduleTier !== 'FREE') matchesChip = true;
         }

         var visible = matchesText && matchesChip;
         card.style.display = visible ? 'block' : 'none';
         if (visible) visibleCount++;
      });

      if (noResults) {
         noResults.classList.toggle('d-none', visibleCount > 0);
      }
   }

   document.addEventListener('input', function(e) {
      if (e.target && e.target.id === 'nextool-module-search') {
         clearTimeout(debounceTimer);
         debounceTimer = setTimeout(applyFilters, 250);
      }
   });

   document.addEventListener('keydown', function(e) {
      if (e.target && e.target.id === 'nextool-module-search' && e.key === 'Escape') {
         e.target.value = '';
         applyFilters();
      }
   });

   document.addEventListener('click', function(e) {
      var chip = e.target.closest('.nextool-filter-chip');
      if (!chip) return;
      var filter = chip.dataset.filter;
      if (!filter) return;

      if (activeFilters.has(filter)) {
         activeFilters.delete(filter);
         chip.classList.remove('active');
      } else {
         activeFilters.add(filter);
         chip.classList.add('active');
      }
      applyFilters();
   });
})();
</script>
