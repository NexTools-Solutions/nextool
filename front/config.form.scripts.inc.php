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

<!-- Anúncio antes do download de módulo gratuito (#283): Continuar só no fim do contador; fechar cancela o download -->
<div class="modal fade" id="nextool-download-ad-modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
   <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
         <div class="modal-header">
            <h5 class="modal-title" id="nextool-download-ad-title"></h5>
            <button type="button" class="btn-close" id="nextool-download-ad-close" data-bs-dismiss="modal" aria-label="<?php echo PluginNextoolCompat::escape(__('Cancelar download', 'nextool')); ?>"></button>
         </div>
         <div class="modal-body">
            <!-- Módulo pago (2026-11-10): agradecimento a quem licenciou, com o que a licença inclui. O título e o
                 texto vêm do anúncio de público "pago" do portal; sem anúncio, os textos padrão da base. -->
            <div class="d-none mb-3" id="nextool-download-ad-thanks">
               <div class="nextool-download-ad-thanks-hero d-flex align-items-center gap-3 text-white">
                  <div class="nextool-download-ad-thanks-icon d-flex align-items-center justify-content-center flex-shrink-0"><i class="ti ti-heart-handshake"></i></div>
                  <div>
                     <div class="fs-2 fw-bold lh-sm" id="nextool-download-ad-thanks-title"></div>
                     <div class="mt-1" id="nextool-download-ad-thanks-text" style="white-space: pre-line;"></div>
                  </div>
               </div>
               <div class="row g-2 mt-1">
                  <div class="col-12 col-md-4">
                     <div class="border rounded p-2 h-100">
                        <div class="fw-semibold"><i class="ti ti-headset me-1" style="color:#7c3aed;"></i><?php echo __('Suporte prioritário', 'nextool'); ?></div>
                        <div class="small text-muted"><?php echo __('Bugs respondidos em até 2 dias úteis, por aqui mesmo.', 'nextool'); ?></div>
                     </div>
                  </div>
                  <div class="col-12 col-md-4">
                     <div class="border rounded p-2 h-100">
                        <div class="fw-semibold"><i class="ti ti-refresh me-1" style="color:#7c3aed;"></i><?php echo __('Atualizações incluídas', 'nextool'); ?></div>
                        <div class="small text-muted"><?php echo __('Novas versões e melhorias enquanto a licença estiver ativa.', 'nextool'); ?></div>
                     </div>
                  </div>
                  <div class="col-12 col-md-4">
                     <div class="border rounded p-2 h-100">
                        <div class="fw-semibold"><i class="ti ti-message-star me-1" style="color:#7c3aed;"></i><?php echo __('Sua opinião conta', 'nextool'); ?></div>
                        <div class="small text-muted"><?php echo __('Depois de usar, deixe um depoimento. Ele orienta a evolução do módulo.', 'nextool'); ?></div>
                     </div>
                  </div>
               </div>
            </div>
            <!-- Card com a cara do player do YouTube (2026-11-10): capa LOCAL (thumb do vídeo, servida pela base), título
                 sobreposto no topo, play vermelho no centro e o selo "Assista no YouTube". É só aparência: o player de
                 verdade (youtube-nocookie) entra no clique do play, e antes disso nada vai ao Google. -->
            <div class="d-none card mb-3 overflow-hidden" id="nextool-download-ad-media">
               <div class="position-relative bg-dark" id="nextool-download-ad-stage" style="aspect-ratio: 16 / 9;">
                  <img class="w-100 h-100 d-block" id="nextool-download-ad-image" alt="" style="object-fit: cover;">
                  <div class="nextool-download-ad-vtitle position-absolute top-0 start-0 end-0 text-white text-truncate d-none" id="nextool-download-ad-vtitle"></div>
                  <button type="button" class="nextool-download-ad-play position-absolute top-50 start-50 translate-middle border-0 p-0 bg-transparent d-none align-items-center justify-content-center"
                          id="nextool-download-ad-play" aria-label="<?php echo PluginNextoolCompat::escape(__('Assistir ao vídeo', 'nextool')); ?>">
                     <svg width="68" height="48" viewBox="0 0 68 48" aria-hidden="true"><path d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55C3.97 2.33 2.27 4.81 1.48 7.74.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z" fill="#f03"/><path d="M45 24 27 14v20z" fill="#fff"/></svg>
                  </button>
                  <a class="nextool-download-ad-watch position-absolute bottom-0 end-0 text-white text-decoration-none d-none align-items-center" id="nextool-download-ad-youtube" target="_blank" rel="noopener noreferrer">
                     <span><?php echo __('Assista no', 'nextool'); ?></span>
                     <svg width="72" height="16" viewBox="0 0 90 20" aria-hidden="true" class="ms-1"><path d="M27.97 3.12a3.58 3.58 0 0 0-2.52-2.53C23.22 0 14.28 0 14.28 0S5.35 0 3.12.6A3.58 3.58 0 0 0 .6 3.12C0 5.35 0 10 0 10s0 4.65.6 6.88a3.58 3.58 0 0 0 2.52 2.53C5.35 20 14.28 20 14.28 20s8.94 0 11.17-.6a3.58 3.58 0 0 0 2.52-2.52C28.57 14.65 28.57 10 28.57 10s0-4.65-.6-6.88z" fill="#f03"/><path d="m11.43 14.29 7.42-4.29-7.42-4.29z" fill="#fff"/><text x="33" y="15.5" fill="#fff" font-family="Arial, Helvetica, sans-serif" font-size="15" font-weight="700" letter-spacing="-.6">YouTube</text></svg>
                  </a>
               </div>
            </div>
            <p class="mb-0" id="nextool-download-ad-body" style="white-space: pre-line;"></p>
            <!-- Jornada de um clique (#301): baixar, instalar e ativar, uma requisição por etapa -->
            <ul class="list-group list-group-flush d-none mt-3" id="nextool-download-ad-steps"></ul>
         </div>
         <div class="modal-footer justify-content-end">
            <div class="d-flex flex-wrap justify-content-end gap-2">
               <!-- Módulo pago: suporte (popup da base) e depoimento (portal), sem contador -->
               <button type="button" class="btn btn-outline-secondary d-none" id="nextool-download-ad-support">
                  <i class="ti ti-lifebuoy me-1"></i><?php echo __('Suporte', 'nextool'); ?>
               </button>
               <a class="btn btn-outline-secondary d-none" id="nextool-download-ad-testimonial" target="_blank" rel="noopener noreferrer">
                  <i class="ti ti-message-star me-1"></i><?php echo __('Deixar depoimento', 'nextool'); ?>
               </a>
               <a class="btn btn-outline-primary d-none" id="nextool-download-ad-cta" target="_blank" rel="noopener noreferrer">
                  <i class="ti ti-external-link me-1"></i><span></span>
               </a>
               <button type="button" class="btn btn-success" id="nextool-download-ad-continue" disabled>
                  <i class="ti ti-cloud-download me-1" id="nextool-download-ad-continue-icon"></i><span id="nextool-download-ad-continue-label"><?php echo __('Continuar download', 'nextool'); ?></span>
               </button>
            </div>
         </div>
      </div>
   </div>
</div>
<style>
   #nextool-download-ad-media .nextool-download-ad-play { transition: transform .15s; cursor: pointer; filter: drop-shadow(0 2px 6px rgba(0, 0, 0, .45)); }
   #nextool-download-ad-media .nextool-download-ad-play:hover { transform: translate(-50%, -50%) scale(1.08); }
   #nextool-download-ad-media .nextool-download-ad-vtitle { padding: 14px 18px 34px; font-size: 1.05rem; font-weight: 600; background: linear-gradient(rgba(0, 0, 0, .78), rgba(0, 0, 0, 0)); pointer-events: none; }
   #nextool-download-ad-media .nextool-download-ad-watch { margin: 0 14px 14px 0; padding: 8px 14px; border-radius: 24px; background: rgba(15, 15, 15, .86); font-size: .85rem; font-weight: 600; }
   #nextool-download-ad-media .nextool-download-ad-watch:hover { background: rgba(15, 15, 15, 1); }
   #nextool-download-ad-stage iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
   #nextool-download-ad-thanks .nextool-download-ad-thanks-hero { padding: 20px 22px; border-radius: 10px; background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%); }
   #nextool-download-ad-thanks .nextool-download-ad-thanks-icon { width: 56px; height: 56px; border-radius: 50%; background: rgba(255, 255, 255, .18); font-size: 30px; }
</style>

<!-- "Atualizar todos" (#284, #301): 1o passo = lista com seleção (nada é atualizado); 2o passo = fila com
     progresso por módulo, que fecha (e recarrega) só no fim -->
<div class="modal fade" id="nextool-update-all-modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
   <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
         <div class="modal-header">
            <h5 class="modal-title">
               <i class="ti ti-refresh me-2 text-info"></i>
               <?php echo __('Atualizar todos os módulos', 'nextool'); ?>
            </h5>
            <button type="button" class="btn-close d-none" id="nextool-update-all-close" aria-label="Close"></button>
         </div>
         <div class="modal-body">
            <p class="text-muted small mb-2" id="nextool-update-all-status"></p>
            <label class="d-none align-items-center gap-2 border-bottom pb-2 mb-0 fw-medium" id="nextool-update-all-pick-all-wrap">
               <input type="checkbox" class="form-check-input m-0" id="nextool-update-all-pick-all" checked>
               <span><?php echo __('Selecionar todos', 'nextool'); ?></span>
            </label>
            <ul class="list-group list-group-flush" id="nextool-update-all-list"></ul>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary d-none" id="nextool-update-all-cancel"><?php echo __('Cancelar', 'nextool'); ?></button>
            <button type="button" class="btn btn-info text-white d-none" id="nextool-update-all-confirm"><i class="ti ti-refresh me-1"></i><span></span></button>
            <button type="button" class="btn btn-primary" id="nextool-update-all-done" disabled><?php echo __('Concluir', 'nextool'); ?></button>
         </div>
      </div>
   </div>
</div>

<!-- Modal de confirmação com digitação (apagar dados do módulo) -->
<div class="modal fade" id="nextool-typed-confirm-modal" tabindex="-1" aria-hidden="true" data-confirm-word="<?php echo PluginNextoolCompat::escape(__('excluir', 'nextool')); ?>">
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
            <input type="text" class="form-control" id="nextool-typed-confirm-input" autocomplete="off" spellcheck="false" placeholder="<?php echo PluginNextoolCompat::escape(__('excluir', 'nextool')); ?>">
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

// GLPI 12 valida CSRF pelos cabeçalhos Sec-Fetch-Site/Origin, sem token: getAjaxCsrfToken()
// devolve '' (com console.warn) e a meta glpi:csrf_token não existe. Nesse modo o token vazio
// é esperado, e os fluxos abaixo não podem tratá-lo como sessão perdida.
var NEXTOOL_CSRF_TOKENLESS = <?php echo json_encode(PluginNextoolCompat::csrfTokenless()); ?>;

function nextoolGetAjaxCsrfToken() {
   if (NEXTOOL_CSRF_TOKENLESS) {
      return '';
   }
   // GLPI 10 expõe getAjaxCsrfToken() via common.js; manter fallback por segurança.
   if (typeof window.getAjaxCsrfToken === 'function') {
      return window.getAjaxCsrfToken();
   }
   var meta = document.querySelector('meta[property="glpi:csrf_token"]');
   return meta ? meta.getAttribute('content') : '';
}

/**
 * Helper compartilhado para POST JSON aos endpoints AJAX do plugin (ME-02).
 * Encapsula headers obrigatórios (Accept, Content-Type, X-Requested-With,
 * X-Glpi-Csrf-Token), encode form-urlencoded e parse de JSON. Devolve uma
 * Promise que resolve para um objeto `{ok, status, data}` onde `data` é o
 * JSON parseado (ou `{}` se body inválido).
 *
 * Não aplicável a callsites que usam FormData (multipart) ou que precisam
 * de parsing customizado da Response (ex.: ler headers de versão).
 *
 * @param {string} endpoint URL do endpoint AJAX
 * @param {Object} params   Pares chave/valor que vão no body como form-urlencoded
 * @param {Object} [opts]   { rejectOnNoCsrf?: boolean } -- default false (envia mesmo sem token)
 * @returns {Promise<{ok: boolean, status: number, data: Object}>}
 */
function nextoolPostJson(endpoint, params, opts) {
   opts = opts || {};
   var csrfToken = nextoolGetAjaxCsrfToken();
   if (!csrfToken && opts.rejectOnNoCsrf) {
      return Promise.reject(new Error('csrf-token-not-found'));
   }
   return fetch(endpoint, {
      method: 'POST',
      headers: {
         'Accept': 'application/json',
         'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
         'X-Requested-With': 'XMLHttpRequest',
         'X-Glpi-Csrf-Token': csrfToken || ''
      },
      body: new URLSearchParams(params || {}).toString(),
      credentials: 'same-origin'
   }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
         return { ok: r.ok, status: r.status, data: data };
      });
   });
}
window.nextoolPostJson = nextoolPostJson;

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

   // Empilhamento sobre outro modal já aberto (ex.: "Desvincular" a partir do modal de vínculo):
   // por padrão o Bootstrap deixaria este modal de confirmação ATRÁS (mesmo z-index, ordem do DOM).
   // Eleva o z-index deste modal e do seu backdrop acima do modal de baixo.
   var nextoolOpenModals = document.querySelectorAll('.modal.show').length;
   if (nextoolOpenModals > 0) {
      var nextoolZ = 1055 + nextoolOpenModals * 20;
      modalEl.style.zIndex = nextoolZ;
      modalEl.addEventListener('shown.bs.modal', function onNextoolStacked() {
         modalEl.removeEventListener('shown.bs.modal', onNextoolStacked);
         var bds = document.querySelectorAll('.modal-backdrop');
         if (bds.length) { bds[bds.length - 1].style.zIndex = nextoolZ - 1; }
      });
   } else {
      modalEl.style.zIndex = '';
   }
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
   if (!csrfToken && !NEXTOOL_CSRF_TOKENLESS) {
      window.location.reload();
      return;
   }

   var forcetab = window._nextoolCurrentTab || new URLSearchParams(window.location.search || '').get('forcetab') || 'PluginNextoolMainConfig$1';
   var moduleFilter = window._nextoolCurrentModuleFilter || new URLSearchParams(window.location.search || '').get('module_filter') || '';

   var originalHtml = btn.innerHTML;
   btn.disabled = true;
   btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span><?php echo PluginNextoolCompat::escape(__('Processando...', 'nextool')); ?>';

   // "Instalar e ativar" (#301): duas requisições em sequência; a mensagem ao usuário é a da ativação.
   var chainEnable = action === 'install' && btn.dataset && btn.dataset.chain === 'enable';
   var firstParams = { action: action, module: moduleKey, forcetab: forcetab, module_filter: moduleFilter };
   if (chainEnable) firstParams.quiet = '1';

   nextoolPostJson(endpoint, firstParams)
      .then(function (result) {
         if (chainEnable && result.ok && result.data && result.data.success && !result.data.pending_upgrade) {
            return nextoolPostJson(endpoint, { action: 'enable', module: moduleKey, forcetab: forcetab, module_filter: moduleFilter })
               .then(function (enabled) {
                  // Ativação recusada (licença, pré-requisito): o módulo fica instalado e a mensagem é a do servidor.
                  if (!enabled.ok && !(enabled.data && enabled.data.message)) {
                     enabled.data = { success: false, message: <?php echo json_encode(__('O módulo foi instalado, mas não foi possível ativá-lo.', 'nextool')); ?> };
                  }
                  // O módulo já está instalado: se a ativação falhar, a tela recarrega para o card mostrar isso.
                  enabled.afterInstall = true;
                  return enabled;
               });
         }
         return result;
      })
      .then(function (result) {
         if (!result.ok) {
            var rawMsg = (result.data && result.data.message) ? String(result.data.message) : '';
            var errMsg = rawMsg ? rawMsg.replace(/[<>]/g, '') : <?php echo json_encode(__('Erro inesperado ao processar a ação.', 'nextool')); ?>;
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            if (typeof glpi_toast_error === 'function') {
               glpi_toast_error(errMsg);
            } else if (typeof glpi_alert === 'function') {
               glpi_alert({ title: <?php echo json_encode(__('Erro', 'nextool')); ?>, message: errMsg, type: 'error' });
            } else {
               alert(errMsg);
            }
            if (result.afterInstall) {
               setTimeout(function () { window.location.reload(); }, 2500);
            }
            return;
         }
         var data = result.data;

         // FASE 2 do update (issue #158): a fase 1 só trocou os arquivos em disco. A
         // classe do módulo carregada NAQUELA requisição era a antiga -- o PHP não
         // redefine classe já carregada -- então a migração precisa de uma requisição
         // NOVA, que já carrega o código novo. Só depois dela recarregamos a página, e a
         // mensagem exibida é o resultado REAL da migração (não um "sucesso" otimista).
         if (data && data.pending_upgrade) {
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span><?php echo PluginNextoolCompat::escape(__('Concluindo atualização...', 'nextool')); ?>';
            nextoolPostJson(endpoint, {
               action: 'finalize_update',
               module: moduleKey,
               forcetab: forcetab,
               module_filter: moduleFilter
            })
               .then(function (finalizeResult) {
                  var fdata = finalizeResult.data;
                  if (fdata && fdata.redirect_url) {
                     window.location.assign(String(fdata.redirect_url));
                     return;
                  }
                  window.location.reload();
               })
               .catch(function () {
                  // Rede de segurança: mesmo sem a fase 2, o CAMINHO B do
                  // syncInstalledVersionsFromDisk() converge no próximo carregamento.
                  window.location.reload();
               });
            return;
         }

         if (data && data.redirect_url) {
            window.location.assign(String(data.redirect_url));
            return;
         }
         window.location.reload();
      })
      .catch(function () {
         btn.innerHTML = originalHtml;
         btn.disabled = false;
         var fallbackMsg = <?php echo json_encode(__('Falha na comunicação com o servidor.', 'nextool')); ?>;
         if (typeof glpi_toast_error === 'function') {
            glpi_toast_error(fallbackMsg);
         } else {
            alert(fallbackMsg);
         }
      });
}

function nextoolInitModuleActions() {
   if (document.documentElement.dataset.nextoolModuleActionsBound === '1') return;
   document.documentElement.dataset.nextoolModuleActionsBound = '1';

   var endpoint = <?php echo json_encode(PluginNextoolCompat::webDir() . '/ajax/module_action.php'); ?>;

   document.addEventListener('click', function (event) {
      var btn = event.target && typeof event.target.closest === 'function'
         ? event.target.closest('.nextool-module-action')
         : null;
      if (!btn) return;

      event.preventDefault();
      event.stopPropagation();

      if (btn.disabled) return;
      var action = (btn.dataset && btn.dataset.action) ? String(btn.dataset.action) : '';
      var moduleKey = (btn.dataset && btn.dataset.module) ? String(btn.dataset.module) : '';
      if (action === '' || moduleKey === '') return;

      // Download: o popup abre SEMPRE (#301) e faz a jornada inteira (baixar, instalar, ativar). Módulo gratuito
      // com anúncio vigente: o contador (#283) segura a jornada. Sem o popup na página, cai no download simples.
      if (action === 'download' && nextoolShowDownloadAd(btn, moduleKey, endpoint)) {
         return;
      }

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

// --- Anúncio antes do download de módulo gratuito (#283) ---
// Os anúncios vêm do /validate (guardados pela base) num <script type="application/json"> da guia Módulos. Vale o do
// próprio módulo, senão o geral. Sem anúncio, sem dados ou com qualquer falha, o download segue normal (devolve false).
var NEXTOOL_DOWNLOAD_AD_I18N = <?php echo json_encode([
   'goFull'      => __('Baixar e ativar', 'nextool'),
   'goInstall'   => __('Baixar e instalar', 'nextool'),
   'buttonIn'    => __('%1$s (%2$s s)', 'nextool'),
   'startsIn'    => __('%1$s em %2$s s', 'nextool'),
   'watch'       => __('Assistir e baixar', 'nextool'),
   'titleNoAd'   => __('Instalar o módulo %s', 'nextool'),
   'bodyFull'    => __('O módulo será baixado, instalado e ativado em sequência.', 'nextool'),
   'bodyInstall' => __('O módulo será baixado e instalado. A ativação depende de quem tem essa permissão.', 'nextool'),
   'paidTitle'   => __('Obrigado por apoiar a NexTool', 'nextool'),
   'paidText'    => __('Sua licença mantém este módulo evoluindo. Conte com a gente na configuração e no dia a dia.', 'nextool'),
   'stepDownload' => __('Baixar', 'nextool'),
   'stepInstall'  => __('Instalar', 'nextool'),
   'stepEnable'   => __('Ativar', 'nextool'),
   'waiting'     => __('Aguardando', 'nextool'),
   'running'     => __('Em andamento', 'nextool'),
   'ok'          => __('Concluído', 'nextool'),
   'failed'      => __('Falhou', 'nextool'),
   'canceled'    => __('Cancelado', 'nextool'),
   'failSubject' => __('Falha ao instalar o módulo %s', 'nextool'),
   // Uma frase por msgid, sem quebra de linha dentro do __(): o JS junta (quebra de linha no msgid nunca traduz).
   'failIntro'   => __('A instalação não foi concluída. Veja abaixo em que etapa parou e o motivo.', 'nextool'),
   'failWhere'   => __('A instalação do módulo %1$s (%2$s) parou na etapa "%3$s".', 'nextool'),
   'failWhy'     => __('Mensagem: %s', 'nextool'),
   'failDoing'   => __('O que eu estava fazendo:', 'nextool'),
   'working'     => __('Processando...', 'nextool'),
   'close'       => __('Fechar', 'nextool'),
   'leave'       => __('A instalação do módulo ainda está em andamento.', 'nextool'),
   'unexpected'  => __('Erro inesperado ao processar a ação.', 'nextool'),
   'network'     => __('Falha na comunicação com o servidor.', 'nextool'),
   'testimonial' => PluginNextoolDownloadAds::TESTIMONIAL_URL,
]); ?>;

function nextoolDownloadAdFor(moduleKey, audience) {
   audience = audience || 'free';
   var dataEl = document.getElementById('nextool-download-ads-data');
   if (!dataEl) return null;
   var ads;
   try { ads = JSON.parse(dataEl.textContent || '[]'); } catch (e) { return null; }
   if (!Array.isArray(ads)) return null;
   var fallback = null;
   for (var i = 0; i < ads.length; i++) {
      var ad = ads[i] || {};
      if ((ad.audience || 'free') !== audience) continue;
      if (Array.isArray(ad.modules) && ad.modules.indexOf(moduleKey) !== -1) return ad;
      if (!fallback && ad['default']) fallback = ad;
   }
   return fallback;
}

function nextoolDownloadAdEvent(event, ad, moduleKey) {
   var endpoint = <?php echo json_encode(PluginNextoolCompat::webDir() . '/ajax/download_ad_event.php'); ?>;
   nextoolPostJson(endpoint, { event: event, ad_id: String(ad.id), module: moduleKey }).catch(function () {});
}

// Linha de etapa da jornada (mesmo visual da fila do "Atualizar todos").
function nextoolJourneySetStep(row, state, message) {
   var styles = {
      waiting: ['bg-secondary', 'ti ti-clock'],
      running: ['bg-info', 'spinner'],
      ok:      ['bg-success', 'ti ti-check'],
      failed:  ['bg-danger', 'ti ti-x'],
      canceled: ['bg-secondary', 'ti ti-ban']
   };
   var st = styles[state] || styles.waiting;
   var badge = row.querySelector('.nextool-update-all-state');
   badge.className = 'badge text-white ' + st[0] + ' nextool-update-all-state';
   badge.innerHTML = st[1] === 'spinner'
      ? '<span class="spinner-border me-1" role="status" aria-hidden="true" style="width:.7rem;height:.7rem;border-width:.15em"></span>'
      : '<i class="' + st[1] + ' me-1"></i>';
   badge.appendChild(document.createTextNode(NEXTOOL_DOWNLOAD_AD_I18N[state] || state));
   var msg = row.querySelector('.nextool-update-all-msg');
   msg.textContent = message ? String(message).replace(/[<>]/g, '') : '';
   msg.classList.toggle('d-none', !message);
}

// Jornada de um clique (#301): baixar -> instalar -> ativar, UMA requisição por etapa (a etapa seguinte precisa de
// uma requisição nova para carregar o código recém-baixado, issue #158, e cada etapa tem a própria permissão no
// servidor). Para na primeira falha, com a mensagem daquela etapa, e as etapas seguintes ficam "Cancelado".
// Devolve Promise<{ok, redirect, failedStep, message}>.
function nextoolRunModuleJourney(endpoint, moduleKey, steps, listEl) {
   var forcetab = window._nextoolCurrentTab || new URLSearchParams(window.location.search || '').get('forcetab') || 'PluginNextoolMainConfig$1';
   var moduleFilter = window._nextoolCurrentModuleFilter || new URLSearchParams(window.location.search || '').get('module_filter') || '';
   listEl.innerHTML = '';
   var rows = steps.map(function (step) {
      var row = nextoolUpdateAllRow(listEl, step.label);
      nextoolJourneySetStep(row, 'waiting');
      return row;
   });
   listEl.classList.remove('d-none');
   var failText = function (res) {
      return (res && res.data && res.data.message) ? String(res.data.message) : NEXTOOL_DOWNLOAD_AD_I18N.unexpected;
   };
   var post = function (action, quiet) {
      var params = { action: action, module: moduleKey, forcetab: forcetab, module_filter: moduleFilter };
      if (quiet) params.quiet = '1';
      return nextoolPostJson(endpoint, params);
   };
   var redirect = '';
   var failure = null;
   // A etapa falhou: guarda o motivo (vai para o pedido de suporte) e cancela as que viriam depois.
   var fail = function (idx, message) {
      nextoolJourneySetStep(rows[idx], 'failed', message);
      failure = { step: steps[idx].label, message: message };
      for (var i = idx + 1; i < rows.length; i++) { nextoolJourneySetStep(rows[i], 'canceled'); }
      return false;
   };
   var chain = Promise.resolve(true);
   steps.forEach(function (step, idx) {
      chain = chain.then(function (goOn) {
         if (!goOn) return false;
         var last = idx === steps.length - 1;
         nextoolJourneySetStep(rows[idx], 'running');
         return post(step.action, !last).then(function (res) {
            if (!res.ok || !res.data || !res.data.success) {
               return fail(idx, failText(res));
            }
            var done = function (data) {
               if (data && data.redirect_url) redirect = String(data.redirect_url);
               nextoolJourneySetStep(rows[idx], 'ok');
               return true;
            };
            if (!res.data.pending_upgrade) return done(res.data);
            // O pacote baixado trouxe migração pendente: conclui numa requisição nova, como no update.
            return post('finalize_update', !last).then(function (fin) {
               if (!fin.ok || !fin.data || !fin.data.success) {
                  return fail(idx, failText(fin));
               }
               return done(fin.data);
            });
         }).catch(function () {
            return fail(idx, NEXTOOL_DOWNLOAD_AD_I18N.network);
         });
      });
   });
   return chain.then(function (ok) {
      return { ok: ok === true, redirect: redirect, failedStep: failure ? failure.step : '', message: failure ? failure.message : '' };
   });
}

// Popup do Download. Abre sempre (#301): com o anúncio do módulo (ou o geral do público), ou só com a jornada.
//  - módulo gratuito com anúncio: o contador (#283) segura o botão; fechar antes cancela tudo;
//  - módulo pago: agradecimento sem contador, com Suporte e Deixar depoimento;
//  - sem anúncio: só a jornada.
// Devolve false quando o popup não existe na página (quem chamou segue com o download simples).
function nextoolShowDownloadAd(btn, moduleKey, endpoint) {
   var modalEl = document.getElementById('nextool-download-ad-modal');
   if (!modalEl || typeof bootstrap === 'undefined') return false;

   var I18N = NEXTOOL_DOWNLOAD_AD_I18N;
   var card = typeof btn.closest === 'function' ? btn.closest('.nextool-module-card') : null;
   var tier = card && card.dataset.moduleTier ? String(card.dataset.moduleTier) : '';
   var audience = tier === 'FREE' ? 'free' : (tier === 'PAID' ? 'paid' : '');
   var ad = audience !== '' ? nextoolDownloadAdFor(moduleKey, audience) : null;
   var isPaid = audience === 'paid';
   var canEnable = !!(btn.dataset && btn.dataset.chain === 'enable');
   var nameEl = card ? card.querySelector('.card-title') : null;
   var moduleName = nameEl ? nameEl.textContent.trim() : moduleKey;
   var goLabel = canEnable ? I18N.goFull : I18N.goInstall;

   var el = function (id) { return document.getElementById(id); };
   var safeUrl = function (u) { return (typeof u === 'string' && /^https:\/\//i.test(u)) ? u : null; };
   var event = function (name) { if (ad) nextoolDownloadAdEvent(name, ad, moduleKey); };

   var baseText = canEnable ? I18N.bodyFull : I18N.bodyInstall;
   // Módulo pago: o agradecimento ocupa o corpo (título e texto do anúncio de pago, ou os padrões da base); a barra
   // do popup fica com o nome do módulo e a linha de baixo, com o que vai acontecer.
   var thanks = el('nextool-download-ad-thanks');
   thanks.classList.toggle('d-none', !isPaid);
   if (isPaid) {
      el('nextool-download-ad-thanks-title').textContent = ad && ad.title ? String(ad.title) : I18N.paidTitle;
      el('nextool-download-ad-thanks-text').textContent = ad && ad.body ? String(ad.body) : I18N.paidText;
   }
   el('nextool-download-ad-title').textContent = (ad && !isPaid) ? String(ad.title || '') : I18N.titleNoAd.replace('%s', moduleName);
   el('nextool-download-ad-body').textContent = (ad && !isPaid) ? String(ad.body || '') : baseText;
   el('nextool-download-ad-body').classList.toggle('text-muted', isPaid);

   // Card com a cara do player do YouTube: capa local (thumb do vídeo, servida pela base), título no topo, play
   // vermelho no centro e o selo "Assista no YouTube". O play troca a capa pelo player do youtube-nocookie DENTRO do
   // card: antes do clique o navegador não fala com o Google. O selo abre o vídeo no YouTube (saída para firewall que
   // bloqueie o player) e também inicia o contador. Sem vídeo, só a imagem, no tamanho natural. Sem anúncio, o card some.
   var media = el('nextool-download-ad-media');
   var stage = el('nextool-download-ad-stage');
   var img = el('nextool-download-ad-image');
   var playBtn = el('nextool-download-ad-play');
   var ytLink = el('nextool-download-ad-youtube');
   var video = ad ? safeUrl(ad.video_url) : null;
   var videoId = (ad && typeof ad.video_id === 'string' && /^[A-Za-z0-9_-]{11}$/.test(ad.video_id)) ? ad.video_id : null;
   var imageUrl = (ad && typeof ad.image_url === 'string' && ad.image_url !== '') ? ad.image_url : null;
   var oldPlayer = stage.querySelector('iframe');
   if (oldPlayer) oldPlayer.remove();
   media.classList.toggle('d-none', !imageUrl && !videoId);
   media.classList.toggle('nextool-has-video', !!videoId);
   stage.style.aspectRatio = videoId ? '16 / 9' : '';
   img.style.height = videoId ? '100%' : 'auto';
   img.classList.toggle('d-none', !imageUrl);
   if (imageUrl) { img.src = imageUrl; } else { img.removeAttribute('src'); }
   playBtn.classList.toggle('d-none', !videoId);
   playBtn.classList.toggle('d-flex', !!videoId);
   var vtitle = el('nextool-download-ad-vtitle');
   vtitle.textContent = ad ? String(ad.video_title || ad.title || '') : '';
   vtitle.classList.toggle('d-none', !videoId);
   ytLink.classList.toggle('d-none', !(videoId && video));
   ytLink.classList.toggle('d-flex', !!(videoId && video));
   if (video) ytLink.href = video;
   ytLink.onclick = function () { event('click'); startCountdown(); };
   // O vídeo é um extra: qualquer problema para mostrá-lo (bloqueio, erro do player) não pode segurar o
   // download. O contador começa antes de montar o player e não depende dele.
   var startPlayer = function () {
      if (!videoId || stage.querySelector('iframe')) return;
      startCountdown();
      try {
      event('click');
      var frame = document.createElement('iframe');
      frame.src = 'https://www.youtube-nocookie.com/embed/' + videoId + '?autoplay=1&rel=0&playsinline=1';
      frame.title = String(ad.video_title || ad.title || 'YouTube');
      frame.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen');
      frame.setAttribute('allowfullscreen', '');
      frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
      img.classList.add('d-none');
      playBtn.classList.add('d-none');
      playBtn.classList.remove('d-flex');
      // O player de verdade traz o próprio título e o próprio "Assista no YouTube".
      vtitle.classList.add('d-none');
      ytLink.classList.add('d-none');
      ytLink.classList.remove('d-flex');
      stage.appendChild(frame);
      } catch (e) { /* sem vídeo, o contador segue */ }
   };
   playBtn.onclick = startPlayer;
   // Como no portfólio: clicar em qualquer ponto da capa também abre o vídeo.
   stage.style.cursor = videoId ? 'pointer' : '';
   stage.onclick = function (e) {
      if (videoId && !playBtn.contains(e.target) && !ytLink.contains(e.target)) startPlayer();
   };

   var cta = el('nextool-download-ad-cta');
   var ctaUrl = ad ? safeUrl(ad.cta_url) : null;
   cta.classList.toggle('d-none', !ctaUrl || !ad.cta_label);
   if (ctaUrl) {
      cta.href = ctaUrl;
      cta.querySelector('span').textContent = String(ad.cta_label || '');
   }
   cta.onclick = function () { event('click'); };

   // Módulo pago: suporte e depoimento, sempre (mesmo sem anúncio cadastrado).
   var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
   var supportBtn = el('nextool-download-ad-support');
   var supportModal = document.getElementById('nextool-support-modal');
   var testimonial = el('nextool-download-ad-testimonial');
   supportBtn.classList.toggle('d-none', !(isPaid && supportModal));
   // Falha da jornada: o Suporte abre com o módulo, o tipo Bug e o motivo da falha já escritos.
   var failureInfo = null;
   supportBtn.onclick = function () {
      event('click');
      var info = failureInfo;
      modalEl.addEventListener('hidden.bs.modal', function openSupport() {
         modalEl.removeEventListener('hidden.bs.modal', openSupport);
         if (typeof window.nextoolSupportOpen === 'function') {
            window.nextoolSupportOpen(info
               ? { item: moduleKey, type: 'bug',
                   subject: I18N.failSubject.replace('%s', moduleName),
                   description: I18N.failWhere.replace('%1$s', moduleName).replace('%2$s', moduleKey).replace('%3$s', info.failedStep)
                      + '\n\n' + I18N.failWhy.replace('%s', info.message) + '\n\n' + I18N.failDoing + ' ' }
               : { item: moduleKey });
         } else {
            bootstrap.Modal.getOrCreateInstance(supportModal).show();
         }
      });
      // Depois de uma falha, fechar o popup recarregaria a página: aqui ele só dá lugar ao suporte, e a página
      // recarrega quando o suporte fechar (o card passa a mostrar o estado em que o módulo ficou).
      if (info && supportModal) {
         supportModal.addEventListener('hidden.bs.modal', function reloadAfter() {
            supportModal.removeEventListener('hidden.bs.modal', reloadAfter);
            window.location.reload();
         });
      }
      finished = false;
      modal.hide();
   };
   testimonial.classList.toggle('d-none', !isPaid);
   if (isPaid) testimonial.href = I18N.testimonial + encodeURIComponent(moduleKey);
   testimonial.onclick = function () { event('click'); };

   var stepsEl = el('nextool-download-ad-steps');
   stepsEl.innerHTML = '';
   stepsEl.classList.add('d-none');
   var closeBtn = el('nextool-download-ad-close');
   closeBtn.classList.remove('d-none');

   var continueBtn = el('nextool-download-ad-continue');
   var continueLabel = el('nextool-download-ad-continue-label');
   var continueIcon = el('nextool-download-ad-continue-icon');
   // Contador só no anúncio de módulo gratuito. Pago e "sem anúncio": botão liberado ao abrir.
   var hasCountdown = !!ad && !isPaid;
   var total = hasCountdown ? Math.max(3, Math.min(30, parseInt(ad.countdown, 10) || 10)) : 0;
   var remaining = total;
   var timer = null;
   var running = false;
   var finished = false;
   // Clicou no botão de baixar: a jornada começa sozinha no fim do contador, aconteça o que acontecer com o vídeo.
   var armed = false;
   var setButton = function (label, icon, enabled) {
      continueLabel.textContent = label;
      continueIcon.className = icon + ' me-1';
      continueBtn.disabled = !enabled;
   };
   var tick = function () {
      if (remaining <= 0) {
         clearInterval(timer);
         timer = null;
         event('complete');
         if (armed) { runJourney(); return; }
         setButton(goLabel, 'ti ti-cloud-download', true);
         return;
      }
      // Antes do clique o botão fica disponível durante a contagem: clicar agenda o início para o fim dela.
      setButton((armed ? I18N.startsIn : I18N.buttonIn).replace('%1$s', goLabel).replace('%2$s', String(remaining)), 'ti ti-clock', !armed);
      remaining--;
   };
   var startCountdown = function () {
      if (!hasCountdown || timer || remaining !== total) return;
      tick();
      timer = setInterval(tick, 1000);
   };
   var beforeUnload = function (e) {
      e.preventDefault();
      e.returnValue = I18N.leave;
      return I18N.leave;
   };
   var runJourney = function () {
      running = true;
      closeBtn.classList.add('d-none');
      supportBtn.classList.add('d-none');
      continueLabel.textContent = I18N.working;
      continueIcon.className = 'spinner-border spinner-border-sm me-1';
      continueBtn.disabled = true;
      window.addEventListener('beforeunload', beforeUnload);
      var steps = [
         { action: 'download', label: I18N.stepDownload },
         { action: 'install', label: I18N.stepInstall }
      ];
      if (canEnable) steps.push({ action: 'enable', label: I18N.stepEnable });
      nextoolRunModuleJourney(endpoint, moduleKey, steps, stepsEl).then(function (res) {
         running = false;
         finished = true;
         window.removeEventListener('beforeunload', beforeUnload);
         if (res.ok) {
            if (res.redirect) { window.location.assign(res.redirect); } else { window.location.reload(); }
            return;
         }
         // Parou numa etapa: o card passa a refletir o estado em que ficou (baixado ou instalado). No lugar do
         // depoimento entra o Suporte (qualquer módulo), que já leva o motivo da falha.
         setButton(I18N.close, 'ti ti-x', true);
         closeBtn.classList.remove('d-none');
         testimonial.classList.add('d-none');
         cta.classList.add('d-none');
         // O agradecimento ("será instalado agora", convite ao depoimento) e o anúncio não cabem numa falha.
         thanks.classList.add('d-none');
         media.classList.add('d-none');
         var playing = stage.querySelector('iframe');
         if (playing) playing.remove();
         el('nextool-download-ad-body').textContent = I18N.failIntro;
         if (supportModal) {
            failureInfo = res;
            supportBtn.classList.remove('d-none');
         }
      });
   };
   continueBtn.onclick = function () {
      if (continueBtn.disabled || running) return;
      if (finished) { window.location.reload(); return; }
      if (remaining > 0) {
         // Contagem em curso (ou ainda por começar, no anúncio com vídeo): o clique agenda a jornada para o fim
         // dela e, se houver vídeo, toca o vídeo. Nenhuma falha do vídeo desfaz o agendamento.
         armed = true;
         if (hasCountdown && videoId && !stage.querySelector('iframe')) { startPlayer(); }
         startCountdown();
         setButton(I18N.startsIn.replace('%1$s', goLabel).replace('%2$s', String(Math.min(total, remaining + 1))), 'ti ti-clock', false);
         return;
      }
      runJourney();
   };
   // Fechar antes de começar = nada acontece; depois de uma falha, recarrega para o card mostrar o estado real.
   modalEl.addEventListener('hidden.bs.modal', function stopTimer() {
      if (timer) { clearInterval(timer); timer = null; }
      // Tirar o iframe para o vídeo parar junto com o popup.
      var player = stage.querySelector('iframe');
      if (player) player.remove();
      modalEl.removeEventListener('hidden.bs.modal', stopTimer);
      if (finished) window.location.reload();
   });

   if (hasCountdown && videoId) {
      setButton(I18N.watch, 'ti ti-player-play-filled', true);
   } else if (hasCountdown) {
      setButton(I18N.buttonIn.replace('%1$s', goLabel).replace('%2$s', String(total)), 'ti ti-clock', true);
   } else {
      setButton(goLabel, 'ti ti-cloud-download', true);
   }
   modal.show();
   event('impression');
   if (hasCountdown && !videoId) startCountdown();
   return true;
}

// --- "Atualizar todos" (#284) ---
// Elegível = card com o botão Atualizar HABILITADO: o card já aplica permissão, licença,
// vínculo, versão mínima da base e major, então a fila não reavalia regra nenhuma.
// UM módulo por vez (em paralelo as duas fases correm contra o OPcache e contra o limite de
// taxa da ContainerAPI), cada um com as mesmas duas chamadas do botão individual, e um
// único reload no fim.
var NEXTOOL_UPDATE_ALL_I18N = <?php echo json_encode([
   'waiting'      => __('Aguardando', 'nextool'),
   'downloading'  => __('Baixando', 'nextool'),
   'migrating'    => __('Migrando', 'nextool'),
   'ok'           => __('Atualizado', 'nextool'),
   'failed'       => __('Falhou', 'nextool'),
   'skipped'      => __('Ignorado', 'nextool'),
   'progress'     => __('Atualizando %1$s de %2$s. Não feche esta página.', 'nextool'),
   'summary'      => __('Concluído: %1$s atualizado(s), %2$s com falha, %3$s ignorado(s).', 'nextool'),
   'noPermission' => __('Sem permissão ou licença para atualizar este módulo.', 'nextool'),
   'needsAction'  => __('Requer ação no card: %s', 'nextool'),
   'unexpected'   => __('Erro inesperado ao processar a ação.', 'nextool'),
   'network'      => __('Falha na comunicação com o servidor.', 'nextool'),
   'leave'        => __('A atualização dos módulos ainda está em andamento.', 'nextool'),
   'pick'         => __('Escolha os módulos que serão atualizados. Nada é atualizado antes da confirmação.', 'nextool'),
   'confirmAll'   => __('Atualizar todos (%s)', 'nextool'),
   'confirmSome'  => __('Atualizar selecionados (%s)', 'nextool'),
]); ?>;

function nextoolUpdateAllCards() {
   var eligible = [];
   var skipped = [];
   document.querySelectorAll('.nextool-module-card').forEach(function (card) {
      var nameEl = card.querySelector('.card-title');
      var name = nameEl ? nameEl.textContent.trim() : '';
      var btn = card.querySelector('.nextool-module-action[data-action="update"]');
      if (btn && !btn.disabled && btn.dataset.module) {
         eligible.push({
            key: String(btn.dataset.module),
            name: name || String(btn.dataset.module),
            from: card.dataset.moduleInstalledVersion || '',
            to: card.dataset.moduleAvailableVersion || ''
         });
         return;
      }
      if (card.dataset.moduleUpdate !== '1') return;
      // Tem atualização, mas o card não oferece o botão: o motivo é o que o card mostra.
      var actions = card.querySelector('.mt-auto');
      var badge = actions ? actions.querySelector('.badge') : null;
      var reason;
      if (badge) {
         reason = badge.textContent.trim();
      } else if (btn) {
         reason = NEXTOOL_UPDATE_ALL_I18N.noPermission;
      } else {
         var other = actions ? actions.querySelector('button, a.btn') : null;
         var label = other ? other.textContent.trim() : '';
         reason = label ? NEXTOOL_UPDATE_ALL_I18N.needsAction.replace('%s', label) : NEXTOOL_UPDATE_ALL_I18N.noPermission;
      }
      skipped.push({ name: name, reason: reason });
   });
   return { eligible: eligible, skipped: skipped };
}

function nextoolSyncUpdateAllButton() {
   var btn = document.getElementById('nextool-update-all-btn');
   if (!btn) return;
   var count = nextoolUpdateAllCards().eligible.length;
   var countEl = document.getElementById('nextool-update-all-count');
   if (countEl) countEl.textContent = String(count);
   btn.classList.toggle('d-none', count < 2 || window._nextoolUpdateAllRunning === true);
}
window.nextoolSyncUpdateAllButton = nextoolSyncUpdateAllButton;

function nextoolUpdateAllSetRow(row, state, message) {
   var styles = {
      waiting:     ['bg-secondary', 'ti ti-clock'],
      downloading: ['bg-info', 'spinner'],
      migrating:   ['bg-info', 'spinner'],
      ok:          ['bg-success', 'ti ti-check'],
      failed:      ['bg-danger', 'ti ti-x'],
      skipped:     ['bg-warning', 'ti ti-player-skip-forward']
   };
   var st = styles[state] || styles.waiting;
   var badge = row.querySelector('.nextool-update-all-state');
   badge.className = 'badge text-white ' + st[0] + ' nextool-update-all-state';
   // "Em andamento" usa o spinner do Bootstrap: os ícones Tabler não giram sozinhos (#301).
   badge.innerHTML = st[1] === 'spinner'
      ? '<span class="spinner-border me-1" role="status" aria-hidden="true" style="width:.7rem;height:.7rem;border-width:.15em"></span>'
      : '<i class="' + st[1] + ' me-1"></i>';
   badge.appendChild(document.createTextNode(NEXTOOL_UPDATE_ALL_I18N[state] || state));
   var msg = row.querySelector('.nextool-update-all-msg');
   msg.textContent = message ? String(message).replace(/[<>]/g, '') : '';
   msg.classList.toggle('d-none', !message);
}

function nextoolUpdateAllRow(listEl, name) {
   var li = document.createElement('li');
   li.className = 'list-group-item px-0';
   li.innerHTML = '<div class="d-flex justify-content-between align-items-center gap-2">'
      + '<span class="fw-medium nextool-update-all-name"></span>'
      + '<span class="badge bg-secondary text-white nextool-update-all-state"></span></div>'
      + '<div class="small text-muted mt-1 d-none nextool-update-all-msg"></div>';
   li.querySelector('.nextool-update-all-name').textContent = name;
   listEl.appendChild(li);
   return li;
}

function nextoolUpdateOneModule(endpoint, key, row) {
   var base = { module: key, quiet: '1', forcetab: window._nextoolCurrentTab || 'PluginNextoolMainConfig$1' };
   var failMsg = function (data) {
      return (data && data.message) ? String(data.message) : NEXTOOL_UPDATE_ALL_I18N.unexpected;
   };
   nextoolUpdateAllSetRow(row, 'downloading');
   return nextoolPostJson(endpoint, Object.assign({ action: 'update' }, base))
      .then(function (r1) {
         if (!r1.ok || !r1.data || !r1.data.success) {
            nextoolUpdateAllSetRow(row, 'failed', failMsg(r1.data));
            return false;
         }
         if (!r1.data.pending_upgrade) {
            // Sucesso: a etiqueta "Atualizado" já diz tudo. Texto embaixo do nome só na falha (o motivo).
            nextoolUpdateAllSetRow(row, 'ok');
            return true;
         }
         // Fase 2 numa requisição NOVA, que já carrega o código novo (issue #158).
         nextoolUpdateAllSetRow(row, 'migrating');
         return nextoolPostJson(endpoint, Object.assign({ action: 'finalize_update' }, base))
            .then(function (r2) {
               if (!r2.ok || !r2.data || !r2.data.success) {
                  nextoolUpdateAllSetRow(row, 'failed', failMsg(r2.data));
                  return false;
               }
               nextoolUpdateAllSetRow(row, 'ok');
               return true;
            });
      })
      .catch(function () {
         // Se a fase 2 não chegou, o sync do próximo carregamento conclui a migração.
         nextoolUpdateAllSetRow(row, 'failed', NEXTOOL_UPDATE_ALL_I18N.network);
         return false;
      });
}

// 1o passo (#301): a lista com seleção. Nada é atualizado aqui; fechar ou Cancelar não faz nada.
function nextoolOpenUpdateAll() {
   if (window._nextoolUpdateAllRunning === true) return;
   var el = function (id) { return document.getElementById(id); };
   var modalEl = el('nextool-update-all-modal');
   var listEl = el('nextool-update-all-list');
   var statusEl = el('nextool-update-all-status');
   var doneBtn = el('nextool-update-all-done');
   var closeBtn = el('nextool-update-all-close');
   var cancelBtn = el('nextool-update-all-cancel');
   var confirmBtn = el('nextool-update-all-confirm');
   var pickAll = el('nextool-update-all-pick-all');
   var pickAllWrap = el('nextool-update-all-pick-all-wrap');
   if (!modalEl || !listEl || !statusEl || !doneBtn || !closeBtn || !cancelBtn || !confirmBtn || !pickAll || !pickAllWrap) return;

   var cards = nextoolUpdateAllCards();
   if (cards.eligible.length === 0) return;

   statusEl.textContent = NEXTOOL_UPDATE_ALL_I18N.pick;
   listEl.innerHTML = '';
   cards.eligible.forEach(function (m) {
      var li = document.createElement('li');
      li.className = 'list-group-item px-0';
      li.innerHTML = '<label class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer">'
         + '<input type="checkbox" class="form-check-input m-0 nextool-update-all-check" checked>'
         + '<span class="fw-medium flex-fill nextool-update-all-name"></span>'
         + '<span class="text-muted small text-nowrap nextool-update-all-versions"></span></label>';
      li.querySelector('.nextool-update-all-check').value = m.key;
      li.querySelector('.nextool-update-all-name').textContent = m.name;
      li.querySelector('.nextool-update-all-versions').textContent = (m.from && m.to) ? (m.from + ' \u2192 ' + m.to) : (m.to || '');
      listEl.appendChild(li);
   });
   cards.skipped.forEach(function (s) {
      var row = nextoolUpdateAllRow(listEl, s.name);
      row.classList.add('text-muted');
      nextoolUpdateAllSetRow(row, 'skipped', s.reason);
   });

   var checks = function () { return Array.prototype.slice.call(listEl.querySelectorAll('.nextool-update-all-check')); };
   var sync = function () {
      var all = checks();
      var n = all.filter(function (c) { return c.checked; }).length;
      pickAll.checked = n === all.length;
      pickAll.indeterminate = n > 0 && n < all.length;
      confirmBtn.disabled = n === 0;
      confirmBtn.querySelector('span').textContent =
         (n === all.length ? NEXTOOL_UPDATE_ALL_I18N.confirmAll : NEXTOOL_UPDATE_ALL_I18N.confirmSome).replace('%s', String(n));
   };
   listEl.onchange = sync;
   pickAll.onchange = function () {
      var on = pickAll.checked;
      checks().forEach(function (c) { c.checked = on; });
      sync();
   };

   var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
   var hide = function () { modal.hide(); };
   pickAllWrap.classList.remove('d-none');
   pickAllWrap.classList.add('d-flex');
   cancelBtn.classList.remove('d-none');
   confirmBtn.classList.remove('d-none');
   doneBtn.classList.add('d-none');
   closeBtn.classList.remove('d-none');
   closeBtn.onclick = hide;
   cancelBtn.onclick = hide;
   confirmBtn.onclick = function () {
      var picked = {};
      checks().forEach(function (c) { if (c.checked) picked[c.value] = true; });
      var chosen = cards.eligible.filter(function (m) { return picked[m.key] === true; });
      if (chosen.length === 0) return;
      nextoolRunUpdateAll(chosen, cards.skipped);
   };
   sync();
   modal.show();
}

// 2o passo: a fila, só com os módulos escolhidos no 1o passo.
function nextoolRunUpdateAll(chosen, skippedCards) {
   if (window._nextoolUpdateAllRunning === true) return;
   var modalEl = document.getElementById('nextool-update-all-modal');
   var listEl = document.getElementById('nextool-update-all-list');
   var statusEl = document.getElementById('nextool-update-all-status');
   var doneBtn = document.getElementById('nextool-update-all-done');
   var closeBtn = document.getElementById('nextool-update-all-close');
   if (!modalEl || !listEl || !statusEl || !doneBtn || !closeBtn) return;

   var cards = { eligible: Array.isArray(chosen) ? chosen : [], skipped: Array.isArray(skippedCards) ? skippedCards : [] };
   if (cards.eligible.length === 0) return;
   ['nextool-update-all-cancel', 'nextool-update-all-confirm'].forEach(function (id) {
      var b = document.getElementById(id);
      if (b) b.classList.add('d-none');
   });
   var pickAllWrap = document.getElementById('nextool-update-all-pick-all-wrap');
   if (pickAllWrap) { pickAllWrap.classList.add('d-none'); pickAllWrap.classList.remove('d-flex'); }
   listEl.onchange = null;
   doneBtn.classList.remove('d-none');

   var endpoint = <?php echo json_encode(PluginNextoolCompat::webDir() . '/ajax/module_action.php'); ?>;
   var fmt = function (tpl, args) {
      return tpl.replace(/%(\d)\$s/g, function (m, i) { return String(args[parseInt(i, 10) - 1]); });
   };

   listEl.innerHTML = '';
   var queue = cards.eligible.map(function (m) {
      var row = nextoolUpdateAllRow(listEl, m.name);
      nextoolUpdateAllSetRow(row, 'waiting');
      return { key: m.key, row: row };
   });
   cards.skipped.forEach(function (s) {
      nextoolUpdateAllSetRow(nextoolUpdateAllRow(listEl, s.name), 'skipped', s.reason);
   });

   var finish = function () { window.location.reload(); };
   doneBtn.disabled = true;
   closeBtn.classList.add('d-none');
   doneBtn.onclick = finish;
   closeBtn.onclick = finish;

   var beforeUnload = function (e) {
      e.preventDefault();
      e.returnValue = NEXTOOL_UPDATE_ALL_I18N.leave;
      return NEXTOOL_UPDATE_ALL_I18N.leave;
   };
   window._nextoolUpdateAllRunning = true;
   window.addEventListener('beforeunload', beforeUnload);
   nextoolSyncUpdateAllButton();
   bootstrap.Modal.getOrCreateInstance(modalEl).show();

   var okCount = 0;
   var failCount = 0;
   var chain = Promise.resolve();
   queue.forEach(function (item, idx) {
      chain = chain.then(function () {
         statusEl.textContent = fmt(NEXTOOL_UPDATE_ALL_I18N.progress, [idx + 1, queue.length]);
         return nextoolUpdateOneModule(endpoint, item.key, item.row).then(function (ok) {
            if (ok) { okCount++; } else { failCount++; }
         });
      });
   });
   chain.then(function () {
      window._nextoolUpdateAllRunning = false;
      window.removeEventListener('beforeunload', beforeUnload);
      statusEl.textContent = fmt(NEXTOOL_UPDATE_ALL_I18N.summary, [okCount, failCount, cards.skipped.length]);
      doneBtn.disabled = false;
      closeBtn.classList.remove('d-none');
   });
}

document.addEventListener('click', function (event) {
   var btn = event.target && typeof event.target.closest === 'function'
      ? event.target.closest('#nextool-update-all-btn')
      : null;
   if (!btn) return;
   event.preventDefault();
   nextoolOpenUpdateAll();
});
if (document.readyState === 'loading') {
   document.addEventListener('DOMContentLoaded', nextoolSyncUpdateAllButton);
} else {
   nextoolSyncUpdateAllButton();
}
document.addEventListener('glpi.load', nextoolSyncUpdateAllButton);

function nextoolInitCoreUpdateModal() {
   var modalEl = document.getElementById('nextool-core-update-modal');
   if (!modalEl) return;
   if (modalEl.dataset.nextoolCoreUpdateBound === '1') return;
   modalEl.dataset.nextoolCoreUpdateBound = '1';

   var endpoint = <?php echo json_encode(PluginNextoolCompat::webDir() . '/ajax/core_update.php'); ?>;

   function invokeCoreUpdate(action) {
      var csrfToken = nextoolGetAjaxCsrfToken();
      if (!csrfToken && !NEXTOOL_CSRF_TOKENLESS) {
         return Promise.reject(new Error('csrf-token-not-found'));
      }

      return fetch(endpoint, {
         method: 'POST',
         headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': csrfToken || ''
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
            var actionError = new Error(payload.message || <?php echo json_encode(__('Falha na execução da ação.', 'nextool')); ?>);
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
   // Prontidão para a próxima major do GLPI (preflight.major_readiness): aviso + checkbox que libera
   // o próximo passo. Só aparece quando a release já suporta a major e há módulo instalado sem ela.
   var confirmMajorEl = document.getElementById('nextool-modal-confirm-major');
   var confirmMajorTextEl = document.getElementById('nextool-modal-confirm-major-text');
   var confirmMajorInputEl = document.getElementById('nextool-modal-confirm-major-input');
   var majorConfirmPending = false;
   var LABEL_MAJOR_WAITING = <?php echo json_encode(__('Ainda sem versão compatível:', 'nextool')); ?>;

   function hideMajorConfirm() {
      majorConfirmPending = false;
      if (confirmMajorEl) confirmMajorEl.classList.add('d-none');
      if (confirmMajorInputEl) confirmMajorInputEl.checked = false;
      if (confirmMajorTextEl) confirmMajorTextEl.textContent = '';
   }

   function showMajorConfirm(info) {
      if (!confirmMajorEl || !confirmMajorTextEl || !info || !(info.count > 0)) { return false; }
      var text = document.createElement('div');
      var p = document.createElement('p'); p.className = 'mb-1'; p.textContent = info.message || ''; text.appendChild(p);
      if (info.waiting && info.waiting.length) {
         var pw = document.createElement('p'); pw.className = 'mb-0';
         var sw = document.createElement('strong'); sw.textContent = LABEL_MAJOR_WAITING + ' '; pw.appendChild(sw);
         pw.appendChild(document.createTextNode(info.waiting.join(', '))); text.appendChild(pw);
      }
      confirmMajorTextEl.innerHTML = '';
      confirmMajorTextEl.appendChild(text);
      if (confirmMajorInputEl) confirmMajorInputEl.checked = false;
      confirmMajorEl.classList.remove('d-none');
      majorConfirmPending = true;
      return true;
   }

   var STEPS = [
      { action: 'preflight', badge: 'nextool-modal-step-1-badge', status: 'nextool-modal-step-1-status' },
      { action: 'prepare',   badge: 'nextool-modal-step-2-badge', status: 'nextool-modal-step-2-status' },
      { action: 'apply',     badge: 'nextool-modal-step-3-badge', status: 'nextool-modal-step-3-status' }
   ];

   var isRunning = false;
   var wasStarted = false;
   var currentStep = -1; // -1=not started, 0=preflight done, 1=download done

   var LABEL_DONE  = '<i class="ti ti-check me-1"></i>' + <?php echo json_encode(__('Concluído! Recarregando...', 'nextool')); ?>;
   // Estado final: verde com texto branco e sem o esmaecido do disabled (o btn-primary desabilitado
   // virava fundo claro com texto cinza). A página recarrega em seguida, então não há reset.
   function nextoolMarkUpdateDone(btn) {
      btn.disabled = true;
      btn.className = 'btn btn-success text-white';
      btn.style.opacity = '1';
      btn.innerHTML = LABEL_DONE;
   }
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
      '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + <?php echo json_encode(__('Verificando...', 'nextool')); ?>,
      '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + <?php echo json_encode(__('Baixando...', 'nextool')); ?>,
      '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + <?php echo json_encode(__('Aplicando...', 'nextool')); ?>
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
         if (badge) { badge.className = 'badge bg-secondary text-white rounded-pill me-2'; badge.textContent = String(i + 1); }
         if (status) status.innerHTML = '';
      });

      if (alertEl) { alertEl.className = 'alert d-none mb-2'; alertEl.textContent = ''; }
      if (permDetailEl) permDetailEl.classList.add('d-none');
      if (confirmApplyEl) confirmApplyEl.classList.add('d-none');
      if (confirmApplyInputEl) confirmApplyInputEl.value = '';
      hideMajorConfirm();
      if (actionBtn) { actionBtn.disabled = false; actionBtn.innerHTML = STEP_BTN_LABELS[0]; }
      if (typeof fetchBackups === 'function') fetchBackups();
   }

   function setStepActive(i) {
      var badge = document.getElementById(STEPS[i].badge);
      if (badge) {
         badge.className = 'badge bg-primary text-white rounded-pill me-2';
         badge.innerHTML = '<span class="spinner-border" role="status" aria-hidden="true" style="width:.75rem;height:.75rem;border-width:.15em"></span>';
      }
   }

   function setStepOk(i) {
      var badge = document.getElementById(STEPS[i].badge);
      var status = document.getElementById(STEPS[i].status);
      if (badge) { badge.className = 'badge bg-success text-white rounded-pill me-2'; badge.innerHTML = '<i class="ti ti-check" style="font-size:.75rem"></i>'; }
      if (status) status.innerHTML = '<i class="ti ti-check text-success"></i>';
   }

   function setStepError(i, message) {
      var badge = document.getElementById(STEPS[i].badge);
      var status = document.getElementById(STEPS[i].status);
      if (badge) { badge.className = 'badge bg-danger text-white rounded-pill me-2'; badge.innerHTML = '<i class="ti ti-x" style="font-size:.75rem"></i>'; }
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
         desc: <?php echo json_encode(__('A atualização precisa das extensões PHP curl (baixar o pacote) e phar (descompactar o .tar.gz).', 'nextool')); ?>,
         fix: <?php echo json_encode(__('curl e phar normalmente já vêm habilitados no PHP. Habilite os que faltarem e reinicie o PHP-FPM/Apache.', 'nextool')); ?>
      },
      'signature_verifier': {
         title: <?php echo json_encode(__('Sem verificador de assinatura do pacote', 'nextool')); ?>,
         desc: <?php echo json_encode(__('Antes de aplicar, o NexTool confere a assinatura oficial do pacote. Isso exige a extensão sodium OU o openssl do sistema (versão 3.0+).', 'nextool')); ?>,
         fix: <?php echo json_encode(__('Habilite a extensão sodium - faz parte do núcleo do PHP 7.2+, não é biblioteca de terceiros (ex.: apt install php-sodium no Debian/Ubuntu, ou extension=sodium no php.ini) - e reinicie o PHP-FPM/Apache. Em alternativa, garanta o openssl 3.0+ no servidor com a função proc_open habilitada.', 'nextool')); ?>
      },
      'containerapi_connectivity': {
         title: <?php echo json_encode(__('Sem conexão com o servidor de atualizações', 'nextool')); ?>,
         desc: <?php echo json_encode(__('O servidor GLPI não conseguiu se conectar à ContainerAPI.', 'nextool')); ?>,
         fix: <?php echo json_encode(__('Verifique se o servidor tem acesso à internet ou à rede interna da ContainerAPI, e se não há regras de firewall bloqueando conexões de saída.', 'nextool')); ?>
      },
      // #285: sem manifesto o motivo vem do servidor (requisito de PHP/GLPI, nenhuma versão nova, falha de
      // comunicação) e entra como descrição (desc: null = usar bl.message, escapado).
      'manifest_available': {
         title: <?php echo json_encode(__('Atualização indisponível para este servidor', 'nextool')); ?>,
         desc: null,
         fix: <?php echo json_encode(__('Se o motivo for um requisito (PHP ou GLPI), ajuste o servidor e verifique de novo. Em caso de dúvida, abra um atendimento pelo botão Suporte.', 'nextool')); ?>
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

   function nextoolEscapeBlockerText(text) {
      return String(text).replace(/[&<>"']/g, function (c) {
         return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
      });
   }

   function buildPreflightErrorHtml(blockers) {
      var currentVer = currentVersionEl ? currentVersionEl.textContent : (window._nextoolCurrentVersion || '?');
      var targetVer = targetVersionEl ? targetVersionEl.textContent : (window._nextoolTargetVersion || '?');

      var clip = [];
      clip.push('=== ' + <?php echo json_encode(__('Relatório de Bloqueio – Atualização NexTool', 'nextool')); ?> + ' ===');
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
         var desc = h ? (h.desc !== null ? h.desc : nextoolEscapeBlockerText(bl.message || '')) : (bl.message || '');
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
         if (desc) clip.push(h && h.desc === null ? (bl.message || '') : desc);
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
      hideMajorConfirm();

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
            // Módulos que ficariam bloqueados numa migração para a próxima major: exige o "entendi".
            var preflightData = payload.data.preflight || null;
            if (preflightData && preflightData.major_readiness) {
               showMajorConfirm(preflightData.major_readiness);
            }
         }

         // After apply success with needs_reload - reload the current page (#264).
         // redirect_url continua respeitado se o backend algum dia voltar a envia-lo.
         if (stepIndex === 2 && payload && payload.data && payload.data.needs_reload) {
            if (actionBtn) { nextoolMarkUpdateDone(actionBtn); }
            if (alertEl) {
               alertEl.className = 'alert alert-success mb-2';
               alertEl.textContent = payload.message || <?php echo json_encode(__('Atualização aplicada com sucesso.', 'nextool')); ?>;
            }
            isRunning = false;
            wasStarted = false;
            var redirectUrl = (payload.data && payload.data.redirect_url) ? payload.data.redirect_url : '';
            setTimeout(function() {
               if (redirectUrl) {
                  window.location.href = redirectUrl;
               } else {
                  window.location.reload();
               }
            }, 3000);
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
               // Com aviso de major pendente, o próximo passo só libera depois do checkbox.
               if (actionBtn) actionBtn.disabled = majorConfirmPending;
            }
         } else {
            // All done (safety fallback)
            if (actionBtn) { nextoolMarkUpdateDone(actionBtn); }
            wasStarted = false;
         }
         isRunning = false;

      } catch (error) {
         var msg = error && error.message ? error.message : <?php echo json_encode(__('Erro desconhecido.', 'nextool')); ?>;
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
   if (confirmMajorInputEl) {
      confirmMajorInputEl.addEventListener('change', function() {
         // Só gera efeito entre o preflight e o download (currentStep 0); o "entendi" libera o botão.
         if (actionBtn && currentStep === 0 && !isRunning) {
            actionBtn.disabled = !confirmMajorInputEl.checked;
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
      if (!backupSectionEl) return;

      nextoolPostJson(endpoint, { action: 'list_backups', channel: 'stable' })
      .then(function(result) {
         var data = result.data;
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
            html += '<span class="badge bg-secondary text-white me-1">v' + (bk.version || '?') + '</span>';
            html += '<span class="small text-muted">' + dateStr + '</span>';
            if (i === 0) html += ' <span class="badge bg-azure text-white ms-1">' + LABEL_LATEST + '</span>';
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
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + LABEL_RESTORING;

      nextoolPostJson(endpoint, { action: 'restore', backup_id: backupId })
      .then(function(result) {
         var data = result.data;
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
   var COOLDOWN_SECONDS = 10;
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
   var actionInput = form.querySelector('input[name="action"]');
   if (!actionInput) {
      actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      form.appendChild(actionInput);
   }
   actionInput.value = 'validate_license';

   // Cooldown: ativar countdown de 10s e desabilitar botão
   _nextoolSyncCooldown.activate();
   if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + <?php echo json_encode(__('Sincronizando...', 'nextool')); ?>;
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

// Aba Logs: o form de busca do grid Search::show submete sem forcetab/id, então ENTER
// (e o botão Pesquisar) recarregava para FORA da aba Logs. Injeta forcetab+id como campos
// ocultos no form da busca (identificado por itemtype) -> o submit preserva a aba Logs com
// a pesquisa aplicada. Sem isso, apertar ENTER no filtro jogava o usuário para outra aba.
(function nextoolSearchEnterAsButton() {
   // Grids Search embutidos nas abas do plugin (ex.: Logs): apertar ENTER no campo de busca
   // disparava o SUBMIT NATIVO do form (GET) -> recarregava a página inteira e caía FORA da
   // aba. O core do GLPI (Search/Table.js) só trata o CLIQUE no botão "Pesquisar" (onSearch,
   // busca via AJAX, sem reload). Interceptamos o submit desses forms e disparamos o clique
   // no botão -> ENTER passa a se comportar exatamente como o clique em "Pesquisar".
   document.addEventListener('submit', function (e) {
      var f = e.target;
      if (!f || typeof f.querySelector !== 'function') { return; }
      if (!f.classList || !f.classList.contains('search-form-container')) { return; }
      var it = f.querySelector('input[name="itemtype"]');
      if (!it || String(it.value).indexOf('PluginNextool') !== 0) { return; }
      var btn = f.querySelector('button[name="search"]');
      if (!btn) { return; }
      e.preventDefault();
      btn.click();
   }, true);
})();

// F5 -- nextoolRegenerateHmac removido: a rotação de segredo/identidade é coordenada pelo servidor
// (re-enroll/migrate). Sem botões que disparem a ação 'regenerate_hmac'.


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
   nextoolInitContactSourceField();
   nextoolInitContactModulesField();
}
if (document.readyState === 'loading') {
   document.addEventListener('DOMContentLoaded', _nextoolInitContactAll);
} else {
   _nextoolInitContactAll();
}
document.addEventListener('glpi.load', _nextoolInitContactAll);

// ─── Module Filter/Search (event delegation - robusto para AJAX) ────────────
(function() {
   if (document.documentElement.dataset.nextoolModuleFiltersBound === '1') return;
   document.documentElement.dataset.nextoolModuleFiltersBound = '1';

   var storageKey = 'nextool.module.filter';
   var searchStorageKey = 'nextool.module.search';
   var allowedFilters = ['enabled', 'disabled', 'download', 'install', 'update', 'free', 'licensed', 'dev', 'category'];
   var allowedFilterSet = new Set(allowedFilters);
   var activeFilter = '';
   var activeCategory = '';
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

   function getSearchFromUrl() {
      try {
         var url = new URL(window.location.href);
         return String(url.searchParams.get('module_search') || '').trim();
      } catch (e) {
         return '';
      }
   }

   function setSearchInUrl(query) {
      if (!window.history || !window.history.replaceState) return;
      try {
         var url = new URL(window.location.href);
         if (query) {
            url.searchParams.set('module_search', query);
         } else {
            url.searchParams.delete('module_search');
         }
         window.history.replaceState(null, '', url.toString());
      } catch (e) {}
   }

   function loadSearchFromStorage() {
      var storage = getStorage();
      if (!storage) return '';
      try {
         return String(storage.getItem(searchStorageKey) || '').trim();
      } catch (e) {
         return '';
      }
   }

   function saveSearchToStorage(query) {
      var storage = getStorage();
      if (!storage) return;
      try {
         if (query) {
            storage.setItem(searchStorageKey, query);
         } else {
            storage.removeItem(searchStorageKey);
         }
      } catch (e) {}
   }

   function persistSearch(query) {
      saveSearchToStorage(query);
      setSearchInUrl(query);
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
         if (filter === 'category') {
            var cat = (chip.dataset.category || '').trim();
            chip.classList.toggle('active', activeFilter === 'category' && activeCategory === cat);
         } else {
            chip.classList.toggle('active', filter !== '' && filter === activeFilter);
         }
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
         return card.dataset.moduleDownloaded === '0' && card.dataset.moduleCanDownload === '1';
      case 'install':
         return card.dataset.moduleInstallReady === '1';
      case 'update':
         return card.dataset.moduleUpdate === '1';
      case 'free':
         return card.dataset.moduleTier === 'FREE';
      case 'licensed':
         return card.dataset.moduleTier !== 'FREE' && card.dataset.moduleTier !== 'DEV';
      case 'dev':
         return card.dataset.moduleTier === 'DEV';
      case 'category':
         // N:N -- o card pode ter várias categorias (CSV no data-attr); casa se CONTÉM a ativa.
         return activeCategory !== '' && (card.dataset.moduleCategory || '')
            .split(',').map(function (c) { return c.trim(); }).indexOf(activeCategory) !== -1;
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

      // Restaurar texto de busca (URL tem prioridade sobre storage)
      var searchInput = document.getElementById('nextool-module-search');
      if (searchInput) {
         var savedSearch = getSearchFromUrl() || loadSearchFromStorage();
         if (savedSearch) {
            searchInput.value = savedSearch;
            persistSearch(savedSearch);
         }
      }

      if (!syncChipClasses()) return false;
      applyFilters();
      if (typeof window.nextoolSyncUpdateAllButton === 'function') window.nextoolSyncUpdateAllButton();
      return true;
   }

   var _restoredFilter = getFilterFromUrl() || loadFilterFromStorage();
   if (_restoredFilter === 'category') {
      _restoredFilter = '';
      saveFilterToStorage('');
   }
   setActiveFilter(_restoredFilter, false);

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

   // Event delegation no document - funciona mesmo após replace do DOM via AJAX
   document.addEventListener('input', function(e) {
      if (e.target && e.target.id === 'nextool-module-search') {
         clearTimeout(debounceTimer);
         debounceTimer = setTimeout(function() {
            var query = e.target.value.trim();
            persistSearch(query);
            applyFilters();
         }, 250);
      }
   });

   document.addEventListener('keydown', function(e) {
      if (e.target && e.target.id === 'nextool-module-search' && e.key === 'Escape') {
         e.target.value = '';
         persistSearch('');
         applyFilters();
      }
   });

   document.addEventListener('click', function(e) {
      var chip = e.target.closest('.nextool-filter-chip');
      if (!chip) return;
      var filter = (chip.dataset.filter || '').trim();
      if (!isAllowedFilter(filter)) return;

      if (filter === 'category') {
         var cat = (chip.dataset.category || '').trim();
         if (activeFilter === 'category' && activeCategory === cat) {
            activeCategory = '';
            setActiveFilter('');
         } else {
            activeCategory = cat;
            setActiveFilter('category');
         }
      } else {
         activeCategory = '';
         setActiveFilter(activeFilter === filter ? '' : filter);
      }
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

// Screenshot hover (POC)
(function() {
   var activePopover = null;
   document.addEventListener('mouseenter', function(e) {
      var card = e.target.closest && e.target.closest('.nextool-module-card');
      if (!card) return;
      var url = card.dataset.screenshotUrl;
      if (!url || !window.bootstrap || typeof window.bootstrap.Popover !== 'function') return;
      if (activePopover) { activePopover.dispose(); activePopover = null; }
      var trigger = card.querySelector('.card');
      if (!trigger) return;
      activePopover = new window.bootstrap.Popover(trigger, {
         trigger: 'manual', placement: 'auto', html: true,
         content: '<img src="' + url + '" alt="Preview" style="max-width:280px;border-radius:4px;">',
         customClass: 'nextool-screenshot-tooltip'
      });
      activePopover.show();
   }, true);
   document.addEventListener('mouseleave', function(e) {
      var card = e.target.closest && e.target.closest('.nextool-module-card');
      if (!card) return;
      if (activePopover) { activePopover.dispose(); activePopover = null; }
   }, true);
})();
</script>

<?php
require_once NEXTOOL_PHP_DIR . '/inc/alertmanager.class.php';
$_nextoolUnreadAlerts = PluginNextoolAlertManager::getUnreadAlerts();
if (!empty($_nextoolUnreadAlerts)):
   $alertsAjaxUrl = PluginNextoolCompat::webDir() . '/ajax/alerts.php';
?>
<div class="modal fade" id="nextool-alerts-modal" tabindex="-1" aria-hidden="true">
   <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
         <div class="modal-header" style="background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 40%, #14b8a6 100%); color: #fff;">
            <h5 class="modal-title"><i class="ti ti-bell me-2"></i><?php echo __('Novos alertas da NexTool', 'nextool'); ?></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
         </div>
         <div class="modal-body">
            <div class="list-group list-group-flush">
               <?php foreach ($_nextoolUnreadAlerts as $ua):
                  $uaIcon = PluginNextoolAlertManager::getTypeIcon($ua['alert_type']);
                  $uaBadge = PluginNextoolAlertManager::getTypeBadgeClass($ua['alert_type']);
               ?>
               <div class="list-group-item border-0 px-0">
                  <div class="d-flex gap-2 align-items-start">
                     <i class="<?php echo $uaIcon; ?> fs-3 mt-1"></i>
                     <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                           <strong><?php echo PluginNextoolCompat::escape($ua['title']); ?></strong>
                           <span class="badge <?php echo $uaBadge; ?>" style="font-size: 0.65rem;"><?php echo ucfirst($ua['alert_type']); ?></span>
                        </div>
                        <div class="small"><?php echo PluginNextoolAlertManager::sanitizeBody($ua['body']); ?></div>
                     </div>
                  </div>
               </div>
               <?php endforeach; ?>
            </div>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-primary" id="nextool-alerts-modal-dismiss" data-bs-dismiss="modal">
               <i class="ti ti-check me-1"></i><?php echo __('Entendi', 'nextool'); ?>
            </button>
         </div>
      </div>
   </div>
</div>
<script>
(function() {
   var m = document.getElementById('nextool-alerts-modal');
   if (m && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
      var modal = new window.bootstrap.Modal(m);
      modal.show();
      var dismissBtn = document.getElementById('nextool-alerts-modal-dismiss');
      if (dismissBtn) {
         dismissBtn.addEventListener('click', function() {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', <?php echo json_encode($alertsAjaxUrl); ?>);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            var csrfToken = nextoolGetAjaxCsrfToken();
            if (csrfToken) { xhr.setRequestHeader('X-Glpi-Csrf-Token', csrfToken); }
            xhr.send('action=mark_all_read');
         });
      }
   }
})();
</script>
<?php endif; // fim do modal de alertas (só renderiza quando há alertas não lidos) ?>

<?php /* F3 (5.0.0): vínculo de conta -- SEMPRE emitido, fora do if de alertas. */ ?>
<script>
// === F3 (5.0.0): vínculo de conta NexTool ===
var NEXTOOL_ACCOUNT_ENDPOINT = <?php echo json_encode(PluginNextoolCompat::webDir() . '/ajax/account_action.php'); ?>;

function nextoolAccountLinkAlert(type, msg) {
   var box = document.getElementById('nextool-account-link-alert');
   if (!box) { return; }
   box.className = 'alert mt-2 alert-' + type;
   box.textContent = msg;
   box.classList.remove('d-none');
}

function nextoolResetLinkSuccessView() {
   // Restaura o modal normal (header/body/footer) e esconde a view celebrativa.
   // Chamado a cada abertura para que reabrir volte à view de vincular/gerenciar.
   var mc = document.querySelector('#nextool-account-link-modal .modal-content');
   var successView = document.getElementById('nextool-account-link-success');
   if (successView) { successView.classList.add('d-none'); }
   if (mc) {
      ['.modal-header', '.modal-body', '.modal-footer'].forEach(function (sel) {
         var el = mc.querySelector(sel);
         if (el) { el.classList.remove('d-none'); }
      });
   }
}
function nextoolRefreshLinkStatus() {
   nextoolResetLinkSuccessView();
   var statusBox = document.getElementById('nextool-account-link-status');
   var unlinkBtn = document.getElementById('nextool-account-link-unlink-btn');
   var codeBox = document.getElementById('nextool-account-link-code-box');
   if (codeBox) { codeBox.classList.add('d-none'); }
   if (!statusBox) { return; }
   statusBox.className = 'alert alert-secondary d-flex align-items-center mb-3';
   statusBox.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span><span><?php echo PluginNextoolCompat::escape(__('Verificando vínculo...', 'nextool')); ?></span>';
   nextoolPostJson(NEXTOOL_ACCOUNT_ENDPOINT, { action: 'refresh_status' }).then(function (res) {
      var d = res.data || {};
      if (!res.ok || !d.success) {
         statusBox.className = 'alert alert-warning mb-3';
         statusBox.textContent = d.message || <?php echo json_encode(__('Não foi possível consultar o vínculo.', 'nextool')); ?>;
         return;
      }
      var goBtn = document.getElementById('nextool-account-link-portal-go-btn');
      var genBtn = document.getElementById('nextool-account-link-generate-btn');
      var introLinked = document.getElementById('nextool-account-link-intro-linked');
      var introUnlinked = document.getElementById('nextool-account-link-intro-unlinked');
      if (introLinked) { introLinked.classList.toggle('d-none', !d.linked); }
      if (introUnlinked) { introUnlinked.classList.toggle('d-none', !!d.linked); }
      if (d.linked) {
         // Um dado por linha (o .alert do Tabler é flex: d-block evita as linhas lado a lado). E-mail e código
         // do ambiente (client_identifier) vêm do servidor: montados com nós de texto, nunca no innerHTML.
         statusBox.className = 'alert alert-success mb-3 d-block';
         statusBox.textContent = '';
         var addLine = function (label, value, asCode) {
            var line = document.createElement('div');
            line.className = 'mt-1';
            line.appendChild(document.createTextNode(label + ': '));
            var val = document.createElement(asCode ? 'code' : 'strong');
            if (asCode) { val.className = 'text-nowrap'; }
            val.textContent = value;
            line.appendChild(val);
            statusBox.appendChild(line);
         };
         var linkedLine = document.createElement('div');
         linkedLine.className = 'fw-semibold';
         linkedLine.innerHTML = '<i class="ti ti-circle-check me-1"></i>';
         linkedLine.appendChild(document.createTextNode(<?php echo json_encode(__('Ambiente vinculado', 'nextool')); ?>));
         statusBox.appendChild(linkedLine);
         if (d.portal_email) { addLine(<?php echo json_encode(__('Conta', 'nextool')); ?>, d.portal_email, false); }
         if (d.environment_id) { addLine(<?php echo json_encode(__('Código do ambiente', 'nextool')); ?>, d.environment_id, true); }
         if (unlinkBtn) { unlinkBtn.classList.remove('d-none'); }
         if (goBtn) { goBtn.classList.remove('d-none'); }
         if (genBtn) { genBtn.classList.add('d-none'); }
      } else {
         statusBox.className = 'alert alert-secondary mb-3';
         statusBox.innerHTML = '<i class="ti ti-circle-dashed me-1"></i><?php echo PluginNextoolCompat::escape(__('Ambiente ainda não vinculado.', 'nextool')); ?>';
         if (unlinkBtn) { unlinkBtn.classList.add('d-none'); }
         if (goBtn) { goBtn.classList.add('d-none'); }
         if (genBtn) { genBtn.classList.remove('d-none'); }
      }
   });
}

function nextoolGenerateLinkCode(btn) {
   if (btn) { btn.disabled = true; }
   nextoolPostJson(NEXTOOL_ACCOUNT_ENDPOINT, { action: 'generate_link_code' }).then(function (res) {
      if (btn) { btn.disabled = false; }
      var d = res.data || {};
      if (!res.ok || !d.success) {
         nextoolAccountLinkAlert('danger', d.message || <?php echo json_encode(__('Falha ao gerar o código.', 'nextool')); ?>);
         return;
      }
      var codeInput = document.getElementById('nextool-account-link-code');
      var box = document.getElementById('nextool-account-link-code-box');
      var portalBtn = document.getElementById('nextool-account-link-portal-btn');
      var expires = document.getElementById('nextool-account-link-expires');
      if (codeInput) { codeInput.value = d.link_code || ''; }
      if (portalBtn && d.portal_link_url) { portalBtn.setAttribute('href', d.portal_link_url); }
      if (expires) {
         var mins = Math.round((d.expires_in || 600) / 60);
         expires.textContent = <?php echo json_encode(__('Válido por aproximadamente', 'nextool')); ?> + ' ' + mins + ' min.';
      }
      if (box) { box.classList.remove('d-none'); }
      var alertBox = document.getElementById('nextool-account-link-alert');
      if (alertBox) { alertBox.classList.add('d-none'); }
      nextoolStartLinkPolling();
   }).catch(function () {
      if (btn) { btn.disabled = false; }
      nextoolAccountLinkAlert('danger', <?php echo json_encode(__('Erro de comunicação.', 'nextool')); ?>);
   });
}

function nextoolCopyLinkCode(btn) {
   var codeInput = document.getElementById('nextool-account-link-code');
   if (!codeInput) { return; }
   codeInput.select();
   try { document.execCommand('copy'); } catch (e) {}
   if (navigator.clipboard) { navigator.clipboard.writeText(codeInput.value).catch(function () {}); }
   if (btn) {
      var orig = btn.innerHTML;
      btn.innerHTML = '<i class="ti ti-check"></i>';
      setTimeout(function () { btn.innerHTML = orig; }, 1500);
   }
}

function nextoolUnlinkAccount(btn) {
   nextoolShowConfirm(<?php echo json_encode(__('Desvincular este ambiente da conta? Você precisará vincular novamente para baixar módulos gratuitos.', 'nextool')); ?>, function () {
      if (btn) { btn.disabled = true; }
      nextoolPostJson(NEXTOOL_ACCOUNT_ENDPOINT, { action: 'unlink' }).then(function (res) {
         if (btn) { btn.disabled = false; }
         var d = res.data || {};
         if (res.ok && d.success) {
            nextoolRefreshLinkStatus();
         } else {
            nextoolAccountLinkAlert('danger', d.message || <?php echo json_encode(__('Falha ao desvincular.', 'nextool')); ?>);
         }
      });
   });
}
// Item 2: após gerar o código, fica "Aguardando confirmação..." (amarelo girando) e faz polling
// do status a cada 3s; quando o vínculo confirma no site, vira verde (via nextoolRefreshLinkStatus).
var nextoolLinkPollTimer = null;
function nextoolStopLinkPolling() {
   // setTimeout auto-reagendado (fix #243): clearTimeout cobre o timer pendente.
   if (nextoolLinkPollTimer) { clearTimeout(nextoolLinkPollTimer); nextoolLinkPollTimer = null; }
}
function nextoolStartLinkPolling() {
   nextoolStopLinkPolling();
   var statusBox = document.getElementById('nextool-account-link-status');
   if (statusBox) {
      statusBox.className = 'alert alert-warning d-flex align-items-center mb-3';
      statusBox.innerHTML = '<span class="spinner-border spinner-border-sm text-warning me-2"></span><span>'
         + <?php echo json_encode(__('Aguardando confirmação no portal...', 'nextool')); ?> + '</span>';
   }
   // Fix do incidente smagalhaes (#243): o setInterval fixo de 3s sem .catch e sem
   // caminho de parada martelou 401 por 36h (~43k requests). Agora: setTimeout
   // auto-reagendado com escada de recuo em falha [3s,10s,30s,2min], parada após
   // 5 falhas seguidas (ou sinal suppressed do servidor) com botao "Tentar novamente".
   var failStreak = 0;
   var POLL_LADDER_MS = [3000, 10000, 30000, 120000];
   function nextoolShowPollStopped() {
      var box = document.getElementById('nextool-account-link-status');
      if (!box) { return; }
      box.className = 'alert alert-warning mb-3';
      box.innerHTML = '<i class="ti ti-alert-triangle me-1"></i> '
         + <?php echo json_encode(__('Não foi possível consultar o status do vínculo agora. Tente novamente em instantes.', 'nextool')); ?>
         + ' <button type="button" class="btn btn-sm btn-outline-warning ms-2" onclick="nextoolStartLinkPolling();">'
         + <?php echo json_encode(__('Tentar novamente', 'nextool')); ?> + '</button>';
   }
   function nextoolScheduleNextPoll() {
      var delay = failStreak > 0
         ? POLL_LADDER_MS[Math.min(failStreak, POLL_LADDER_MS.length) - 1]
         : POLL_LADDER_MS[0];
      nextoolLinkPollTimer = setTimeout(nextoolPollTick, delay);
   }
   function nextoolPollFailed() {
      failStreak++;
      if (failStreak >= 5) {
         nextoolStopLinkPolling();
         nextoolShowPollStopped();
         return;
      }
      nextoolScheduleNextPoll();
   }
   function nextoolPollTick() {
      nextoolPostJson(NEXTOOL_ACCOUNT_ENDPOINT, { action: 'refresh_status' }).then(function (res) {
         var d = res.data || {};
         if (d && d.suppressed) {
            // Backoff server-side ativo: parar de vez (a janela pode ser de minutos).
            nextoolStopLinkPolling();
            nextoolShowPollStopped();
            return;
         }
         if (!res.ok || !d.success) {
            nextoolPollFailed();
            return;
         }
         failStreak = 0;
         if (!d.linked) {
            nextoolScheduleNextPoll();
            return;
         }
         nextoolHandleLinkConfirmed();
      }).catch(function () {
         nextoolPollFailed();
      });
   }
   function nextoolHandleLinkConfirmed() {
      nextoolStopLinkPolling();
      // Vinculo recem-confirmado: mostra a view celebrativa (esconde o modal normal).
      // O catalogo/modulos so aparecem apos recarregar a pagina.
      var mc = document.querySelector('#nextool-account-link-modal .modal-content');
      var successView = document.getElementById('nextool-account-link-success');
      if (mc && successView) {
         ['.modal-header', '.modal-body', '.modal-footer'].forEach(function (sel) {
            var el = mc.querySelector(sel);
            if (el) { el.classList.add('d-none'); }
         });
         successView.classList.remove('d-none');
      } else {
         // Fallback: se a view nao existir, mantem a confirmacao simples no status.
         var statusBox = document.getElementById('nextool-account-link-status');
         if (statusBox) {
            statusBox.className = 'alert alert-success mb-3';
            statusBox.innerHTML = '<i class="ti ti-circle-check me-1"></i> '
               + <?php echo json_encode(__('Conta vinculada. Recarregue a página para atualizar a lista.', 'nextool')); ?>
               + ' <button type="button" class="btn btn-sm btn-success ms-2" onclick="window.location.reload();"><i class="ti ti-refresh me-1"></i>'
               + <?php echo json_encode(__('Recarregar agora', 'nextool')); ?> + '</button>';
         }
      }
   }
   nextoolScheduleNextPoll();
}
(function () {
   var m = document.getElementById('nextool-account-link-modal');
   if (m) { m.addEventListener('hidden.bs.modal', nextoolStopLinkPolling); }
})();
window.nextoolRefreshLinkStatus = nextoolRefreshLinkStatus;
window.nextoolGenerateLinkCode = nextoolGenerateLinkCode;
window.nextoolCopyLinkCode = nextoolCopyLinkCode;
window.nextoolUnlinkAccount = nextoolUnlinkAccount;
</script>
