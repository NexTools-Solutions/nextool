<?php
/**
 * Nextools - Config Redirect
 *
 * Redireciona para a página de configuração do plugin (Configurar → Plugins).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

// Redireciona para a página standalone com abas verticais
$target = Plugin::getWebDir('nextool') . '/front/nextoolconfig.form.php?id=1&forcetab=PluginNextoolMainConfig$1';

Html::redirect($target);

