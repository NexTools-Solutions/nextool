<?php
declare(strict_types=1);
/**
 * Aba Módulos do Nextool.
 * Contexto/variáveis esperadas: $nextool_is_standalone, $nextool_standalone_output_tab,
 * $canViewAnyModule, $firstTabKey, $nextool_hero_standalone, $stats, $canManageModules,
 * $requiresPolicyAcceptance, $canManageAdminTabs, $modulesState.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
?>

<!-- TAB 1: Módulos -->
<?php $show_modulos = (!$nextool_is_standalone || $nextool_standalone_output_tab === 'modules') && $canViewAnyModule; if ($show_modulos): ?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade<?php echo $firstTabKey === 'modules' ? ' show active' : ''; ?>" id="rt-tab-modulos" role="tabpanel" aria-labelledby="rt-tab-modulos-link"><?php endif; ?>
   <div class="d-flex flex-column gap-3">

      <?php echo $nextool_hero_standalone; ?>

      <div class="card shadow-sm nextool-tab-card">
         <div class="card-header mb-3 pt-2 border-top rounded-0">
            <h4 class="card-title ms-5 mb-0">
               <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-purple s-1">
                  <i class="fs-2x ti ti-puzzle"></i>
               </div>
               <span><?php echo __('Módulos Disponíveis', 'nextool'); ?></span>
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
               <div class="d-flex justify-content-center">
                  <div class="text-center p-4 rounded" style="max-width: 600px; width: 100%; background-color: #eef4fb; border: 1px solid #cfe0f3; color: #1f3a56;">
                     <p class="mb-3">
                        <?php echo __('Vincule este ambiente à sua conta NexTool para ativar e liberar os módulos oficiais.', 'nextool'); ?>
                     </p>
                     <?php if ($canManageAdminTabs): ?>
                        <button type="button" class="btn btn-primary"
                                data-bs-toggle="modal" data-bs-target="#nextool-account-link-modal" onclick="nextoolRefreshLinkStatus();">
                           <i class="ti ti-user-check me-1"></i>
                           <?php echo __('Vincular conta', 'nextool'); ?>
                        </button>
                     <?php else: ?>
                        <div class="text-muted small mt-2">
                           <i class="ti ti-lock me-1"></i>
                           <?php echo __('Somente usuários com permissão de gerenciamento podem liberar o catálogo de módulos.', 'nextool'); ?>
                        </div>
                     <?php endif; ?>
                  </div>
               </div>
            <?php elseif (empty($modulesState)): ?>
               <div class="alert alert-info mb-0">
                  <i class="ti ti-info-circle me-2"></i>
                  <?php echo __('Nenhum módulo está disponível para este perfil no momento. Solicite ao administrador a liberação de acesso.', 'nextool'); ?>
               </div>
            <?php else: ?>
               <?php
                  // Contadores dos chips - calculados server-side
                  $fc = ['enabled' => 0, 'disabled' => 0, 'download' => 0, 'install' => 0, 'update' => 0, 'free' => 0, 'licensed' => 0, 'dev' => 0];
                  $categoryCounters = [];
                  foreach ($modulesState as $m) {
                     $tier = strtoupper($m['billing_tier'] ?? '');
                     if (!empty($m['is_enabled']))                                   $fc['enabled']++;
                     if (!empty($m['is_installed']) && empty($m['is_enabled']))       $fc['disabled']++;
                     if (empty($m['module_downloaded']) && !empty($m['can_download'])) $fc['download']++;
                     if (!empty($m['module_downloaded']) && empty($m['is_installed'])) $fc['install']++;
                     if (!empty($m['update_available']))                              $fc['update']++;
                     if ($tier === 'FREE')                                            $fc['free']++;
                     if ($tier === 'DEV')                                             $fc['dev']++;
                     if ($tier !== 'FREE' && $tier !== 'DEV')                         $fc['licensed']++;
                     // category pode trazer VÁRIAS categorias separadas por vírgula (N:N):
                     // conta o módulo em cada uma. Valor único vira lista de 1 elemento
                     // (retrocompatível com o catálogo antigo, 1 categoria por módulo).
                     foreach (array_filter(array_map('trim', explode(',', (string) ($m['category'] ?? '')))) as $cat) {
                        $categoryCounters[$cat] = ($categoryCounters[$cat] ?? 0) + 1;
                     }
                  }
                  $isDevEnvironment = isset($licenseTier) && $licenseTier === 'DESENVOLVIMENTO';

                  // Filtros de estado ordenados por contagem (desc), ocultar zeros
                  $stateChips = [
                     ['key' => 'enabled',  'label' => __('Ativado', 'nextool'),     'icon' => 'ti ti-player-play',    'btn' => 'btn-outline-success', 'badge' => 'bg-success',   'count' => $fc['enabled']],
                     ['key' => 'disabled', 'label' => __('Desativado', 'nextool'),   'icon' => 'ti ti-player-pause',   'btn' => 'btn-outline-warning', 'badge' => 'bg-warning text-dark', 'count' => $fc['disabled']],
                     ['key' => 'download', 'label' => __('Download', 'nextool'),     'icon' => 'ti ti-cloud-download', 'btn' => 'btn-outline-secondary', 'badge' => 'bg-secondary', 'count' => $fc['download']],
                     ['key' => 'install',  'label' => __('Instalar', 'nextool'),     'icon' => 'ti ti-download',       'btn' => 'btn-outline-primary', 'badge' => 'bg-primary',   'count' => $fc['install']],
                     ['key' => 'update',   'label' => __('Atualização', 'nextool'),  'icon' => 'ti ti-arrow-up',       'btn' => 'btn-outline-info',    'badge' => 'bg-info',      'count' => $fc['update']],
                  ];
                  usort($stateChips, function($a, $b) { return $b['count'] - $a['count']; });

                  // Filtros de tipo ordenados por contagem (desc), ocultar zeros
                  $tierChips = [
                     ['key' => 'free',     'label' => __('Gratuito', 'nextool'),     'icon' => 'ti ti-free-rights',    'btn' => 'btn-outline-teal',      'badge' => 'bg-teal text-white',      'count' => $fc['free']],
                     ['key' => 'licensed', 'label' => __('Licenciado', 'nextool'),   'icon' => 'ti ti-certificate',    'btn' => 'btn-outline-licensing',  'badge' => 'bg-licensing text-white', 'count' => $fc['licensed']],
                  ];
                  if ($isDevEnvironment) {
                     $tierChips[] = ['key' => 'dev', 'label' => __('Em desenvolvimento', 'nextool'), 'icon' => 'ti ti-code', 'btn' => 'btn-outline-dev', 'badge' => 'bg-dev text-white', 'count' => $fc['dev']];
                  }
                  usort($tierChips, function($a, $b) { return $b['count'] - $a['count']; });

                  // Categorias ordenadas por contagem (desc)
                  arsort($categoryCounters);
               ?>
               <div class="mb-3" id="nextool-module-filter-bar">
                  <div class="input-group mb-2">
                     <span class="input-group-text bg-white border-end-0">
                        <i class="ti ti-search text-muted"></i>
                     </span>
                     <input type="text"
                            class="form-control border-start-0"
                            id="nextool-module-search"
                            placeholder="<?php echo __('Buscar módulo por nome ou descrição...', 'nextool'); ?>"
                            autocomplete="off">
                  </div>
                  <div class="d-flex gap-2 flex-wrap" id="nextool-module-chips">
                     <?php foreach ($stateChips as $chip): if ($chip['count'] <= 0) continue; ?>
                     <button type="button" class="btn btn-sm <?php echo $chip['btn']; ?> nextool-filter-chip rounded-pill" data-filter="<?php echo $chip['key']; ?>">
                        <i class="<?php echo $chip['icon']; ?> me-1"></i><?php echo $chip['label']; ?> <span class="badge <?php echo $chip['badge']; ?> ms-1"><?php echo $chip['count']; ?></span>
                     </button>
                     <?php endforeach; ?>

                     <span class="border-start mx-1 d-none d-md-block" style="height:24px"></span>

                     <?php foreach ($tierChips as $chip): if ($chip['count'] <= 0) continue; ?>
                     <button type="button" class="btn btn-sm <?php echo $chip['btn']; ?> nextool-filter-chip rounded-pill" data-filter="<?php echo $chip['key']; ?>">
                        <i class="<?php echo $chip['icon']; ?> me-1"></i><?php echo $chip['label']; ?> <span class="badge <?php echo $chip['badge']; ?> ms-1"><?php echo $chip['count']; ?></span>
                     </button>
                     <?php endforeach; ?>

                     <?php if (!empty($categoryCounters)): ?>
                     <span class="border-start mx-1 d-none d-md-block" style="height:24px"></span>
                     <?php foreach ($categoryCounters as $catName => $catCount): if ($catCount <= 0) continue; ?>
                     <button type="button" class="btn btn-sm nextool-filter-chip nextool-chip-category rounded-pill" data-filter="category" data-category="<?php echo Html::entities_deep($catName); ?>">
                        <?php echo Html::entities_deep($catName); ?> <span class="badge nextool-chip-category-badge ms-1"><?php echo $catCount; ?></span>
                     </button>
                     <?php endforeach; ?>
                     <?php endif; ?>
                  </div>
               </div>
               <div class="row g-3">
                  <?php foreach ($modulesState as $module):
                     $tier = strtoupper($module['billing_tier'] ?? 'FREE');
                     if ($tier === 'DEV') {
                        $borderClass = 'nextool-border-dev';
                        $tierColor = 'nextool-color-dev';
                     } elseif ($module['is_paid']) {
                        $borderClass = 'nextool-border-paid';
                        $tierColor = 'nextool-color-paid';
                     } else {
                        $borderClass = 'nextool-border-free';
                        $tierColor = 'nextool-color-free';
                     }
                  ?>
                  <?php
                     // Badge e preco
                     if ($tier === 'DEV') {
                        $ribbonLabel = __('DEV', 'nextool');
                        $ribbonClass = 'nextool-ribbon-dev';
                     } elseif ($module['is_paid']) {
                        $ribbonLabel = __('LICENCIADO', 'nextool');
                        $ribbonClass = 'nextool-ribbon-paid';
                     } else {
                        $ribbonLabel = __('GRÁTIS', 'nextool');
                        $ribbonClass = 'nextool-ribbon-free';
                     }

                     $dlCount = (int)($module['download_count'] ?? 0);
                     $features = $module['features'] ?? [];
                     $screenshotUrl = $module['screenshot_url'] ?? '';
                     $moduleCategory = $module['category'] ?? '';
                  ?>
                  <div class="col-md-6 nextool-module-card"
                       data-module-name="<?php echo strtolower(Html::entities_deep($module['name'])); ?>"
                       data-module-desc="<?php echo strtolower(Html::entities_deep($module['description'])); ?>"
                       data-module-enabled="<?php echo $module['is_enabled'] ? '1' : '0'; ?>"
                       data-module-installed="<?php echo $module['is_installed'] ? '1' : '0'; ?>"
                       data-module-downloaded="<?php echo $module['module_downloaded'] ? '1' : '0'; ?>"
                       data-module-can-download="<?php echo !empty($module['can_download']) ? '1' : '0'; ?>"
                       data-module-install-ready="<?php echo (!$module['is_installed'] && $module['module_downloaded']) ? '1' : '0'; ?>"
                       data-module-update="<?php echo $module['update_available'] ? '1' : '0'; ?>"
                       data-module-tier="<?php echo $tier; ?>"
                       data-module-category="<?php echo Html::entities_deep($moduleCategory); ?>"
                       data-module-downloads="<?php echo $dlCount; ?>"
                       <?php if ($screenshotUrl !== ''): ?>data-screenshot-url="<?php echo Html::entities_deep($screenshotUrl); ?>"<?php endif; ?>>
                     <div class="card border <?php echo $borderClass; ?> h-100 position-relative">
                        <span class="nextool-badge-ribbon <?php echo $ribbonClass; ?>"><?php echo $ribbonLabel; ?></span>
                        <div class="card-body d-flex flex-column">
                           <div class="d-flex align-items-start gap-2 mb-2">
                              <i class="<?php echo $module['icon']; ?> fs-2x <?php echo $tierColor; ?> mt-1"></i>
                              <div class="flex-grow-1" style="min-width: 0;">
                                 <div class="d-flex align-items-baseline justify-content-between">
                                    <h5 class="card-title mb-0 <?php echo $tierColor; ?>"><?php
                                       if (!empty($module['website_url'])) {
                                          echo '<a href="' . Html::entities_deep($module['website_url']) . '" target="_blank" rel="noopener" class="text-decoration-none ' . $tierColor . '">'
                                             . Html::entities_deep($module['name']) . '</a>'
                                             . ' <i class="ti ti-external-link nextool-ext-icon"></i>';
                                       } else {
                                          echo Html::entities_deep($module['name']);
                                       }
                                    ?></h5>
                                 </div>
                                 <?php
                                    $installedVersion = $module['installed_version'] ?? null;
                                    $availableVersion = $module['available_version'] ?? null;
                                    // Com atualização disponível, mostra "atual → nova" para deixar
                                    // claro qual versão será baixada (antes só mostrava a instalada).
                                    if (!empty($module['update_available']) && $installedVersion && $availableVersion
                                        && $installedVersion !== $availableVersion) {
                                       $versionLabel = 'v' . $installedVersion . ' → v' . $availableVersion;
                                    } elseif ($installedVersion) {
                                       $versionLabel = 'v' . $installedVersion;
                                    } elseif ($availableVersion) {
                                       $versionLabel = 'v' . $availableVersion;
                                    } else {
                                       $versionLabel = '';
                                    }
                                 ?>
                                 <small class="text-muted">
                                    <?php if ($versionLabel !== ''): ?><?php echo Html::entities_deep($versionLabel); ?> · <?php endif; ?>
                                    <?php if (is_array($module['author']) && !empty($module['author']['url'])): ?>
                                       <a href="<?php echo Html::entities_deep($module['author']['url']); ?>" target="_blank" rel="noopener" class="text-decoration-underline"><?php echo Html::entities_deep($module['author']['name'] ?? ''); ?></a>
                                    <?php else: ?>
                                       <?php echo Html::entities_deep(is_array($module['author']) ? ($module['author']['name'] ?? '') : $module['author']); ?>
                                    <?php endif; ?>
                                    <?php if ($dlCount > 0): ?>
                                       · <i class="ti ti-download" style="font-size: 0.85em;"></i> <?php echo number_format($dlCount, 0, '', '.'); ?>
                                    <?php else: ?>
                                       · <span class="text-info"><?php echo __('Novo', 'nextool'); ?></span>
                                    <?php endif; ?>
                                 </small>
                              </div>
                           </div>

                           <?php if (!empty($features)): ?>
                           <ul class="nextool-features list-unstyled small mb-2">
                              <?php foreach (array_slice($features, 0, 3) as $feat): ?>
                              <li><i class="ti ti-check text-success me-1"></i><?php echo Html::entities_deep($feat); ?></li>
                              <?php endforeach; ?>
                           </ul>
                           <?php else: ?>
                           <p class="card-text text-muted small mb-2"><?php echo Html::entities_deep($module['description']); ?></p>
                           <?php endif; ?>

                           <?php if (!$module['catalog_is_enabled']): ?>
                           <div class="mb-2">
                              <span class="badge text-white bg-secondary"><?php echo __('Indisponível', 'nextool'); ?></span>
                           </div>
                           <?php endif; ?>

                           <div class="mt-auto pt-2">
                              <?php if ($module['is_paid'] && empty($module['can_download']) && empty($module['is_license_suspended'])): ?>
                              <div class="alert alert-warning small p-2 mb-2 d-flex align-items-center justify-content-between">
                                 <span><i class="ti ti-lock me-1"></i><?php echo __('Licença necessária para utilizar todos os recursos', 'nextool'); ?></span>
                              </div>
                              <?php endif; ?>
                              <div class="d-flex justify-content-between align-items-center">
                                 <?php echo $module['actions_html']; ?>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>
               <div class="alert alert-secondary text-center mt-3 d-none" id="nextool-module-no-results">
                  <i class="ti ti-search-off me-2"></i>
                  <?php echo __('Nenhum módulo encontrado com os filtros selecionados.', 'nextool'); ?>
               </div>
            <?php endif; ?>
         </div>
      </div>
   </div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
