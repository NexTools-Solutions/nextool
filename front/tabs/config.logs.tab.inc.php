<?php
/**
 * Nextools - Aba Logs
 *
 * Renderiza a aba Logs da configuração do Nextools (tentativas de validação,
 * erros e eventos relevantes).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
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
            <span>Logs de Licenciamento</span>
         </h4>
      </div>
      <div class="card-body">
         <p class="text-muted">
            Histórico das últimas sincronizações de licença realizadas pelo NexTool.
            Use este painel para acompanhar resultados e identificar eventuais falhas de conexão ou configuração.
         </p>

         <hr class="my-4">

         <?php PluginNextoolValidationAttempt::showSimpleList(); ?>

         <hr class="my-5">

         <h5 class="fw-semibold mb-2"><?php echo __('Auditoria de configuração/licença', 'nextool'); ?></h5>
         <p class="text-muted">
            <?php echo __('Mostra quem alterou configurações da licença ou executou sincronizações manuais, incluindo os valores anteriores.', 'nextool'); ?>
         </p>
         <?php PluginNextoolConfigAudit::showSimpleList(); ?>

         <hr class="my-5">

         <h5 class="fw-semibold mb-2"><?php echo __('Auditoria de ações de módulos', 'nextool'); ?></h5>
         <p class="text-muted">
            <?php echo __('Lista as últimas instalações, ativações, desativações e remoções de módulos, com usuário e data da ação.', 'nextool'); ?>
         </p>
         <?php PluginNextoolModuleAudit::showSimpleList(); ?>
      </div>
   </div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
