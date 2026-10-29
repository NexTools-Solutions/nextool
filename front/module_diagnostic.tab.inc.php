<?php
declare(strict_types=1);
/**
 * Painel compartilhado "Modo diagnóstico do módulo" (Fase 1: o secguard é o piloto).
 *
 * Variáveis esperadas via $GLOBALS:
 *   'nextool_diag_module_key'          => string (module_key)
 *   'nextool_diag_observability_class' => string (FQCN da casca PluginNextool<X>Observability)
 *
 * Quem vê o quê (PluginNextoolModuleDiagnostic):
 *   - estado + Ativar/Desativar: CONFIGURE do módulo;
 *   - últimos erros + diagnóstico completo (vêm de ações de OUTROS usuários): VIEW_LOGS.
 * "Abrir chamado" leva só o RESUMO (sem linha de log); o completo sai pelo "Copiar".
 * Tudo que vem de log passa por escape na saída.
 *
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once NEXTOOL_PHP_DIR . '/inc/modulediagnostic.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/baseobservability.class.php';

$nxDiagKey   = PluginNextoolModuleDiagnostic::cleanKey((string) ($GLOBALS['nextool_diag_module_key'] ?? ''));
$nxDiagClass = (string) ($GLOBALS['nextool_diag_observability_class'] ?? '');
if ($nxDiagKey === '' || !class_exists($nxDiagClass) || !is_subclass_of($nxDiagClass, 'PluginNextoolBaseObservability')) {
   return;
}
$nxDiagStatus = PluginNextoolModuleDiagnostic::status($nxDiagKey);
if (!$nxDiagStatus['can_enable'] && !$nxDiagStatus['can_view_logs']) {
   return;
}

$nxDiagId       = 'nxdiag-' . $nxDiagKey;
$nxDiagEndpoint = PluginNextoolCompat::webDir() . '/ajax/module_diagnostic.php';
$nxDiagErrors   = $nxDiagStatus['can_view_logs'] ? $nxDiagClass::errosRecentes(15) : [];
$nxDiagText     = $nxDiagStatus['can_view_logs'] ? $nxDiagClass::snapshotAsText() : '';
$nxDiagTicket   = $nxDiagStatus['can_view_logs']
   ? $nxDiagClass::urlAbrirChamado(sprintf(__('Suporte - módulo %s', 'nextool'), $nxDiagKey))
   : '';
$esc = static fn(string $s): string => PluginNextoolCompat::escape($s);
?>
<div class="card mt-3" id="<?php echo $esc($nxDiagId); ?>" data-nextool-diag-panel="<?php echo $esc($nxDiagKey); ?>">
   <div class="card-header">
      <h5 class="card-title mb-0 d-flex align-items-center gap-2">
         <i class="ti ti-stethoscope"></i>
         <span><?php echo __('Modo diagnóstico do módulo', 'nextool'); ?></span>
         <?php if ($nxDiagStatus['active']): ?>
            <span class="badge bg-orange text-white" data-nextool-diag-state="on"><?php
               echo $esc($nxDiagStatus['until'] !== ''
                  ? sprintf(__('Ativo até %s', 'nextool'), $nxDiagStatus['until'])
                  : __('Ativo', 'nextool'));
            ?></span>
         <?php else: ?>
            <span class="badge bg-secondary text-white" data-nextool-diag-state="off"><?php echo __('Desligado', 'nextool'); ?></span>
         <?php endif; ?>
      </h5>
   </div>
   <div class="card-body">
      <p class="text-secondary mb-3"><?php
         echo __('Não é o modo debug do GLPI. Registra mais detalhes deste módulo por até 24 horas e depois desliga sozinho. Os erros técnicos abaixo aparecem mesmo com o modo desligado.', 'nextool');
      ?></p>

      <?php if ($nxDiagStatus['can_enable']): ?>
      <div class="d-flex flex-wrap gap-2 mb-3">
         <?php if ($nxDiagStatus['active']): ?>
            <button type="button" class="btn btn-outline-secondary" data-nextool-diag-action="disable">
               <i class="ti ti-player-stop me-1"></i><?php echo __('Desativar modo diagnóstico', 'nextool'); ?>
            </button>
         <?php else: ?>
            <button type="button" class="btn btn-primary" data-nextool-diag-action="enable">
               <i class="ti ti-player-play me-1"></i><?php echo __('Ativar modo diagnóstico', 'nextool'); ?>
            </button>
         <?php endif; ?>
         <span class="small text-danger align-self-center" data-nextool-diag-msg style="display:none;"></span>
      </div>
      <?php endif; ?>

      <?php if ($nxDiagStatus['can_view_logs']): ?>
      <div class="fw-semibold mb-2"><i class="ti ti-alert-circle me-1"></i><?php echo __('Últimos erros técnicos', 'nextool'); ?></div>
      <?php if ($nxDiagErrors === []): ?>
         <div class="text-secondary mb-3" data-nextool-diag-errors="0"><?php echo __('Nenhum erro técnico registrado.', 'nextool'); ?></div>
      <?php else: ?>
         <ul class="list-unstyled font-monospace small mb-3" data-nextool-diag-errors="<?php echo count($nxDiagErrors); ?>">
            <?php foreach ($nxDiagErrors as $nxDiagLine): ?>
               <li class="border-bottom py-1"><?php echo $esc((string) $nxDiagLine); ?></li>
            <?php endforeach; ?>
         </ul>
      <?php endif; ?>

      <div class="fw-semibold mb-2"><i class="ti ti-clipboard-text me-1"></i><?php echo __('Diagnóstico para o suporte', 'nextool'); ?></div>
      <textarea class="form-control font-monospace" rows="10" readonly data-nextool-diag-text><?php echo $esc($nxDiagText); ?></textarea>
      <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
         <button type="button" class="btn btn-outline-primary" data-nextool-diag-copy>
            <i class="ti ti-copy me-1"></i><?php echo __('Copiar diagnóstico', 'nextool'); ?>
         </button>
         <a href="<?php echo $esc($nxDiagTicket); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary">
            <i class="ti ti-lifebuoy me-1"></i><?php echo __('Abrir chamado na NexTool', 'nextool'); ?>
         </a>
         <span class="small text-success" data-nextool-diag-copied style="display:none;">
            <i class="ti ti-check me-1"></i><?php echo __('Copiado!', 'nextool'); ?>
         </span>
      </div>
      <div class="small text-secondary mt-2"><?php
         echo __('O chamado leva só o resumo. Para enviar os erros, copie o diagnóstico e cole no chamado.', 'nextool');
      ?></div>
      <?php endif; ?>
   </div>
</div>
<script>
(function () {
   var root = document.getElementById(<?php echo json_encode($nxDiagId); ?>);
   if (!root) { return; }
   var endpoint = <?php echo json_encode($nxDiagEndpoint, JSON_UNESCAPED_SLASHES); ?>;
   var moduleKey = <?php echo json_encode($nxDiagKey); ?>;
   var msg = root.querySelector('[data-nextool-diag-msg]');
   root.querySelectorAll('[data-nextool-diag-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
         if (!window.jQuery) { return; }
         btn.disabled = true;
         window.jQuery.ajax({ url: endpoint, method: 'POST', dataType: 'json',
            data: { action: btn.getAttribute('data-nextool-diag-action'), module: moduleKey } })
            .done(function (r) {
               if (r && r.success) { window.location.reload(); return; }
               btn.disabled = false;
               if (msg) { msg.textContent = (r && r.message) || ''; msg.style.display = ''; }
            })
            .fail(function (xhr) {
               btn.disabled = false;
               var r = xhr && xhr.responseJSON;
               if (msg) { msg.textContent = (r && r.message) || ''; msg.style.display = ''; }
            });
      });
   });
   var copy = root.querySelector('[data-nextool-diag-copy]');
   var text = root.querySelector('[data-nextool-diag-text]');
   var copied = root.querySelector('[data-nextool-diag-copied]');
   if (copy && text) {
      copy.addEventListener('click', function () {
         var done = function () { if (copied) { copied.style.display = ''; } };
         if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text.value).then(done, function () { text.select(); document.execCommand('copy'); done(); });
         } else {
            text.select(); document.execCommand('copy'); done();
         }
      });
   }
})();
</script>
