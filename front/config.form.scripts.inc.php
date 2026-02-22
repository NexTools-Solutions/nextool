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

function nextoolExtractMainConfigForcetab(value) {
   if (!value) return '';
   var decoded = String(value);
   try {
      decoded = decodeURIComponent(decoded);
   } catch (e) {
      // mantém valor bruto quando não for URI-encoded
   }
   var explicitMatch = decoded.match(/PluginNextoolMainConfig\$\d+/);
   if (explicitMatch) {
      return explicitMatch[0];
   }

   // IDs/panes do GLPI (ex.: tab-PluginNextoolMainConfig_4-123456789)
   var glpiPaneMatch = decoded.match(/PluginNextoolMainConfig_(\d+)/);
   if (glpiPaneMatch && glpiPaneMatch[1]) {
      return 'PluginNextoolMainConfig$' + glpiPaneMatch[1];
   }

   // Mapeamento das abas internas do config.form.php (modo não-standalone)
   var normalized = decoded.toLowerCase();
   if (normalized.indexOf('rt-tab-modulos') !== -1) return 'PluginNextoolMainConfig$1';
   if (normalized.indexOf('rt-tab-contato') !== -1) return 'PluginNextoolMainConfig$2';
   if (normalized.indexOf('rt-tab-licenca') !== -1) return 'PluginNextoolMainConfig$3';
   if (normalized.indexOf('rt-tab-logs') !== -1) return 'PluginNextoolMainConfig$4';

   return '';
}

function nextoolResolveActiveForcetab() {
   var candidates = [];

   // 1) Aba ativa no bloco interno (config.form.php em modo não-standalone)
   var internalActiveTab = document.querySelector('#nextool-config-tabs .nav-link.active');
   if (internalActiveTab) {
      candidates.push(internalActiveTab.getAttribute('data-nextool-forcetab'));
      candidates.push(internalActiveTab.getAttribute('data-bs-target'));
      candidates.push(internalActiveTab.getAttribute('href'));
      candidates.push(internalActiveTab.getAttribute('id'));
   }

   // 2) Aba ativa nativa do GLPI (standalone nextoolconfig.form.php)
   var glpiActiveTab = document.querySelector('[role="tab"][aria-selected="true"], .nav-tabs .nav-link.active');
   if (glpiActiveTab) {
      candidates.push(glpiActiveTab.getAttribute('data-glpi-tab'));
      candidates.push(glpiActiveTab.getAttribute('data-tab'));
      candidates.push(glpiActiveTab.getAttribute('data-bs-target'));
      candidates.push(glpiActiveTab.getAttribute('href'));
      candidates.push(glpiActiveTab.getAttribute('id'));
   }

   // 3) Query string atual (fallback)
   try {
      var params = new URLSearchParams(window.location.search);
      candidates.push(params.get('forcetab'));
      candidates.push(params.get('_glpi_tab'));
   } catch (e) {
      // sem fallback adicional
   }

   for (var i = 0; i < candidates.length; i++) {
      var resolved = nextoolExtractMainConfigForcetab(candidates[i]);
      if (resolved !== '') return resolved;
   }

   // 4) Último fallback seguro para Nextool
   return 'PluginNextoolMainConfig$1';
}

function nextoolResolveForcetabFromTrigger(triggerElement) {
   if (!triggerElement || typeof triggerElement.closest !== 'function') {
      return '';
   }

   // Fonte mais confiável: valor explicitamente definido no botão do hero.
   var buttonForcetab = nextoolExtractMainConfigForcetab(triggerElement.getAttribute('data-nextool-forcetab'));
   if (buttonForcetab !== '') {
      return buttonForcetab;
   }

   // GLPI standalone: id costuma vir como tab-PluginNextoolMainConfig_4-xxxxxxxx
   var glpiPane = triggerElement.closest('[id^="tab-PluginNextoolMainConfig_"]');
   if (glpiPane && glpiPane.id) {
      var glpiMatch = glpiPane.id.match(/tab-PluginNextoolMainConfig_(\d+)/);
      if (glpiMatch && glpiMatch[1]) {
         return 'PluginNextoolMainConfig$' + glpiMatch[1];
      }
   }

   // Fallback para o modo não-standalone do arquivo config.form.php
   var internalPane = triggerElement.closest('[id^="rt-tab-"]');
   if (internalPane && internalPane.id) {
      var map = {
         'rt-tab-modulos': 'PluginNextoolMainConfig$1',
         'rt-tab-contato': 'PluginNextoolMainConfig$2',
         'rt-tab-licenca': 'PluginNextoolMainConfig$3',
         'rt-tab-logs': 'PluginNextoolMainConfig$4'
      };
      if (Object.prototype.hasOwnProperty.call(map, internalPane.id)) {
         return map[internalPane.id];
      }
   }

   return '';
}

function nextoolEnsureForcetabInput(form, triggerElement) {
   if (!form) return;
   var forcetabInput = form.querySelector('input[name="forcetab"]');
   if (!forcetabInput) {
      forcetabInput = document.createElement('input');
      forcetabInput.type = 'hidden';
      forcetabInput.name = 'forcetab';
      form.appendChild(forcetabInput);
   }

   var resolvedForcetab = nextoolResolveForcetabFromTrigger(triggerElement);
   if (!resolvedForcetab) {
      resolvedForcetab = nextoolResolveActiveForcetab();
   }
   forcetabInput.value = resolvedForcetab;
}

function nextoolValidateLicense(btn) {
   // Prioriza o formulário dedicado para sincronização (evita enviar campos
   // de configuração por engano quando o botão fica fora de um <form>).
   var form = (btn && btn.form)
      ? btn.form
      : document.getElementById('nextoolSyncForm') || document.getElementById('configForm');
   if (!form) return false;
   nextoolEnsureForcetabInput(form, btn);
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
   nextoolEnsureForcetabInput(form, btn);
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

function nextoolCopyHmacSecret(btn) {
   if (!btn || !btn.dataset) return;
   var endpoint = btn.dataset.secretEndpoint || '';
   if (endpoint === '') return;

   var csrfInput = null;
   if (typeof btn.closest === 'function') {
      var parentForm = btn.closest('form');
      if (parentForm) {
         csrfInput = parentForm.querySelector('input[name="_glpi_csrf_token"]');
      }
   }
   if (!csrfInput) {
      csrfInput = document.querySelector('#nextoolSyncForm input[name="_glpi_csrf_token"]');
   }
   if (!csrfInput) {
      csrfInput = document.querySelector('#configForm input[name="_glpi_csrf_token"]');
   }
   var csrfToken = csrfInput ? csrfInput.value : '';
   if (csrfToken === '') return;

   var original = btn.innerHTML;
   btn.disabled = true;

   fetch(endpoint, {
      method: 'POST',
      headers: {
         'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
         'X-Requested-With': 'XMLHttpRequest',
         'X-Glpi-Csrf-Token': csrfToken
      },
      body: '_glpi_csrf_token=' + encodeURIComponent(csrfToken),
      credentials: 'same-origin'
   })
      .then(function (response) { return response.json().catch(function () { return {}; }); })
      .then(function (data) {
         if (!data || !data.success || !data.secret) {
            throw new Error((data && data.message) ? data.message : 'Falha ao copiar chave.');
         }
         return navigator.clipboard.writeText(String(data.secret)).then(function () {
            btn.innerHTML = '<i class="ti ti-check me-1"></i><?php echo Html::entities_deep(__('Copiado!', 'nextool')); ?>';
         });
      })
      .catch(function () {
         btn.innerHTML = '<i class="ti ti-alert-circle me-1"></i><?php echo Html::entities_deep(__('Falha ao copiar', 'nextool')); ?>';
      })
      .finally(function () {
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
