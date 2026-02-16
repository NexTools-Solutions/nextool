<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Config Redirect
 * -------------------------------------------------------------------------
 * Redireciona para a página de configuração do plugin. Chamado quando o
 * usuário clica em "Configurar" na lista de plugins (Configurar → Plugins).
 * -------------------------------------------------------------------------
 * @author    Richard Loureiro
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

// Redireciona para a página standalone com abas verticais
$target = Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?id=1&forcetab=PluginNextoolMainConfig$1';

Html::redirect($target);

