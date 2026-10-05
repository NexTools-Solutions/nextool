<?php
declare(strict_types=1);
/**
 * Aba Contato do Nextool.
 * Contexto/variáveis esperadas: $nextool_is_standalone, $nextool_standalone_output_tab,
 * $canViewAdminTabs, $firstTabKey, $nextool_hero_standalone, $distributionClientIdentifier.
 *
 * Desde a nextool-dev#276 o atendimento é no portal: a aba não tem mais formulário enviado por API.
 * O botão abre uma solicitação nova no portal já com a categoria "Plugin NexTool" e, no texto, o
 * ambiente e as versões (identificador do ambiente, NexTool, GLPI e PHP -- nada sensível), para o
 * suporte não precisar perguntar. O portal valida categoria/tipo e sanitiza o texto da query.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

$nextoolPortalBase = 'https://app.nextoolsolutions.com/painel/solicitacoes';
$nextoolContexto = sprintf(
   __('Ambiente: %1$s | NexTool %2$s | GLPI %3$s | PHP %4$s', 'nextool'),
   (string) ($distributionClientIdentifier ?: '-'),
   PluginNextoolConfig::getPluginVersion(),
   defined('GLPI_VERSION') ? GLPI_VERSION : '-',
   PHP_VERSION
);
$nextoolNovaSolicitacao = $nextoolPortalBase . '/nova?' . http_build_query([
   'categoria' => 'plugin',
   'tipo'      => 'duvida',
   'corpo'     => __('Descreva aqui a sua dúvida ou o problema.', 'nextool') . "\n\n" . $nextoolContexto,
]);
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
      <div class="card-body" id="nextool-contato-portal">
         <p class="mb-3">
            <?php echo __('O atendimento da NexTool é feito pelo portal: lá você abre a solicitação, conversa com a equipe e acompanha o andamento, com o histórico guardado.', 'nextool'); ?>
         </p>
         <p class="text-muted small mb-3">
            <?php echo __('A solicitação já abre com os dados deste ambiente, para o suporte não precisar perguntar:', 'nextool'); ?>
            <code><?php echo Html::entities_deep($nextoolContexto); ?></code>
         </p>
         <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" id="nextool-contato-nova" target="_blank" rel="noopener"
               href="<?php echo Html::entities_deep($nextoolNovaSolicitacao); ?>">
               <i class="ti ti-message-plus me-1"></i><?php echo __('Abrir solicitação no portal', 'nextool'); ?>
            </a>
            <a class="btn btn-outline-secondary" id="nextool-contato-lista" target="_blank" rel="noopener"
               href="<?php echo Html::entities_deep($nextoolPortalBase); ?>">
               <i class="ti ti-list-details me-1"></i><?php echo __('Acompanhar minhas solicitações', 'nextool'); ?>
            </a>
         </div>
         <p class="text-muted small mt-3 mb-0">
            <i class="ti ti-info-circle me-1"></i><?php echo __('Ainda não tem conta no portal? Ela é criada na hora, com o seu e-mail.', 'nextool'); ?>
         </p>
      </div>
   </div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
