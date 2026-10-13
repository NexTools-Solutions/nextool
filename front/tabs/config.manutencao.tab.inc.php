<?php
declare(strict_types=1);
/**
 * Aba Manutenção do Nextool: rotinas dos módulos, provisionamento do ambiente e desinstalação
 * do plugin. Ajustes técnicos e de uso raro, que saíram das abas Logs e Licenciamento.
 * Contexto/variáveis esperadas: $nextool_is_standalone, $nextool_standalone_output_tab,
 * $canViewAdminTabs, $canManageAdminTabs, $firstTabKey, $nextool_hero_standalone.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
?>

<!-- Aba Manutenção -->
<?php $show_manutencao = (!$nextool_is_standalone || $nextool_standalone_output_tab === 'manutencao') && $canViewAdminTabs; if ($show_manutencao): ?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade<?php echo $firstTabKey === 'manutencao' ? ' show active' : ''; ?>" id="rt-tab-manutencao" role="tabpanel" aria-labelledby="rt-tab-manutencao-link"><?php endif; ?>
   <?php echo $nextool_hero_standalone; ?>

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
   <div class="card shadow-sm nextool-tab-card">
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
                        <td><?php echo PluginNextoolCompat::escape($modName); ?></td>
                        <td><code><?php echo PluginNextoolCompat::escape($jr['key']); ?></code></td>
                        <td><?php echo $nextoolJobEvery((int) $jr['every']); ?></td>
                        <td><?php echo $st !== null && !empty($st['last_run']) ? Html::convDateTime((string) $st['last_run']) : '-'; ?></td>
                        <td><?php echo $st !== null && $st['last_result'] !== null ? sprintf('%.1f s', ((int) $st['last_duration_ms']) / 1000) : '-'; ?></td>
                        <td><span class="badge text-white <?php echo $badge; ?>"><?php echo PluginNextoolCompat::escape($rotulo); ?></span></td>
                        <td class="text-muted small"><?php echo $st !== null ? PluginNextoolCompat::escape((string) $st['last_message']) : ''; ?></td>
                        <?php if ($nextoolJobsCanManage): ?>
                        <td class="text-end text-nowrap">
                           <form method="post" action="<?php echo PluginNextoolCompat::webDir() . '/front/config.save.php'; ?>" class="d-inline">
                              <input type="hidden" name="action" value="job_run_now">
                              <input type="hidden" name="module_key" value="<?php echo PluginNextoolCompat::escape($jr['module_key']); ?>">
                              <input type="hidden" name="job_key" value="<?php echo PluginNextoolCompat::escape($jr['key']); ?>">
                              <input type="hidden" name="forcetab" value="PluginNextoolMainConfig$93">
                              <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="ti ti-player-play me-1"></i><?php echo __('Executar agora', 'nextool'); ?></button>
                           <?php Html::closeForm(); ?>
                           <?php if ($pausado || ($st !== null && (int) $st['fails'] > 0)): ?>
                           <form method="post" action="<?php echo PluginNextoolCompat::webDir() . '/front/config.save.php'; ?>" class="d-inline">
                              <input type="hidden" name="action" value="job_resume">
                              <input type="hidden" name="module_key" value="<?php echo PluginNextoolCompat::escape($jr['module_key']); ?>">
                              <input type="hidden" name="job_key" value="<?php echo PluginNextoolCompat::escape($jr['key']); ?>">
                              <input type="hidden" name="forcetab" value="PluginNextoolMainConfig$93">
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

   <?php if ($canManageAdminTabs): ?>
   <div class="card shadow-sm nextool-tab-card mt-3">
      <div class="card-body">
         <h5 class="fw-semibold mb-2">
            <i class="ti ti-key-off me-1"></i>
            <?php echo __('Segredo de comunicação do ambiente', 'nextool'); ?>
         </h5>
         <p class="text-muted mb-2">
            <?php echo __('Apaga o segredo que este GLPI usa para se comunicar com o servidor de licenças da NexTool. Não é o vínculo com a sua conta NexTool (esse fica no botão Conta, no topo da página) e não altera a licença nem os módulos instalados.', 'nextool'); ?>
         </p>
         <p class="text-muted mb-2">
            <?php echo __('Use só quando o suporte NexTool pedir ou ao desativar este GLPI. Até um segredo novo ser gerado, a sincronização e o download de módulos ficam sem comunicação. Ambiente sem licença vinculada recebe o segredo novo sozinho na próxima sincronização; ambiente licenciado precisa que o suporte NexTool libere o reprovisionamento no servidor.', 'nextool'); ?>
         </p>
         <form method="post" action="<?php echo PluginNextoolCompat::webDir() . '/front/config.save.php'; ?>"
               onsubmit="return confirm('<?php echo PluginNextoolCompat::escape(__('Apagar o segredo de comunicação deste GLPI? A sincronização com o servidor de licenças fica parada até um segredo novo ser gerado. Isso não desvincula a sua conta NexTool.', 'nextool')); ?>');">
            <?php if (!PluginNextoolCompat::csrfTokenless()) { echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); } ?>
            <?php echo Html::hidden('forcetab', ['value' => $nextool_is_standalone ? 'PluginNextoolMainConfig$93' : 'PluginNextoolSetup$1']); ?>
            <?php echo Html::hidden('action', ['value' => 'unlink_environment']); ?>
            <button type="submit" class="btn btn-sm btn-outline-danger">
               <i class="ti ti-key-off me-1"></i><?php echo __('Apagar segredo de comunicação', 'nextool'); ?>
            </button>
         </form>
      </div>
   </div>
   <?php endif; ?>

   <?php
      // #160: escolha do comportamento na DESINSTALAÇÃO da base. O modal do core não é
      // interceptável, então a intenção é persistida aqui e lida pelo hook de uninstall.
      $nextoolUninstallMode = (Config::getConfigurationValues('plugin:nextool')['uninstall_mode'] ?? 'keep');
      $nextoolUninstallPurge = ($nextoolUninstallMode === 'purge_all');
   ?>
   <div class="card shadow-sm nextool-tab-card mt-3">
      <div class="card-body">
         <h5 class="fw-semibold mb-2">
            <i class="ti ti-trash me-1"></i>
            <?php echo __('Desinstalação do plugin', 'nextool'); ?>
         </h5>
         <p class="text-muted mb-2">
            <?php echo __('Define o que acontece com os dados dos módulos quando o plugin base for desinstalado pela tela de plugins do GLPI.', 'nextool'); ?>
         </p>
         <form method="post" action="<?php echo PluginNextoolCompat::webDir() . '/front/config.save.php'; ?>">
            <?php if (!PluginNextoolCompat::csrfTokenless()) { echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); } ?>
            <?php echo Html::hidden('forcetab', ['value' => $nextool_is_standalone ? 'PluginNextoolMainConfig$93' : 'PluginNextoolSetup$1']); ?>
            <?php echo Html::hidden('action', ['value' => 'save_uninstall_mode']); ?>
            <div class="form-check mb-1">
               <input class="form-check-input" type="radio" name="uninstall_mode" id="nextool-uninstall-keep"
                      value="keep" <?php echo $nextoolUninstallPurge ? '' : 'checked'; ?> <?php echo $canManageAdminTabs ? '' : 'disabled'; ?>>
               <label class="form-check-label" for="nextool-uninstall-keep">
                  <strong><?php echo __('Manter dados (padrão)', 'nextool'); ?></strong> --
                  <?php echo __('módulos, tabelas, arquivos e chave de cifra são preservados; reinstalar o plugin traz tudo de volta como estava.', 'nextool'); ?>
               </label>
            </div>
            <div class="form-check mb-2">
               <input class="form-check-input" type="radio" name="uninstall_mode" id="nextool-uninstall-purge"
                      value="purge_all" <?php echo $nextoolUninstallPurge ? 'checked' : ''; ?> <?php echo $canManageAdminTabs ? '' : 'disabled'; ?>>
               <label class="form-check-label" for="nextool-uninstall-purge">
                  <strong class="text-danger"><?php echo __('Apagar tudo', 'nextool'); ?></strong> --
                  <?php echo __('remove permanentemente os dados de TODOS os módulos (tabelas e arquivos), o registro de módulos e a chave de cifra.', 'nextool'); ?>
               </label>
            </div>
            <div class="alert alert-warning small p-2 mb-2">
               <i class="ti ti-alert-triangle me-1"></i>
               <?php echo __('Com "Manter dados", após desinstalar o plugin os dados só poderão ser apagados REINSTALANDO o plugin (o botão "Apagar dados" por módulo deixa de existir junto com a interface).', 'nextool'); ?>
            </div>
            <?php if ($canManageAdminTabs): ?>
            <button type="submit" class="btn btn-sm btn-outline-primary">
               <i class="ti ti-device-floppy me-1"></i><?php echo __('Salvar modo de desinstalação', 'nextool'); ?>
            </button>
            <?php endif; ?>
         </form>
      </div>
   </div>

<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
