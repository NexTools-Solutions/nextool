<?php
/**
 * Nextools - Aba Módulos
 *
 * Renderiza a aba Módulos da configuração (cards, ações, variáveis do contexto).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
?>

<!-- TAB 1: Módulos -->
<style>
   /* Header do card do módulo: título à esquerda, badges à direita (estável) */
   .nextool-module-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 0.75rem;
   }
   .nextool-module-badges {
      display: flex;
      justify-content: flex-end;
      align-items: flex-start;
      flex-wrap: wrap;
      gap: 0.25rem;
      min-width: 220px;
      text-align: right;
   }
   @media (max-width: 576px) {
      .nextool-module-badges {
         min-width: 0;
      }
   }
</style>
<?php $show_modulos = (!$nextool_is_standalone || $nextool_standalone_output_tab === 'modules') && $canViewAnyModule; if ($show_modulos): ?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade<?php echo $firstTabKey === 'modules' ? ' show active' : ''; ?>" id="rt-tab-modulos" role="tabpanel" aria-labelledby="rt-tab-modulos-link"><?php endif; ?>
   <div>

      <?php echo $nextool_hero_standalone; ?>

      <!-- Card de Módulos -->
      <div class="card shadow-sm nextool-tab-card">
         <div class="card-header mb-3 pt-2 border-top rounded-0">
            <h4 class="card-title ms-5 mb-0">
               <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-purple s-1">
                  <i class="fs-2x ti ti-puzzle"></i>
               </div>
               <span>Módulos Disponíveis</span>
               <span class="badge text-white bg-secondary ms-2"><?php echo $stats['total']; ?> total</span>
               <span class="badge text-white bg-success ms-1"><?php echo $stats['enabled']; ?> ativos</span>
            </h4>
         </div>
         <div class="card-body">
            <?php if (!$canManageModules): ?>
               <div class="alert alert-info">
                  <i class="ti ti-info-circle me-2"></i>
                  <?php echo __('Você possui acesso somente leitura. Os botões de download, instalação e atualização permanecem desabilitados.', 'nextool'); ?>
               </div>
            <?php endif; ?>
            <?php if ($requiresPolicyAcceptance): ?>
               <div class="alert alert-info mb-0">
                  <div class="text-center">
                     <div>
                        <i class="ti ti-info-circle fs-4 me-3"></i>
                        <div>
                           <p class="mb-2">
                              Para visualizar e instalar os módulos oficiais do Nextools, é necessário aceitar as
                              <a href="<?= NEXTOOL_TERMS_URL ?>" target="_blank" class="text-decoration-underline fw-semibold">Políticas de Uso</a>.
                           </p>
                           <p class="mb-0 text-muted small">
                              Esse processo confirma o ambiente na plataforma NexTool, registra o aceite e atualiza sua lista de módulos disponíveis.
                           </p>
                        </div>
                     </div>
                     <div class="w-100 mx-auto nextool-policy-actions">
                        <?php if ($canManageAdminTabs): ?>
                           <form method="post"
                                 class="d-block text-center"
                                 action="<?php echo Plugin::getWebDir('nextool') . '/front/config.save.php'; ?>">
                              <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
                              <?php echo Html::hidden('action', ['value' => 'accept_policies']); ?>
                              <?php echo Html::hidden('forcetab', ['value' => $nextool_is_standalone ? 'PluginNextoolMainConfig$1' : 'PluginNextoolSetup$1']); ?>
                              <button type="submit" class="btn btn-primary w-100">
                                 <i class="ti ti-checkbox me-1"></i>
                                 Aceitar políticas e liberar módulos
                              </button>
                           </form>
                        <?php else: ?>
                           <div class="alert alert-light border mb-0">
                              <i class="ti ti-lock me-2"></i>
                              <?php echo __('Somente usuários com permissão de gerenciamento podem liberar o catálogo de módulos.', 'nextool'); ?>
                           </div>
                        <?php endif; ?>
                     </div>
                  </div>
               </div>
            <?php elseif (empty($modulesState)): ?>
               <div class="alert alert-info mb-0">
                  <i class="ti ti-info-circle me-2"></i>
                  <?php echo __('Nenhum módulo está disponível para este perfil no momento. Solicite ao administrador a liberação de acesso.', 'nextool'); ?>
               </div>
            <?php else: ?>
               <div style="display:block">
                  <?php foreach ($modulesState as $module):
                     $borderClass = $module['is_enabled']
                        ? 'border-success'
                        : ($module['is_installed'] ? 'border-warning' : 'border-secondary');
                  ?>
                  <div style="display:block;width:100%;margin-bottom:0.5rem">
                     <div class="card border <?php echo $borderClass; ?> h-100">
                        <div class="card-body">
                           <div class="mb-2">
                              <div class="nextool-module-header">
                                 <div class="flex-grow-1">
                                    <h5 class="card-title mb-0 d-flex align-items-center gap-2"><i class="<?php echo $module['icon']; ?> fs-4 text-muted"></i><span><?php echo Html::entities_deep($module['name']); ?></span></h5>
                                    <?php
                                       $installedVersion = $module['installed_version'] ?? null;
                                       $availableVersion = $module['available_version'] ?? null;
                                       $versionLabel = $installedVersion
                                          ? 'v' . $installedVersion
                                          : ($availableVersion ? 'v' . $availableVersion : '—');
                                       if (!empty($module['update_available']) && $availableVersion) {
                                          $versionLabel .= ' → v' . $availableVersion;
                                       }
                                    ?>
                                    <small class="text-muted">
                                       <?php echo Html::entities_deep($versionLabel); ?> •
                                       <?php if (is_array($module['author']) && !empty($module['author']['url'])): ?>
                                          <a href="<?php echo Html::entities_deep($module['author']['url']); ?>"
                                             target="_blank"
                                             rel="noopener"
                                             class="text-decoration-underline">
                                             <?php echo Html::entities_deep($module['author']['name'] ?? ''); ?>
                                          </a>
                                       <?php else: ?>
                                          <?php echo Html::entities_deep(is_array($module['author']) ? ($module['author']['name'] ?? '') : $module['author']); ?>
                                       <?php endif; ?>
                                    </small>
                                 </div>

                                 <div class="nextool-module-badges">
                                    <?php if (isset($module['billing_tier']) && strtoupper((string)$module['billing_tier']) === 'DEV'): ?>
                                       <span class="badge badge-dev">Em desenvolvimento</span>
                                    <?php elseif ($module['is_paid']): ?>
                                       <span class="badge badge-licensing">Módulo Licenciado</span>
                                    <?php else: ?>
                                       <span class="badge bg-teal text-white">Módulo Gratuito</span>
                                    <?php endif; ?>
                                    <?php if (!$module['catalog_is_enabled']): ?>
                                       <span class="badge text-white bg-secondary">Indisponível</span>
                                    <?php elseif (!empty($module['update_available'])): ?>
                                       <span class="badge bg-warning text-dark">Atualização disponível</span>
                                    <?php endif; ?>
                                 </div>
                              </div>
                           </div>

                           <p class="card-text text-muted small mb-3"><?php echo Html::entities_deep($module['description']); ?></p>

                           <div style="display:block" class="mt-2 pt-2">
                              <?php echo $module['actions_html']; ?>
                           </div>

                        </div>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>
            <?php endif; ?>
         </div>
      </div>

   </div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
