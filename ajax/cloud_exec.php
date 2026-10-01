<?php
/**
 * Cloud link v1 -- ponto de entrada do push do cérebro (`/plugin/v1/exec`).
 *
 * Stateless por desenho: sem cookie, sem CSRF, sem sessão do GLPI. A autorização é a assinatura
 * HMAC do contrato v1 (PluginNextoolCloudRequestGuard) e a sessão efêmera do ator é montada pelo
 * executor. Molde do boot: `modules/telegrambot/ajax/webhook.php`.
 *
 * Respostas: 200 com o envelope do contrato; 401/409/413/503 só para falhas ANTES do pipeline.
 * O 401 é genérico de propósito -- não revela se o cloud link existe nesta instância. O GLPI 10 é
 * recusado DENTRO do pipeline (200 `unavailable`), depois da assinatura: a base só responde envelope
 * a quem assinou certo, e o cérebro promove a URL por envelope válido.
 *
 * @since 6.23.0
 */

// Boot do GLPI (10 e 11). No 11 o roteador já carregou o core; no 10 é preciso incluir.
if (!defined('GLPI_ROOT')) {
   define('GLPI_ROOT', dirname(__DIR__, 3));
   include GLPI_ROOT . '/inc/includes.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
   http_response_code(405);
   echo json_encode(['ok' => false, 'error' => ['code' => 'validation', 'message' => 'method', 'retryable' => false]]);
   exit;
}

if (!defined('NEXTOOL_PHP_DIR')) {
   define('NEXTOOL_PHP_DIR', dirname(__DIR__));
}
require_once NEXTOOL_PHP_DIR . '/inc/cloudrequestguard.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/cloudsession.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/cloudexecutor.class.php';

// Teto ANTES de ler o corpo: pelo Content-Length declarado e, sem ele, lendo no máximo um byte além
// do limite. O `file_get_contents('php://input')` montava o corpo inteiro numa string (até o
// post_max_size, alto no GLPI por causa dos anexos) para só então o guard recusar -- e este endereço é
// público em todo GLPI com a base, com ou sem cloud link.
$maxCorpo = PluginNextoolCloudRequestGuard::MAX_BODY_BYTES;
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxCorpo) {
   $body = '';
   $auth = ['ok' => false, 'code' => 'payload_too_large', 'reason' => 'body_too_large'];
   // Conta no resumo de saúde do /validate como as recusas que passam pelo guard (contrato 1.1, O1).
   PluginNextoolCloudRequestGuard::noteRefusal('body_too_large');
} else {
   $entrada = fopen('php://input', 'rb');
   $body    = $entrada !== false ? (string) stream_get_contents($entrada, $maxCorpo + 1) : '';
   $auth    = PluginNextoolCloudRequestGuard::verify(
      PluginNextoolCloudRequestGuard::fromServer($_SERVER),
      $body
   );
}

if (!$auth['ok']) {
   // Motivo REAL da recusa, só no log interno (nunca no envelope). Sem valores de header nem
   // corpo: só o nome do motivo e o ambiente declarado, que não é segredo.
   Toolbox::logInFile('plugin_nextool_cloud', sprintf(
      "[CLOUD_EXEC] recusado: %s (ambiente declarado: %s)\n",
      (string) ($auth['reason'] ?? $auth['code']),
      substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_SERVER['HTTP_X_NX_AMBIENTE'] ?? '')), 0, 64) ?: '-'
   ));
   // O envelope só fala os códigos do contrato. `replay` e `payload_too_large` são erros do
   // chamador (validation); o resto é 401 genérico, que não revela se o cloud link existe aqui.
   // Contrato 1.1 (§2.13.5, O1): `clock_skew` e `ambiente_mismatch` só existem DEPOIS de uma
   // assinatura válida (o guard não os devolve antes dela), e quem provou a chave recebe o motivo. O
   // `clock_skew` leva o horário do GLPI, para o cérebro mostrar o desvio.
   $map = [
      'payload_too_large' => [413, 'validation'],
      'replay'            => [409, 'validation'],
      'unavailable'       => [503, 'unavailable'],
      'clock_skew'        => [401, 'clock_skew'],
      'ambiente_mismatch' => [401, 'ambiente_mismatch'],
   ];
   [$status, $code] = $map[$auth['code']] ?? [401, 'unauthorized'];
   $erro = [
      'code'      => $code,
      'message'   => in_array($code, ['clock_skew', 'ambiente_mismatch'], true) ? (string) ($auth['message'] ?? '') : '',
      'retryable' => $status === 503,
   ];
   if ($code === 'clock_skew') {
      $erro['glpi_time'] = (string) ($auth['glpi_time'] ?? date('c'));
   }
   http_response_code($status);
   echo json_encode(['ok' => false, 'error' => $erro], JSON_UNESCAPED_UNICODE);
   exit;
}

$payload = json_decode($body, true);
if (!is_array($payload)) {
   // Assinatura válida sobre um corpo que não é JSON. UTF-8 inválido (inclusive um surrogate solto num
   // escape \u, que o JSON.stringify produz ao cortar um emoji ao meio) tem motivo próprio: é erro de
   // quem montou o corpo, e o erro explícito poupa a depuração. Nada é executado nem gravado.
   $motivo = in_array(json_last_error(), [JSON_ERROR_UTF8, JSON_ERROR_UTF16], true) ? 'body_utf8' : 'body';
   Toolbox::logInFile('plugin_nextool_cloud', sprintf("[CLOUD_EXEC] corpo recusado: %s\n", $motivo));
   http_response_code(200);
   echo PluginNextoolCloudExecutor::encode(PluginNextoolCloudExecutor::error('validation', $motivo, false));
   exit;
}

// `encode()` e não `json_encode()` direto: com texto em UTF-8 inválido na resposta, o json_encode
// devolvia false e a resposta saía 200 sem corpo (LO-02).
http_response_code(200);
echo PluginNextoolCloudExecutor::encode(PluginNextoolCloudExecutor::run($payload));
