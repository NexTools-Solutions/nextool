<?php
declare(strict_types=1);
/**
 * Nextools - Config Form Scripts
 *
 * Scripts compartilhados do formulário de configuração (contato, abas, validação).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
?>

<!-- Modal de confirmação simples (desinstalar módulo) -->
<div class="modal fade" id="nextool-confirm-modal" tabindex="-1" aria-hidden="true">
   <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
         <div class="modal-header">
            <h5 class="modal-title">
               <i class="ti ti-alert-triangle me-2 text-warning"></i>
               <?php echo __('Confirmação', 'nextool'); ?>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
         </div>
         <div class="modal-body">
            <p id="nextool-confirm-message" class="mb-0"></p>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Cancelar', 'nextool'); ?></button>
            <button type="button" class="btn btn-danger" id="nextool-confirm-btn"><?php echo __('Confirmar', 'nextool'); ?></button>
         </div>
      </div>
   </div>
</div>

<!-- Modal de confirmação com digitação (apagar dados do módulo) -->
<div class="modal fade" id="nextool-typed-confirm-modal" tabindex="-1" aria-hidden="true" data-confirm-word="<?php echo Html::entities_deep(__('excluir', 'nextool')); ?>">
   <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
         <div class="modal-header">
            <h5 class="modal-title">
               <i class="ti ti-alert-octagon me-2 text-danger"></i>
               <?php echo __('Ação irreversível', 'nextool'); ?>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
         </div>
         <div class="modal-body">
            <p id="nextool-typed-confirm-message" class="mb-2"></p>
            <p class="text-danger small mb-3">
               <i class="ti ti-alert-triangle me-1"></i>
               <?php echo __('Esta operação não pode ser desfeita.', 'nextool'); ?>
            </p>
            <label for="nextool-typed-confirm-input" class="form-label"><?php echo __('Digite a palavra de confirmação', 'nextool'); ?></label>
            <input type="text" class="form-control" id="nextool-typed-confirm-input" autocomplete="off" spellcheck="false" placeholder="<?php echo Html::entities_deep(__('excluir', 'nextool')); ?>">
            <div class="form-text">
               <?php echo sprintf(__('Para confirmar, digite a palavra "%s".', 'nextool'), __('excluir', 'nextool')); ?>
            </div>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Cancelar', 'nextool'); ?></button>
            <button type="button" class="btn btn-danger" id="nextool-typed-confirm-btn" disabled><?php echo __('Confirmar exclusão', 'nextool'); ?></button>
         </div>
      </div>
   </div>
</div>

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

// --- Modal de confirmação simples ---
function nextoolShowConfirm(message, onConfirm) {
   var modalEl = document.getElementById('nextool-confirm-modal');
   var msgEl = document.getElementById('nextool-confirm-message');
   var confirmBtn = document.getElementById('nextool-confirm-btn');
   if (!modalEl || !msgEl || !confirmBtn) { if (onConfirm) onConfirm(); return; }

   msgEl.textContent = message;
   var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

   // Remove listener anterior clonando o botão
   var newBtn = confirmBtn.cloneNode(true);
   confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
   newBtn.id = 'nextool-confirm-btn';

   newBtn.addEventListener('click', function () {
      modal.hide();
      if (onConfirm) onConfirm();
   });
   modal.show();
}

// --- Modal de confirmação com digitação ---
function nextoolShowTypedConfirm(message, onConfirm) {
   var modalEl = document.getElementById('nextool-typed-confirm-modal');
   var msgEl = document.getElementById('nextool-typed-confirm-message');
   var inputEl = document.getElementById('nextool-typed-confirm-input');
   var confirmBtn = document.getElementById('nextool-typed-confirm-btn');
   if (!modalEl || !msgEl || !inputEl || !confirmBtn) { if (onConfirm) onConfirm(); return; }

   var confirmWord = (modalEl.dataset.confirmWord || 'excluir').toLowerCase();
   msgEl.textContent = message;
   inputEl.value = '';
   confirmBtn.disabled = true;

   var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

   // Listener no input: habilita botão quando palavra correta é digitada
   var inputHandler = function () {
      confirmBtn.disabled = (inputEl.value || '').trim().toLowerCase() !== confirmWord;
   };
   inputEl.removeEventListener('input', inputHandler);
   inputEl.addEventListener('input', inputHandler);

   // Remove listener anterior clonando o botão
   var newBtn = confirmBtn.cloneNode(true);
   newBtn.disabled = true;
   confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
   newBtn.id = 'nextool-typed-confirm-btn';

   // Reatribuir referência para o input handler funcionar com o botão correto
   confirmBtn = newBtn;
   inputEl.removeEventListener('input', inputHandler);
   inputEl.addEventListener('input', function () {
      confirmBtn.disabled = (inputEl.value || '').trim().toLowerCase() !== confirmWord;
   });

   newBtn.addEventListener('click', function () {
      modal.hide();
      if (onConfirm) onConfirm();
   });

   // Foca no input ao abrir
   modalEl.addEventListener('shown.bs.modal', function focusInput() {
      inputEl.focus();
      modalEl.removeEventListener('shown.bs.modal', focusInput);
   });

   // Limpa input ao fechar
   modalEl.addEventListener('hidden.bs.modal', function resetInput() {
      inputEl.value = '';
      confirmBtn.disabled = true;
      modalEl.removeEventListener('hidden.bs.modal', resetInput);
   });

   modal.show();
}

// --- Executa ação de módulo via AJAX ---
function nextoolExecuteModuleAction(btn, action, moduleKey, endpoint) {
   var csrfToken = nextoolGetAjaxCsrfToken();
   if (!csrfToken) {
      window.location.reload();
      return;
   }

   var forcetab = window._nextoolCurrentTab || new URLSearchParams(window.location.search || '').get('forcetab') || 'PluginNextoolMainConfig$1';
   var moduleFilter = window._nextoolCurrentModuleFilter || new URLSearchParams(window.location.search || '').get('module_filter') || '';

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
         forcetab: forcetab,
         module_filter: moduleFilter
      }).toString(),
      credentials: 'same-origin'
   })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (data) {
         if (data && data.redirect_url) {
            window.location.assign(String(data.redirect_url));
            return;
         }
         window.location.reload();
      })
      .catch(function () {
         btn.innerHTML = originalHtml;
         btn.disabled = false;
      });
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
      var confirmType = (btn.dataset && btn.dataset.confirmType) ? String(btn.dataset.confirmType) : '';

      if (confirmMsg !== '') {
         var execute = function () {
            nextoolExecuteModuleAction(btn, action, moduleKey, endpoint);
         };
         if (confirmType === 'typed') {
            nextoolShowTypedConfirm(confirmMsg, execute);
         } else {
            nextoolShowConfirm(confirmMsg, execute);
         }
         return;
      }

      nextoolExecuteModuleAction(btn, action, moduleKey, endpoint);
   });
}

if (document.readyState === 'loading') {
   document.addEventListener('DOMContentLoaded', nextoolInitModuleActions);
} else {
   nextoolInitModuleActions();
}
document.addEventListener('glpi.load', nextoolInitModuleActions);

function nextoolInitCoreUpdateModal() {
   var modalEl = document.getElementById('nextool-core-update-modal');
   if (!modalEl) return;
   if (modalEl.dataset.nextoolCoreUpdateBound === '1') return;
   modalEl.dataset.nextoolCoreUpdateBound = '1';

   var endpoint = <?php echo json_encode(Plugin::getWebDir('nextool') . '/ajax/core_update.php'); ?>;

   function invokeCoreUpdate(action) {
      var csrfToken = nextoolGetAjaxCsrfToken();
      if (!csrfToken) {
         return Promise.reject(new Error('csrf-token-not-found'));
      }

      return fetch(endpoint, {
         method: 'POST',
         headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': csrfToken
         },
         body: new URLSearchParams({ action: action, channel: 'stable' }).toString(),
         credentials: 'same-origin'
      })
      .then(function(r) {
         return r.json().catch(function() { return {}; }).then(function(payload) {
            return { ok: r.ok, payload: payload };
         });
      })
      .then(function(result) {
         var payload = result.payload || {};
         var success = payload.success === true;
         if (!success) {
            var actionError = new Error(payload.message || 'Falha na execução da ação.');
            actionError.payload = payload;
            throw actionError;
         }
         return payload;
      });
   }

   var actionBtn = document.getElementById('nextool-modal-action-btn');
   var alertEl = document.getElementById('nextool-modal-alert');
   var permDetailEl = document.getElementById('nextool-modal-permission-detail');
   var currentVersionEl = document.getElementById('nextool-modal-current-version');
   var targetVersionEl = document.getElementById('nextool-modal-target-version');
   var confirmApplyEl = document.getElementById('nextool-modal-confirm-apply');
   var confirmApplyInputEl = document.getElementById('nextool-modal-confirm-apply-input');
   var CONFIRM_APPLY_WORD = (confirmApplyEl && confirmApplyEl.dataset.confirmWord || 'confirmo').toLowerCase();

   var STEPS = [
      { action: 'preflight', badge: 'nextool-modal-step-1-badge', status: 'nextool-modal-step-1-status' },
      { action: 'prepare',   badge: 'nextool-modal-step-2-badge', status: 'nextool-modal-step-2-status' },
      { action: 'apply',     badge: 'nextool-modal-step-3-badge', status: 'nextool-modal-step-3-status' }
   ];

   var isRunning = false;
   var wasStarted = false;
   var currentStep = -1; // -1=not started, 0=preflight done, 1=download done

   var LABEL_DONE  = '<i class="ti ti-check me-1"></i>' + <?php echo json_encode(__('Concluído! Recarregando...', 'nextool')); ?>;
   var LABEL_RETRY = '<i class="ti ti-refresh me-1"></i>' + <?php echo json_encode(__('Tentar novamente', 'nextool')); ?>;

   // Backup / Restore labels
   var LABEL_BACKUPS_TITLE = <?php echo json_encode(__('Backups disponíveis', 'nextool')); ?>;
   var LABEL_BACKUPS_DESC = <?php echo json_encode(__('Se uma atualização causar problemas, você pode restaurar uma versão anterior.', 'nextool')); ?>;
   var LABEL_LATEST = <?php echo json_encode(__('mais recente', 'nextool')); ?>;
   var LABEL_RESTORE = <?php echo json_encode(__('Restaurar', 'nextool')); ?>;
   var LABEL_RESTORING = <?php echo json_encode(__('Restaurando...', 'nextool')); ?>;
   var LABEL_RESTORE_CONFIRM = <?php echo json_encode(__('Restaurar o plugin para a versão {version}? Um backup da versão atual será criado antes da restauração.', 'nextool')); ?>;
   var LABEL_RESTORE_SUCCESS = <?php echo json_encode(__('Restauração concluída. Recarregando...', 'nextool')); ?>;
   var LABEL_RESTORE_FAIL = <?php echo json_encode(__('Falha ao restaurar. Consulte os logs.', 'nextool')); ?>;

   // Labels do botão para INICIAR cada step (antes de rodar)
   var STEP_BTN_LABELS = [
      '<i class="ti ti-player-play me-1"></i>' + <?php echo json_encode(__('Verificar ambiente', 'nextool')); ?>,
      '<i class="ti ti-download me-1"></i>' + <?php echo json_encode(__('Baixar atualização', 'nextool')); ?>,
      '<i class="ti ti-upload me-1"></i>' + <?php echo json_encode(__('Aplicar atualização', 'nextool')); ?>
   ];

   // Labels do botão DURANTE execução do step (spinner)
   var STEP_RUNNING_LABELS = [
      '<i class="ti ti-loader-2 ti-spin me-1"></i>' + <?php echo json_encode(__('Verificando...', 'nextool')); ?>,
      '<i class="ti ti-loader-2 ti-spin me-1"></i>' + <?php echo json_encode(__('Baixando...', 'nextool')); ?>,
      '<i class="ti ti-loader-2 ti-spin me-1"></i>' + <?php echo json_encode(__('Aplicando...', 'nextool')); ?>
   ];

   function resetModal() {
      isRunning = false;
      wasStarted = false;
      currentStep = -1;

      if (currentVersionEl) currentVersionEl.textContent = window._nextoolCurrentVersion || '-';
      if (targetVersionEl) targetVersionEl.textContent = window._nextoolTargetVersion || '-';

      STEPS.forEach(function(step, i) {
         var badge = document.getElementById(step.badge);
         var status = document.getElementById(step.status);
         if (badge) { badge.className = 'badge bg-secondary rounded-pill me-2'; badge.textContent = String(i + 1); }
         if (status) status.innerHTML = '';
      });

      if (alertEl) { alertEl.className = 'alert d-none mb-2'; alertEl.textContent = ''; }
      if (permDetailEl) permDetailEl.classList.add('d-none');
      if (confirmApplyEl) confirmApplyEl.classList.add('d-none');
      if (confirmApplyInputEl) confirmApplyInputEl.value = '';
      if (actionBtn) { actionBtn.disabled = false; actionBtn.innerHTML = STEP_BTN_LABELS[0]; }
      if (typeof fetchBackups === 'function') fetchBackups();
   }

   function setStepActive(i) {
      var badge = document.getElementById(STEPS[i].badge);
      if (badge) {
         badge.className = 'badge bg-primary rounded-pill me-2';
         badge.innerHTML = '<i class="ti ti-loader-2 ti-spin" style="font-size:.75rem"></i>';
      }
   }

   function setStepOk(i) {
      var badge = document.getElementById(STEPS[i].badge);
      var status = document.getElementById(STEPS[i].status);
      if (badge) { badge.className = 'badge bg-success rounded-pill me-2'; badge.innerHTML = '<i class="ti ti-check" style="font-size:.75rem"></i>'; }
      if (status) status.innerHTML = '<i class="ti ti-check text-success"></i>';
   }

   function setStepError(i, message) {
      var badge = document.getElementById(STEPS[i].badge);
      var status = document.getElementById(STEPS[i].status);
      if (badge) { badge.className = 'badge bg-danger rounded-pill me-2'; badge.innerHTML = '<i class="ti ti-x" style="font-size:.75rem"></i>'; }
      if (status) status.innerHTML = '<i class="ti ti-x text-danger"></i>';

      if (message && alertEl) {
         alertEl.className = 'alert alert-danger mb-2';
         alertEl.textContent = message;
      }
   }

   var BLOCKER_HELP = {
      'plugin_dir_exists': {
         title: <?php echo json_encode(__('Diretório do plugin não encontrado', 'nextool')); ?>,
         desc: <?php echo json_encode(__('O diretório plugins/nextool/ não existe no servidor.', 'nextool')); ?>,
         fix: <?php echo json_encode(__('Verifique se o plugin NexTool está corretamente instalado. Se o diretório foi removido, reinstale o plugin.', 'nextool')); ?>
      },
      'plugin_dir_writable': {
         title: <?php echo json_encode(__('Sem permissão de escrita no diretório do plugin', 'nextool')); ?>,
         desc: <?php echo json_encode(__('O servidor web não consegue gravar na pasta plugins/nextool/.', 'nextool')); ?>,
         fix: <?php echo json_encode(__('O responsável pela infraestrutura deve conceder permissão de escrita ao usuário do servidor web (Apache/Nginx) no diretório de plugins do GLPI, incluindo a pasta nextool. Em ambientes Docker, verificar se o volume está montado com as permissões corretas.', 'nextool')); ?>
      },
      'backup_dir_writable': {
         title: <?php echo json_encode(__('Diretório de staging sem permissão de escrita', 'nextool')); ?>,
         desc: <?php echo json_encode(__('O NexTool precisa de um diretório temporário com permissão de escrita para preparar a atualização.', 'nextool')); ?>,
         fix: <?php echo json_encode(__('Conceda permissão de escrita ao usuário do servidor web no diretório de dados do GLPI (files/_plugins/nextool/).', 'nextool')); ?>
      },
      'php_extensions': {
         title: <?php echo json_encode(__('Extensões PHP ausentes', 'nextool')); ?>,
         desc: <?php echo json_encode(__('A atualização requer as extensões: curl, zip e sodium.', 'nextool')); ?>,
         fix: <?php echo json_encode(__('Instale as extensões faltantes (ex: apt install php-curl php-zip php-sodium) e reinicie o PHP-FPM ou Apache.', 'nextool')); ?>
      },
      'containerapi_connectivity': {
         title: <?php echo json_encode(__('Sem conexão com o servidor de atualizações', 'nextool')); ?>,
         desc: <?php echo json_encode(__('O servidor GLPI não conseguiu se conectar à ContainerAPI.', 'nextool')); ?>,
         fix: <?php echo json_encode(__('Verifique se o servidor tem acesso à internet ou à rede interna da ContainerAPI, e se não há regras de firewall bloqueando conexões de saída.', 'nextool')); ?>
      },
      'signature_valid': {
         title: <?php echo json_encode(__('Assinatura do pacote inválida', 'nextool')); ?>,
         desc: <?php echo json_encode(__('O manifesto da atualização não possui assinatura válida ou está ausente.', 'nextool')); ?>,
         fix: <?php echo json_encode(__('O pacote pode estar corrompido ou não é oficial. Contate o suporte NexTool.', 'nextool')); ?>
      },
      'disk_space': {
         title: <?php echo json_encode(__('Espaço em disco insuficiente', 'nextool')); ?>,
         desc: <?php echo json_encode(__('Não há espaço livre suficiente para realizar a atualização.', 'nextool')); ?>,
         fix: <?php echo json_encode(__('Libere pelo menos 150 MB no volume onde o GLPI está instalado.', 'nextool')); ?>
      },
      'anti_downgrade': {
         title: <?php echo json_encode(__('Downgrade não permitido', 'nextool')); ?>,
         desc: <?php echo json_encode(__('A versão disponível é igual ou inferior à versão instalada.', 'nextool')); ?>,
         fix: <?php echo json_encode(__('Não é possível fazer downgrade via auto-update. Se necessário, reinstale manualmente a versão desejada.', 'nextool')); ?>
      },
      'lock_free': {
         title: <?php echo json_encode(__('Outra atualização em andamento', 'nextool')); ?>,
         desc: <?php echo json_encode(__('Existe um lock ativo indicando que outra operação está em execução.', 'nextool')); ?>,
         fix: <?php echo json_encode(__('Aguarde a conclusão da operação anterior. Se o lock está travado, o administrador pode remover manualmente o arquivo de lock.', 'nextool')); ?>
      }
   };

   var _nextoolPreflightClipboard = '';

   function nextoolCopyPreflightReport(btn) {
      navigator.clipboard.writeText(_nextoolPreflightClipboard).then(function() {
         var original = btn.innerHTML;
         btn.innerHTML = '<i class="ti ti-check me-1"></i>' + <?php echo json_encode(__('Copiado!', 'nextool')); ?>;
         btn.classList.replace('btn-outline-secondary', 'btn-outline-success');
         setTimeout(function() {
            btn.innerHTML = original;
            btn.classList.replace('btn-outline-success', 'btn-outline-secondary');
         }, 2000);
      });
   }
   window.nextoolCopyPreflightReport = nextoolCopyPreflightReport;

   function buildPreflightErrorHtml(blockers) {
      var currentVer = currentVersionEl ? currentVersionEl.textContent : (window._nextoolCurrentVersion || '?');
      var targetVer = targetVersionEl ? targetVersionEl.textContent : (window._nextoolTargetVersion || '?');

      var clip = [];
      clip.push('=== ' + <?php echo json_encode(__('Relatório de Bloqueio — Atualização NexTool', 'nextool')); ?> + ' ===');
      clip.push(<?php echo json_encode(__('Versão atual:', 'nextool')); ?> + ' ' + currentVer);
      clip.push(<?php echo json_encode(__('Versão alvo:', 'nextool')); ?> + ' ' + targetVer);
      clip.push('');

      var LABEL_SOLUTION = <?php echo json_encode(__('Solução:', 'nextool')); ?>;
      var LABEL_RUN_CMD = <?php echo json_encode(__('Execute no terminal do servidor:', 'nextool')); ?>;
      var LABEL_DOCKER_HINT = <?php echo json_encode(__('Em ambientes Docker/container, prefixe com:', 'nextool')); ?>;

      var html = '<div style="width:100%">';
      for (var b = 0; b < blockers.length; b++) {
         var bl = blockers[b];
         var h = BLOCKER_HELP[bl.id] || null;
         var blData = bl.data || {};
         var title = h ? h.title : (bl.message || bl.id || '?');
         var desc = h ? h.desc : (bl.message || '');
         var fix = h ? h.fix : '';

         var cmdText = '';
         if (blData.path && (bl.id === 'plugin_dir_writable' || bl.id === 'backup_dir_writable')) {
            var webUser = blData.web_user || 'www-data';
            cmdText = 'chown -R ' + webUser + ':' + webUser + ' ' + blData.path;
         }

         if (b > 0) html += '<hr class="my-2">';
         html += '<div class="d-flex align-items-start mb-1">';
         html += '<i class="ti ti-alert-circle text-danger me-2 mt-1" style="flex-shrink:0"></i>';
         html += '<div style="min-width:0;width:100%">';
         html += '<div class="fw-semibold">' + title + '</div>';
         if (desc) html += '<div class="text-muted small">' + desc + '</div>';
         if (fix) {
            html += '<div class="small mt-1"><strong>' + LABEL_SOLUTION + '</strong> ' + fix + '</div>';
         }
         if (cmdText) {
            var dockerExample = 'docker exec &lt;container&gt; ' + cmdText;
            html += '<div class="small mt-1 p-2 bg-light rounded border" style="font-family:monospace;font-size:0.78rem;word-break:break-all">';
            html += '<div class="text-muted mb-1" style="font-size:0.7rem">' + LABEL_RUN_CMD + '</div>';
            html += cmdText;
            html += '<div class="text-muted mt-2 pt-2 border-top" style="font-size:0.7rem">' + LABEL_DOCKER_HINT + '</div>';
            html += '<span class="text-muted">' + dockerExample + '</span>';
            html += '</div>';
         }
         html += '</div></div>';

         clip.push(<?php echo json_encode(__('Problema:', 'nextool')); ?> + ' ' + title);
         if (desc) clip.push(desc);
         if (fix) clip.push(LABEL_SOLUTION + ' ' + fix);
         if (cmdText) {
            clip.push(LABEL_RUN_CMD + '\n' + cmdText);
            clip.push(LABEL_DOCKER_HINT + '\ndocker exec <container> ' + cmdText);
         }
         clip.push('');
      }

      clip.push('---');
      clip.push(<?php echo json_encode(__('Envie esta mensagem para o responsável pela infraestrutura do GLPI.', 'nextool')); ?>);
      _nextoolPreflightClipboard = clip.join('\n');

      html += '<div class="border-top pt-2 mt-2">';
      html += '<button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="nextoolCopyPreflightReport(this)">';
      html += '<i class="ti ti-copy me-1"></i>' + <?php echo json_encode(__('Copiar relatório para enviar ao responsável', 'nextool')); ?>;
      html += '</button>';
      html += '</div>';
      html += '</div>';

      return html;
   }

   async function runNextStep() {
      if (isRunning) return;

      var stepIndex = currentStep + 1; // next step to execute
      if (stepIndex >= STEPS.length) return;

      isRunning = true;
      wasStarted = true;

      // Clear previous alerts
      if (alertEl) { alertEl.className = 'alert d-none mb-2'; alertEl.innerHTML = ''; }
      if (permDetailEl) permDetailEl.classList.add('d-none');
      if (confirmApplyEl) confirmApplyEl.classList.add('d-none');

      setStepActive(stepIndex);
      if (actionBtn) { actionBtn.disabled = true; actionBtn.innerHTML = STEP_RUNNING_LABELS[stepIndex]; }

      try {
         var payload = await invokeCoreUpdate(STEPS[stepIndex].action);
         setStepOk(stepIndex);
         currentStep = stepIndex;

         // After preflight, update version labels from response
         if (stepIndex === 0 && payload && payload.data) {
            var checkData = (payload.data.check && payload.data.check.data) ? payload.data.check.data : payload.data;
            if (checkData.current_version && currentVersionEl) {
               currentVersionEl.textContent = checkData.current_version;
            }
            if (checkData.target_version && targetVersionEl) {
               targetVersionEl.textContent = checkData.target_version;
            }
         }

         // After apply success with needs_reload — redirect to plugins page (safe core page)
         if (stepIndex === 2 && payload && payload.data && payload.data.needs_reload) {
            if (actionBtn) { actionBtn.disabled = true; actionBtn.innerHTML = LABEL_DONE; }
            if (alertEl) {
               alertEl.className = 'alert alert-success mb-2';
               alertEl.textContent = payload.message || <?php echo json_encode(__('Atualização aplicada com sucesso.', 'nextool')); ?>;
            }
            isRunning = false;
            wasStarted = false;
            var redirectUrl = (payload.data && payload.data.redirect_url)
               ? payload.data.redirect_url
               : '/front/plugin.php';
            setTimeout(function() { window.location.href = redirectUrl; }, 3000);
            return;
         }

         // Show button for NEXT step (if there is one)
         var nextIndex = stepIndex + 1;
         if (nextIndex < STEPS.length) {
            if (actionBtn) { actionBtn.innerHTML = STEP_BTN_LABELS[nextIndex]; }
            // Before apply step: require typed confirmation
            if (nextIndex === 2 && confirmApplyEl && confirmApplyInputEl) {
               confirmApplyEl.classList.remove('d-none');
               if (actionBtn) actionBtn.disabled = true;
               confirmApplyInputEl.value = '';
               confirmApplyInputEl.focus();
            } else {
               if (actionBtn) actionBtn.disabled = false;
            }
         } else {
            // All done (safety fallback)
            if (actionBtn) { actionBtn.disabled = true; actionBtn.innerHTML = LABEL_DONE; }
            wasStarted = false;
         }
         isRunning = false;

      } catch (error) {
         var msg = error && error.message ? error.message : 'Erro desconhecido.';
         var errorPayload = error && error.payload ? error.payload : null;

         // Update version labels even on preflight failure
         if (stepIndex === 0 && errorPayload && errorPayload.data) {
            var errCheckData = (errorPayload.data.check && errorPayload.data.check.data) ? errorPayload.data.check.data : null;
            if (errCheckData) {
               if (errCheckData.current_version && currentVersionEl) {
                  currentVersionEl.textContent = errCheckData.current_version;
               }
               if (errCheckData.target_version && targetVersionEl) {
                  targetVersionEl.textContent = errCheckData.target_version;
               }
            }
         }

         // Build detailed error display for preflight blockers
         var hasDetailedError = false;
         if (stepIndex === 0 && errorPayload && errorPayload.data) {
            var prefData = errorPayload.data.preflight || errorPayload.data;
            var blockers = prefData.blocking_errors || prefData.blocking || [];
            if (blockers.length > 0 && alertEl) {
               hasDetailedError = true;
               setStepError(stepIndex, '');
               alertEl.className = 'alert alert-warning mb-2';
               alertEl.innerHTML = buildPreflightErrorHtml(blockers);
            }
         }
         if (!hasDetailedError) {
            setStepError(stepIndex, msg);
         }

         if (actionBtn) { actionBtn.disabled = false; actionBtn.innerHTML = LABEL_RETRY; }
         isRunning = false;
      }
   }

   // GLPI 11 (Tabler/Bootstrap ES module) does not fire show.bs.modal / hidden.bs.modal
   // on vanilla addEventListener. Use MutationObserver to detect modal open/close.
   var _modalWasVisible = false;
   var _modalObserver = new MutationObserver(function() {
      var isVisible = modalEl.style.display === 'block' || modalEl.classList.contains('show');
      if (isVisible && !_modalWasVisible) {
         _modalWasVisible = true;
         resetModal();
      } else if (!isVisible && _modalWasVisible) {
         _modalWasVisible = false;
         if (wasStarted) {
            invokeCoreUpdate('cancel_staging').catch(function() {});
            wasStarted = false;
         }
      }
   });
   _modalObserver.observe(modalEl, { attributes: true, attributeFilter: ['style', 'class'] });

   // Also call resetModal immediately to populate versions on init
   resetModal();

   // Event: action button → run next step (one step per click)
   if (actionBtn) {
      actionBtn.addEventListener('click', function() { runNextStep(); });
   }

   // Confirmation input listener: enable apply button only when "confirmo!" is typed
   if (confirmApplyInputEl) {
      confirmApplyInputEl.addEventListener('input', function() {
         var typed = (confirmApplyInputEl.value || '').trim().toLowerCase();
         if (actionBtn && currentStep === 1) {
            actionBtn.disabled = typed !== CONFIRM_APPLY_WORD;
         }
      });
   }

   // --- Backup / Restore Section ---
   var backupSectionEl = document.createElement('div');
   backupSectionEl.id = 'nextool-backup-section';
   backupSectionEl.className = 'd-none';
   var modalBody = modalEl.querySelector('.modal-body');
   if (modalBody) {
      modalBody.appendChild(backupSectionEl);
   }

   function fetchBackups() {
      var csrfToken = nextoolGetAjaxCsrfToken();
      if (!csrfToken || !backupSectionEl) return;

      fetch(endpoint, {
         method: 'POST',
         headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': csrfToken
         },
         body: new URLSearchParams({ action: 'list_backups', channel: 'stable' }).toString(),
         credentials: 'same-origin'
      })
      .then(function(r) { return r.json().catch(function() { return {}; }); })
      .then(function(data) {
         if (!data || !data.data || !data.data.backups || data.data.backups.length === 0) {
            backupSectionEl.className = 'd-none';
            return;
         }

         var backups = data.data.backups;
         var html = '<hr class="my-3">';
         html += '<div class="d-flex align-items-center mb-2">';
         html += '<i class="ti ti-archive me-2 text-muted"></i>';
         html += '<span class="fw-semibold">' + LABEL_BACKUPS_TITLE + '</span>';
         html += '</div>';
         html += '<div class="small text-muted mb-2">' + LABEL_BACKUPS_DESC + '</div>';

         for (var i = 0; i < backups.length; i++) {
            var bk = backups[i];
            var dateStr = bk.created_at ? new Date(bk.created_at).toLocaleString() : '?';
            html += '<div class="d-flex align-items-center justify-content-between p-2 rounded border mb-1' + (i === 0 ? '' : ' opacity-75') + '">';
            html += '<div>';
            html += '<span class="badge bg-secondary me-1">v' + (bk.version || '?') + '</span>';
            html += '<span class="small text-muted">' + dateStr + '</span>';
            if (i === 0) html += ' <span class="badge bg-azure-lt ms-1">' + LABEL_LATEST + '</span>';
            html += '</div>';
            html += '<button type="button" class="btn btn-sm btn-outline-warning nextool-restore-btn" data-backup-id="' + (bk.backup_id || '') + '" data-backup-version="' + (bk.version || '?') + '">';
            html += '<i class="ti ti-history me-1"></i>' + LABEL_RESTORE;
            html += '</button>';
            html += '</div>';
         }

         backupSectionEl.innerHTML = html;
         backupSectionEl.className = '';
      })
      .catch(function() {
         backupSectionEl.className = 'd-none';
      });
   }

   backupSectionEl.addEventListener('click', function(e) {
      var btn = e.target.closest('.nextool-restore-btn');
      if (!btn) return;

      var backupId = btn.dataset.backupId || '';
      var backupVersion = btn.dataset.backupVersion || '?';
      if (!backupId) return;

      var msg = LABEL_RESTORE_CONFIRM.replace('{version}', backupVersion);
      if (!window.confirm(msg)) return;

      btn.disabled = true;
      btn.innerHTML = '<i class="ti ti-loader-2 ti-spin me-1"></i>' + LABEL_RESTORING;

      var csrfToken = nextoolGetAjaxCsrfToken();
      if (!csrfToken) { btn.disabled = false; return; }

      fetch(endpoint, {
         method: 'POST',
         headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': csrfToken
         },
         body: new URLSearchParams({ action: 'restore', backup_id: backupId }).toString(),
         credentials: 'same-origin'
      })
      .then(function(r) { return r.json().catch(function() { return {}; }); })
      .then(function(data) {
         if (data && data.success) {
            if (alertEl) {
               alertEl.className = 'alert alert-success mb-2';
               alertEl.textContent = data.message || LABEL_RESTORE_SUCCESS;
            }
            setTimeout(function() { location.reload(); }, 3000);
         } else {
            if (alertEl) {
               alertEl.className = 'alert alert-danger mb-2';
               alertEl.textContent = (data && data.message) || LABEL_RESTORE_FAIL;
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-history me-1"></i>' + LABEL_RESTORE;
         }
      })
      .catch(function() {
         if (alertEl) {
            alertEl.className = 'alert alert-danger mb-2';
            alertEl.textContent = LABEL_RESTORE_FAIL;
         }
         btn.disabled = false;
         btn.innerHTML = '<i class="ti ti-history me-1"></i>' + LABEL_RESTORE;
      });
   });

   window.nextoolCoreUpdate = { invoke: invokeCoreUpdate };
}

// The modal HTML is loaded via AJAX tab content, so it may not exist when this script runs.
// Use MutationObserver to detect when the modal appears in the DOM.
(function() {
   function tryInit() {
      if (document.getElementById('nextool-core-update-modal')) {
         nextoolInitCoreUpdateModal();
         return true;
      }
      return false;
   }

   // Try immediately (in case modal already exists)
   if (!tryInit()) {
      // Watch for modal being added to DOM (AJAX tab load)
      var _coreUpdateObserver = new MutationObserver(function() {
         if (tryInit()) {
            _coreUpdateObserver.disconnect();
         }
      });
      _coreUpdateObserver.observe(document.body, { childList: true, subtree: true });
   }

   // Also handle GLPI tab re-navigation (modal re-rendered via AJAX)
   document.addEventListener('glpi.load', function() { tryInit(); });
})();

var _nextoolSyncCooldown = (function() {
   var COOLDOWN_SECONDS = 60;
   var STORAGE_KEY = 'nextool.sync.cooldown';
   var originalLabel = <?php echo json_encode(__('Sincronizar', 'nextool')); ?>;
   var intervalId = null;

   function getExpiresAt() {
      try {
         var raw = sessionStorage.getItem(STORAGE_KEY);
         return raw ? parseInt(raw, 10) : 0;
      } catch (e) { return 0; }
   }

   function setExpiresAt(ts) {
      try { sessionStorage.setItem(STORAGE_KEY, String(ts)); } catch (e) {}
   }

   function getRemaining() {
      var diff = Math.ceil((getExpiresAt() - Date.now()) / 1000);
      return diff > 0 ? diff : 0;
   }

   function getSyncButtons() {
      return document.querySelectorAll('.btn-hero-validate');
   }

   function updateButtons(remaining) {
      getSyncButtons().forEach(function(btn) {
         if (remaining > 0) {
            btn.disabled = true;
            btn.innerHTML = '<i class="ti ti-clock me-1"></i>' + originalLabel + ' (' + remaining + 's)';
         } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-refresh me-1"></i>' + originalLabel;
         }
      });
   }

   function stopTimer() {
      if (intervalId) { clearInterval(intervalId); intervalId = null; }
   }

   function startTimer() {
      stopTimer();
      var remaining = getRemaining();
      if (remaining <= 0) { updateButtons(0); return; }
      updateButtons(remaining);
      intervalId = setInterval(function() {
         var r = getRemaining();
         updateButtons(r);
         if (r <= 0) stopTimer();
      }, 1000);
   }

   function activate() {
      setExpiresAt(Date.now() + COOLDOWN_SECONDS * 1000);
      startTimer();
   }

   function init() { startTimer(); }

   return { activate: activate, init: init };
})();

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

   // Cooldown: ativar countdown de 60s e desabilitar botão
   _nextoolSyncCooldown.activate();
   if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="ti ti-loader-2 ti-spin me-1"></i>Sincronizando...';
   }

   form.submit();
   return false;
}

// Inicializar cooldown ao carregar (restaura countdown se ainda ativo após reload)
if (document.readyState === 'loading') {
   document.addEventListener('DOMContentLoaded', _nextoolSyncCooldown.init);
} else {
   _nextoolSyncCooldown.init();
}
document.addEventListener('glpi.load', _nextoolSyncCooldown.init);

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
   if (document.documentElement.dataset.nextoolModuleFiltersBound === '1') return;
   document.documentElement.dataset.nextoolModuleFiltersBound = '1';

   var storageKey = 'nextool.module.filter';
   var allowedFilters = ['enabled', 'disabled', 'download', 'install', 'update', 'free', 'licensed'];
   var allowedFilterSet = new Set(allowedFilters);
   var activeFilter = '';
   var debounceTimer = null;

   function isAllowedFilter(filter) {
      return allowedFilterSet.has(filter);
   }

   function getStorage() {
      try {
         return window.sessionStorage;
      } catch (e) {
         return null;
      }
   }

   function loadFilterFromStorage() {
      var storage = getStorage();
      if (!storage) return '';
      try {
         var raw = String(storage.getItem(storageKey) || '').trim();
         return isAllowedFilter(raw) ? raw : '';
      } catch (e) {
         return '';
      }
   }

   function saveFilterToStorage(filter) {
      var storage = getStorage();
      if (!storage) return;
      try {
         if (filter && isAllowedFilter(filter)) {
            storage.setItem(storageKey, filter);
         } else {
            storage.removeItem(storageKey);
         }
      } catch (e) {
         // Ignore falha de storage (quota/privacy mode)
      }
   }

   function getFilterFromUrl() {
      try {
         var url = new URL(window.location.href);
         var filter = String(url.searchParams.get('module_filter') || '').trim();
         return isAllowedFilter(filter) ? filter : '';
      } catch (e) {
         return '';
      }
   }

   function setFilterInUrl(filter) {
      if (!window.history || !window.history.replaceState) return;
      try {
         var url = new URL(window.location.href);
         if (filter && isAllowedFilter(filter)) {
            url.searchParams.set('module_filter', filter);
         } else {
            url.searchParams.delete('module_filter');
         }
         window.history.replaceState(null, '', url.toString());
      } catch (e) {
         // Ignore erro de URL inválida
      }
   }

   function setActiveFilter(filter, persist) {
      var normalized = (typeof filter === 'string') ? filter.trim() : '';
      if (!isAllowedFilter(normalized)) {
         normalized = '';
      }
      activeFilter = normalized;
      window._nextoolCurrentModuleFilter = activeFilter;
      if (persist !== false) {
         saveFilterToStorage(activeFilter);
         setFilterInUrl(activeFilter);
      }
   }

   function syncChipClasses() {
      var chips = document.querySelectorAll('.nextool-filter-chip[data-filter]');
      if (!chips.length) return false;
      chips.forEach(function(chip) {
         var filter = (chip.dataset.filter || '').trim();
         chip.classList.toggle('active', filter !== '' && filter === activeFilter);
      });
      return true;
   }

   function matchesChipFilter(card) {
      if (!activeFilter) return true;

      switch (activeFilter) {
      case 'enabled':
         return card.dataset.moduleEnabled === '1';
      case 'disabled':
         return card.dataset.moduleInstalled === '1' && card.dataset.moduleEnabled === '0';
      case 'download':
         return card.dataset.moduleDownloaded === '0';
      case 'install':
         return card.dataset.moduleInstallReady === '1';
      case 'update':
         return card.dataset.moduleUpdate === '1';
      case 'free':
         return card.dataset.moduleTier === 'FREE';
      case 'licensed':
         return card.dataset.moduleTier !== 'FREE';
      default:
         return true;
      }
   }

   function applyFilters() {
      var searchInput = document.getElementById('nextool-module-search');
      var noResults = document.getElementById('nextool-module-no-results');
      var cards = document.querySelectorAll('.nextool-module-card');
      if (!cards.length) return;

      var query = searchInput ? searchInput.value.toLowerCase().trim() : '';
      var visibleCount = 0;

      cards.forEach(function(card) {
         var matchesText = true;
         if (query) {
            matchesText = (card.dataset.moduleName || '').indexOf(query) !== -1
                       || (card.dataset.moduleDesc || '').indexOf(query) !== -1;
         }

         var matchesChip = matchesChipFilter(card);

         var visible = matchesText && matchesChip;
         card.style.display = visible ? '' : 'none';
         if (visible) visibleCount++;
      });

      if (noResults) {
         noResults.classList.toggle('d-none', visibleCount > 0);
      }
   }

   function initializeModuleFilters() {
      var filterFromUrl = getFilterFromUrl();
      if (filterFromUrl !== '') {
         setActiveFilter(filterFromUrl);
      } else if (!activeFilter) {
         setActiveFilter(loadFilterFromStorage(), false);
      }

      if (!syncChipClasses()) return false;
      applyFilters();
      return true;
   }

   setActiveFilter(getFilterFromUrl() || loadFilterFromStorage(), false);

   var initializeTimer = null;
   function scheduleInitializeModuleFilters() {
      clearTimeout(initializeTimer);
      initializeTimer = setTimeout(initializeModuleFilters, 50);
   }

   function watchModuleFiltersDom() {
      if (!window.MutationObserver || !document.body) return;
      var observer = new MutationObserver(function(mutations) {
         var shouldInitialize = false;
         mutations.forEach(function(mutation) {
            if (shouldInitialize) return;
            if (mutation.type !== 'childList' || !mutation.addedNodes || !mutation.addedNodes.length) return;
            mutation.addedNodes.forEach(function(node) {
               if (shouldInitialize || !node || node.nodeType !== 1) return;
               var element = node;
               if (element.matches && (element.matches('#nextool-module-filter-bar') || element.matches('.nextool-filter-chip') || element.matches('.nextool-module-card'))) {
                  shouldInitialize = true;
                  return;
               }
               if (element.querySelector && (element.querySelector('#nextool-module-filter-bar') || element.querySelector('.nextool-filter-chip') || element.querySelector('.nextool-module-card'))) {
                  shouldInitialize = true;
               }
            });
         });
         if (shouldInitialize) {
            scheduleInitializeModuleFilters();
         }
      });
      observer.observe(document.body, { childList: true, subtree: true });
   }

   // Event delegation no document — funciona mesmo após replace do DOM via AJAX
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
      var filter = (chip.dataset.filter || '').trim();
      if (!isAllowedFilter(filter)) return;

      setActiveFilter(activeFilter === filter ? '' : filter);
      syncChipClasses();
      applyFilters();
   });

   if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() {
         initializeModuleFilters();
         watchModuleFiltersDom();
      });
   } else {
      initializeModuleFilters();
      watchModuleFiltersDom();
   }
   document.addEventListener('glpi.load', scheduleInitializeModuleFilters);
})();
</script>
