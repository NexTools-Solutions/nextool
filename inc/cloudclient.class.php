<?php
/**
 * Cloud link v1 -- SAÍDA: a base chamando o cérebro (contrato §2.10/§2.11).
 *
 * Espelho do `PluginNextoolCloudRequestGuard`, que verifica o que o cérebro manda para cá. Mesma
 * canônica (PluginNextoolCloudSignature), trocando o caminho lógico e a chave:
 *
 *   v1\nPOST\n<caminho lógico>\n<ambiente>\n<ts>\n<nonce>\n<sha256 hex do corpo>
 *   HMAC-SHA256 com o `outbound` (string hex de 64, NÃO os bytes crus)
 *
 * URL = `api_url` do vínculo + `/webhook/v1` + caminho lógico. O corpo assinado é EXATAMENTE o que
 * vai no POST: nada de re-serializar depois de assinar. Toda chamada leva o `X-Nx-Kid` da chave local
 * (contrato 1.1, §2.13.1), fora da canônica; o `401 E_AUTH_KID` que o cérebro devolve quando o kid não
 * é o dele pede uma ressincronização da credencial (PluginNextoolCloudResync).
 *
 * Regras de engenharia de feature online (hybrid-features §4): timeout curto, falha de rede vira
 * resultado e nunca exceção, e nada disto roda no caminho síncrono de uma tela sem que ela se
 * declare esperando.
 *
 * Transporte (auditoria de 2026-09-24, LO-03 e LO-04): o cliente HTTP do próprio GLPI
 * (`Toolbox::getGuzzleClient`), que aplica o proxy de Configurar > Geral. Até a 6.24.1 era um curl
 * próprio, e um GLPI que só sai pela internet por proxy nunca falava com a nuvem. Só https, e sem
 * seguir redirecionamento: o corpo assinado leva o token do bot e não vai para outro endereço.
 *
 * @since 6.24.0
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/cloudsignature.class.php';

class PluginNextoolCloudClient {

   // Versão do contrato: PluginNextoolCloudSignature::CONTRACT, definida num lugar só (LO-06). Sem
   // apelido aqui: constante que referencia outra classe é o antipadrão B1 do permission-guard.

   /**
    * Resposta do cérebro quando o `X-Nx-Kid` não é o da chave atual do ambiente (contrato 1.1): a base
    * está com a chave velha, ou o cérebro está. Distinguível de propósito, para pedir a ressincronização.
    */
   public const E_AUTH_KID = 'E_AUTH_KID';

   /** Timeout total da chamada, em segundos. O cérebro chama o Telegram antes de responder. */
   private const TIMEOUT = 15;

   /** Timeout de conexão, em segundos. */
   private const CONNECT_TIMEOUT = 5;

   /**
    * Teto do timeout por chamada (B1, nextool-dev#278): um módulo pede mais tempo que o padrão (a
    * Chamada HTTP do Workflow espera o destino do cliente + a margem do cérebro), mas nunca segura um
    * worker do PHP-FPM sem limite.
    */
   public const MAX_TIMEOUT = 60;

   /** Mesma serialização no envio e na conta do tamanho da declaração. */
   private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

   /**
    * Envia um POST assinado ao cérebro.
    *
    * @param string $path    caminho LÓGICO, com barra inicial (ex.: `/channels/telegram/token`)
    * @param array  $body    corpo; `request_id` é gerado se faltar
    * @param int    $timeout tempo total em segundos (B1); o padrão de 15 s vale para quem não passa nada,
    *                        e o pedido fica entre 1 e MAX_TIMEOUT
    * @param int    $connectTimeout tempo de conexão em segundos (padrão 5), nunca acima do total
    * @return array{ok:bool, http:int, data:?array, error:string}
    *         `error` é um código curto (`no_link`, `inactive`, `bad_body`, `network`, `http_<n>`,
    *         `bad_json` ou o `error.code` do envelope) -- NUNCA contém o corpo enviado.
    */
   public static function post(string $path, array $body, int $timeout = self::TIMEOUT, int $connectTimeout = self::CONNECT_TIMEOUT): array {
      $pedido = self::prepare($path, $body);
      if (isset($pedido['fail'])) {
         return $pedido['fail'];
      }
      $cred = $pedido['cred'];

      [$http, $raw] = self::send($pedido['url'], $pedido['json'], $pedido['headers'], $timeout, $connectTimeout);

      if ($raw === null || $http === 0) {
         return self::fail(0, 'network');
      }
      $data = json_decode((string) $raw, true);
      if (!is_array($data)) {
         return self::fail($http, $http >= 200 && $http < 300 ? 'bad_json' : 'http_' . $http);
      }
      if ($http >= 200 && $http < 300 && !empty($data['ok'])) {
         return ['ok' => true, 'http' => $http, 'data' => $data, 'error' => ''];
      }
      $code = (string) ($data['error']['code'] ?? '');
      self::handleAuthKid($http, $code, (string) $cred['kid']);

      return ['ok' => false, 'http' => $http, 'data' => $data, 'error' => $code !== '' ? $code : 'http_' . $http];
   }

   /**
    * Credencial, corpo e cabeçalhos assinados de um POST ao cérebro, comuns ao post() e ao fetchFile().
    *
    * @return array{cred?:array, url?:string, json?:string, headers?:array, fail?:array}
    */
   private static function prepare(string $path, array $body): array {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      // Vínculo com api_url que não seja https já volta null daqui: nada é enviado.
      $cred = PluginNextoolCloudCreds::get();
      if ($cred === null) {
         return ['fail' => self::fail(0, 'no_link')];
      }
      if (!PluginNextoolCloudCreds::isActive($cred)) {
         return ['fail' => self::fail(0, 'inactive')];
      }
      if (empty($body['request_id'])) {
         $body['request_id'] = 'req-' . bin2hex(random_bytes(12));
      }

      $json = json_encode($body, self::JSON_FLAGS);
      if ($json === false) {
         // Assinar e mandar um corpo vazio só produziria uma recusa sem explicação do outro lado.
         return ['fail' => self::fail(0, 'bad_body')];
      }
      $ts    = (string) time();
      $nonce = 'n-' . bin2hex(random_bytes(16));
      $sig   = self::sign($path, $cred['ambiente'], $ts, $nonce, $json, $cred['outbound']);

      return [
         'cred'    => $cred,
         'url'     => rtrim($cred['api_url'], '/') . '/webhook/v1' . $path,
         'json'    => $json,
         'headers' => [
            'Content-Type'   => 'application/json',
            'User-Agent'     => 'NexTool/' . (defined('PLUGIN_NEXTOOL_VERSION') ? PLUGIN_NEXTOOL_VERSION : '0'),
            'X-Nx-Contract'  => PluginNextoolCloudSignature::CONTRACT,
            'X-Nx-Ambiente'  => $cred['ambiente'],
            'X-Nx-Timestamp' => $ts,
            'X-Nx-Nonce'     => $nonce,
            'X-Nx-Signature' => $sig,
            // Contrato 1.1: o id da chave que assinou, sempre. Fora da canônica: não muda a assinatura.
            'X-Nx-Kid'       => $cred['kid'],
         ],
      ];
   }

   /** Teto de um arquivo buscado ao cérebro (contrato §8.9), contado no stream. */
   public const FILE_MAX_BYTES = 16777216;

   /** Tempo total da busca de um arquivo (contrato §8.9). */
   public const FILE_TIMEOUT = 20;

   /**
    * Busca um ARQUIVO no cérebro por POST assinado (hoje, `/whatsapp/media/get`, contrato §8.9): a mesma
    * assinatura do post(), mas a resposta de sucesso são bytes, gravados num temporário que quem chama APAGA.
    * Só https, sem redirect e teto contado no stream (o `Content-Length` pode mentir). Recusa do cérebro vem
    * em JSON e volta com o código, como no post().
    *
    * @return array{ok:bool, http:int, error:string, path?:string, content_type?:string, file_name?:string, size?:int}
    *         `error`: `no_link`, `inactive`, `bad_body`, `network`, `file_too_large`, `http_<n>` ou o `error.code`
    */
   public static function fetchFile(string $path, array $body, int $maxBytes = self::FILE_MAX_BYTES, int $timeout = self::FILE_TIMEOUT): array {
      $pedido = self::prepare($path, $body);
      if (isset($pedido['fail'])) {
         return ['ok' => false, 'http' => 0, 'error' => $pedido['fail']['error']];
      }
      [$timeout, $connect] = self::clampTimeouts($timeout, self::CONNECT_TIMEOUT);
      $options = [
         'timeout'         => $timeout,
         'connect_timeout' => $connect,
         'allow_redirects' => false,
         'http_errors'     => false,
         'stream'          => true,
      ];
      if (defined('CURLOPT_PROTOCOLS') && defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
         $options['curl'] = [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS];
      }
      $tmp = tempnam(defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir(), 'nxf');
      if ($tmp === false) {
         return ['ok' => false, 'http' => 0, 'error' => 'network'];
      }
      try {
         $client = method_exists('Toolbox', 'getGuzzleClient')
            ? Toolbox::getGuzzleClient($options)
            : new \GuzzleHttp\Client($options);
         $resp   = $client->request('POST', $pedido['url'], ['headers' => $pedido['headers'], 'body' => $pedido['json']]);
         $http   = (int) $resp->getStatusCode();
         $tipo   = strtolower(trim(explode(';', $resp->getHeaderLine('Content-Type'))[0]));
         $stream = $resp->getBody();

         if ($http < 200 || $http >= 300 || $tipo === 'application/json') {
            // Recusa (ou sucesso fora do contrato) em JSON: lê no máximo 64 KB e devolve o código.
            $raw  = $stream->read(65536);
            $data = json_decode((string) $raw, true);
            @unlink($tmp);
            // `error` é o envelope `{code, ...}` (§2.10) ou, em recusas simples, só o código em texto.
            $err  = is_array($data) ? ($data['error'] ?? null) : null;
            $code = is_array($err) ? (string) ($err['code'] ?? '') : (is_string($err) ? $err : '');
            if ($http >= 200 && $http < 300 && $code === '') {
               $code = 'bad_json';
            }
            self::handleAuthKid($http, $code, (string) $pedido['cred']['kid']);
            return ['ok' => false, 'http' => $http, 'error' => $code !== '' ? $code : 'http_' . $http];
         }

         $fh    = fopen($tmp, 'wb');
         $total = 0;
         while (!$stream->eof()) {
            $parte = $stream->read(65536);
            $total += strlen($parte);
            if ($total > $maxBytes) {
               fclose($fh);
               @unlink($tmp);
               return ['ok' => false, 'http' => $http, 'error' => 'file_too_large'];
            }
            fwrite($fh, $parte);
         }
         fclose($fh);
      } catch (\Throwable $e) {
         // Rede, timeout, TLS ou protocolo recusado: nada do pedido vai para o log.
         @unlink($tmp);
         return ['ok' => false, 'http' => 0, 'error' => 'network'];
      }

      return [
         'ok'           => true,
         'http'         => $http,
         'error'        => '',
         'path'         => $tmp,
         'content_type' => $tipo,
         'file_name'    => self::fileNameFromDisposition($resp->getHeaderLine('Content-Disposition')),
         'size'         => $total,
      ];
   }

   /** Nome sugerido no `Content-Disposition` (`filename*=UTF-8''...` ou `filename="..."`), sem caminho. */
   public static function fileNameFromDisposition(string $valor): string {
      $nome = '';
      if (preg_match("/filename\\*\\s*=\\s*UTF-8''([^;]+)/i", $valor, $m)) {
         $nome = rawurldecode(trim($m[1]));
      } elseif (preg_match('/filename\\s*=\\s*"([^"]*)"/i', $valor, $m) || preg_match('/filename\\s*=\\s*([^;]+)/i', $valor, $m)) {
         $nome = trim($m[1]);
      }

      return trim(basename(str_replace('\\', '/', $nome)));
   }

   /**
    * `401 E_AUTH_KID` (contrato 1.1, §2.13.1): o kid desta chamada não é o do cérebro. Pede o mesmo
    * /validate de ressincronização da entrada (mesmo teto de um a cada 5 min, depois da resposta).
    * Quem chamou recebe o erro como sempre e tenta de novo no ciclo seguinte: repetir agora usaria a
    * mesma chave. Pública para o E2E.
    *
    * @return bool true = pediu a ressincronização
    */
   public static function handleAuthKid(int $http, string $code, string $kidLocal = ''): bool {
      if ($http !== 401 || $code !== self::E_AUTH_KID) {
         return false;
      }
      require_once NEXTOOL_PHP_DIR . '/inc/cloudresync.class.php';
      PluginNextoolCloudResync::request(self::E_AUTH_KID, $kidLocal !== '' ? 'local=' . $kidLocal : '');

      return true;
   }

   /** Caminho lógico da rota que recebe a URL do exec (contrato §2.12). */
   public const ENDPOINT_PATH = '/cloud/v1/endpoint';

   /**
    * Impressão digital da última declaração ACEITA (URL + ambiente + `api_url` + kid), recusas
    * consecutivas da URL (`E_ENDPOINT_REJECTED`; rede e 5xx não contam, são falha nossa) e o início da
    * sequência de recusas (timestamp com microssegundos, entra na chave do alerta). Ficam em
    * PluginNextoolCloudState, no contexto `plugin:nextool_cloud`.
    */
   private const CFG_REPORTED      = 'endpoint_reported';
   private const CFG_REJECTS       = 'endpoint_rejects';
   private const CFG_REJECTS_SINCE = 'endpoint_rejects_since';

   /** Tarefa de redeclaração em PluginNextoolCloudDeferred. */
   public const REPORT_TASK = 'endpoint_report';

   /** Redeclaração depois de ativar/desativar um módulo com ferramentas de entrada (B2). */
   public const REPORT_INBOUND_TASK = 'endpoint_report_inbound';

   /** Só no E2E (CLI): fixa a resposta de hasInboundTools(). */
   public static ?bool $inboundOverride = null;

   /** Na 3ª recusa seguida (~18 h com o ciclo de 6 h do catalogSync) vira alerta. */
   private const REJECT_ALERT_AFTER = 3;

   /** Família do alerta local (prefixo até o ':' no AlertManager). Pública para a tarefa cloudPurge. */
   public const ALERT_FAMILY = 'cloud_endpoint_rejected:';

   /** Família do alerta do estado do destino devolvido pelo cérebro (contrato 1.1, §2.13.2). */
   public const DESTINO_FAMILY = 'cloud_endpoint_destino:';

   /** `pendente` só vira aviso depois disto: a 1ª URL é promovida na 1ª chamada, e a troca espera uma pessoa. */
   private const PENDENTE_AVISO_APOS = 86400;

   /** A declaração cabe folgada em 64 KB (contrato 1.1, §2.13.4); acima disso, é reduzida. */
   public const MAX_DECLARATION_BYTES = 65536;

   /**
    * URL pública do executor desta instalação, montada pelo PRÓPRIO plugin.
    *
    * O cérebro chegou a derivá-la do domínio do phone-home, o que erra em GLPI num subcaminho
    * (`/glpi`): assinava certo e chamava o lugar errado. Quem sabe onde o plugin está é o plugin.
    * '' quando o GLPI não tem URL absoluta configurada.
    */
   public static function execUrl(): string {
      global $CFG_GLPI;
      $urlBase = (string) Config::getConfigurationValue('core', 'url_base');
      if ($urlBase === '' && isset($CFG_GLPI['url_base'])) {
         $urlBase = (string) $CFG_GLPI['url_base'];
      }

      return self::execUrlFor($urlBase);
   }

   /**
    * URL do exec a partir de um `url_base` ('' quando ele não é absoluto).
    *
    * O `url_base` JÁ contém o subcaminho do GLPI (`https://cliente/glpi`). Até a 6.24.1 somava-se o
    * `Plugin::getWebDir('nextool')`, que prefixa o `root_doc` (`/glpi`), e a URL declarada saía
    * `.../glpi/glpi/plugins/...`: o cérebro a aceitava, ela nunca respondia, e nada avisava. Por isso
    * o caminho do plugin entra aqui SEM o root_doc.
    */
   public static function execUrlFor(string $urlBase): string {
      $urlBase = rtrim($urlBase, '/');
      if ($urlBase === '' || !preg_match('#^https?://#i', $urlBase)) {
         return '';
      }

      return $urlBase . '/' . self::pluginPath() . '/ajax/cloud_exec.php';
   }

   /**
    * Caminho do plugin relativo à raiz do GLPI, sem o `root_doc`.
    *
    * No GLPI 11 todo recurso de plugin é servido em `/plugins/<chave>`, esteja ele em `plugins/` ou em
    * `marketplace/`, e o `getWebDir()` está depreciado. No GLPI 10 o Apache serve o diretório físico,
    * então vale o que o `getWebDir(..., false)` disser (`plugins/nextool` ou `marketplace/nextool`).
    */
   public static function pluginPath(): string {
      if (defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '11.0.0-dev', '>=')) {
         return 'plugins/nextool';
      }
      $rel = Plugin::getWebDir('nextool', false);

      return (is_string($rel) && $rel !== '') ? trim($rel, '/') : 'plugins/nextool';
   }

   /**
    * Declara ao cérebro onde está o executor (contrato §2.12), com o anúncio do contrato 1.1 (§2.13.4).
    *
    * Reafirma em TODO ciclo do catalogSync, mesmo sem mudança (§2.13.2). Até a 6.24.1 só enviava
    * quando a impressão digital mudava, e duas coisas ficavam sem volta: a URL aceita que passou a
    * redirecionar e o destino apagado no cérebro (revogação desfeita, `delete` + `provision`) com a
    * mesma URL, que nunca era redeclarada. A resposta traz o `destino` (em uso, pendente, conflito ou
    * recusado), que vira aviso local.
    *
    * O cérebro guarda a URL como PENDENTE e só a promove depois que ela responder a uma chamada real
    * com envelope válido (a primeira URL do ambiente; a troca de uma URL em uso exige uma pessoa, K2).
    * Por isso uma URL errada aqui (ambiente clonado, `url_base` mal configurado) não derruba o cloud
    * link. A prova vale porque o executor só devolve envelope DEPOIS de conferir a assinatura -- se
    * isso mudar, a §2.12 muda junto.
    *
    * @return array{ok:bool, action:string, error:string, destino:string} action: skipped|reported|failed;
    *         destino: o `estado` devolvido ('' quando o cérebro ainda não o manda)
    */
   public static function reportEndpoint(): array {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
      $cred = PluginNextoolCloudCreds::get();
      $url  = self::execUrl();
      if ($cred === null || $url === '' || !PluginNextoolCloudCreds::isActive($cred)) {
         return ['ok' => true, 'action' => 'skipped', 'error' => '', 'destino' => ''];
      }

      $inbound = self::hasInboundTools();
      $res = self::post(self::ENDPOINT_PATH, self::declaracao($url));
      if (!$res['ok']) {
         if ($res['error'] === 'E_ENDPOINT_REJECTED') {
            self::handleRejection($url, (string) ($res['data']['error']['message'] ?? ''), $inbound);
         }
         // `E_AUTH_KID`: o post() já pediu a ressincronização. Não é recusa do endereço (não conta para o
         // alerta) e não se repete agora: a próxima declaração sai com a chave nova.
         return ['ok' => false, 'action' => 'failed', 'error' => $res['error'], 'destino' => ''];
      }
      PluginNextoolCloudState::set(self::CFG_REPORTED, self::fingerprint($url, $cred));
      self::onAccepted();
      $estado = self::applyDestino(is_array($res['data']) ? $res['data'] : [], $url, 0, $inbound);

      return ['ok' => true, 'action' => 'reported', 'error' => '', 'destino' => $estado];
   }

   /**
    * Algum módulo ativo atende a nuvem pela ENTRADA, isto é, declara ferramentas de nuvem? (B2,
    * nextool-dev#279)
    *
    * Sem nenhum, o vínculo só é usado para SAÍDA (a Chamada HTTP do Workflow): o cérebro nunca chama
    * este GLPI de volta, a URL declarada fica `pendente` para sempre e a de um GLPI de intranet (IP,
    * nome interno, http) é recusada. Os avisos de "pendente" e "recusado" são verdadeiros para quem
    * recebe chamadas e falsos aqui. A declaração continua (versão, capacidades e o `_base`); só os
    * avisos dependem disto. O `_base` (ping) não conta: ele existe para os módulos de entrada.
    */
   public static function hasInboundTools(): bool {
      if (self::$inboundOverride !== null && PHP_SAPI === 'cli') {
         return self::$inboundOverride;
      }
      $ferramentas = self::ferramentas();
      if (self::baseToolsDispatched()) {
         require_once NEXTOOL_PHP_DIR . '/inc/cloudbasetools.class.php';
         unset($ferramentas[PluginNextoolCloudBaseTools::SERVICE]);
      }

      return $ferramentas !== [];
   }

   /**
    * Um módulo com ferramentas de entrada foi ativado ou desativado (B2): redeclara logo depois da
    * resposta, sem esperar o ciclo de 6 h do catalogSync, e o aviso do endereço volta (ou se recolhe)
    * na hora. Sem vínculo ativo ou sem URL, nada.
    *
    * @return string `scheduled` | `inactive` | `no_url`
    */
   public static function afterInboundChange(): string {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      $cred = PluginNextoolCloudCreds::get();
      if ($cred === null || !PluginNextoolCloudCreds::isActive($cred)) {
         return 'inactive';
      }
      if (self::execUrl() === '') {
         return 'no_url';
      }
      require_once NEXTOOL_PHP_DIR . '/inc/clouddeferred.class.php';
      PluginNextoolCloudDeferred::add(self::REPORT_INBOUND_TASK, static function (): void {
         self::reportEndpoint();
      });

      return 'scheduled';
   }

   /**
    * `E_ENDPOINT_REJECTED` na declaração: conta para o aviso só se algum módulo recebe chamadas da nuvem
    * (B2). Num ambiente só de saída a recusa não tem efeito nenhum, e a sequência é zerada (o aviso que
    * estivesse aberto se recolhe). Pública para o E2E.
    */
   public static function handleRejection(string $url, string $motivo, bool $inbound): void {
      if ($inbound) {
         self::onRejected($url, $motivo);
         return;
      }
      require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
      self::onAccepted();
   }

   /**
    * Depois de um /validate que gravou o `managed_services` (PluginNextoolLicenseValidator): a
    * credencial do vínculo mudou desde a última declaração aceita?
    *
    *  - vínculo ativo com impressão digital diferente da última aceita (chave nova, ambiente novo,
    *    reativação, URL do GLPI mudou): redeclara LOGO depois, no mesmo pedido, inclusive no
    *    Sincronizar da tela -- mas depois de a resposta sair (PluginNextoolCloudDeferred), para a tela
    *    não esperar a rede. Caso do N7: desfazer uma revogação reprovisiona com chave nova, o mesmo
    *    ambiente e o mesmo `api_url`, e o cérebro está sem destino até alguém declarar;
    *  - vínculo suspenso ou revogado: apaga a impressão guardada, para a reativação sempre redeclarar.
    *
    * Servidor que não solta a resposta antes (mod_php) não redeclara aqui: a reafirmação do ciclo
    * seguinte (6 h) cobre.
    *
    * @return string `scheduled` | `unchanged` | `forgotten` | `inactive` | `no_url`
    */
   public static function afterCredentialSync(): string {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
      $cred = PluginNextoolCloudCreds::get();
      if ($cred === null || !PluginNextoolCloudCreds::isActive($cred)) {
         if (PluginNextoolCloudState::get(self::CFG_REPORTED) === null) {
            return 'inactive';
         }
         PluginNextoolCloudState::delete(self::CFG_REPORTED);

         return 'forgotten';
      }
      $url = self::execUrl();
      if ($url === '') {
         return 'no_url';
      }
      if (hash_equals((string) PluginNextoolCloudState::get(self::CFG_REPORTED), self::fingerprint($url, $cred))) {
         return 'unchanged';
      }
      require_once NEXTOOL_PHP_DIR . '/inc/clouddeferred.class.php';
      PluginNextoolCloudDeferred::add(self::REPORT_TASK, static function (): void {
         self::reportIfChanged();
      });

      return 'scheduled';
   }

   /**
    * Declara só se a impressão digital guardada não é a atual. É a tarefa agendada pelo
    * `afterCredentialSync()`: no cron, a declaração do próprio ciclo já pode ter acontecido no meio.
    *
    * @return array o retorno de reportEndpoint(), ou action `unchanged`/`skipped`
    */
   public static function reportIfChanged(): array {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
      $cred = PluginNextoolCloudCreds::get();
      $url  = self::execUrl();
      if ($cred === null || $url === '' || !PluginNextoolCloudCreds::isActive($cred)) {
         return ['ok' => true, 'action' => 'skipped', 'error' => '', 'destino' => ''];
      }
      if (hash_equals((string) PluginNextoolCloudState::get(self::CFG_REPORTED), self::fingerprint($url, $cred))) {
         return ['ok' => true, 'action' => 'unchanged', 'error' => '', 'destino' => ''];
      }

      return self::reportEndpoint();
   }

   /**
    * Impressão digital de uma declaração: muda quando a URL, o vínculo (ambiente, `api_url`) ou a
    * CHAVE (kid) mudam. Sem o kid, reprovisionar com o mesmo ambiente e o mesmo `api_url` não mudava
    * nada, e a base nunca redeclarava.
    */
   public static function fingerprint(string $url, array $cred): string {
      return hash('sha256', $url . '|' . ($cred['ambiente'] ?? '') . '|' . ($cred['api_url'] ?? '') . '|' . ($cred['kid'] ?? ''));
   }

   /**
    * Corpo da declaração (contrato 1.1, §2.13.4), assinado como qualquer corpo:
    *
    *   {"request_id", "exec_url", "base": {"versao", "glpi"}, "capacidades": [...],
    *    "ferramentas": {"<serviço>": {"versao", "tools": {"<tool>": {"kind", "args"}}}}}
    *
    * O cérebro anterior ao K2 lê só o `exec_url` e ignora o resto. Pública para o E2E.
    */
   public static function declaracao(string $execUrl): array {
      require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';
      $versao      = PluginNextoolConfig::getPluginVersion();
      $ferramentas = self::ferramentas($versao);

      return self::fitDeclaration([
         'request_id'  => 'req-' . bin2hex(random_bytes(12)),
         'exec_url'    => $execUrl,
         'base'        => ['versao' => $versao, 'glpi' => defined('GLPI_VERSION') ? (string) GLPI_VERSION : ''],
         'capacidades' => self::capacidades(),
         'ferramentas' => $ferramentas !== [] ? $ferramentas : new \stdClass(),
      ]);
   }

   /**
    * Capacidades do contrato 1.1 que esta base cumpre (§2.13.4). Capacidade anunciada passa a ser
    * EXIGIDA pelo cérebro deste ambiente (catraca), então só entra o que o código instalado faz de
    * fato: as duas do executor (I2) são conferidas pela presença dos códigos de erro nele.
    *
    * @return string[]
    */
   public static function capacidades(): array {
      $caps = ['kid-v1', 'destino-v1'];
      $f = NEXTOOL_PHP_DIR . '/inc/cloudexecutor.class.php';
      if (is_file($f)) {
         require_once $f;
      }
      if (defined('PluginNextoolCloudExecutor::E_OUTCOME_UNKNOWN')) {
         $caps[] = 'outcome-unknown-v1';
      }
      if (defined('PluginNextoolCloudExecutor::E_IDEMPOTENCY_MISMATCH')) {
         $caps[] = 'idempotency-mismatch-v1';
      }
      // 6.27.0 (contrato §8.5): o executor atende contato externo nas ferramentas que declaram isso.
      if (defined('PluginNextoolCloudExecutor::ACTOR_KIND_EXTERNAL')) {
         $caps[] = 'external-contact-v1';
      }

      return $caps;
   }

   /**
    * Ferramentas anunciadas, por serviço: cada módulo instalado e ATIVO com ferramentas de nuvem, com a
    * versão dele e, por ferramenta, o `kind` (ausente ou inválido = `write`, a mesma regra fail-closed
    * do executor) e os `args` (o `schema` que o módulo declara). O modo local forçado e a licença não
    * tiram o módulo do anúncio: são condições da hora da chamada, que o executor confere e responde.
    *
    * O `_base` (ping) entra quando o executor já o despacha.
    *
    * @return array<string, array{versao:string, tools:array}>
    */
   public static function ferramentas(string $versaoBase = ''): array {
      $out = [];
      if (class_exists('PluginNextoolModuleManager')) {
         try {
            $mm      = PluginNextoolModuleManager::getInstance();
            $estados = $mm->getModulesStateMap();
         } catch (\Throwable $e) {
            $mm      = null;
            $estados = [];
         }
         ksort($estados);
         foreach ($estados as $chave => $estado) {
            if ($mm === null || empty($estado['installed']) || empty($estado['enabled'])) {
               continue;
            }
            $secao = self::secaoDoModulo($mm, (string) $chave);
            if ($secao !== null) {
               $out[(string) $chave] = $secao;
            }
         }
      }
      if (self::baseToolsDispatched()) {
         require_once NEXTOOL_PHP_DIR . '/inc/cloudbasetools.class.php';
         $out[PluginNextoolCloudBaseTools::SERVICE] = PluginNextoolCloudBaseTools::announcement($versaoBase);
      }

      return $out;
   }

   /**
    * A declaração passa de `$max` bytes? Reduz em degraus e registra no log: primeiro só o `kind` de
    * cada ferramenta, depois só a versão de cada módulo, e por fim sem `ferramentas`. O que sai nunca é
    * cortado no meio: o JSON continua válido e assinado inteiro. Pública para o E2E.
    */
   public static function fitDeclaration(array $corpo, int $max = self::MAX_DECLARATION_BYTES): array {
      $original = self::jsonSize($corpo);
      if ($original <= $max || !is_array($corpo['ferramentas'] ?? null)) {
         return $corpo;
      }

      foreach ($corpo['ferramentas'] as $servico => $secao) {
         foreach ((array) ($secao['tools'] ?? []) as $tool => $spec) {
            // O `actor: any` (ferramenta que aceita chat não vinculado) sobrevive à redução: sem ele o cérebro
            // não saberia que pode chamar a ferramenta antes do vínculo.
            $corpo['ferramentas'][$servico]['tools'][$tool] = ['kind' => (string) ($spec['kind'] ?? 'write')]
               + (($spec['actor'] ?? null) === 'any' ? ['actor' => 'any'] : []);
         }
      }
      $nivel = 'kind';
      if (self::jsonSize($corpo) > $max) {
         foreach ($corpo['ferramentas'] as $servico => $secao) {
            $corpo['ferramentas'][$servico] = ['versao' => (string) ($secao['versao'] ?? '')];
         }
         $nivel = 'versao';
      }
      if (self::jsonSize($corpo) > $max) {
         $corpo['ferramentas'] = new \stdClass();
         $nivel = 'nada';
      }
      self::log(sprintf(
         "[CLOUD_ENDPOINT] declaracao de %d bytes passa de %d: ferramentas enviadas so com %s (%d bytes)\n",
         $original,
         $max,
         $nivel,
         self::jsonSize($corpo)
      ));

      return $corpo;
   }

   /**
    * O que fazer com o `destino` da resposta (contrato 1.1, §2.13.2), sem efeito colateral:
    *  - `em_uso`: limpa o aviso;
    *  - `pendente`: limpa até 24 h (a 1ª URL é promovida na 1ª chamada); depois, aviso;
    *  - `conflito` e `recusado`: aviso crítico, com o motivo;
    *  - sem `destino` (cérebro anterior ao K2) ou estado desconhecido: nada.
    *
    * Sem módulo ativo com ferramentas de entrada (`$inbound` false, B2), `pendente` e `recusado` só
    * limpam: o cérebro não chama este GLPI. O `conflito` (outro GLPI com a mesma credencial, típico de
    * cópia) continua avisando.
    *
    * A chave do aviso leva o estado, o `desde` e o motivo: o mesmo episódio não repete o aviso, e um
    * episódio novo depois de resolvido volta a avisar. Pública para o E2E.
    *
    * @return array{acao:string, estado:string, tipo?:string, chave?:string, motivo?:string}
    */
   public static function destinoAcao(array $data, string $url, int $now = 0, bool $inbound = true): array {
      $d = $data['destino'] ?? null;
      if (!is_array($d)) {
         return ['acao' => 'nada', 'estado' => ''];
      }
      $now    = $now > 0 ? $now : time();
      $estado = (string) ($d['estado'] ?? '');
      $desde  = (string) ($d['desde'] ?? '');
      $motivo = self::textoCurto((string) ($d['motivo'] ?? ''));
      if (!$inbound && in_array($estado, ['pendente', 'recusado'], true)) {
         return ['acao' => 'limpar', 'estado' => $estado];
      }

      switch ($estado) {
         case 'em_uso':
            return ['acao' => 'limpar', 'estado' => $estado];
         case 'pendente':
            $inicio = $desde !== '' ? strtotime($desde) : false;
            if ($inicio === false || $now - $inicio < self::PENDENTE_AVISO_APOS) {
               return ['acao' => 'limpar', 'estado' => $estado];
            }
            return [
               'acao'   => 'alertar',
               'estado' => $estado,
               'tipo'   => 'warning',
               'chave'  => self::DESTINO_FAMILY . hash('sha256', 'pendente|' . $desde . '|' . $url),
               'motivo' => $motivo,
            ];
         case 'conflito':
         case 'recusado':
            return [
               'acao'   => 'alertar',
               'estado' => $estado,
               'tipo'   => 'critical',
               'chave'  => self::DESTINO_FAMILY . hash('sha256', $estado . '|' . $desde . '|' . $motivo . '|' . $url),
               'motivo' => $motivo,
            ];
         default:
            return ['acao' => 'nada', 'estado' => $estado];
      }
   }

   /**
    * Aplica o `destino` da resposta nos avisos locais (aba Alertas e sino). Pública para o E2E aplicar
    * uma resposta sintética, sem chamar o cérebro.
    *
    * @param ?bool $inbound há módulo com ferramentas de entrada (null = consulta hasInboundTools())
    * @return string o `estado` recebido ('' sem `destino`)
    */
   public static function applyDestino(array $data, string $url, int $now = 0, ?bool $inbound = null): string {
      $acao = self::destinoAcao($data, $url, $now, $inbound ?? self::hasInboundTools());
      if ($acao['acao'] === 'nada' || !self::alertManager()) {
         return $acao['estado'];
      }
      if ($acao['acao'] === 'limpar') {
         PluginNextoolAlertManager::expireLocalFamily(self::DESTINO_FAMILY);
         return $acao['estado'];
      }

      $texto   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $urlHtml = $texto(self::textoCurto($url));
      $motivo  = $texto($acao['motivo'] !== '' ? $acao['motivo'] : __('motivo não informado', 'nextool'));
      switch ($acao['estado']) {
         case 'conflito':
            $titulo = __('Outro GLPI responde à nuvem NexTool com a credencial deste ambiente', 'nextool');
            $corpo  = sprintf(
               __('A nuvem NexTool recebeu resposta de dois endereços com a credencial deste ambiente (%2$s) e continua usando o que já estava em uso. O endereço deste GLPI é %1$s. Isso acontece quando o GLPI foi copiado (homologação, backup restaurado) ou quando o mesmo servidor responde por dois endereços. Se este GLPI mudou de endereço, peça ao suporte NexTool para confirmar a troca; se é uma cópia, ele não deve usar a credencial deste ambiente.', 'nextool'),
               $urlHtml,
               $motivo
            );
            break;
         case 'recusado':
            $titulo = __('A nuvem NexTool recusou o endereço deste GLPI', 'nextool');
            $corpo  = sprintf(
               __('A nuvem NexTool não usa o endereço %1$s para chegar a este GLPI (%2$s). As funções de nuvem dos módulos não chegam aqui enquanto isso não for corrigido. Confira a "URL da aplicação" em Configurar > Geral: ela precisa ser o endereço final, público e com https, sem redirecionar para outro.', 'nextool'),
               $urlHtml,
               $motivo
            );
            break;
         default: // pendente há mais de 24 h
            $titulo = __('A nuvem NexTool ainda não usa o endereço deste GLPI', 'nextool');
            $corpo  = sprintf(
               __('O endereço %1$s foi informado à nuvem NexTool há mais de 24 horas e ainda não está em uso: a nuvem continua chamando outro endereço deste ambiente. Se este GLPI mudou de endereço, peça ao suporte NexTool para confirmar a troca.', 'nextool'),
               $urlHtml
            );
      }
      PluginNextoolAlertManager::raiseLocal($acao['chave'], $titulo, $corpo, $acao['tipo']);

      return $acao['estado'];
   }

   /**
    * A nuvem recusou a URL declarada: é configuração do CLIENTE (`url_base` com http, IP ou host
    * interno), não instabilidade. Depois de algumas recusas seguidas, avisa quem administra --
    * sem isso o plugin retentaria para sempre e ninguém olharia o log do cérebro.
    *
    * O alerta mostra o VALOR recusado: sem ele, a pessoa confere a configuração e acha que está
    * certa. A URL não é segredo.
    *
    * O corpo do alerta é HTML, e o motivo vem da resposta do cérebro, que não é assinada: URL e motivo
    * entram ESCAPADOS. Até a 6.24.1 entravam crus, e um cérebro comprometido (ou quem controlasse a
    * resposta) punha script na tela do administrador de cada GLPI que declarasse o endereço.
    *
    * A chave do alerta leva a URL e o início da sequência de recusas. O AlertManager ignora chave já
    * emitida, mesmo recolhida: até a 6.26.1 a chave era só a URL, e a mesma URL recusada de novo depois
    * de uma declaração aceita não avisava mais. Sequência que começou antes desta correção (início vazio)
    * segue com a chave antiga enquanto o alerta dela estiver aberto, para a atualização não repetir o
    * aviso; sem alerta aberto, ganha um início e a chave nova.
    */
   private static function onRejected(string $url, string $motivo): void {
      $n = 1 + (int) (PluginNextoolCloudState::get(self::CFG_REJECTS) ?? 0);
      PluginNextoolCloudState::set(self::CFG_REJECTS, (string) $n);
      if (!self::alertManager()) {
         return;
      }
      $desde = (string) (PluginNextoolCloudState::get(self::CFG_REJECTS_SINCE) ?? '');
      if ($n === 1 || ($desde === '' && PluginNextoolAlertManager::findActiveLocalKey(self::ALERT_FAMILY) === null)) {
         $desde = sprintf('%.6F', microtime(true));
         PluginNextoolCloudState::set(self::CFG_REJECTS_SINCE, $desde);
      }
      if ($n < self::REJECT_ALERT_AFTER) {
         return;
      }
      $texto = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      PluginNextoolAlertManager::raiseLocal(
         self::ALERT_FAMILY . hash('sha256', $desde === '' ? $url : $url . '|' . $desde),
         __('A nuvem NexTool não aceitou o endereço deste GLPI', 'nextool'),
         sprintf(
            __('O endereço informado à nuvem foi %1$s e foi recusado (%2$s). As funções de nuvem dos módulos não chegam a este GLPI enquanto isso não for corrigido. Confira a "URL da aplicação" em Configurar > Geral: ela precisa ser pública, com https, e não pode ser um IP ou um nome que só existe na rede interna.', 'nextool'),
            $texto($url),
            $texto($motivo !== '' ? $motivo : __('motivo não informado', 'nextool'))
         ),
         'critical'
      );
   }

   /** Declaração aceita: zera a sequência de recusas e recolhe o alerta dela. */
   private static function onAccepted(): void {
      PluginNextoolCloudState::set(self::CFG_REJECTS, '0');
      PluginNextoolCloudState::delete(self::CFG_REJECTS_SINCE);
      self::alertManager() && PluginNextoolAlertManager::expireLocalFamily(self::ALERT_FAMILY);
   }

   private static function alertManager(): bool {
      $f = NEXTOOL_PHP_DIR . '/inc/alertmanager.class.php';
      if (!is_file($f)) {
         return false;
      }
      require_once $f;

      return class_exists('PluginNextoolAlertManager');
   }

   /**
    * Assinatura da saída. Fachada da canônica única (PluginNextoolCloudSignature); pública para o
    * E2E conferir contra os vetores do contrato.
    */
   public static function sign(string $path, string $ambiente, string $ts, string $nonce, string $body, string $key): string {
      return PluginNextoolCloudSignature::sign($path, $ambiente, $ts, $nonce, $body, $key);
   }

   /** Seção de um módulo no anúncio, ou null se ele não tem ferramenta de nuvem utilizável. */
   private static function secaoDoModulo($mm, string $chave): ?array {
      try {
         $modulo = $mm->getModule($chave);
         if (!is_object($modulo) || !method_exists($modulo, 'getCloudTools')) {
            return null;
         }
         $tools  = $modulo->getCloudTools();
         $versao = (string) $modulo->getVersion();
      } catch (\Throwable $e) {
         self::log(sprintf("[CLOUD_ENDPOINT] %s fora do anuncio: as ferramentas nao carregaram (%s)\n", $chave, get_class($e)));
         return null;
      }
      if (!is_array($tools) || $tools === []) {
         return null;
      }
      ksort($tools);
      $lista = [];
      foreach ($tools as $nome => $spec) {
         // Mesmos filtros do executor: nome no formato do contrato e handler chamável.
         if (!is_string($nome) || !preg_match('/^[a-z0-9_]{2,64}$/', $nome)
             || !is_array($spec) || !is_callable($spec['handler'] ?? null)) {
            continue;
         }
         $lista[$nome] = ['kind' => self::toolKind($spec), 'args' => self::toolArgs($spec)];
         // Ferramenta que aceita chat não vinculado (o executor roda sem sessão): o cérebro precisa saber.
         if (($spec['actor'] ?? null) === 'any') {
            $lista[$nome]['actor'] = 'any';
         }
         // Ferramenta que atende contato externo (6.27.0): o cérebro só manda contato externo para estas.
         if (method_exists('PluginNextoolCloudExecutor', 'toolAcceptsExternal') && PluginNextoolCloudExecutor::toolAcceptsExternal($spec)) {
            $lista[$nome]['actors'] = [PluginNextoolCloudExecutor::ACTOR_KIND_GLPI_USER, PluginNextoolCloudExecutor::ACTOR_KIND_EXTERNAL];
         }
      }
      if ($lista === []) {
         return null;
      }
      $secao = ['versao' => $versao, 'tools' => $lista];
      if (!is_string(json_encode($secao, self::JSON_FLAGS))) {
         // Schema com texto que não vira JSON (UTF-8 inválido): esse módulo vai só com o kind, e o
         // resto da declaração segue.
         foreach ($lista as $nome => $tool) {
            $lista[$nome] = ['kind' => $tool['kind']] + (isset($tool['actor']) ? ['actor' => $tool['actor']] : []);
         }
         $secao = ['versao' => (string) preg_replace('/[^0-9A-Za-z.+_-]/', '', $versao), 'tools' => $lista];
         self::log(sprintf("[CLOUD_ENDPOINT] %s anunciado so com o kind: o schema nao vira JSON\n", $chave));
      }

      return $secao;
   }

   /** `kind` da ferramenta: só `read` exato é leitura (a mesma regra fail-closed do executor). */
   private static function toolKind(array $spec): string {
      return ($spec['kind'] ?? null) === 'read' ? 'read' : 'write';
   }

   /**
    * `args` anunciados: o `schema` da ferramenta. O formato dos módulos é `['args' => [...]]`, e é o
    * miolo que vai. Vazio vira objeto (`{}`), como no contrato.
    *
    * @return array|\stdClass
    */
   private static function toolArgs(array $spec) {
      $schema = $spec['schema'] ?? [];
      if (is_array($schema) && is_array($schema['args'] ?? null)) {
         $schema = $schema['args'];
      }

      return (is_array($schema) && $schema !== []) ? $schema : new \stdClass();
   }

   /** O executor já despacha o `_base` (integração do K1 aplicada nele)? */
   private static function baseToolsDispatched(): bool {
      $f = NEXTOOL_PHP_DIR . '/inc/cloudexecutor.class.php';
      if (is_file($f)) {
         require_once $f;
      }

      return class_exists('PluginNextoolCloudExecutor', false) && method_exists('PluginNextoolCloudExecutor', 'runBaseTool');
   }

   private static function jsonSize(array $corpo): int {
      $json = json_encode($corpo, self::JSON_FLAGS);

      return is_string($json) ? strlen($json) : PHP_INT_MAX;
   }

   /** Texto de fora (o motivo do cérebro) para aviso: sem controle nem barra invertida, curto. */
   private static function textoCurto(string $texto): string {
      $texto = (string) preg_replace('/[\x00-\x1F\x7F\\\\]+/u', ' ', $texto);
      $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

      return function_exists('mb_substr') ? mb_substr($texto, 0, 200) : substr($texto, 0, 200);
   }

   private static function log(string $linha): void {
      if (class_exists('Toolbox')) {
         Toolbox::logInFile('plugin_nextool_cloud', $linha);
      }
   }

   /**
    * POST pelo cliente HTTP do core, com o proxy configurado no GLPI.
    *
    * `Toolbox::getGuzzleClient` existe no GLPI 10 e no 11 e é o que o core usa para sair à internet
    * (marketplace, inventário). O `Toolbox::callCurl` também aplica o proxy, mas não serve aqui: ele
    * segue o `Location` de uma resposta 3xx repetindo o MESMO POST (corpo assinado, com o token do bot)
    * para o endereço novo, sem limite de protocolo, e no GLPI 10 ainda dispara aviso de PHP a cada
    * falha de rede. Aqui: 3xx é resposta (vira `http_3xx`), 4xx/5xx não lançam, e o curl só fala
    * https, inclusive se alguém ligar o redirecionamento.
    *
    * GLPI 10 anterior ao helper: cliente sem proxy, como era o curl próprio até a 6.24.1.
    *
    * @param array<string,string> $headers
    * @return array{0:int, 1:?string} [código HTTP, corpo], ou [0, null] quando não houve resposta
    */
   private static function send(string $url, string $body, array $headers, int $timeout = self::TIMEOUT, int $connectTimeout = self::CONNECT_TIMEOUT): array {
      [$timeout, $connectTimeout] = self::clampTimeouts($timeout, $connectTimeout);
      $options = [
         'timeout'         => $timeout,
         'connect_timeout' => $connectTimeout,
         'allow_redirects' => false,
         'http_errors'     => false,
      ];
      if (defined('CURLOPT_PROTOCOLS') && defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
         $options['curl'] = [
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
         ];
      }

      try {
         $client = method_exists('Toolbox', 'getGuzzleClient')
            ? Toolbox::getGuzzleClient($options)
            : new \GuzzleHttp\Client($options);
         $resp = $client->request('POST', $url, ['headers' => $headers, 'body' => $body]);
      } catch (\Throwable $e) {
         // Rede, timeout, TLS, protocolo recusado ou proxy fora: mesmo resultado para quem chama, e
         // nada do pedido vai para o log, como antes.
         return [0, null];
      }

      return [(int) $resp->getStatusCode(), (string) $resp->getBody()];
   }

   /**
    * Timeouts efetivos de uma chamada (B1): o total fica entre 1 e MAX_TIMEOUT, e o de conexão entre 1
    * e o total. Pública para o E2E.
    *
    * @return array{0:int, 1:int} [total, conexão]
    */
   public static function clampTimeouts(int $timeout, int $connectTimeout): array {
      $timeout = max(1, min($timeout, self::MAX_TIMEOUT));

      return [$timeout, max(1, min($connectTimeout, $timeout))];
   }

   private static function fail(int $http, string $error): array {
      return ['ok' => false, 'http' => $http, 'data' => null, 'error' => $error];
   }
}
