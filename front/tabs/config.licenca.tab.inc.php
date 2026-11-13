<?php
declare(strict_types=1);
/**
 * Aba Licenciamento do Nextool: card "Licenças" (tabela das licenças do ambiente) e card "Ambiente" (código,
 * conta vinculada e comunicação com o servidor), no padrão das outras abas (2026-11-12; antes era
 * um card só com duas caixas cinzas e a lista de módulos repetida).
 * Contexto/variáveis esperadas: $nextool_is_standalone, $nextool_standalone_output_tab,
 * $canViewAdminTabs, $nextool_hero_standalone, $licensesSnapshot,
 * $licenseStatusCode, $config, $distributionBaseUrl, $distributionClientIdentifier,
 * $distributionClientSecret, $allowedModules, $licenseWarnings,
 * $canManageAdminTabs, $accountLinked, $accountEmail.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
?>

<!-- TAB 2: Licenciamento -->
<?php $show_licenca = (!$nextool_is_standalone || $nextool_standalone_output_tab === 'licenca') && !empty($canViewLicense); if ($show_licenca): ?>
<?php
   $nxLicEsc = static fn ($v): string => (string) PluginNextoolCompat::escape((string) $v);
   // Nome do módulo pelo catálogo (idioma da sessão); chave crua só se o módulo não estiver no catálogo.
   $nxLicManager = PluginNextoolModuleManager::getInstance();
   $nxLicModuleName = static fn (string $key): string => $nxLicManager->getDisplayName($key, $key);
   // Botão de copiar só com ícone; o texto vai na dica. Atributo onclick: funciona com a aba carregada por AJAX.
   $nxLicCopy = static function (string $valor) use ($nxLicEsc): string {
      $rotulo = __('Copiar', 'nextool');
      return '<button type="button" class="btn btn-sm btn-icon btn-ghost-secondary ms-1" title="' . $nxLicEsc($rotulo) . '" aria-label="' . $nxLicEsc($rotulo) . '"'
         . ' data-copy="' . $nxLicEsc($valor) . '"'
         . ' onclick="var b=this;navigator.clipboard.writeText(b.getAttribute(\'data-copy\'));var i=b.querySelector(\'i\');i.className=\'ti ti-check text-success\';setTimeout(function(){i.className=\'ti ti-copy\';},1500);">'
         . '<i class="ti ti-copy"></i></button>';
   };
   $nxLicRow = static function (string $rotulo, string $valorHtml, string $ajuda = '') use ($nxLicEsc): string {
      $help = $ajuda === '' ? '' : ' <span class="form-help" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" data-bs-title="' . $nxLicEsc($ajuda) . '">?</span>';
      return '<div class="row align-items-center py-2 border-bottom">'
         . '<div class="col-sm-4 col-lg-3 text-sm-end text-muted fw-semibold">' . $nxLicEsc($rotulo) . $help . '</div>'
         . '<div class="col-sm-8 col-lg-9">' . $valorHtml . '</div>'
         . '</div>';
   };
?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade" id="rt-tab-licenca" role="tabpanel" aria-labelledby="rt-tab-licenca-link"><?php endif; ?>
   <?php // O form segue: o "Sincronizar" do hero usa o form em que está (nextoolValidateLicense -> btn.form). ?>
   <form method="post" action="<?php echo PluginNextoolCompat::webDir() . '/front/config.save.php'; ?>" id="configForm">
      <?php if (!PluginNextoolCompat::csrfTokenless()) { echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); } ?>
      <?php echo Html::hidden('forcetab', ['value' => $nextool_is_standalone ? 'PluginNextoolMainConfig$3' : 'PluginNextoolSetup$1']); ?>
      <div class="d-flex flex-column gap-3">

         <?php echo $nextool_hero_standalone; ?>

         <div class="card shadow-sm nextool-tab-card">
            <div class="card-header mb-3 pt-2 border-top rounded-0">
               <h4 class="card-title ms-5 mb-0">
                  <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-blue s-1">
                     <i class="fs-2x ti ti-key"></i>
                  </div>
                  <span><?php echo __('Licenças', 'nextool'); ?></span>
               </h4>
            </div>
            <div class="card-body pt-0">
               <?php if (!empty($licensesSnapshot) && $licenseStatusCode === 'SUSPENDED'): ?>
                  <div class="alert alert-warning">
                     <i class="ti ti-alert-triangle me-2"></i>
                     <?php echo __('Licença suspensa. Os módulos já instalados continuam funcionando normalmente. Renove seu contrato para restaurar o acesso a suporte técnico, atualizações e novos downloads.', 'nextool'); ?>
                  </div>
               <?php elseif (!empty($licensesSnapshot) && $licenseStatusCode === 'CANCELLED'): ?>
                  <div class="alert alert-danger">
                     <i class="ti ti-ban me-2"></i>
                     <?php echo __('Licença cancelada. Os módulos licenciados ficarão bloqueados até a regularização com o suporte NexTool.', 'nextool'); ?>
                  </div>
               <?php elseif (!empty($licensesSnapshot) && $licenseStatusCode === 'INACTIVE'): ?>
                  <div class="alert alert-info">
                     <i class="ti ti-info-circle me-2"></i>
                     <?php echo __('A licença ainda não está ativa. O ambiente segue apenas com os módulos gratuitos até que uma licença válida seja vinculada.', 'nextool'); ?>
                  </div>
               <?php endif; ?>

               <?php if (empty($licensesSnapshot)): ?>
                  <p class="text-muted mb-0">
                     <?php echo __('Nenhuma licença ativa foi encontrada no momento. Fale com o suporte NexTool para liberar os módulos contratados.', 'nextool'); ?>
                  </p>
               <?php else: ?>
                  <div class="table-responsive">
                     <table class="table table-vcenter mb-0">
                        <thead>
                           <tr>
                              <th><?php echo __('Licença', 'nextool'); ?></th>
                              <th><?php echo __('Tipo', 'nextool'); ?></th>
                              <th><?php echo __('Status', 'nextool'); ?></th>
                              <th><?php echo __('Início', 'nextool'); ?></th>
                              <th><?php echo __('Validade', 'nextool'); ?></th>
                              <th><?php echo __('Módulos', 'nextool'); ?></th>
                           </tr>
                        </thead>
                        <tbody>
                           <?php foreach ($licensesSnapshot as $licenseRow):
                              $rowKey = $licenseRow['license_key'] ?? __('(desconhecida)', 'nextool');
                              $rowPlan = strtoupper($licenseRow['plan'] ?? 'FREE');
                              $rowStatus = strtoupper($licenseRow['license_status'] ?? 'INACTIVE');
                              $rowModules = (!empty($licenseRow['allowed_modules']) && is_array($licenseRow['allowed_modules'])) ? $licenseRow['allowed_modules'] : [];
                              // Tipo de licença: rótulo e cor centralizados (aceita os códigos legados).
                              $planBadge = PluginNextoolConfigViewState::getPlanBadgeClass($rowPlan);
                              $planLabel = PluginNextoolConfigViewState::getPlanLabel($rowPlan);
                              $statusBadge = [
                                 'ACTIVE'    => 'bg-green',
                                 'INACTIVE'  => 'bg-red',
                                 'SUSPENDED' => 'bg-orange',
                                 'CANCELLED' => 'bg-red',
                                 'EXPIRED'   => 'bg-red',
                              ][$rowStatus] ?? 'bg-secondary';
                              $statusLabel = [
                                 'ACTIVE'    => __('Ativa', 'nextool'),
                                 'INACTIVE'  => __('Inativa', 'nextool'),
                                 'SUSPENDED' => __('Suspensa', 'nextool'),
                                 'CANCELLED' => __('Cancelada', 'nextool'),
                                 'EXPIRED'   => __('Expirada', 'nextool'),
                              ][$rowStatus] ?? $rowStatus;
                              // Datas sem hora: a validade é de dia; a hora completa fica na dica.
                              $nxDate = static fn ($v): string => empty($v) ? '' : (string) Html::convDate((string) $v);
                              $nxDateTitle = static fn ($v): string => empty($v) ? '' : (string) Html::convDateTime((string) $v);
                           ?>
                           <tr>
                              <td><code><?php echo $nxLicEsc($rowKey); ?></code></td>
                              <td><span class="badge text-white <?php echo $planBadge; ?>"><?php echo $nxLicEsc($planLabel); ?></span></td>
                              <td><span class="badge text-white <?php echo $statusBadge; ?>"><?php echo $nxLicEsc($statusLabel); ?></span></td>
                              <td class="text-nowrap"><?php echo empty($licenseRow['starts_at']) ? '-' : '<span title="' . $nxLicEsc($nxDateTitle($licenseRow['starts_at'])) . '">' . $nxLicEsc($nxDate($licenseRow['starts_at'])) . '</span>'; ?></td>
                              <td class="text-nowrap"><?php echo empty($licenseRow['expires_at']) ? $nxLicEsc(__('Sem expiração', 'nextool')) : '<span title="' . $nxLicEsc($nxDateTitle($licenseRow['expires_at'])) . '">' . $nxLicEsc($nxDate($licenseRow['expires_at'])) . '</span>'; ?></td>
                              <td>
                                 <?php if (in_array('*', $rowModules, true)): ?>
                                    <span class="badge text-white bg-purple"><?php echo __('Todos os módulos', 'nextool'); ?></span>
                                 <?php elseif (empty($rowModules)): ?>
                                    <span class="text-muted"><?php echo __('Nenhum módulo', 'nextool'); ?></span>
                                 <?php else: ?>
                                    <?php echo $nxLicEsc(implode(', ', array_map(static fn ($k): string => $nxLicModuleName((string) $k), $rowModules))); ?>
                                 <?php endif; ?>
                              </td>
                           </tr>
                           <?php endforeach; ?>
                        </tbody>
                     </table>
                  </div>
               <?php endif; ?>

               <?php if (!empty($licenseWarnings)): ?>
                  <div class="alert alert-warning mt-3 mb-0">
                     <ul class="list-unstyled mb-0">
                        <?php foreach ($licenseWarnings as $warning): ?>
                           <li><i class="ti ti-alert-triangle me-1"></i><?php echo $nxLicEsc($warning); ?></li>
                        <?php endforeach; ?>
                     </ul>
                  </div>
               <?php endif; ?>
            </div>
         </div>

         <div class="card shadow-sm nextool-tab-card">
            <div class="card-header mb-3 pt-2 border-top rounded-0">
               <h4 class="card-title ms-5 mb-0">
                  <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-purple s-1">
                     <i class="fs-2x ti ti-server"></i>
                  </div>
                  <span><?php echo __('Ambiente', 'nextool'); ?></span>
               </h4>
            </div>
            <div class="card-body pt-0">
               <?php
                  // Código do ambiente
                  $nxEnvId = trim((string) ($config['client_identifier'] ?? ''));
                  echo $nxLicRow(
                     __('Código do ambiente', 'nextool'),
                     $nxEnvId !== ''
                        ? '<code>' . $nxLicEsc($nxEnvId) . '</code>' . $nxLicCopy($nxEnvId)
                        : '<span class="text-muted">' . $nxLicEsc(__('Não configurado', 'nextool')) . '</span>',
                     __('Identifica este GLPI na NexTool. Informe este código ao suporte.', 'nextool')
                  );

                  // Conta NexTool
                  if (!empty($accountLinked)) {
                     // Link para o perfil da conta no portal NexTool.
                     $nxPortalConta = 'https://app.nextoolsolutions.com/painel/profile';
                     $nxConta = '<span class="badge text-white bg-success me-2"><i class="ti ti-user-check me-1"></i>' . $nxLicEsc(__('Vinculada', 'nextool')) . '</span>'
                        . '<a href="' . $nxLicEsc($nxPortalConta) . '" target="_blank" rel="noopener" title="' . $nxLicEsc(__('Abrir minha conta no portal NexTool', 'nextool')) . '">'
                        . (($accountEmail ?? '') !== '' ? $nxLicEsc($accountEmail) : $nxLicEsc(__('Minha conta no portal', 'nextool')))
                        . '<i class="ti ti-external-link ms-1"></i></a>';
                  } else {
                     $nxConta = '<span class="badge bg-secondary text-white me-2"><i class="ti ti-user-off me-1"></i>' . $nxLicEsc(__('Não vinculada', 'nextool')) . '</span>'
                        . '<button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#nextool-account-link-modal" onclick="nextoolRefreshLinkStatus();">'
                        . '<i class="ti ti-user-check me-1"></i>' . $nxLicEsc(__('Vincular conta', 'nextool')) . '</button>';
                  }
                  echo $nxLicRow(
                     __('Conta NexTool', 'nextool'),
                     $nxConta,
                     __('Conta do portal NexTool vinculada a este ambiente. Abra "Conta", no topo da página, para ver detalhes ou desvincular.', 'nextool')
                  );

                  // Comunicação com o servidor (chave de segurança)
                  if ($distributionClientIdentifier === '') {
                     $nxChave = '<span class="text-muted">' . $nxLicEsc(__('Aguardando o código do ambiente.', 'nextool')) . '</span>';
                  } elseif ($distributionClientSecret !== '') {
                     $nxChave = '<span class="badge text-white bg-success"><i class="ti ti-shield-check me-1"></i>' . $nxLicEsc(__('Chave provisionada', 'nextool')) . '</span>';
                  } else {
                     $nxChave = '<span class="badge bg-secondary text-white me-2">' . $nxLicEsc(__('Sem chave', 'nextool')) . '</span>'
                        . '<span class="text-muted small">' . $nxLicEsc(__('É gerada sozinha na próxima sincronização.', 'nextool')) . '</span>';
                  }
                  echo $nxLicRow(
                     __('Comunicação com o servidor', 'nextool'),
                     $nxChave,
                     __('Chave de segurança que este GLPI usa para falar com o servidor de licenças. É criada e renovada na sincronização.', 'nextool')
                  );

               ?>
            </div>
         </div>
      </div>
   </form>

<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
