<?php
/**
 * Nextools - Aba Sobre (módulos)
 *
 * Aba "Sobre" genérica para módulos standalone. Exibe informações técnicas do módulo:
 * nome, versão, status, ícone, autor, billing tier.
 * Uso: incluir via displayTabContentForItem com $GLOBALS['nextool_about_module_key'] definido.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

$moduleKey = $GLOBALS['nextool_about_module_key'] ?? '';
if ($moduleKey === '') {
   echo '<div class="alert alert-warning m-3">Módulo não identificado.</div>';
   return;
}

require_once GLPI_ROOT . '/plugins/nextool/inc/modulespath.inc.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';

$manager = PluginNextoolModuleManager::getInstance();
$module = $manager->getModule($moduleKey);
if (!$module) {
   echo '<div class="alert alert-warning m-3">' . __('Módulo não encontrado.', 'nextool') . '</div>';
   return;
}

$billingTier = method_exists($module, 'getBillingTier') ? strtoupper($module->getBillingTier()) : '—';
$tierBadge = $billingTier === 'FREE'
   ? '<span class="badge bg-success text-white">FREE</span>'
   : '<span class="badge bg-warning text-dark">' . Html::entities_deep($billingTier) . '</span>';
$statusBadge = $module->isEnabled()
   ? '<span class="badge bg-success text-white">' . __('Ativo', 'nextool') . '</span>'
   : '<span class="badge bg-secondary text-white">' . __('Inativo', 'nextool') . '</span>';
?>

<div class="m-3">
   <div class="card">
      <div class="card-header">
         <h5 class="card-title mb-0">
            <i class="<?php echo Html::entities_deep($module->getIcon()); ?> me-2"></i>
            <?php echo Html::entities_deep($module->getName()); ?>
         </h5>
      </div>
      <div class="card-body">
         <table class="table table-sm mb-0">
            <tr>
               <th style="width:200px"><?php echo __('Status', 'nextool'); ?></th>
               <td><?php echo $statusBadge; ?></td>
            </tr>
            <tr>
               <th><?php echo __('Nome', 'nextool'); ?></th>
               <td><?php echo Html::entities_deep($module->getName()); ?></td>
            </tr>
            <tr>
               <th>Module Key</th>
               <td><code><?php echo Html::entities_deep($module->getModuleKey()); ?></code></td>
            </tr>
            <tr>
               <th><?php echo __('Versão', 'nextool'); ?></th>
               <td><?php echo Html::entities_deep($module->getVersion()); ?></td>
            </tr>
            <tr>
               <th><?php echo __('Autor', 'nextool'); ?></th>
               <td><?php
                  if (defined('NEXTOOL_AUTHOR_URL') && NEXTOOL_AUTHOR_URL !== '') {
                     echo '<a href="' . Html::entities_deep(NEXTOOL_AUTHOR_URL) . '" target="_blank" rel="noopener" class="text-decoration-underline">' . Html::entities_deep($module->getAuthor()) . '</a>';
                  } else {
                     echo Html::entities_deep($module->getAuthor());
                  }
               ?></td>
            </tr>
            <tr>
               <th><?php echo __('Tier de cobrança', 'nextool'); ?></th>
               <td><?php echo $tierBadge; ?></td>
            </tr>
            <tr>
               <th><?php echo __('Descrição', 'nextool'); ?></th>
               <td><?php echo Html::entities_deep($module->getDescription()); ?></td>
            </tr>
         </table>
      </div>
   </div>
</div>
