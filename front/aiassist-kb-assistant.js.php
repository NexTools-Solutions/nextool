<?php
/**
 * Nextools - Wrapper Assistente do Artigo (AI Assist)
 *
 * Wrapper de asset do módulo AI Assist para GLPI 10. Delega para module_assets.php.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

$_GET['module'] = 'aiassist';
$_GET['file']   = 'aiassist-kb-assistant.js.php';

require __DIR__ . '/module_assets.php';
