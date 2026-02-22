<?php
/**
 * Nextools - Hero Plano atual
 *
 * Bloco hero compartilhado nas abas da configuração: exibe plano atual,
 * status da licença e ações rápidas (sincronizar, validar).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 *
 * Variáveis esperadas: $heroPlanBadgeClass, $heroPlanLabel, $heroPlanDescription, $contractActive, $isFreeTier, $licenseStatusCode,
 * Variáveis opcionais:
 * - $nextoolHeroWithMarginTop (bool) adiciona mt-3 no card
 * - $nextoolHeroDisableSync (bool) desabilita botão Sincronizar
 * - $nextoolHeroHideSync (bool) oculta botão Sincronizar
 */

$nextoolHeroWithMarginTop = !empty($nextoolHeroWithMarginTop);
$nextoolHeroDisableSync = !empty($nextoolHeroDisableSync);
$nextoolHeroHideSync = !empty($nextoolHeroHideSync);
?>
<div class="card shadow-sm border-0<?php echo $nextoolHeroWithMarginTop ? ' mt-3' : ''; ?>" style="background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 40%, #14b8a6 100%);">
   <div class="card-body text-white">
      <div class="row g-3 align-items-start">
         <div class="col-12 col-lg-8">
            <h4 class="mb-1">
               <i class="ti ti-crown"></i>
               <span>Plano atual:</span>
               <span class="badge <?php echo $heroPlanBadgeClass; ?> text-white">
                  <?php echo Html::entities_deep($heroPlanLabel); ?>
               </span>
            </h4>
            <p class="mb-2">
               <?php if (trim((string) $heroPlanDescription) !== ''): ?>
                  <?php echo Html::entities_deep($heroPlanDescription); ?>
                  <br>
               <?php endif; ?>
               <span class="small text-warning fw-semibold" style="display:inline">
                  <i class="ti ti-bolt"></i>
                  Desbloqueie módulos pagos, integrações avançadas e automações sob demanda.
               </span>
               <br>
               <span class="small text-info fw-semibold" style="display:inline">
                  <i class="ti ti-plug-connected"></i>
                  Precisa de um módulo específico ou integração personalizada?
                  <a href="<?= NEXTOOL_BOOKING_URL ?>" target="_blank" class="text-white text-decoration-underline">Agende uma reunião.</a>
               </span>
               <br>
               <span class="small text-licensing-hero fw-semibold" style="display:inline">
                  <i class="ti ti-lifebuoy"></i>
                  Planos de licenciamento com suporte oficial, atualizações contínuas e acompanhamento técnico.
               </span>
            </p>
            <?php if ($contractActive === false && !$isFreeTier): ?>
               <div class="alert alert-danger mt-3 mb-0">
                  <i class="ti ti-ban me-2"></i>
                  Contrato inativo: o acesso aos módulos licenciados está temporariamente bloqueado até a regularização com o suporte NexTool.
               </div>
            <?php elseif ($contractActive === true && $licenseStatusCode === 'EXPIRED'): ?>
               <div class="alert alert-warning mt-3 mb-0 text-dark">
                  <i class="ti ti-alert-triangle me-2"></i>
                  Licença vencida com contrato ativo: os módulos continuam funcionando normalmente, mas recomenda-se renovar a licença para evitar interrupções futuras.
               </div>
            <?php endif; ?>
         </div>
         <div class="col-12 col-lg-4">
            <div class="nextool-hero-actions">
            <?php if (!$nextoolHeroHideSync): ?>
               <button type="button"
                       class="btn btn-hero-validate fw-semibold mb-2"
                       onclick="nextoolValidateLicense(this);"
                       <?php echo $nextoolHeroDisableSync ? ' disabled' : ''; ?>>
                  <i class="ti ti-refresh me-1"></i>
                  Sincronizar
               </button>
            <?php endif; ?>
            <div class="small text-white-50">
               <a href="<?= NEXTOOL_WHATSAPP_URL ?>" target="_blank" class="text-white text-decoration-underline">
                  Atendimento Whatsapp
               </a>
            </div>
            <div class="small mt-2">
               <a href="<?= NEXTOOL_SITE_URL ?>" target="_blank" rel="noopener noreferrer" class="text-white text-decoration-underline">Site</a>
               <span class="text-white-50 mx-1">/</span>
               <a href="<?= NEXTOOL_RELEASES_URL ?>" target="_blank" rel="noopener noreferrer" class="text-white text-decoration-underline">Releases</a>
               <span class="text-white-50 mx-1">/</span>
               <a href="<?= NEXTOOL_TERMS_URL ?>" target="_blank" class="text-white text-decoration-underline">Termos de uso</a>
            </div>
            </div>
         </div>
      </div>
   </div>
</div>
