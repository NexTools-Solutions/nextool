<?php
/**
 * Aba Contato do Nextool.
 * Contexto/variáveis esperadas: $nextool_is_standalone, $nextool_standalone_output_tab,
 * $canViewAdminTabs, $firstTabKey, $nextool_hero_standalone, $distributionConfigured,
 * $distributionClientIdentifier, $contactModuleOptions, $canManageAdminTabs.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
?>

<!-- TAB CONTATO -->
<?php $show_contato = (!$nextool_is_standalone || $nextool_standalone_output_tab === 'contato') && $canViewAdminTabs; if ($show_contato): ?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade<?php echo $firstTabKey === 'contato' ? ' show active' : ''; ?>" id="rt-tab-contato" role="tabpanel" aria-labelledby="rt-tab-contato-link"><?php endif; ?>
   <?php echo $nextool_hero_standalone; ?>

   <div class="card shadow-sm nextool-tab-card">
      <div class="card-header mb-3 pt-2 border-top rounded-0">
         <h4 class="card-title ms-5 mb-0">
            <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-info s-1">
               <i class="fs-2x ti ti-headset"></i>
            </div>
            <span><?php echo __('Fale com o time NexTool Solutions', 'nextool'); ?></span>
         </h4>
      </div>
      <div class="card-body">
         <?php if (!$distributionConfigured): ?>
            <div class="alert alert-warning mb-4">
               <div class="d-flex align-items-center">
                  <i class="ti ti-alert-triangle fs-4 me-3"></i>
                  <div>
                     <h5 class="alert-heading h6 mb-1"><?php echo __('Configuração Pendente', 'nextool'); ?></h5>
                     <p class="mb-0">
                        <?php echo __('Para entrar em contato através deste formulário, é necessário primeiro validar o ambiente e aceitar as políticas de uso.', 'nextool'); ?>
                        <br>
                        <a href="#" onclick="nextoolActivateDefaultTab(); return false;" class="alert-link text-decoration-underline">
                           <?php echo __('Vá para a aba Licenciamento e clique em Sincronizar.', 'nextool'); ?>
                        </a>
                     </p>
                  </div>
               </div>
            </div>
         <?php endif; ?>

         <form id="nextool-contact-form"
               action="<?php echo Plugin::getWebDir('nextool') . '/ajax/contact.form.php'; ?>"
               method="post"
               class="needs-validation"
               novalidate>
            <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
            <?php echo Html::hidden('contact_client_identifier', ['value' => Html::entities_deep($distributionClientIdentifier)]); ?>
            <input type="text" name="contact_extra_info" class="d-none" tabindex="-1" autocomplete="off">

            <fieldset <?php echo !$distributionConfigured ? 'disabled' : ''; ?>>
            <div class="row g-3">
               <div class="col-12 col-lg-6">
                  <label class="form-label fw-semibold" for="contact-name"><?php echo __('Nome completo *', 'nextool'); ?></label>
                  <input type="text" class="form-control" id="contact-name" name="contact_name" required>
                  <div class="invalid-feedback"><?php echo __('Informe seu nome completo.', 'nextool'); ?></div>
               </div>
               <div class="col-12 col-lg-6">
                  <label class="form-label fw-semibold" for="contact-company"><?php echo __('Empresa / Organização', 'nextool'); ?></label>
                  <input type="text" class="form-control" id="contact-company" name="contact_company">
               </div>
               <div class="col-12 col-lg-6">
                  <label class="form-label fw-semibold" for="contact-email"><?php echo __('E-mail *', 'nextool'); ?></label>
                  <input type="email" class="form-control" id="contact-email" name="contact_email" required>
                  <div class="invalid-feedback"><?php echo __('Informe um e-mail válido.', 'nextool'); ?></div>
               </div>
               <div class="col-12 col-lg-6">
                  <label class="form-label fw-semibold" for="contact-phone"><?php echo __('Telefone / WhatsApp', 'nextool'); ?></label>
                  <input type="text" class="form-control" id="contact-phone" name="contact_phone">
               </div>
               <div class="col-12 col-lg-6">
                  <label class="form-label fw-semibold" for="contact-reason"><?php echo __('Motivo do contato *', 'nextool'); ?></label>
                  <select class="form-select" id="contact-reason" name="contact_reason" required>
                     <option value=""><?php echo __('Selecione', 'nextool'); ?></option>
                     <option value="duvidas"><?php echo __('Dúvidas', 'nextool'); ?></option>
                     <option value="apresentacao"><?php echo __('Apresentação técnica', 'nextool'); ?></option>
                     <option value="desenvolvimento"><?php echo __('Desenvolvimento de plugin', 'nextool'); ?></option>
                     <option value="melhoria"><?php echo __('Sugestão de melhoria', 'nextool'); ?></option>
                     <option value="contratar"><?php echo __('Contratar licença', 'nextool'); ?></option>
                     <option value="outros"><?php echo __('Outros', 'nextool'); ?></option>
                  </select>
                  <div class="invalid-feedback"><?php echo __('Selecione o motivo do contato.', 'nextool'); ?></div>
               </div>
               <div class="col-12 col-lg-6">
                  <label class="form-label fw-semibold" for="contact-source"><?php echo __('Onde nos encontrou? *', 'nextool'); ?></label>
                  <select class="form-select" id="contact-source" name="contact_source" required>
                     <option value=""><?php echo __('Selecione', 'nextool'); ?></option>
                     <option value="canais_jmba"><?php echo __('Canais JMBA', 'nextool'); ?></option>
                     <option value="indicacao"><?php echo __('Indicação', 'nextool'); ?></option>
                     <option value="linkedin">LinkedIn</option>
                     <option value="telegram">Telegram</option>
                     <option value="outros"><?php echo __('Outros', 'nextool'); ?></option>
                  </select>
                  <div class="invalid-feedback"><?php echo __('Selecione onde nos encontrou.', 'nextool'); ?></div>
                  <div class="mt-2 d-none" id="contact-source-other-wrapper">
                     <input type="text"
                            class="form-control form-control-sm"
                            id="contact-source-other"
                            name="contact_source_other"
                            placeholder="<?php echo __('Descreva o canal (ex.: evento, podcast, outro site)', 'nextool'); ?>">
                  </div>
               </div>
               <div class="col-12">
                  <label class="form-label fw-semibold d-block"><?php echo __('Módulos de interesse', 'nextool'); ?></label>
                  <?php if (!empty($contactModuleOptions)): ?>
                     <div class="d-flex flex-wrap gap-2 mb-2">
                        <?php foreach ($contactModuleOptions as $moduleKey => $moduleName): ?>
                           <input type="checkbox"
                                  class="btn-check"
                                  name="contact_modules[]"
                                  id="contact-module-<?php echo Html::entities_deep($moduleKey); ?>"
                                  value="<?php echo Html::entities_deep($moduleKey); ?>">
                           <label class="btn btn-outline-primary btn-sm"
                                  for="contact-module-<?php echo Html::entities_deep($moduleKey); ?>">
                              <?php echo Html::entities_deep($moduleName); ?>
                           </label>
                        <?php endforeach; ?>
                        <input type="checkbox"
                               class="btn-check"
                               name="contact_modules[]"
                               id="contact-module-outros"
                               value="outros">
                        <label class="btn btn-outline-primary btn-sm"
                               for="contact-module-outros">
                           <?php echo __('Outros', 'nextool'); ?>
                        </label>
                     </div>
                  <?php else: ?>
                     <p class="text-muted small mb-2">
                        <?php echo __('Nenhum módulo no catálogo. Atualize a licença para sincronizar a lista.', 'nextool'); ?>
                     </p>
                  <?php endif; ?>
                  <div class="mt-2" id="contact-modules-other-wrapper">
                     <input type="text"
                            class="form-control form-control-sm"
                            placeholder="<?php echo __('Outros módulos', 'nextool'); ?>"
                            name="contact_modules_other"
                            id="contact-modules-other">
                  </div>
               </div>
               <div class="col-12">
                  <label class="form-label fw-semibold" for="contact-message"><?php echo __('Como podemos ajudar? *', 'nextool'); ?></label>
                  <textarea class="form-control" id="contact-message" name="contact_message" rows="4" required></textarea>
                  <div class="invalid-feedback"><?php echo __('Descreva sua necessidade.', 'nextool'); ?></div>
               </div>
               <div class="col-12">
                  <div class="form-check">
                     <input class="form-check-input" type="checkbox" value="1" id="contact-consent" name="contact_consent">
                     <label class="form-check-label" for="contact-consent">
                        <?php echo __('Autorizo a NexTool Solutions a entrar em contato com meus dados.', 'nextool'); ?>
                     </label>
                  </div>
               </div>
            </div>
            </fieldset>

            <div class="d-flex align-items-center gap-3 mt-4">
               <button type="submit" class="btn btn-primary" <?php echo ($canManageAdminTabs && $distributionConfigured) ? '' : ' disabled'; ?>>
                  <i class="ti ti-send me-1"></i><?php echo __('Enviar contato', 'nextool'); ?>
               </button>
               <div id="nextool-contact-feedback" class="small"></div>
            </div>
            <?php if (!$canManageAdminTabs): ?>
               <p class="text-muted small mt-2 mb-0">
                  <i class="ti ti-lock me-1"></i><?php echo __('Apenas administradores podem enviar este formulário.', 'nextool'); ?>
               </p>
            <?php endif; ?>
         </form>
      </div>
   </div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
