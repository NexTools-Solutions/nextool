<?php
/**
 * Nextools - Wrapper Sugestão de KB CSS (AI Assist)
 *
 * Wrapper de asset do módulo AI Assist para GLPI 10. Delega para module_assets.php.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

$_GET['module'] = 'aiassist';
$_GET['file']   = 'aiassist-kbsuggest.css.php';

require __DIR__ . '/module_assets.php';
