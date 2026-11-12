<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Profile Form Handler
 * -------------------------------------------------------------------------
 * Processa POST do formulário de permissões NexTool na aba de perfis.
 * Salva os direitos via Profile::update() e redireciona de volta.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkRight('profile', UPDATE);

if (isset($_POST['update'])) {
   $profile = new PluginNextoolProfile();
   $profile->update($_POST);
   // A matriz do perfil simplificado não exibe o super-direito nem o bloco base, mas este POST
   // aceitaria os campos se viessem montados à mão. O saneamento (idempotente, o mesmo do upgrade)
   // zera os dois em todo perfil helpdesk logo após gravar; os gates também negam (canBase).
   try {
      require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
      PluginNextoolPermissionManager::cleanHelpdeskProfileRights();
   } catch (\Throwable $e) {
      Toolbox::logInFile('plugin_nextool', 'Saneamento de perfil helpdesk falhou: ' . $e->getMessage() . "\n");
   }
   Html::back();
}

Html::back();
