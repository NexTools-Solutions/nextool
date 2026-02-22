<?php
/**
 * Nextools - Config Form Scripts
 *
 * Scripts compartilhados do formulário de configuração (contato, abas, validação).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
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

      // Mantém a aba atual ao voltar do redirect
      var params = new URLSearchParams(window.location.search || '');
      var forcetab = params.get('forcetab') || 'PluginNextoolMainConfig$1';

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
</script>
