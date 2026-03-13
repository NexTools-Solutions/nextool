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
   Html::back();
}

Html::back();
