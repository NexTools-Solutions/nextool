<?php
declare(strict_types=1);
/**
 * Popup de suporte (2026-10-09): o atendimento é aberto aqui e acompanhado no portal.
 *
 * Renderizado UMA vez na página principal (nextoolconfig.form.php), não no hero: o hero chega por AJAX em cada
 * guia e script embutido em innerHTML não executa. O botão "Suporte" do hero só abre este modal.
 *
 * Regras (decisões do owner, 2026-10-09): SLA sem horário de atendimento; licenciado (item = módulo com licença
 * ativa) Bug até 2 dias úteis e Suporte geral até 5; gratuito Bug até 5 e Dúvida até 30, sem suporte a módulos;
 * sugestão não abre atendimento (vira registro fechado); urgência por WhatsApp só com licença ativa; relatório
 * técnico com a caixa desmarcada e prévia. O nível real é decidido pelo servidor.
 * Desenho: /data/relatorios/suporte-no-plugin-2026-10-09/desenho.md
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
if (!PluginNextoolPermissionManager::canBase(PluginNextoolPermissionManager::BASE_SEND_CONTACT)) {
   return;
}
require_once NEXTOOL_PHP_DIR . '/inc/supportreport.class.php';

$nxSupportItems   = PluginNextoolSupportReport::supportItems();
$nxSupportLinked  = ((string) (Config::getConfigurationValues('plugin:nextool_account_link')['linked'] ?? '0')) === '1';
$nxSupportReport  = PluginNextoolPermissionManager::canManageAdminTabs();
$nxSupportUrgent  = PluginNextoolSupportReport::hasActiveLicense();
$nxSupportEnv     = (string) (PluginNextoolConfig::getDistributionSettings()['client_identifier'] ?? '');
parse_str((string) parse_url(NEXTOOL_WHATSAPP_URL, PHP_URL_QUERY), $nxSupportWaQuery);
$nxSupportPhone   = preg_replace('/\D/', '', (string) ($nxSupportWaQuery['phone'] ?? ''));
$nxSupportUrgentUrl = 'https://api.whatsapp.com/send?phone=' . $nxSupportPhone . '&text=' . rawurlencode(
   sprintf(__('Olá! Preciso de suporte URGENTE no NexTool (ambiente %s): ', 'nextool'), $nxSupportEnv !== '' ? $nxSupportEnv : '-')
);
$nxSupportEndpoint = PluginNextoolCompat::webDir() . '/ajax/support.php';

// Tipos por nível. 'days' = prazo de resposta em dias úteis (null = sugestão, sem atendimento).
$nxSupportTypes = [
   'licensed' => [
      ['key' => 'bug',      'label' => __('Bug', 'nextool'),           'days' => 2],
      ['key' => 'suporte',  'label' => __('Suporte geral', 'nextool'), 'days' => 5],
      ['key' => 'sugestao', 'label' => __('Sugestão', 'nextool'), 'days' => null],
   ],
   'free' => [
      ['key' => 'bug',      'label' => __('Bug', 'nextool'),           'days' => 5],
      ['key' => 'duvida',   'label' => __('Dúvida', 'nextool'),        'days' => 30],
      ['key' => 'sugestao', 'label' => __('Sugestão', 'nextool'), 'days' => null],
   ],
];
?>
<div class="modal fade" id="nextool-support-modal" tabindex="-1" aria-hidden="true">
   <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
      <div class="modal-content">
         <div class="modal-header">
            <h5 class="modal-title"><i class="ti ti-lifebuoy me-2"></i><?php echo __('Suporte NexTool', 'nextool'); ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
         </div>
         <div class="modal-body" id="nextool-support-body">
            <p class="text-muted small mb-3">
               <?php echo __('Abra o atendimento por aqui e acompanhe a conversa com a equipe no portal NexTool.', 'nextool'); ?>
            </p>

            <div class="row g-2 mb-3">
               <div class="col-12 col-md-6">
                  <?php // Destaque das vantagens do licenciado: roxo da marca (o mesmo do "Licenciar" dos cards). ?>
                  <div class="rounded p-2 h-100" style="border:1px solid #7c3aed;border-left-width:4px;background:rgba(124,58,237,.08);">
                     <div class="fw-semibold mb-1" style="color:#7c3aed;"><i class="ti ti-certificate me-1"></i><?php echo __('Módulos licenciados', 'nextool'); ?></div>
                     <div class="small"><i class="ti ti-check me-1" style="color:#7c3aed;"></i><?php echo __('Bugs: resposta em até 2 dias úteis', 'nextool'); ?></div>
                     <div class="small"><i class="ti ti-check me-1" style="color:#7c3aed;"></i><?php echo __('Suporte geral: resposta em até 5 dias úteis', 'nextool'); ?></div>
                     <div class="small"><i class="ti ti-check me-1" style="color:#7c3aed;"></i><?php echo __('Suporte por WhatsApp para incidentes', 'nextool'); ?></div>
                  </div>
               </div>
               <div class="col-12 col-md-6">
                  <div class="border rounded p-2 h-100">
                     <div class="fw-semibold mb-1"><i class="ti ti-gift me-1"></i><?php echo __('Plugin e módulos gratuitos', 'nextool'); ?></div>
                     <div class="small"><?php echo __('Bugs: resposta em até 5 dias úteis', 'nextool'); ?></div>
                     <div class="small"><?php echo __('Dúvidas: resposta em até 30 dias úteis', 'nextool'); ?></div>
                  </div>
               </div>
            </div>

            <?php if (!$nxSupportLinked): ?>
               <div class="alert alert-warning d-flex align-items-center gap-2 mb-0" id="nextool-support-unlinked">
                  <i class="ti ti-user-exclamation fs-2"></i>
                  <div class="flex-grow-1"><?php echo __('Para abrir atendimentos, vincule este ambiente a uma conta NexTool. As respostas da equipe chegam por essa conta.', 'nextool'); ?></div>
                  <button type="button" class="btn btn-warning btn-sm text-nowrap" onclick="nextoolSupportGoLink();">
                     <i class="ti ti-user-check me-1"></i><?php echo __('Vincular conta', 'nextool'); ?>
                  </button>
               </div>
            <?php else: ?>
               <form id="nextool-support-form" onsubmit="nextoolSupportSubmit(event);" novalidate>
                  <div class="row g-3">
                     <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="nextool-support-item"><?php echo __('Sobre o quê?', 'nextool'); ?></label>
                        <select class="form-select" id="nextool-support-item" onchange="nextoolSupportRenderTypes();">
                           <?php foreach ($nxSupportItems as $row): ?>
                              <option value="<?php echo PluginNextoolCompat::escape($row['key']); ?>" data-licensed="<?php echo $row['licensed'] ? '1' : '0'; ?>">
                                 <?php echo PluginNextoolCompat::escape($row['name'] . ($row['licensed'] ? ' (' . __('licenciado', 'nextool') . ')' : '')); ?>
                              </option>
                           <?php endforeach; ?>
                        </select>
                     </div>
                     <div class="col-12 col-md-6">
                        <span class="form-label fw-semibold d-block mb-2"><?php echo __('Tipo', 'nextool'); ?></span>
                        <div class="btn-group flex-wrap" role="group" id="nextool-support-types"></div>
                        <div class="form-text" id="nextool-support-sla"></div>
                     </div>
                     <div class="col-12">
                        <label class="form-label fw-semibold" for="nextool-support-subject"><?php echo __('Assunto', 'nextool'); ?></label>
                        <input type="text" class="form-control" id="nextool-support-subject" maxlength="150" autocomplete="off">
                     </div>
                     <div class="col-12">
                        <label class="form-label fw-semibold" for="nextool-support-description"><?php echo __('Descrição', 'nextool'); ?></label>
                        <textarea class="form-control" id="nextool-support-description" rows="5" maxlength="10000"
                           placeholder="<?php echo PluginNextoolCompat::escape(__('O que aconteceu, o que você esperava e os passos para reproduzir.', 'nextool')); ?>"></textarea>
                     </div>
                     <?php if ($nxSupportReport): ?>
                        <div class="col-12">
                           <div class="border rounded p-2">
                              <label class="form-check mb-1">
                                 <input class="form-check-input" type="checkbox" id="nextool-support-with-report">
                                 <span class="form-check-label fw-semibold"><?php echo __('Anexar relatório técnico deste ambiente', 'nextool'); ?>
                                    <?php // Ajuda no padrão do core ("?" form-help + data-bs-title); o initTooltips() do GLPI inicializa. ?>
                                    <span class="form-help" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true"
                                       data-bs-title="<?php echo PluginNextoolCompat::escape(__('Versões do GLPI, do PHP e do banco, módulos e plugins instalados, tarefas automáticas e os erros ligados ao NexTool dos últimos 7 dias. E-mails, IPs e tokens saem mascarados. Ajuda a equipe a responder sem precisar pedir esses dados.', 'nextool')); ?>">?</span>
                                 </span>
                              </label>
                              <div class="ms-4 mt-1 d-flex flex-wrap gap-3 small">
                                 <a href="#" onclick="nextoolSupportPreview(event);"><i class="ti ti-eye me-1"></i><?php echo __('Ver o que será enviado', 'nextool'); ?></a>
                                 <a href="<?php echo PluginNextoolCompat::escape($nxSupportEndpoint . '?action=download'); ?>"><i class="ti ti-download me-1"></i><?php echo __('Baixar relatório', 'nextool'); ?></a>
                              </div>
                              <pre class="d-none mt-2 mb-0 p-2 bg-light border rounded small" id="nextool-support-preview" style="max-height:280px;overflow:auto;white-space:pre-wrap;"></pre>
                           </div>
                        </div>
                     <?php endif; ?>
                  </div>
                  <div class="alert d-none mt-3 mb-0" role="alert" id="nextool-support-alert"></div>
               </form>
            <?php endif; ?>
         </div>

         <div class="modal-footer" id="nextool-support-footer">
            <?php if ($nxSupportUrgent): ?>
               <a href="<?php echo PluginNextoolCompat::escape($nxSupportUrgentUrl); ?>" target="_blank" rel="noopener" class="btn btn-outline-danger me-auto"
                  title="<?php echo PluginNextoolCompat::escape(__('Para incidentes que param a operação', 'nextool')); ?>">
                  <i class="ti ti-urgent me-1"></i><?php echo __('Urgência', 'nextool'); ?>
               </a>
            <?php endif; ?>
            <a href="<?php echo PluginNextoolCompat::escape(NEXTOOL_PORTAL_SUPPORT_URL); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary<?php echo $nxSupportUrgent ? '' : ' me-auto'; ?>">
               <i class="ti ti-list-details me-1"></i><?php echo __('Acompanhar minhas solicitações', 'nextool'); ?>
            </a>
            <?php if ($nxSupportLinked): ?>
               <button type="submit" form="nextool-support-form" class="btn btn-primary" id="nextool-support-submit">
                  <i class="ti ti-send me-1"></i><span id="nextool-support-submit-label"><?php echo __('Abrir atendimento', 'nextool'); ?></span>
               </button>
            <?php endif; ?>
         </div>

         <div id="nextool-support-success" class="d-none p-4 text-center">
            <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle bg-success text-white" style="width:64px;height:64px;">
               <i class="ti ti-check" style="font-size:32px;"></i>
            </div>
            <h4 class="mb-1" id="nextool-support-success-title"></h4>
            <p class="text-muted mb-3" id="nextool-support-success-text"></p>
            <a href="#" target="_blank" rel="noopener" class="btn btn-primary" id="nextool-support-success-link">
               <i class="ti ti-external-link me-1"></i><?php echo __('Ver no portal', 'nextool'); ?>
            </a>
            <button type="button" class="btn btn-link text-muted" data-bs-dismiss="modal"><?php echo __('Fechar', 'nextool'); ?></button>
         </div>
      </div>
   </div>
</div>

<script>
// === Popup de suporte (2026-10-09) ===
var NEXTOOL_SUPPORT_ENDPOINT = <?php echo json_encode($nxSupportEndpoint); ?>;
var NEXTOOL_SUPPORT_TYPES = <?php echo json_encode($nxSupportTypes, JSON_UNESCAPED_UNICODE); ?>;
var NEXTOOL_SUPPORT_TXT = <?php echo json_encode([
   'sla'          => __('Prazo de resposta: até %d dias úteis.', 'nextool'),
   'submit'       => __('Abrir atendimento', 'nextool'),
   'submitSugg'   => __('Registrar sugestão', 'nextool'),
   'sending'      => __('Enviando...', 'nextool'),
   'loading'      => __('Gerando o relatório...', 'nextool'),
   'needSubject'  => __('Informe um assunto entre 3 e 150 caracteres.', 'nextool'),
   'needDesc'     => __('Descreva a solicitação com pelo menos 10 caracteres.', 'nextool'),
   'needType'     => __('Escolha o tipo da solicitação.', 'nextool'),
   'commError'    => __('Erro de comunicação.', 'nextool'),
   'opened'       => __('Atendimento aberto', 'nextool'),
   'openedText'   => __('A equipe responde pelo portal NexTool e você recebe os avisos por e-mail.', 'nextool'),
   'deadline'     => __('Resposta até %s.', 'nextool'),
   'suggested'    => __('Sugestão registrada', 'nextool'),
   'suggestedText'=> __('Obrigado! A sugestão foi registrada para avaliação da equipe.', 'nextool'),
], JSON_UNESCAPED_UNICODE); ?>;

function nextoolSupportFmt(tpl, value) {
   return String(tpl).replace(/%[sd]/, String(value));
}

function nextoolSupportAlert(type, msg) {
   var box = document.getElementById('nextool-support-alert');
   if (!box) { return; }
   if (!msg) { box.classList.add('d-none'); return; }
   box.className = 'alert mt-3 mb-0 alert-' + type;
   box.textContent = msg;
}

function nextoolSupportSelectedType() {
   var checked = document.querySelector('#nextool-support-types input[name="nextool-support-type"]:checked');
   return checked ? checked.value : '';
}

function nextoolSupportRenderTypes() {
   var select = document.getElementById('nextool-support-item');
   var wrap = document.getElementById('nextool-support-types');
   if (!select || !wrap) { return; }
   var opt = select.options[select.selectedIndex];
   var tier = opt && opt.getAttribute('data-licensed') === '1' ? 'licensed' : 'free';
   var previous = nextoolSupportSelectedType();
   wrap.innerHTML = '';
   NEXTOOL_SUPPORT_TYPES[tier].forEach(function (t, i) {
      var id = 'nextool-support-type-' + t.key;
      var input = document.createElement('input');
      input.type = 'radio';
      input.className = 'btn-check';
      input.name = 'nextool-support-type';
      input.id = id;
      input.value = t.key;
      input.setAttribute('data-days', t.days === null ? '' : String(t.days));
      input.checked = (t.key === previous) || (previous === '' && i === 0);
      input.addEventListener('change', nextoolSupportRenderSla);
      var label = document.createElement('label');
      label.className = 'btn btn-outline-secondary btn-sm';
      label.setAttribute('for', id);
      label.textContent = t.label;
      wrap.appendChild(input);
      wrap.appendChild(label);
   });
   if (!nextoolSupportSelectedType()) {
      var first = wrap.querySelector('input');
      if (first) { first.checked = true; }
   }
   nextoolSupportRenderSla();
}

function nextoolSupportRenderSla() {
   var sla = document.getElementById('nextool-support-sla');
   var label = document.getElementById('nextool-support-submit-label');
   var checked = document.querySelector('#nextool-support-types input:checked');
   if (!sla || !checked) { return; }
   var days = checked.getAttribute('data-days');
   sla.textContent = days ? nextoolSupportFmt(NEXTOOL_SUPPORT_TXT.sla, days) : '';
   if (label) { label.textContent = days ? NEXTOOL_SUPPORT_TXT.submit : NEXTOOL_SUPPORT_TXT.submitSugg; }
}

function nextoolSupportReset() {
   var body = document.getElementById('nextool-support-body');
   var footer = document.getElementById('nextool-support-footer');
   var success = document.getElementById('nextool-support-success');
   if (body) { body.classList.remove('d-none'); }
   if (footer) { footer.classList.remove('d-none'); }
   if (success) { success.classList.add('d-none'); }
   nextoolSupportAlert('', '');
   nextoolSupportRenderTypes();
}

function nextoolSupportGoLink() {
   var el = document.getElementById('nextool-support-modal');
   var target = document.getElementById('nextool-account-link-modal');
   if (window.bootstrap && el) { bootstrap.Modal.getOrCreateInstance(el).hide(); }
   if (window.bootstrap && target) {
      bootstrap.Modal.getOrCreateInstance(target).show();
      if (typeof nextoolRefreshLinkStatus === 'function') { nextoolRefreshLinkStatus(); }
   }
}

function nextoolSupportPreview(ev) {
   if (ev) { ev.preventDefault(); }
   var pre = document.getElementById('nextool-support-preview');
   if (!pre) { return; }
   if (!pre.classList.contains('d-none') && pre.textContent !== NEXTOOL_SUPPORT_TXT.loading) {
      pre.classList.add('d-none');
      return;
   }
   pre.textContent = NEXTOOL_SUPPORT_TXT.loading;
   pre.classList.remove('d-none');
   nextoolPostJson(NEXTOOL_SUPPORT_ENDPOINT, { action: 'preview' }).then(function (res) {
      var d = res.data || {};
      pre.textContent = (res.ok && d.success) ? d.text : (d.message || NEXTOOL_SUPPORT_TXT.commError);
   }).catch(function () {
      pre.textContent = NEXTOOL_SUPPORT_TXT.commError;
   });
}

function nextoolSupportSubmit(ev) {
   if (ev) { ev.preventDefault(); }
   var subject = (document.getElementById('nextool-support-subject') || {}).value || '';
   var description = (document.getElementById('nextool-support-description') || {}).value || '';
   var item = (document.getElementById('nextool-support-item') || {}).value || '';
   var type = nextoolSupportSelectedType();
   var withReport = document.getElementById('nextool-support-with-report');
   subject = subject.trim();
   description = description.trim();
   if (!type) { nextoolSupportAlert('warning', NEXTOOL_SUPPORT_TXT.needType); return; }
   if (subject.length < 3 || subject.length > 150) { nextoolSupportAlert('warning', NEXTOOL_SUPPORT_TXT.needSubject); return; }
   if (description.length < 10) { nextoolSupportAlert('warning', NEXTOOL_SUPPORT_TXT.needDesc); return; }
   var btn = document.getElementById('nextool-support-submit');
   var label = document.getElementById('nextool-support-submit-label');
   var original = label ? label.textContent : '';
   if (btn) { btn.disabled = true; }
   if (label) { label.textContent = NEXTOOL_SUPPORT_TXT.sending; }
   nextoolSupportAlert('', '');
   nextoolPostJson(NEXTOOL_SUPPORT_ENDPOINT, {
      action: 'submit',
      subject: subject,
      description: description,
      item: item,
      type: type,
      with_report: withReport && withReport.checked ? '1' : '0'
   }).then(function (res) {
      if (btn) { btn.disabled = false; }
      if (label) { label.textContent = original; }
      var d = res.data || {};
      if (!res.ok || !d.success) {
         nextoolSupportAlert('danger', d.message || (NEXTOOL_SUPPORT_TXT.commError + ' (HTTP ' + res.status + ')'));
         return;
      }
      nextoolSupportShowSuccess(d, type === 'sugestao');
   }).catch(function () {
      if (btn) { btn.disabled = false; }
      if (label) { label.textContent = original; }
      nextoolSupportAlert('danger', NEXTOOL_SUPPORT_TXT.commError);
   });
}

function nextoolSupportShowSuccess(d, isSuggestion) {
   var title = document.getElementById('nextool-support-success-title');
   var text = document.getElementById('nextool-support-success-text');
   var link = document.getElementById('nextool-support-success-link');
   if (title) { title.textContent = isSuggestion ? NEXTOOL_SUPPORT_TXT.suggested : NEXTOOL_SUPPORT_TXT.opened; }
   if (text) {
      var msg = isSuggestion ? NEXTOOL_SUPPORT_TXT.suggestedText : NEXTOOL_SUPPORT_TXT.openedText;
      if (!isSuggestion && d.prazo_resposta_em) {
         var dt = new Date(d.prazo_resposta_em);
         if (!isNaN(dt.getTime())) { msg = nextoolSupportFmt(NEXTOOL_SUPPORT_TXT.deadline, dt.toLocaleDateString()) + ' ' + msg; }
      }
      text.textContent = msg;
   }
   if (link) {
      if (d.url) { link.setAttribute('href', d.url); link.classList.remove('d-none'); } else { link.classList.add('d-none'); }
   }
   ['nextool-support-body', 'nextool-support-footer'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) { el.classList.add('d-none'); }
   });
   var success = document.getElementById('nextool-support-success');
   if (success) { success.classList.remove('d-none'); }
   var form = document.getElementById('nextool-support-form');
   if (form) { form.reset(); }
   var pre = document.getElementById('nextool-support-preview');
   if (pre) { pre.classList.add('d-none'); pre.textContent = ''; }
}

(function () {
   var el = document.getElementById('nextool-support-modal');
   if (!el) { return; }
   el.addEventListener('show.bs.modal', nextoolSupportReset);
   // Links de aviso (licença suspensa/cancelada) chegam com ?nextool_support=1.
   try {
      if (new URLSearchParams(window.location.search).get('nextool_support') === '1' && window.bootstrap) {
         document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(el).show(); });
         if (document.readyState !== 'loading') { bootstrap.Modal.getOrCreateInstance(el).show(); }
      }
   } catch (e) {}
})();
</script>
