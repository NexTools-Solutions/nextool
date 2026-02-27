<?php
/**
 * Nextools - Column Resize User Preferences (AJAX)
 *
 * Endpoint para obter/salvar preferências de redimensionamento por usuário.
 *
 * GLPI 10:
 * - Rotas em `/ajax/*` têm validação CSRF automática no `inc/includes.php`
 *   (via header `X-Glpi-Csrf-Token`).
 * - Não chamar Session::validateCSRF()/checkCSRF() aqui para não quebrar contrato JSON.
 *
 * GET:  ?path=/front/ticket.php
 *   -> { widths: <array|null>, reduceState: <array|null> }
 *
 * POST: path, widths_json, reduce_state_json (form urlencoded ou JSON body)
 *   -> { ok: true|false }
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json; charset=UTF-8');

require_once GLPI_ROOT . '/plugins/nextool/inc/modulespath.inc.php';

// O módulo columnresize é opcional; se não estiver presente, não podemos fatal-error aqui.
$prefClassPath = NEXTOOL_MODULES_BASE . '/columnresize/inc/columnresizeuserpref.class.php';
if (!is_file($prefClassPath)) {
   http_response_code(404);
   echo json_encode(['error' => 'module columnresize not installed']);
   exit;
}
require_once $prefClassPath;

$path = isset($_REQUEST['path']) ? trim((string) $_REQUEST['path']) : '';
if ($path === '' || strpos($path, '..') !== false) {
   http_response_code(400);
   echo json_encode(['error' => 'path invalid']);
   exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
   $data = PluginNextoolColumnresizeUserPref::getForCurrentUser($path);
   if ($data === null) {
      echo json_encode(['widths' => null, 'reduceState' => null]);
      exit;
   }

   echo json_encode([
      'widths'      => $data['widths'],
      'reduceState' => $data['reduceState'],
   ]);
   exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   $widths_json = isset($_POST['widths_json']) ? (string) $_POST['widths_json'] : '';
   $reduce_json = isset($_POST['reduce_state_json']) ? (string) $_POST['reduce_state_json'] : '';

   // Aceitar body JSON (clientes futuros), mantendo compat com form urlencoded (jQuery).
   if (isset($_SERVER['CONTENT_TYPE']) && strpos((string) $_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
      $raw = file_get_contents('php://input');
      $body = json_decode($raw, true);
      if (is_array($body)) {
         if (array_key_exists('widths_json', $body)) {
            $widths_json = is_string($body['widths_json']) ? $body['widths_json'] : json_encode($body['widths_json']);
         }
         if (array_key_exists('reduce_state_json', $body)) {
            $reduce_json = is_string($body['reduce_state_json']) ? $body['reduce_state_json'] : json_encode($body['reduce_state_json']);
         }
      }
   }

   $ok = PluginNextoolColumnresizeUserPref::saveForCurrentUser($path, $widths_json, $reduce_json);
   echo json_encode(['ok' => (bool) $ok]);
   exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
exit;

