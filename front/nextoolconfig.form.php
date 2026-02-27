<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Config Form (Layout)
 * -------------------------------------------------------------------------
 * Página standalone de configuração do plugin com abas verticais: Módulos,
 * Contato, Licenciamento, Logs e abas dinâmicas por módulo.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   include(__DIR__ . '/../../inc/includes.php');
}

Session::checkRight('config', READ);

require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/nextoolmainconfig.class.php';

if (!PluginNextoolPermissionManager::canViewAnyModule() && !PluginNextoolPermissionManager::canAccessAdminTabs()) {
   Session::addMessageAfterRedirect(__('Você não tem permissão para acessar a configuração do NexTool.', 'nextool'), false, ERROR);
   Html::back();
   exit;
}

$item = new PluginNextoolMainConfig();
$id = isset($_GET['id']) ? (int) $_GET['id'] : PluginNextoolMainConfig::CONFIG_ID;
if ($id <= 0) {
   $id = PluginNextoolMainConfig::CONFIG_ID;
}

if (!$item->getFromDB($id)) {
   $item->add(['id' => $id]);
   $item->getFromDB($id);
}

// 'nextools' para destacar o menu Nextools em vez de Configurar > Plug-ins
Html::header(
   PluginNextoolMainConfig::getTypeName(),
   $_SERVER['PHP_SELF'],
   'nextools'
);

// Respeita forcetab da URL para que links do menu abram a aba correta (igual Config > Geral)
$validTabs = PluginNextoolMainConfig::getValidTabIds();
$forcetab = isset($_GET['forcetab']) && in_array($_GET['forcetab'], $validTabs, true)
   ? $_GET['forcetab']
   : 'PluginNextoolMainConfig$1';

// O GLPI usa $_SESSION['glpi_tabs'][itemtype] para lembrar a última aba; sobrescrever quando forcetab na URL
$tabKey = strtolower($item::getType());
if (!isset($_SESSION['glpi_tabs'])) {
   $_SESSION['glpi_tabs'] = [];
}
$_SESSION['glpi_tabs'][$tabKey] = $forcetab;

// Garante que nextoolValidateLicense e funções relacionadas existam na página principal.
// Necessário porque o conteúdo das abas pode ser carregado via AJAX e scripts injetados
// por innerHTML não executam; incluir aqui garante disponibilidade global.
require_once GLPI_ROOT . '/plugins/nextool/inc/licenseconfig.class.php';
$licenseConfig = PluginNextoolLicenseConfig::getDefaultConfig();
$policiesAcceptedAt = $licenseConfig['policies_accepted_at'] ?? null;
$hasAcceptedPolicies = !empty($policiesAcceptedAt);
include GLPI_ROOT . '/plugins/nextool/front/config.form.scripts.inc.php';

// Formulário fallback para o botão Sincronizar na aba Módulos (onde o hero não está dentro de form).
$configSaveUrl = Plugin::getWebDir('nextool') . '/front/config.save.php';
echo '<form id="nextoolSyncForm" method="post" action="' . htmlspecialchars($configSaveUrl) . '" style="display:none;">';
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo Html::hidden('forcetab', ['value' => $forcetab]);
echo '</form>';

$options = [
   'id'       => $id,
   'target'   => Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php',
   'forcetab' => $forcetab,
];

$item->display($options);

Html::footer();
