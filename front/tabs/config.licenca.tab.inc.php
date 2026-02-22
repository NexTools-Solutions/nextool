<?php
/**
 * Aba Licenciamento do Nextool.
 * Contexto/variáveis esperadas: $nextool_is_standalone, $nextool_standalone_output_tab,
 * $canViewAdminTabs, $nextool_hero_standalone, $licensesSnapshot, $contractActive,
 * $licenseStatusCode, $config, $distributionBaseUrl, $distributionClientIdentifier,
 * $distributionClientSecret, $hmacSecretRow, $allowedModules, $licenseWarnings,
 * $canManageAdminTabs.
 */
?>

<!-- TAB 2: Licenciamento -->
<?php $show_licenca = (!$nextool_is_standalone || $nextool_standalone_output_tab === 'licenca') && $canViewAdminTabs; if ($show_licenca): ?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade" id="rt-tab-licenca" role="tabpanel" aria-labelledby="rt-tab-licenca-link"><?php endif; ?>
   <form method="post" action="<?php echo Plugin::getWebDir('nextool') . '/front/config.save.php'; ?>" id="configForm">
      <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
      <?php echo Html::hidden('forcetab', ['value' => $nextool_is_standalone ? 'PluginNextoolMainConfig$3' : 'PluginNextoolSetup$1']); ?>
      <div class="d-flex flex-column gap-3">

         <?php echo $nextool_hero_standalone; ?>

         <div class="card shadow-sm nextool-tab-card">
            <div class="card-header mb-3 pt-2 border-top rounded-0">
               <h4 class="card-title ms-5 mb-0">
                  <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-blue s-1">
                     <i class="fs-2x ti ti-key"></i>
                  </div>
                  <span><?php echo __('Plano e status do ambiente', 'nextool'); ?></span>
               </h4>
            </div>
            <div class="card-body">
               <?php if (!empty($licensesSnapshot) && $contractActive === false): ?>
                  <div class="alert alert-danger mb-4">
                     <i class="ti ti-ban me-2"></i>
                     <?php echo __('Contrato inativo: os módulos licenciados ficarão bloqueados até a regularização com o suporte NexTool.', 'nextool'); ?>
                  </div>
               <?php elseif (!empty($licensesSnapshot) && $licenseStatusCode === 'EXPIRED'): ?>
                  <div class="alert alert-warning mb-4">
                     <i class="ti ti-alert-triangle me-2"></i>
                     <?php echo __('Licença expirada. Os módulos ativos continuam funcionando, mas recomendamos renovar a validade.', 'nextool'); ?>
                  </div>
               <?php elseif (!empty($licensesSnapshot) && $licenseStatusCode && $licenseStatusCode !== 'ACTIVE'): ?>
                  <div class="alert alert-info mb-4">
                     <i class="ti ti-info-circle me-2"></i>
                     <?php echo __('A licença ainda não está ativa. O ambiente permanece no plano gratuito até que uma licença válida seja vinculada.', 'nextool'); ?>
                  </div>
               <?php endif; ?>

               <div class="row g-3">
                  <div class="col-12">
                     <div class="border rounded p-3 h-100 bg-light text-dark">
                        <h6 class="fw-semibold mb-3 text-dark"><?php echo __('Licenças ativas no ambiente', 'nextool'); ?></h6>
                        <?php if (empty($licensesSnapshot)): ?>
                           <p class="text-muted mb-0">
                              <?php echo __('Nenhuma licença ativa foi encontrada no momento. Fale com o suporte NexTool para liberar os módulos contratados.', 'nextool'); ?>
                           </p>
                        <?php else: ?>
                           <div class="table-responsive">
                              <table class="table table-sm align-middle mb-0">
                                 <thead>
                                    <tr>
                                       <th><?php echo __('Licença', 'nextool'); ?></th>
                                       <th><?php echo __('Plano', 'nextool'); ?></th>
                                       <th><?php echo __('Status do contrato', 'nextool'); ?></th>
                                       <th><?php echo __('Validade da licença', 'nextool'); ?></th>
                                       <th><?php echo __('Módulos liberados', 'nextool'); ?></th>
                                    </tr>
                                 </thead>
                                 <tbody>
                                    <?php foreach ($licensesSnapshot as $licenseRow):
                                       $rowKey = $licenseRow['license_key'] ?? __('(desconhecida)', 'nextool');
                                       $rowPlan = strtoupper($licenseRow['plan'] ?? 'FREE');
                                       $rowContract = !empty($licenseRow['contract_active']);
                                       $rowExpires = $licenseRow['expires_at'] ?? null;
                                       $rowModules = [];
                                       if (!empty($licenseRow['allowed_modules']) && is_array($licenseRow['allowed_modules'])) {
                                          $rowModules = $licenseRow['allowed_modules'];
                                       }
                                       $planBadge = [
                                          'FREE'       => 'bg-teal',
                                          'STARTER'    => 'bg-blue',
                                          'PRO'        => 'bg-indigo',
                                          'ENTERPRISE' => 'bg-purple',
                                       ][$rowPlan] ?? 'bg-secondary';
                                       $contractBadge = $rowContract ? 'bg-green' : 'bg-red';
                                       $validityDisplay = __('Sem expiração', 'nextool');
                                       if (!empty($rowExpires)) {
                                          $formatted = $rowExpires;
                                          if (class_exists('Html')) {
                                             $formatted = Html::convDateTime($rowExpires);
                                          }
                                          $validityDisplay = $formatted;
                                       }
                                    ?>
                                    <tr>
                                       <td><code><?php echo Html::entities_deep($rowKey); ?></code></td>
                                       <td><span class="badge text-white <?php echo $planBadge; ?>"><?php echo Html::entities_deep(ucfirst(strtolower($rowPlan))); ?></span></td>
                                       <td><span class="badge text-white <?php echo $contractBadge; ?>"><?php echo $rowContract ? __('Ativo', 'nextool') : __('Inativo', 'nextool'); ?></span></td>
                                       <td><?php echo Html::entities_deep($validityDisplay); ?></td>
                                       <td>
                                          <?php if (empty($rowModules) || in_array('*', $rowModules, true)): ?>
                                             <span class="badge text-white bg-purple"><?php echo __('Todos os módulos', 'nextool'); ?></span>
                                          <?php else: ?>
                                             <?php foreach ($rowModules as $moduleKey): ?>
                                                <span class="badge text-white bg-teal me-1 mb-1"><?php echo Html::entities_deep($moduleKey); ?></span>
                                             <?php endforeach; ?>
                                          <?php endif; ?>
                                       </td>
                                    </tr>
                                    <?php endforeach; ?>
                                 </tbody>
                              </table>
                           </div>
                        <?php endif; ?>
                     </div>
                  </div>

                  <div class="col-12">
                     <div class="border rounded p-3 h-100 bg-light text-dark">
                        <h6 class="fw-semibold mb-3 text-dark"><?php echo __('Dados do ambiente', 'nextool'); ?></h6>
                        <div class="row g-3 small">
                           <div class="col-12 col-lg-6">
                              <div class="mb-3">
                                 <div class="text-muted fw-semibold mb-1"><?php echo __('Código do ambiente', 'nextool'); ?></div>
                                 <?php if (!empty($config['client_identifier'])): ?>
                                    <div class="input-group input-group-sm">
                                       <input type="text"
                                              class="form-control"
                                              id="rt-client-identifier"
                                              value="<?php echo Html::entities_deep($config['client_identifier']); ?>"
                                              readonly>
                                       <button type="button"
                                               class="btn btn-outline-secondary"
                                               onclick="navigator.clipboard.writeText(document.getElementById('rt-client-identifier').value); this.innerText='Copiado!'; setTimeout(() => { this.innerText='Copiar'; }, 2000);">
                                          <i class="ti ti-copy me-1"></i><?php echo __('Copiar'); ?>
                                       </button>
                                    </div>
                                 <?php else: ?>
                                    <span class="text-muted"><?php echo __('Não configurado', 'nextool'); ?></span>
                                 <?php endif; ?>
                              </div>

                              <div>
                                 <div class="text-muted fw-semibold mb-1"><?php echo __('URL da plataforma NexTool', 'nextool'); ?></div>
                                 <div class="input-group input-group-sm">
                                    <input type="url"
                                           class="form-control"
                                           id="rt-endpoint-url"
                                           name="endpoint_url"
                                           value="<?php echo Html::entities_deep($distributionBaseUrl); ?>"
                                           placeholder="<?php echo Html::entities_deep(PluginNextoolConfig::DEFAULT_CONTAINERAPI_BASE_URL); ?>">
                                    <button type="button"
                                            class="btn btn-outline-secondary"
                                            onclick="const el=document.getElementById('rt-endpoint-url'); navigator.clipboard.writeText(el ? el.value : ''); this.innerText='Copiado!'; setTimeout(() => { this.innerText='Copiar'; }, 2000);">
                                       <i class="ti ti-copy me-1"></i><?php echo __('Copiar'); ?>
                                    </button>
                                 </div>
                                 <div class="form-text">
                                    <?php
                                       echo sprintf(
                                          __('Informe a URL de conexão da plataforma NexTool. Deixe em branco para usar o padrão (%s).', 'nextool'),
                                          Html::entities_deep(PluginNextoolConfig::DEFAULT_CONTAINERAPI_BASE_URL)
                                       );
                                    ?>
                                 </div>
                              </div>
                           </div>

                           <div class="col-12 col-lg-6">
                              <div class="mb-3">
                                 <div class="text-muted fw-semibold mb-1"><?php echo __('Chave de segurança', 'nextool'); ?></div>
                                 <?php if ($distributionClientIdentifier === ''): ?>
                                    <span class="text-muted"><?php echo __('Defina primeiro o código do ambiente para habilitar a chave de segurança.', 'nextool'); ?></span>
                                 <?php elseif ($distributionClientSecret !== ''): ?>
                                    <span class="badge text-white bg-success me-2"><?php echo __('Provisionado', 'nextool'); ?></span>
                                    <?php if ($hmacSecretRow): ?>
                                       <div class="form-text">
                                          <?php
                                             $createdAt = !empty($hmacSecretRow['date_creation'])
                                                ? Html::convDateTime($hmacSecretRow['date_creation'])
                                                : __('desconhecida', 'nextool');
                                             $updatedAt = !empty($hmacSecretRow['date_mod'])
                                                ? Html::convDateTime($hmacSecretRow['date_mod'])
                                                : __('desconhecida', 'nextool');
                                             echo sprintf(
                                                __('Gerado em %1$s • Última atualização %2$s', 'nextool'),
                                                Html::entities_deep($createdAt),
                                                Html::entities_deep($updatedAt)
                                             );
                                          ?>
                                       </div>
                                    <?php else: ?>
                                       <div class="form-text">
                                          <?php echo __('Atualizado após a última sincronização bem-sucedida.', 'nextool'); ?>
                                       </div>
                                    <?php endif; ?>
                                    <?php if ($canManageAdminTabs): ?>
                                       <div class="d-flex flex-wrap gap-2 mt-2">
                                          <button type="button"
                                                 class="btn btn-outline-primary btn-sm"
                                                 data-secret-endpoint="<?php echo Html::entities_deep(Plugin::getWebDir('nextool') . '/front/hmac_secret.php'); ?>"
                                                 onclick="nextoolCopyHmacSecret(this);">
                                             <i class="ti ti-copy me-1"></i><?php echo __('Copiar chave', 'nextool'); ?>
                                          </button>
                                          <button type="button"
                                                 class="btn btn-outline-danger btn-sm"
                                                 onclick="nextoolRegenerateHmac(this);">
                                             <i class="ti ti-refresh me-1"></i><?php echo __('Regerar chave', 'nextool'); ?>
                                          </button>
                                       </div>
                                    <?php else: ?>
                                       <div class="form-text mt-2">
                                          <?php echo __('Somente perfis com permissão de gerenciamento podem copiar ou regenerar a chave de segurança.', 'nextool'); ?>
                                       </div>
                                    <?php endif; ?>
                                 <?php else: ?>
                                    <span class="text-muted d-block"><?php echo __('Aguardando sincronização para gerar automaticamente.', 'nextool'); ?></span>
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                       <?php if ($canManageAdminTabs): ?>
                                          <button type="button"
                                                 class="btn btn-outline-primary btn-sm"
                                                 onclick="nextoolRegenerateHmac(this);">
                                             <i class="ti ti-refresh me-1"></i><?php echo __('Gerar chave agora', 'nextool'); ?>
                                          </button>
                                       <?php else: ?>
                                          <span class="form-text mb-0">
                                             <?php echo __('Somente perfis com permissão de gerenciamento podem gerar a chave de segurança.', 'nextool'); ?>
                                          </span>
                                       <?php endif; ?>
                                       <a href="<?= NEXTOOL_TERMS_URL ?>"
                                          target="_blank"
                                          class="btn btn-link px-0 text-decoration-underline">
                                          <?php echo __('Revisar políticas de uso', 'nextool'); ?>
                                       </a>
                                    </div>
                                 <?php endif; ?>
                              </div>

                              <div>
                                 <div class="text-muted fw-semibold mb-1"><?php echo __('Módulos liberados', 'nextool'); ?></div>
                                 <?php if (empty($allowedModules)): ?>
                                    <span class="text-muted">
                                       <?php echo __('Ainda não recebemos a lista de módulos liberados. Clique em "Sincronizar" para atualizar.', 'nextool'); ?>
                                    </span>
                                 <?php elseif (in_array('*', $allowedModules, true)): ?>
                                    <span class="badge text-white bg-purple"><?php echo __('Todos os módulos liberados', 'nextool'); ?></span>
                                 <?php else: ?>
                                    <p class="mb-0">
                                       <?php foreach ($allowedModules as $allowedKey): ?>
                                          <span class="badge text-white bg-teal me-1 mb-1"><?php echo Html::entities_deep($allowedKey); ?></span>
                                       <?php endforeach; ?>
                                    </p>
                                 <?php endif; ?>
                              </div>

                              <?php if (!empty($licenseWarnings)): ?>
                                 <div class="mt-3">
                                    <div class="text-muted fw-semibold mb-1"><?php echo __('Observações', 'nextool'); ?></div>
                                    <ul class="list-unstyled mb-0">
                                       <?php foreach ($licenseWarnings as $warning): ?>
                                          <li><i class="ti ti-alert-triangle text-warning me-1"></i><?php echo Html::entities_deep($warning); ?></li>
                                       <?php endforeach; ?>
                                    </ul>
                                 </div>
                              <?php endif; ?>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </form>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
