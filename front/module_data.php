<?php
/**
 * Nextools - Module Data Viewer
 *
 * Tela administrativa que lista as tabelas de dados associadas a um módulo
 * do Nextools, permitindo inspeção e apagamento seguro via UI.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

include ('../../../inc/includes.php');

require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
$moduleKey = isset($_GET['module']) ? preg_replace('/[^a-z0-9_-]/', '', (string)$_GET['module']) : '';
if ($moduleKey === '') {
   Html::displayErrorAndDie(__('Módulo não informado.', 'nextool'));
}

PluginNextoolPermissionManager::assertCanViewModule($moduleKey);

require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/modulecatalog.class.php';

$catalog = PluginNextoolModuleCatalog::find($moduleKey);
if ($catalog === null) {
   Html::displayErrorAndDie(__('Módulo desconhecido.', 'nextool'));
}

$manager = PluginNextoolModuleManager::getInstance();
$tables = $manager->getModuleDataTables($moduleKey);

Html::header(
   sprintf(__('Dados do módulo %s', 'nextool'), $catalog['name']),
   $_SERVER['PHP_SELF'],
   'config',
   'plugins'
);

echo "<div class='card m-3'>";
echo "<div class='card-header d-flex justify-content-between align-items-center'>";
echo "<h3 class='mb-0'>" . Html::entities_deep($catalog['name']) . "</h3>";
echo "<span class='badge bg-secondary'>" . Html::entities_deep($moduleKey) . "</span>";
echo "</div>";

echo "<div class='card-body'>";

if (empty($tables)) {
   echo "<div class='alert alert-info mb-0'>";
   echo "<i class='ti ti-info-circle me-2'></i>";
   echo __('Este módulo não mantém tabelas próprias ou os metadados ainda não foram cadastrados.', 'nextool');
   echo "</div>";
} else {
   echo "<p class='text-muted'>" . __('Tabelas detectadas para este módulo. Útil para auditoria ou exportação antes de apagar os dados.', 'nextool') . "</p>";
   echo "<table class='table table-striped'>";
   echo "<thead><tr><th>" . __('Tabela', 'nextool') . "</th><th class='text-end'>" . __('Registros', 'nextool') . "</th></tr></thead>";
   echo "<tbody>";

   global $DB;

   foreach ($tables as $table) {
      echo "<tr>";
      echo "<td><code>" . Html::entities_deep($table) . "</code></td>";
      if ($DB->tableExists($table)) {
         $count = countElementsInTable($table);
         echo "<td class='text-end'><span class='badge bg-primary'>" . (int)$count . "</span></td>";
      } else {
         echo "<td class='text-end'><span class='badge bg-secondary'>" . __('Inexistente', 'nextool') . "</span></td>";
      }
      echo "</tr>";
   }

   echo "</tbody>";
   echo "</table>";
}

// Volta para a tela de configuração padrão do GLPI com a aba de módulos selecionada
$configUrl = Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?id=1&forcetab=PluginNextoolMainConfig$1';
echo "<a href='" . Html::entities_deep($configUrl) . "' class='btn btn-secondary'>";
echo "<i class='ti ti-arrow-left me-1'></i>" . __('Voltar para configuração', 'nextool');
echo "</a>";

echo "</div></div>";

Html::footer();


