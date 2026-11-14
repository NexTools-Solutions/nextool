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
   // Intervalo curto (1m, 15m, 1h, 1h30m, 1d): a grade tem muitas colunas e o texto por extenso quebrava a linha.
   $nextoolJobEvery = static function (int $s): string {
      $min = max(1, intdiv($s, 60));
      if ($min % 1440 === 0) {
         return intdiv($min, 1440) . 'd';
      }
      $h = intdiv($min, 60);
      $m = $min % 60;
      return $h === 0 ? $m . 'm' : ($m === 0 ? $h . 'h' : $h . 'h' . $m . 'm');
   };
   // Data da última execução com ano de 2 dígitos, na ordem do formato de data do usuário (Html::convDate).
   $nextoolJobWhen = static function (string $quando): string {
      $ts = strtotime($quando);
      if ($ts === false) {
         return $quando;
      }
      $mascara = match ((int) ($_SESSION['glpidate_format'] ?? 0)) {
         1       => 'd-m-y',
         2       => 'm-d-y',
         default => 'y-m-d',
      };
      return date($mascara . ' H:i', $ts);
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
         <?php
         require_once NEXTOOL_PHP_DIR . '/inc/jobwatchdog.class.php';
         $nextoolCronLimit = PluginNextoolJobWatchdog::cronLimitAdvice();
         if ($nextoolCronLimit !== null):
            $nextoolCronLimitCfg = Session::haveRight('config', UPDATE);
         ?>
            <?php
            // Curto de propósito: o problema, o efeito e a ação. O caminho e o nome do campo ficam na dica do botão
            // (e no texto, só para quem não pode abrir a configuração).
            $nextoolCronLimitField = sprintf(
               __('Configurar > Geral > Sistema, campo "%s"', 'nextool'),
               __('Maximum number of automatic actions (run by CLI)')
            );
            ?>
            <div class="alert alert-warning d-flex align-items-center gap-3 flex-wrap py-2" data-nextool-cron-limit="<?php echo (int) $nextoolCronLimit['limit']; ?>">
               <i class="ti ti-alert-triangle fs-2"></i>
               <div class="flex-grow-1" style="flex: 1 1 18rem;">
                  <strong><?php echo PluginNextoolCompat::escape(sprintf(
                     __('O GLPI roda só %d ações automáticas por vez e as rotinas do NexTool atrasam.', 'nextool'),
                     (int) $nextoolCronLimit['limit']
                  )); ?></strong>
                  <?php echo PluginNextoolCompat::escape(sprintf(
                     __('Sugerimos %d.', 'nextool'),
                     (int) $nextoolCronLimit['suggested']
                  )); ?>
                  <?php if (!$nextoolCronLimitCfg): ?>
                     <div class="text-muted" style="font-size: .875em;"><?php echo PluginNextoolCompat::escape($nextoolCronLimitField); ?></div>
                  <?php endif; ?>
               </div>
               <?php if ($nextoolCronLimitCfg): ?>
                  <a class="btn btn-sm btn-warning text-white" target="_blank" rel="noopener" title="<?php echo PluginNextoolCompat::escape($nextoolCronLimitField); ?>"
                     href="<?php echo PluginNextoolCompat::escape(Config::getFormURL() . '?forcetab=Config$5'); ?>">
                     <i class="ti ti-adjustments me-1"></i><?php echo __('Ajustar o limite', 'nextool'); ?>
                  </a>
               <?php endif; ?>
            </div>
         <?php endif; ?>
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
                        <td class="text-nowrap"><?php echo $nextoolJobEvery((int) $jr['every']); ?></td>
                        <td class="text-nowrap"><?php if ($st !== null && !empty($st['last_run'])): ?><span title="<?php echo PluginNextoolCompat::escape((string) Html::convDateTime((string) $st['last_run'])); ?>"><?php echo PluginNextoolCompat::escape($nextoolJobWhen((string) $st['last_run'])); ?></span><?php else: ?>-<?php endif; ?></td>
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
                              <button type="submit" class="btn btn-sm btn-icon btn-outline-secondary" title="<?php echo PluginNextoolCompat::escape(__('Executar agora', 'nextool')); ?>" aria-label="<?php echo PluginNextoolCompat::escape(__('Executar agora', 'nextool')); ?>"><i class="ti ti-player-play"></i></button>
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
      <div class="card-header mb-3 pt-2 border-top rounded-0">
         <h4 class="card-title ms-5 mb-0">
            <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-red s-1">
               <i class="fs-2x ti ti-key-off"></i>
            </div>
            <span><?php echo __('Segredo de comunicação do ambiente', 'nextool'); ?></span>
         </h4>
      </div>
      <div class="card-body pt-0">
         <div class="d-flex flex-wrap align-items-start gap-3">
            <div style="flex: 1 1 24rem;">
               <p class="mb-2">
                  <?php echo __('Apaga o segredo que este GLPI usa para falar com o servidor de licenças da NexTool. Não desvincula a sua conta NexTool nem altera a licença e os módulos instalados.', 'nextool'); ?>
               </p>
               <ul class="text-muted small mb-0 ps-3">
                  <li><?php echo __('Use só quando o suporte NexTool pedir ou ao desativar este GLPI.', 'nextool'); ?></li>
                  <li><?php echo __('Sem licença vinculada, o segredo novo chega na próxima sincronização. Com licença, o suporte NexTool precisa liberar o reprovisionamento.', 'nextool'); ?></li>
               </ul>
            </div>
            <?php // Abre a confirmação só por atributos do Bootstrap: a aba pode chegar por AJAX, e script embutido
                  // em innerHTML não executa. O envio acontece no botão do próprio popup. ?>
            <button type="button" class="btn btn-outline-danger text-nowrap flex-shrink-0"
                    data-bs-toggle="modal" data-bs-target="#nextool-unlink-env-modal">
               <i class="ti ti-key-off me-1"></i><?php echo __('Apagar segredo de comunicação', 'nextool'); ?>
            </button>
         </div>
      </div>
   </div>

   <div class="modal fade" id="nextool-unlink-env-modal" tabindex="-1" aria-labelledby="nextool-unlink-env-title" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
         <?php
            // Palavra que a pessoa digita para liberar o botão (traduzível; o config.save.php confere a mesma).
            $nextoolUnlinkWord = mb_strtolower(trim(__('apagar', 'nextool')));
            // pattern do navegador, sem diferenciar maiúsculas: barra o envio mesmo se o oninput não rodar.
            $nextoolUnlinkPattern = '\\s*' . implode('', array_map(
               static fn (string $c): string => preg_match('/\\p{L}/u', $c) ? '[' . mb_strtolower($c) . mb_strtoupper($c) . ']' : (ctype_space($c) ? '\\s+' : sprintf('\\u{%x}', mb_ord($c))),
               mb_str_split($nextoolUnlinkWord)
            )) . '\\s*';
         ?>
         <form method="post" action="<?php echo PluginNextoolCompat::webDir() . '/front/config.save.php'; ?>" class="modal-content">
            <?php if (!PluginNextoolCompat::csrfTokenless()) { echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); } ?>
            <?php echo Html::hidden('forcetab', ['value' => $nextool_is_standalone ? 'PluginNextoolMainConfig$93' : 'PluginNextoolSetup$1']); ?>
            <?php echo Html::hidden('action', ['value' => 'unlink_environment']); ?>
            <div class="modal-status bg-danger"></div>
            <div class="modal-header">
               <h5 class="modal-title" id="nextool-unlink-env-title">
                  <i class="ti ti-alert-triangle text-danger me-2"></i><?php echo __('Apagar o segredo de comunicação?', 'nextool'); ?>
               </h5>
               <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo PluginNextoolCompat::escape(__('Fechar', 'nextool')); ?>"></button>
            </div>
            <div class="modal-body">
               <p class="mb-2 fw-semibold"><?php echo __('O que acontece logo depois:', 'nextool'); ?></p>
               <ul class="mb-3 ps-3">
                  <li><?php echo __('Este GLPI deixa de se comunicar com o servidor de licenças da NexTool: a sincronização, a consulta de licença e o download e a atualização de módulos param.', 'nextool'); ?></li>
                  <li><?php echo __('Sem licença vinculada, um segredo novo é gerado sozinho na próxima sincronização.', 'nextool'); ?></li>
                  <li><?php echo __('Com licença, a comunicação só volta depois que o suporte NexTool liberar o reprovisionamento deste ambiente no servidor.', 'nextool'); ?></li>
               </ul>
               <p class="mb-2 fw-semibold"><?php echo __('O que não muda:', 'nextool'); ?></p>
               <ul class="mb-0 ps-3">
                  <li><?php echo __('O vínculo com a sua conta NexTool, a licença, os módulos instalados e os dados deles.', 'nextool'); ?></li>
               </ul>
               <div class="mt-3 pt-3 border-top">
                  <label class="form-label" for="nextool-unlink-env-confirm">
                     <?php echo sprintf(__('Para confirmar, digite %s:', 'nextool'), '<strong>' . PluginNextoolCompat::escape($nextoolUnlinkWord) . '</strong>'); ?>
                  </label>
                  <?php // oninput (atributo, não <script>): funciona mesmo com a aba carregada por AJAX. ?>
                  <input type="text" class="form-control" id="nextool-unlink-env-confirm" name="confirm_word"
                         autocomplete="off" spellcheck="false" required
                         pattern="<?php echo PluginNextoolCompat::escape($nextoolUnlinkPattern); ?>"
                         data-word="<?php echo PluginNextoolCompat::escape($nextoolUnlinkWord); ?>"
                         oninput="var b=this.form.querySelector('button[type=submit]');if(b){b.disabled=this.value.trim().toLowerCase()!==this.getAttribute('data-word');}">
               </div>
            </div>
            <div class="modal-footer">
               <button type="button" class="btn btn-link link-secondary me-auto" data-bs-dismiss="modal"><?php echo __('Cancelar', 'nextool'); ?></button>
               <button type="submit" class="btn btn-danger text-white" disabled>
                  <i class="ti ti-key-off me-1"></i><?php echo __('Sim, apagar o segredo', 'nextool'); ?>
               </button>
            </div>
         </form>
      </div>
   </div>
   <?php endif; ?>

   <?php
      // #160: escolha do comportamento na DESINSTALAÇÃO da base. O modal do core não é
      // interceptável, então a intenção é persistida aqui e lida pelo hook de uninstall.
      $nextoolUninstallMode = (Config::getConfigurationValues('plugin:nextool')['uninstall_mode'] ?? 'keep');
      $nextoolUninstallPurge = ($nextoolUninstallMode === 'purge_all');
      $nextoolUninstallLock = $canManageAdminTabs ? '' : ' disabled';
   ?>
   <div class="card shadow-sm nextool-tab-card mt-3">
      <div class="card-header mb-3 pt-2 border-top rounded-0">
         <h4 class="card-title ms-5 mb-0">
            <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-red s-1">
               <i class="fs-2x ti ti-trash"></i>
            </div>
            <span><?php echo __('Desinstalação do plugin', 'nextool'); ?></span>
         </h4>
      </div>
      <div class="card-body pt-0">
         <p class="text-muted mb-3">
            <?php echo __('O que acontece com os dados dos módulos quando o plugin base é desinstalado em Configurar > Plug-ins.', 'nextool'); ?>
         </p>
         <form method="post" action="<?php echo PluginNextoolCompat::webDir() . '/front/config.save.php'; ?>">
            <?php if (!PluginNextoolCompat::csrfTokenless()) { echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); } ?>
            <?php echo Html::hidden('forcetab', ['value' => $nextool_is_standalone ? 'PluginNextoolMainConfig$93' : 'PluginNextoolSetup$1']); ?>
            <?php echo Html::hidden('action', ['value' => 'save_uninstall_mode']); ?>
            <div class="form-selectgroup form-selectgroup-boxes d-flex gap-2 mb-3">
               <label class="form-selectgroup-item" style="flex: 1 1 0; min-width: 16rem;">
                  <input type="radio" name="uninstall_mode" id="nextool-uninstall-keep" value="keep" class="form-selectgroup-input"
                         <?php echo $nextoolUninstallPurge ? '' : 'checked'; ?><?php echo $nextoolUninstallLock; ?>>
                  <div class="form-selectgroup-label d-flex align-items-start p-3 h-100">
                     <span class="me-3 mt-1"><span class="form-selectgroup-check"></span></span>
                     <span class="text-start">
                        <span class="d-block fw-bold mb-1"><i class="ti ti-database me-1"></i><?php echo __('Manter dados (padrão)', 'nextool'); ?>
                           <span class="form-help ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true"
                                 data-bs-title="<?php echo PluginNextoolCompat::escape(__('Depois de desinstalar, apagar esses dados exige reinstalar o plugin: o botão "Apagar dados" de cada módulo sai junto com a interface.', 'nextool')); ?>">?</span>
                        </span>
                        <span class="d-block text-muted"><?php echo __('Módulos, tabelas, arquivos e chave de cifra são preservados. Reinstalar o plugin traz tudo de volta como estava.', 'nextool'); ?></span>
                     </span>
                  </div>
               </label>
               <label class="form-selectgroup-item" style="flex: 1 1 0; min-width: 16rem;">
                  <input type="radio" name="uninstall_mode" id="nextool-uninstall-purge" value="purge_all" class="form-selectgroup-input"
                         <?php echo $nextoolUninstallPurge ? 'checked' : ''; ?><?php echo $nextoolUninstallLock; ?>>
                  <div class="form-selectgroup-label d-flex align-items-start p-3 h-100">
                     <span class="me-3 mt-1"><span class="form-selectgroup-check"></span></span>
                     <span class="text-start">
                        <span class="d-block fw-bold text-danger mb-1"><i class="ti ti-trash me-1"></i><?php echo __('Apagar tudo', 'nextool'); ?></span>
                        <span class="d-block text-muted"><?php echo __('Remove de vez os dados de todos os módulos (tabelas e arquivos), o registro de módulos e a chave de cifra.', 'nextool'); ?></span>
                     </span>
                  </div>
               </label>
            </div>
            <?php if ($canManageAdminTabs): ?>
            <div class="text-end">
               <button type="submit" class="btn btn-primary">
                  <i class="ti ti-device-floppy me-1"></i><?php echo __('Salvar', 'nextool'); ?>
               </button>
            </div>
            <?php endif; ?>
         </form>
      </div>
   </div>

<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
