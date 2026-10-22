<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Fonte da vitrine de serviço (PluginNextoolOfferBanner)
 * -------------------------------------------------------------------------
 * Entrega a Fraunces (SIL Open Font License 1.1, inc/fonts/OFL-Fraunces.txt) embutida no plugin: o título
 * da vitrine fica igual ao aprovado pelo owner sem o GLPI do cliente chamar o Google Fonts (que receberia o
 * IP de cada usuário). Só os dois arquivos da lista; nada de sessão nem banco; cache de 1 ano no navegador
 * (o nome do arquivo muda se a fonte mudar).
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

$nextoolOfferFonts = [
   'regular' => 'fraunces-latin.woff2',
   'italic'  => 'fraunces-italic-latin.woff2',
];
if (session_status() === PHP_SESSION_ACTIVE) {
   session_write_close();
}
header_remove('Expires');
header_remove('Pragma');
$nextoolOfferFontKey = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';
$nextoolOfferFontPath = isset($nextoolOfferFonts[$nextoolOfferFontKey])
   ? dirname(__DIR__) . '/inc/fonts/' . $nextoolOfferFonts[$nextoolOfferFontKey] : '';
if ($nextoolOfferFontPath === '' || !is_file($nextoolOfferFontPath)) {
   http_response_code(404);
   exit;
}
header('Content-Type: font/woff2');
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . (string) filesize($nextoolOfferFontPath));
readfile($nextoolOfferFontPath);
exit;
