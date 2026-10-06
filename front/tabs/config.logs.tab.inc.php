<?php
declare(strict_types=1);
/**
 * Aba Logs do Nextool.
 * Contexto/variáveis esperadas: $nextool_is_standalone, $nextool_standalone_output_tab,
 * $canViewAdminTabs, $firstTabKey, $nextool_hero_standalone.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
?>

<!-- TAB 3: Logs -->
<?php $show_logs = (!$nextool_is_standalone || $nextool_standalone_output_tab === 'logs') && $canViewAdminTabs; if ($show_logs): ?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade<?php echo $firstTabKey === 'logs' ? ' show active' : ''; ?>" id="rt-tab-logs" role="tabpanel" aria-labelledby="rt-tab-logs-link"><?php endif; ?>
   <?php echo $nextool_hero_standalone; ?>

   <div class="card shadow-sm nextool-tab-card">
      <div class="card-header mb-3 pt-2 border-top rounded-0">
         <h4 class="card-title ms-5 mb-0">
            <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-orange s-1">
               <i class="fs-2x ti ti-report-analytics"></i>
            </div>
            <span><?php echo __('Logs de Licenciamento', 'nextool'); ?></span>
         </h4>
      </div>
      <div class="card-body">
         <p class="text-muted">
            <?php echo __('Histórico das últimas sincronizações de licença realizadas pelo NexTool. Use este painel para acompanhar resultados e identificar eventuais falhas de conexão ou configuração.', 'nextool'); ?>
         </p>

         <hr class="my-4">

         <?php
         $GLOBALS['nextool_validation_attempts_forcetab_url'] = Plugin::getWebDir('nextool')
            . '/front/nextoolconfig.form.php?id=1&forcetab=PluginNextoolMainConfig$5';

         $_GET['embedded'] = '1';
         if (empty($_GET['sort'])) {
            unset($_SESSION['glpisearch']['PluginNextoolValidationAttempt']);
            $_GET['sort']  = 2;
            $_GET['order'] = 'DESC';
         }

         try {
            Search::show('PluginNextoolValidationAttempt');
         } finally {
            unset($_GET['embedded'], $_GET['sort'], $_GET['order'],
                  $GLOBALS['nextool_validation_attempts_forcetab_url']);
         }
         ?>

         <!-- CSS: o GLPI Search engine impõe overflow:auto e height fixa no .search-container,
              criando scroll interno quando há muitos registros. Override para expandir naturalmente. -->
         <style>
         .nextool-tab-card .search-container {
            overflow: visible !important;
            height: auto !important;
         }
         </style>
      </div>
   </div>

   <?php
   // Rotinas do NexTool (nextool-dev#281): estado das rotinas que os módulos executam pelo dispatcher.
   require_once NEXTOOL_PHP_DIR . '/inc/jobdispatcher.class.php';
   $nextoolJobRows = PluginNextoolJobDispatcher::getStatusRows();
   $nextoolJobsOn  = PluginNextoolJobDispatcher::isEnabled();
   $nextoolJobsCanManage = !empty($canManageAdminTabs);
   $nextoolJobEvery = static function (int $s): string {
      return $s % 3600 === 0 ? sprintf(_n('%d hora', '%d horas', intdiv($s, 3600), 'nextool'), intdiv($s, 3600))
         : sprintf(_n('%d minuto', '%d minutos', max(1, intdiv($s, 60)), 'nextool'), max(1, intdiv($s, 60)));
   };
   $nextoolJobManager = PluginNextoolModuleManager::getInstance();
   ?>
   <div class="card shadow-sm nextool-tab-card mt-4">
      <div class="card-header mb-3 pt-2 border-top rounded-0">
         <h4 class="card-title ms-5 mb-0">
            <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-orange s-1">
               <i class="fs-2x ti ti-clock-play"></i>
            </div>
            <span><?php echo __('Rotinas do NexTool', 'nextool'); ?></span>
         </h4>
      </div>
      <div class="card-body">
         <p class="text-muted">
            <?php echo __('Rotinas frequentes dos módulos, executadas numa única ação automática do GLPI ("nextoolJobs", a cada minuto) para não ocupar a fila do agendador. Uma rotina que falha 5 vezes seguidas fica pausada por um tempo e volta sozinha; "Retomar" tira a pausa na hora.', 'nextool'); ?>
         </p>
         <?php if (!$nextoolJobsOn): ?>
            <div class="alert alert-warning">
               <?php echo __('As rotinas do NexTool estão desligadas (jobs_dispatcher_enabled = 0). As rotinas abaixo não rodam enquanto a chave estiver desligada.', 'nextool'); ?>
            </div>
         <?php endif; ?>
         <?php if ($nextoolJobRows === []): ?>
            <p class="mb-0"><?php echo __('Nenhum módulo ativo usa rotinas do NexTool.', 'nextool'); ?></p>
         <?php else: ?>
            <div class="table-responsive">
               <table class="table table-sm table-vcenter mb-0">
                  <thead>
                     <tr>
                        <th><?php echo __('Módulo', 'nextool'); ?></th>
                        <th><?php echo __('Rotina', 'nextool'); ?></th>
                        <th><?php echo __('Intervalo', 'nextool'); ?></th>
                        <th><?php echo __('Última execução', 'nextool'); ?></th>
                        <th><?php echo __('Duração', 'nextool'); ?></th>
                        <th><?php echo __('Resultado', 'nextool'); ?></th>
                        <th><?php echo __('Mensagem', 'nextool'); ?></th>
                        <?php if ($nextoolJobsCanManage): ?><th class="text-end"><?php echo __('Ações', 'nextool'); ?></th><?php endif; ?>
                     </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($nextoolJobRows as $jr):
                     $st = $jr['state'];
                     $mod = $nextoolJobManager->getModule($jr['module_key']);
                     $modName = $mod !== null ? (string) $mod->getName() : $jr['module_key'];
                     $pausado = $st !== null && !empty($st['paused_until']) && strtotime((string) $st['paused_until']) > time();
                     if ($st === null || $st['last_result'] === null) {
                        [$badge, $rotulo] = ['bg-secondary', __('Ainda não rodou', 'nextool')];
                     } elseif ($pausado) {
                        [$badge, $rotulo] = ['bg-danger', sprintf(__('Pausada até %s', 'nextool'), Html::convDateTime((string) $st['paused_until']))];
                     } elseif ((int) $st['last_result'] < 0) {
                        [$badge, $rotulo] = ['bg-danger', sprintf(__('Falhou (%d seguidas)', 'nextool'), (int) $st['fails'])];
                     } elseif ((int) $st['last_result'] > 0) {
                        [$badge, $rotulo] = ['bg-success', __('Trabalhou', 'nextool')];
                     } else {
                        [$badge, $rotulo] = ['bg-secondary', __('Nada a fazer', 'nextool')];
                     }
                  ?>
                     <tr>
                        <td><?php echo Html::entities_deep($modName); ?></td>
                        <td><code><?php echo Html::entities_deep($jr['key']); ?></code></td>
                        <td><?php echo $nextoolJobEvery((int) $jr['every']); ?></td>
                        <td><?php echo $st !== null && !empty($st['last_run']) ? Html::convDateTime((string) $st['last_run']) : '-'; ?></td>
                        <td><?php echo $st !== null && $st['last_result'] !== null ? sprintf('%.1f s', ((int) $st['last_duration_ms']) / 1000) : '-'; ?></td>
                        <td><span class="badge text-white <?php echo $badge; ?>"><?php echo Html::entities_deep($rotulo); ?></span></td>
                        <td class="text-muted small"><?php echo $st !== null ? Html::entities_deep((string) $st['last_message']) : ''; ?></td>
                        <?php if ($nextoolJobsCanManage): ?>
                        <td class="text-end text-nowrap">
                           <form method="post" action="<?php echo Plugin::getWebDir('nextool') . '/front/config.save.php'; ?>" class="d-inline">
                              <input type="hidden" name="action" value="job_run_now">
                              <input type="hidden" name="module_key" value="<?php echo Html::entities_deep($jr['module_key']); ?>">
                              <input type="hidden" name="job_key" value="<?php echo Html::entities_deep($jr['key']); ?>">
                              <input type="hidden" name="forcetab" value="PluginNextoolMainConfig$5">
                              <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-player-play me-1"></i><?php echo __('Executar agora', 'nextool'); ?></button>
                           <?php Html::closeForm(); ?>
                           <?php if ($pausado || ($st !== null && (int) $st['fails'] > 0)): ?>
                           <form method="post" action="<?php echo Plugin::getWebDir('nextool') . '/front/config.save.php'; ?>" class="d-inline">
                              <input type="hidden" name="action" value="job_resume">
                              <input type="hidden" name="module_key" value="<?php echo Html::entities_deep($jr['module_key']); ?>">
                              <input type="hidden" name="job_key" value="<?php echo Html::entities_deep($jr['key']); ?>">
                              <input type="hidden" name="forcetab" value="PluginNextoolMainConfig$5">
                              <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-refresh me-1"></i><?php echo __('Retomar', 'nextool'); ?></button>
                           <?php Html::closeForm(); ?>
                           <?php endif; ?>
                        </td>
                        <?php endif; ?>
                     </tr>
                  <?php endforeach; ?>
                  </tbody>
               </table>
            </div>
         <?php endif; ?>
      </div>
   </div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
