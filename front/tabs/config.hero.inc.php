<?php
declare(strict_types=1);
/**
 * Hero "Plano atual" compartilhado entre contextos da configuração.
 *
 * Contexto/variáveis esperadas:
 * - $heroPlanBadgeClass
 * - $heroPlanLabel
 * - $heroPlanDescription
 * - $licenseStatusCode
 * - $isFreeTier
 *
 * Variáveis opcionais:
 * - $nextoolHeroWithMarginTop (bool) adiciona mt-3 no card
 * - $nextoolHeroDisableSync (bool) desabilita botão Sincronizar
 * - $nextoolHeroHideSync (bool) oculta botão Sincronizar
 * - $nextoolHeroForcetab (string) forcetab de retorno após sincronização
 * - $nextoolHeroShowCoreUpdate (bool) exibe botão para modal de atualização
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

$nextoolHeroWithMarginTop = !empty($nextoolHeroWithMarginTop);
$nextoolHeroDisableSync = !empty($nextoolHeroDisableSync);
$nextoolHeroHideSync = !empty($nextoolHeroHideSync);
$nextoolHeroForcetab = isset($nextoolHeroForcetab) ? trim((string) $nextoolHeroForcetab) : '';
$nextoolHeroShowCoreUpdate = !empty($nextoolHeroShowCoreUpdate);
?>
<div class="card shadow-sm border-0<?php echo $nextoolHeroWithMarginTop ? ' mt-3' : ''; ?>" style="background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 40%, #14b8a6 100%);">
   <div class="card-body text-white">
      <div class="row g-3 align-items-start">
         <div class="col-12 col-lg-8">
            <h4 class="mb-1">
               <i class="ti ti-crown"></i>
               <span><?php echo __('Plano atual:', 'nextool'); ?></span>
               <span class="badge <?php echo $heroPlanBadgeClass; ?> text-white">
                  <?php echo Html::entities_deep($heroPlanLabel); ?>
               </span>
            </h4>
            <p class="mb-2">
               <?php if (trim((string) $heroPlanDescription) !== ''): ?>
                  <?php if ($licenseStatusCode === 'SUSPENDED'): ?>
                     <strong style="color: #ff6b6b;"><?php echo Html::entities_deep($heroPlanDescription); ?></strong>
                  <?php else: ?>
                     <?php echo Html::entities_deep($heroPlanDescription); ?>
                  <?php endif; ?>
                  <br>
               <?php endif; ?>
               <span class="small text-warning fw-semibold">
                  <i class="ti ti-bolt"></i>
                  <?php echo __('Desbloqueie módulos pagos, integrações avançadas e automações sob demanda.', 'nextool'); ?>
               </span>
               <br>
               <span class="small text-info fw-semibold">
                  <i class="ti ti-plug-connected"></i>
                  <?php echo __('Precisa de um módulo específico ou integração personalizada?', 'nextool'); ?>
                  <a href="<?= NEXTOOL_BOOKING_URL ?>" target="_blank" class="text-white text-decoration-underline"><?php echo __('Agende uma reunião.', 'nextool'); ?></a>
               </span>
               <br>
               <span class="small text-licensing-hero fw-semibold">
                  <i class="ti ti-lifebuoy"></i>
                  <?php echo __('Planos de licenciamento com suporte oficial, atualizações contínuas e acompanhamento técnico.', 'nextool'); ?>
               </span>
            </p>
            <?php if ($licenseStatusCode === 'CANCELLED' && !$isFreeTier): ?>
               <div class="alert alert-danger mt-3 mb-0">
                  <i class="ti ti-ban me-2"></i>
                  <?php echo __('Licença cancelada: o acesso aos módulos licenciados está temporariamente bloqueado até a regularização com o suporte NexTool.', 'nextool'); ?>
               </div>
            <?php endif; ?>
         </div>
         <div class="col-12 col-lg-4">
            <div class="nextool-hero-actions">
            <div class="d-flex flex-wrap justify-content-lg-end gap-2 mb-2">
            <?php if ($nextoolHeroShowCoreUpdate): ?>
               <button type="button" class="btn btn-light fw-semibold"
                  data-bs-toggle="modal" data-bs-target="#nextool-core-update-modal">
                  <i class="ti ti-cloud-up me-1"></i>
                  <?php echo __('Atualização Disponível', 'nextool'); ?>
               </button>
            <?php endif; ?>
            <?php if (!$nextoolHeroHideSync): ?>
               <button type="button"
                       class="btn btn-hero-validate fw-semibold"
                       onclick="nextoolValidateLicense(this);"
                       data-nextool-forcetab="<?php echo Html::entities_deep($nextoolHeroForcetab); ?>"
                       <?php echo $nextoolHeroDisableSync ? ' disabled' : ''; ?>>
                  <i class="ti ti-refresh me-1"></i>
                  <?php echo __('Sincronizar', 'nextool'); ?>
               </button>
            <?php endif; ?>
            </div>
            <div class="small text-white-50">
               <a href="<?= NEXTOOL_WHATSAPP_URL ?>" target="_blank" class="text-white text-decoration-underline">
                  <?php echo __('Atendimento Whatsapp', 'nextool'); ?>
               </a>
            </div>
            <div class="small mt-2">
               <a href="<?= NEXTOOL_SITE_URL ?>" target="_blank" rel="noopener noreferrer" class="text-white text-decoration-underline"><?php echo __('Site', 'nextool'); ?></a>
               <span class="text-white-50 mx-1">/</span>
               <a href="<?= NEXTOOL_RELEASES_URL ?>" target="_blank" rel="noopener noreferrer" class="text-white text-decoration-underline">Releases</a>
               <span class="text-white-50 mx-1">/</span>
               <a href="<?= NEXTOOL_TERMS_URL ?>" target="_blank" class="text-white text-decoration-underline"><?php echo __('Termos de uso', 'nextool'); ?></a>
            </div>
            </div>
         </div>
      </div>
   </div>
</div>
<?php if (empty($GLOBALS['nextool_core_update_modal_rendered'])): ?>
<?php $GLOBALS['nextool_core_update_modal_rendered'] = true; ?>
<div class="modal fade" id="nextool-core-update-modal" tabindex="-1" aria-hidden="true">
   <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
         <div class="modal-header">
            <h5 class="modal-title"><i class="ti ti-cloud-up me-2"></i><?php echo __('Atualização do NexTool', 'nextool'); ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
         </div>
         <div class="modal-body">
            <div class="d-flex justify-content-between mb-3">
               <span class="fw-semibold"><?php echo __('Versão atual:', 'nextool'); ?> <span id="nextool-modal-current-version">-</span></span>
               <span class="fw-semibold"><?php echo __('Nova versão:', 'nextool'); ?> <span id="nextool-modal-target-version">-</span></span>
            </div>

            <div class="list-group list-group-flush mb-3">
               <div class="list-group-item d-flex align-items-center" id="nextool-modal-step-1">
                  <span class="badge bg-secondary rounded-pill me-2" id="nextool-modal-step-1-badge">1</span>
                  <?php echo __('Verificação', 'nextool'); ?>
                  <span class="ms-auto" id="nextool-modal-step-1-status"></span>
               </div>
               <div class="list-group-item d-flex align-items-center" id="nextool-modal-step-2">
                  <span class="badge bg-secondary rounded-pill me-2" id="nextool-modal-step-2-badge">2</span>
                  <?php echo __('Download', 'nextool'); ?>
                  <span class="ms-auto" id="nextool-modal-step-2-status"></span>
               </div>
               <div class="list-group-item d-flex align-items-center" id="nextool-modal-step-3">
                  <span class="badge bg-secondary rounded-pill me-2" id="nextool-modal-step-3-badge">3</span>
                  <?php echo __('Aplicar', 'nextool'); ?>
                  <span class="ms-auto" id="nextool-modal-step-3-status"></span>
               </div>
            </div>

            <div class="alert d-none mb-2" role="alert" id="nextool-modal-alert"></div>
            <div class="d-none mb-2" id="nextool-modal-permission-detail">
               <p class="text-muted mb-1"><?php echo __('A atualização do core exige escrita no diretório de plugins do GLPI.', 'nextool'); ?></p>
               <p class="fw-semibold mb-0"><?php echo __('Ajuste as permissões do host e tente novamente.', 'nextool'); ?></p>
               <p class="text-danger small mb-0"><?php echo __('Conceder permissão de escrita para o usuário do servidor web (Apache/Nginx/IIS) na pasta de plugins do GLPI, incluindo o diretório nextool.', 'nextool'); ?></p>
            </div>
            <div class="d-none mb-2" id="nextool-modal-confirm-apply" data-confirm-word="<?php echo Html::entities_deep(__('confirmo', 'nextool')); ?>">
               <div class="alert alert-warning mb-2">
                  <div class="d-flex align-items-start">
                     <i class="ti ti-flask me-2 mt-1" style="flex-shrink:0;font-size:1.2rem"></i>
                     <div>
                        <strong><?php echo __('Feature experimental', 'nextool'); ?></strong>
                        <p class="mb-0 mt-1 small"><?php echo __('A atualização automática é uma funcionalidade experimental. Recomendamos ter um backup recente antes de prosseguir. Caso algo dê errado, você pode restaurar a versão anterior usando a seção de backups abaixo.', 'nextool'); ?></p>
                     </div>
                  </div>
               </div>
               <label for="nextool-modal-confirm-apply-input" class="form-label">
                  <?php echo sprintf(__('Para continuar, digite %s', 'nextool'), '<strong>' . __('confirmo', 'nextool') . '</strong>'); ?>
               </label>
               <input type="text" class="form-control" id="nextool-modal-confirm-apply-input" autocomplete="off" spellcheck="false" placeholder="<?php echo Html::entities_deep(__('confirmo', 'nextool')); ?>">
               <div class="form-text">
                  <?php echo sprintf(__('Digite exatamente "%s" para habilitar a aplicação da atualização.', 'nextool'), __('confirmo', 'nextool')); ?>
               </div>
            </div>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('Cancelar', 'nextool'); ?></button>
            <button type="button" class="btn btn-primary" id="nextool-modal-action-btn">
               <i class="ti ti-player-play me-1"></i><?php echo __('Iniciar atualização', 'nextool'); ?>
            </button>
         </div>
      </div>
   </div>
</div>
<?php endif; ?>
