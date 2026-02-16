<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Config Form Scripts
 * -------------------------------------------------------------------------
 * Scripts compartilhados do formulário de configuração (contato, abas,
 * validação). Incluído por config.form.php (modo standalone e full).
 * -------------------------------------------------------------------------
 * @author    Richard Loureiro
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
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

function nextoolValidateLicense(btn) {
   var form = (btn && btn.form) ? btn.form : document.getElementById('configForm') || document.getElementById('nextoolSyncForm');
   if (!form) return false;
   var hasAcceptedPolicies = <?php echo !empty($hasAcceptedPolicies) ? 'true' : 'false'; ?>;
   if (!hasAcceptedPolicies) {
      var msg = 'Ao validar a licença do Nextool pela primeira vez, serão enviados dados técnicos do ambiente (domínio, identificador do cliente, chave de licença, IP do servidor e versões de GLPI/PHP/plugin) ao ContainerAPI apenas para fins de licenciamento, controle de ambientes e auditoria técnica. Nenhum dado de tickets, usuários finais ou anexos é coletado.\n\nVocê concorda com esta política de uso e coleta de dados?';
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
   if (!window.confirm('Gerar um novo segredo HMAC invalida o segredo atual imediatamente. Todos os ambientes ou integrações que utilizam o segredo antigo deixarão de funcionar até que o novo valor seja propagado. Deseja continuar?')) return false;
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

function nextoolCopyHmacSecret(btn) {
   if (!btn || !btn.dataset) return;
   var secret = btn.dataset.secret || '';
   if (secret === '') return;
   navigator.clipboard.writeText(secret).then(function () {
      var original = btn.innerHTML;
      btn.innerHTML = '<i class="ti ti-check me-1"></i><?php echo Html::entities_deep(__('Copiado!', 'nextool')); ?>';
      btn.disabled = true;
      setTimeout(function () { btn.innerHTML = original; btn.disabled = false; }, 2000);
   });
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
      var csrfInput = form.querySelector('input[name="_glpi_csrf_token"]');
      var csrfToken = csrfInput ? csrfInput.value : '';
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
