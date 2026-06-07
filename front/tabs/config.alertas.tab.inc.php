<?php
declare(strict_types=1);
/**
 * Aba Alertas do Nextool - historico de alertas recebidos.
 */

require_once NEXTOOL_PHP_DIR . '/inc/alertmanager.class.php';

$alertHistory = PluginNextoolAlertManager::getAlertHistory();
$alertsEndpoint = Plugin::getWebDir('nextool') . '/ajax/alerts.php';
?>

<?php $show_alertas = (!$nextool_is_standalone || $nextool_standalone_output_tab === 'alertas') && $canViewAdminTabs; if ($show_alertas): ?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade" id="rt-tab-alertas" role="tabpanel"><?php endif; ?>
<div class="d-flex flex-column gap-3">

   <?php echo $nextool_hero_standalone ?? ''; ?>

   <div class="card shadow-sm nextool-tab-card">
      <div class="card-header d-flex justify-content-between align-items-center">
         <h4 class="card-title mb-0">
            <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-purple s-1">
               <i class="fs-2x ti ti-bell"></i>
            </div>
            <span class="ms-5"><?php echo __('Alertas', 'nextool'); ?></span>
         </h4>
         <?php if (!empty($alertHistory)): ?>
         <button type="button" class="btn btn-sm btn-outline-secondary" id="nextool-alerts-mark-all-read"
                 data-endpoint="<?php echo Html::entities_deep($alertsEndpoint); ?>">
            <i class="ti ti-checks me-1"></i><?php echo __('Marcar todos como lidos', 'nextool'); ?>
         </button>
         <?php endif; ?>
      </div>
      <div class="card-body">
         <?php if (empty($alertHistory)): ?>
            <div class="alert alert-info mb-0">
               <i class="ti ti-info-circle me-2"></i><?php echo __('Nenhum alerta recebido. Clique em Sincronizar para verificar.', 'nextool'); ?>
            </div>
         <?php else: ?>
            <div class="list-group" id="nextool-alerts-list">
               <?php foreach ($alertHistory as $alert):
                  $isUnread = empty($alert['is_read']);
                  $typeIcon = PluginNextoolAlertManager::getTypeIcon($alert['alert_type']);
                  $typeBadge = PluginNextoolAlertManager::getTypeBadgeClass($alert['alert_type']);
               ?>
               <div class="list-group-item <?php echo $isUnread ? 'list-group-item-warning' : ''; ?>" data-alert-id="<?php echo (int)$alert['id']; ?>">
                  <div class="d-flex justify-content-between align-items-start">
                     <div class="d-flex gap-2 align-items-start flex-grow-1">
                        <i class="<?php echo $typeIcon; ?> fs-3 mt-1"></i>
                        <div>
                           <div class="d-flex align-items-center gap-2 mb-1">
                              <strong><?php echo Html::entities_deep($alert['title']); ?></strong>
                              <span class="badge <?php echo $typeBadge; ?>" style="font-size: 0.65rem;">
                                 <?php echo Html::entities_deep(ucfirst($alert['alert_type'])); ?>
                              </span>
                              <?php if ($isUnread): ?>
                              <span class="badge bg-warning text-dark" style="font-size: 0.6rem;"><?php echo __('Novo', 'nextool'); ?></span>
                              <?php endif; ?>
                           </div>
                           <div class="small text-muted mb-1">
                              <?php echo Html::entities_deep($alert['date_received']); ?>
                              <?php if (!$isUnread && $alert['date_read']): ?>
                                 &middot; <?php echo __('Lido em', 'nextool'); ?> <?php echo Html::entities_deep($alert['date_read']); ?>
                              <?php endif; ?>
                           </div>
                           <div class="small nextool-alert-body"><?php echo PluginNextoolAlertManager::sanitizeBody($alert['body']); ?></div>
                        </div>
                     </div>
                     <?php if ($isUnread): ?>
                     <button type="button" class="btn btn-sm btn-outline-success nextool-alert-mark-read ms-2"
                             data-alert-id="<?php echo (int)$alert['id']; ?>"
                             data-endpoint="<?php echo Html::entities_deep($alertsEndpoint); ?>">
                        <i class="ti ti-check"></i>
                     </button>
                     <?php endif; ?>
                  </div>
               </div>
               <?php endforeach; ?>
            </div>
         <?php endif; ?>
      </div>
   </div>
</div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>

<script>
(function() {
   document.addEventListener('click', function(e) {
      var btn = e.target.closest('.nextool-alert-mark-read');
      if (btn) {
         var id = btn.dataset.alertId;
         var endpoint = btn.dataset.endpoint;
         var xhr = new XMLHttpRequest();
         xhr.open('POST', endpoint);
         xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
         xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
         var csrfToken = typeof nextoolGetAjaxCsrfToken === 'function' ? nextoolGetAjaxCsrfToken() : '';
         if (csrfToken) { xhr.setRequestHeader('X-Glpi-Csrf-Token', csrfToken); }
         xhr.onload = function() {
            var row = btn.closest('.list-group-item');
            if (row) { row.classList.remove('list-group-item-warning'); }
            btn.remove();
            var newBadge = row && row.querySelector('.badge.bg-warning');
            if (newBadge) { newBadge.remove(); }
         };
         xhr.send('action=mark_read&alert_id=' + id);
         return;
      }

      var markAll = e.target.closest('#nextool-alerts-mark-all-read');
      if (markAll) {
         var endpoint = markAll.dataset.endpoint;
         var xhr = new XMLHttpRequest();
         xhr.open('POST', endpoint);
         xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
         xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
         var csrfToken = typeof nextoolGetAjaxCsrfToken === 'function' ? nextoolGetAjaxCsrfToken() : '';
         if (csrfToken) { xhr.setRequestHeader('X-Glpi-Csrf-Token', csrfToken); }
         xhr.onload = function() {
            var items = document.querySelectorAll('#nextool-alerts-list .list-group-item-warning');
            items.forEach(function(el) { el.classList.remove('list-group-item-warning'); });
            var btns = document.querySelectorAll('.nextool-alert-mark-read');
            btns.forEach(function(b) { b.remove(); });
            var badges = document.querySelectorAll('#nextool-alerts-list .badge.bg-warning');
            badges.forEach(function(b) { b.remove(); });
            markAll.remove();
         };
         xhr.send('action=mark_all_read');
      }
   });
})();
</script>
<?php endif; ?>
