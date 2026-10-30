<?php
declare(strict_types=1);
/**
 * Aba Novidades do Nextool (até 2026-10-13 "Alertas"): avisos recebidos do servidor, alertas locais
 * de pré-requisito e as novidades publicadas pelo portal (tipo `novidades`, que não vão para o sino).
 */

require_once NEXTOOL_PHP_DIR . '/inc/alertmanager.class.php';

$alertHistory = PluginNextoolAlertManager::getAlertHistory();
$alertsEndpoint = PluginNextoolCompat::webDir() . '/ajax/alerts.php';
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
            <span class="ms-5"><?php echo __('Novidades', 'nextool'); ?></span>
         </h4>
         <?php if (!empty($alertHistory)): ?>
         <button type="button" class="btn btn-sm btn-outline-secondary" id="nextool-alerts-mark-all-read"
                 data-endpoint="<?php echo PluginNextoolCompat::escape($alertsEndpoint); ?>">
            <i class="ti ti-checks me-1"></i><?php echo __('Marcar todos como lidos', 'nextool'); ?>
         </button>
         <?php endif; ?>
      </div>
      <div class="card-body">
         <?php if (empty($alertHistory)): ?>
            <div class="alert alert-info mb-0">
               <i class="ti ti-info-circle me-2"></i><?php echo __('Nenhuma novidade ou aviso recebido. Clique em Sincronizar para verificar.', 'nextool'); ?>
            </div>
         <?php else: ?>
            <div class="list-group" id="nextool-alerts-list">
               <?php foreach ($alertHistory as $alert):
                  // Leitura POR USUÁRIO (2026-08-06): "Novo" e o botão de marcar lido
                  // seguem o estado DESTE usuário, não mais o global.
                  $isUnread = empty($alert['is_read_user']);
                  $isExpired = !empty($alert['is_expired']);
                  $typeIcon = PluginNextoolAlertManager::getTypeIcon($alert['alert_type']);
                  $typeBadge = PluginNextoolAlertManager::getTypeBadgeClass($alert['alert_type']);
               ?>
               <div class="list-group-item <?php echo $isUnread ? 'list-group-item-warning' : ''; ?>" data-alert-id="<?php echo (int)$alert['id']; ?>">
                  <div class="d-flex justify-content-between align-items-start">
                     <div class="d-flex gap-2 align-items-start flex-grow-1">
                        <i class="<?php echo $typeIcon; ?> fs-3 mt-1"></i>
                        <div>
                           <div class="d-flex align-items-center gap-2 mb-1">
                              <strong><?php echo PluginNextoolCompat::escape($alert['title']); ?></strong>
                              <span class="badge <?php echo $typeBadge; ?>" style="font-size: 0.65rem;">
                                 <?php echo PluginNextoolCompat::escape(ucfirst($alert['alert_type'])); ?>
                              </span>
                              <?php if ($isExpired): ?>
                              <span class="badge bg-secondary text-white" style="font-size: 0.6rem;"><?php echo __('Expirado', 'nextool'); ?></span>
                              <?php elseif ($isUnread): ?>
                              <span class="badge bg-warning text-white" style="font-size: 0.6rem;"><?php echo __('Novo', 'nextool'); ?></span>
                              <?php endif; ?>
                           </div>
                           <div class="small text-muted mb-1">
                              <?php echo PluginNextoolCompat::escape($alert['date_received']); ?>
                              <?php if (!$isUnread && !empty($alert['date_read_user'])): ?>
                                 &middot; <?php echo __('Lido em', 'nextool'); ?> <?php echo PluginNextoolCompat::escape($alert['date_read_user']); ?>
                              <?php endif; ?>
                           </div>
                           <div class="small nextool-alert-body"><?php echo PluginNextoolAlertManager::sanitizeBody($alert['body']); ?></div>
                        </div>
                     </div>
                     <?php if ($isUnread): ?>
                     <button type="button" class="btn btn-sm btn-outline-success nextool-alert-mark-read ms-2"
                             data-alert-id="<?php echo (int)$alert['id']; ?>"
                             data-endpoint="<?php echo PluginNextoolCompat::escape($alertsEndpoint); ?>">
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

   <?php
   // #292: novidades da NexTool na página inicial do GLPI (opt-in, desligado por padrão).
   require_once NEXTOOL_PHP_DIR . '/inc/newsfeed.class.php';
   $nextoolNewsFeed = PluginNextoolNewsFeed::getState();
   ?>
   <div class="card shadow-sm nextool-tab-card">
      <div class="card-header">
         <h4 class="card-title mb-0">
            <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-purple s-1">
               <i class="fs-2x ti ti-rss"></i>
            </div>
            <span class="ms-5"><?php echo __('Novidades na página inicial do GLPI', 'nextool'); ?></span>
         </h4>
      </div>
      <div class="card-body">
         <form method="post" action="<?php echo PluginNextoolCompat::webDir() . '/front/config.save.php'; ?>">
            <?php if (!PluginNextoolCompat::csrfTokenless()) { echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); } ?>
            <?php echo Html::hidden('forcetab', ['value' => $nextool_is_standalone ? 'PluginNextoolMainConfig$4' : 'PluginNextoolSetup$1']); ?>
            <?php echo Html::hidden('action', ['value' => 'save_news_feed']); ?>
            <label class="form-check form-switch mb-2">
               <input class="form-check-input" type="checkbox" name="news_feed_enabled" value="1"
                      <?php echo $nextoolNewsFeed['enabled'] ? 'checked' : ''; ?> <?php echo $canManageAdminTabs ? '' : 'disabled'; ?>>
               <span class="form-check-label fw-semibold"><?php echo __('Mostrar as novidades da NexTool na página inicial do GLPI', 'nextool'); ?></span>
            </label>
            <p class="text-muted mb-2">
               <?php echo __('Cadastra o blog da NexTool (guias, novidades do GLPI e alertas) em Ferramentas > Feeds RSS, no idioma padrão do GLPI. Ele aparece na aba "Feeds RSS" da página inicial para os perfis da interface central; o self-service não vê. Desligar remove o feed.', 'nextool'); ?>
            </p>
            <?php if ($nextoolNewsFeed['enabled'] && $nextoolNewsFeed['have_error']): ?>
               <div class="alert alert-warning mb-2">
                  <span><i class="ti ti-alert-triangle me-1"></i><?php echo __('Este servidor não conseguiu baixar o feed. Confira o acesso à internet e o proxy do GLPI (Configurar > Geral > Proxy); quando o acesso for liberado, as novidades passam a aparecer sozinhas.', 'nextool'); ?></span>
               </div>
            <?php endif; ?>
            <?php if ($nextoolNewsFeed['enabled']): ?>
               <p class="small text-muted mb-2">
                  <i class="ti ti-link me-1"></i>
                  <a href="<?php echo PluginNextoolCompat::escape(RSSFeed::getFormURLWithID($nextoolNewsFeed['id'])); ?>"><?php echo __('Ver o feed em Ferramentas > Feeds RSS', 'nextool'); ?></a>
                  <span class="ms-1">(<?php echo PluginNextoolCompat::escape($nextoolNewsFeed['url']); ?>)</span>
               </p>
            <?php endif; ?>
            <?php if ($canManageAdminTabs): ?>
               <button type="submit" class="btn btn-sm btn-primary">
                  <i class="ti ti-device-floppy me-1"></i><?php echo __('Salvar', 'nextool'); ?>
               </button>
            <?php endif; ?>
         </form>
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
