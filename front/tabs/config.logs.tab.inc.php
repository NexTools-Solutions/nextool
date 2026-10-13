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
            <span><?php echo __('Logs do NexTool', 'nextool'); ?></span>
         </h4>
      </div>
      <div class="card-body">
         <p class="text-muted">
            <?php echo __('Validações de licença, alterações de configuração e ações em módulos (instalar, ativar, atualizar) num só registro. Use os critérios para filtrar por tipo, módulo, resultado ou período. Registros com mais de 120 dias são removidos automaticamente.', 'nextool'); ?>
         </p>

         <hr class="my-4">

         <?php
         $GLOBALS['nextool_eventlog_forcetab_url'] = PluginNextoolCompat::webDir()
            . '/front/nextoolconfig.form.php?id=1&forcetab=PluginNextoolMainConfig$5';

         $_GET['embedded'] = '1';
         if (empty($_GET['sort'])) {
            unset($_SESSION['glpisearch']['PluginNextoolEventLog']);
            $_GET['sort']  = 2;
            $_GET['order'] = 'DESC';
         }

         try {
            Search::show('PluginNextoolEventLog');
         } finally {
            unset($_GET['embedded'], $_GET['sort'], $_GET['order'],
                  $GLOBALS['nextool_eventlog_forcetab_url']);
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
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
