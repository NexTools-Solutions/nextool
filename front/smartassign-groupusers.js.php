<?php
/**
 * Nextools - Wrapper JS da coluna Distribuição na aba Usuários do grupo (Smart Assign)
 *
 * Wrapper de asset do módulo Smart Assign para GLPI 10. Delega para module_assets.php.
 * O GLPI 10 valida o asset de plugin com file_exists() e descarta caminho com query string
 * (front/module_assets.php?module=...): este arquivo real é o que ele aceita. A URL fica fixa
 * (só o ?v= da versão do plugin), então o asset é servido sem cache longo (marca abaixo).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

$_GET['module'] = 'smartassign';
$_GET['file']   = 'smartassign.groupusers.js.php';
$GLOBALS['nextool_asset_fixed_url'] = true;

require __DIR__ . '/module_assets.php';
