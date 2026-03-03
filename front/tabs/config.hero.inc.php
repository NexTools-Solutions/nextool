<?php
/**
 * Hero "Plano atual" compartilhado entre contextos da configuração.
 *
 * Contexto/variáveis esperadas:
 * - $heroPlanBadgeClass
 * - $heroPlanLabel
 * - $heroPlanDescription
 * - $contractActive
 * - $isFreeTier
 * - $licenseStatusCode
 *
 * Variáveis opcionais:
 * - $nextoolHeroWithMarginTop (bool) adiciona mt-3 no card
 * - $nextoolHeroDisableSync (bool) desabilita botão Sincronizar
 * - $nextoolHeroHideSync (bool) oculta botão Sincronizar
 * - $nextoolHeroForcetab (string) forcetab de retorno após sincronização
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

$nextoolHeroWithMarginTop = !empty($nextoolHeroWithMarginTop);
$nextoolHeroDisableSync = !empty($nextoolHeroDisableSync);
$nextoolHeroHideSync = !empty($nextoolHeroHideSync);
$nextoolHeroForcetab = isset($nextoolHeroForcetab) ? trim((string) $nextoolHeroForcetab) : '';
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
                  <?php echo Html::entities_deep($heroPlanDescription); ?>
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
            <?php if ($contractActive === false && !$isFreeTier): ?>
               <div class="alert alert-danger mt-3 mb-0">
                  <i class="ti ti-ban me-2"></i>
                  <?php echo __('Contrato inativo: o acesso aos módulos licenciados está temporariamente bloqueado até a regularização com o suporte NexTool.', 'nextool'); ?>
               </div>
            <?php elseif ($contractActive === true && $licenseStatusCode === 'EXPIRED'): ?>
               <div class="alert alert-warning mt-3 mb-0 text-dark">
                  <i class="ti ti-alert-triangle me-2"></i>
                  <?php echo __('Licença vencida com contrato ativo: os módulos continuam funcionando normalmente, mas recomenda-se renovar a licença para evitar interrupções futuras.', 'nextool'); ?>
               </div>
            <?php endif; ?>
         </div>
         <div class="col-12 col-lg-4">
            <div class="nextool-hero-actions">
            <?php if (!$nextoolHeroHideSync): ?>
               <button type="button"
                       class="btn btn-hero-validate fw-semibold mb-2"
                       onclick="nextoolValidateLicense(this);"
                       data-nextool-forcetab="<?php echo Html::entities_deep($nextoolHeroForcetab); ?>"
                       <?php echo $nextoolHeroDisableSync ? ' disabled' : ''; ?>>
                  <i class="ti ti-refresh me-1"></i>
                  <?php echo __('Sincronizar', 'nextool'); ?>
               </button>
            <?php endif; ?>
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
