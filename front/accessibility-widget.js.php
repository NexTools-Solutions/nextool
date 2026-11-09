<?php
/**
 * Wrapper GLPI 10 -- módulo Accessibility (accessibility.widget.js.php).
 *
 * O GLPI 10 só emite asset de plugin cujo arquivo exista dentro do plugin e não aceita query
 * string no hook. Este arquivo existe para isso: fixa módulo/arquivo e delega ao module_assets.php.
 * No GLPI 11/12 o módulo registra o roteador direto (com fv) e este wrapper não é usado.
 *
 * @license GPLv3+
 */
$_GET['module'] = 'accessibility';
$_GET['file']   = 'accessibility.widget.js.php';

require __DIR__ . '/module_assets.php';
