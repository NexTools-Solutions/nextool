<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Adaptador do cliente HTTP do GLPI 12
 * -------------------------------------------------------------------------
 * O GLPI 12 removeu `Toolbox::getGuzzleClient` e passou a sair à internet pelo
 * `Glpi\Toolbox\HttpClient` (Symfony HttpClient). Este adaptador dá a ele a
 * superfície mínima do Guzzle que o plugin usa (PluginNextoolCloudClient):
 *
 *   $client = PluginNextoolCompat::httpClient($opcoesGuzzle);
 *   $resp   = $client->request('POST', $url, ['headers' => [...], 'body' => '...']);
 *   $resp->getStatusCode(); $resp->getHeaderLine('Content-Type');
 *   $resp->getBody()->read(65536); ->eof(); ->getContents(); (string) $resp->getBody();
 *
 * Diferenças tratadas aqui:
 *  - o Symfony recusa opção desconhecida: as opções do Guzzle são convertidas por
 *    LISTA FECHADA (`timeout` -> `max_duration`, `connect_timeout` -> `timeout`,
 *    `allow_redirects=false` -> `max_redirects=0`, `verify=false` -> `verify_peer`/`verify_host`
 *    desligados); `stream`, `http_errors` e `curl` nunca passam, e qualquer `verify` que não seja
 *    `false` mantém o TLS no padrão do Symfony (verificado);
 *  - o wrapper do core lança em 3xx/4xx/5xx já no `request()`: a exceção é capturada
 *    e a resposta devolvida, como `http_errors => false` fazia no Guzzle;
 *  - toda leitura usa `getHeaders(false)`/`getContent(false)`/`toStream(false)`, que
 *    não relançam; o buffer do Symfony fica no padrão, então o corpo JSON de uma
 *    recusa continua legível depois da exceção;
 *  - contexto nomeado (`PluginNextoolCloudClient`): o proxy do GLPI continua aplicado
 *    (salvo se o admin o listar em `proxy_exclusions`) e o admin pode liberar destino em
 *    rede privada por `GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS` no
 *    `local_define.php`; com contexto `null` não haveria como.
 *
 * Só é instanciado quando `Glpi\Toolbox\HttpClient` existe (GLPI >= 12). Piso de PHP 8.0.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

if (!class_exists('PluginNextoolHttpClientAdapter', false)) {

final class PluginNextoolHttpClientAdapter {

   /** Contexto passado ao HttpClient do core (chave de `proxy_exclusions` e da válvula de rede privada). */
   public const CONTEXT = 'PluginNextoolCloudClient';

   /** @var array<string,mixed> opções já no vocabulário do Symfony */
   private $options;

   /** @var string contexto do HttpClient do core (por chamada: opção `nextool_context`) */
   private $context = self::CONTEXT;

   /** @var bool aceitar `http://` além de `https://` (por chamada: opção `nextool_allow_http`) */
   private $allowHttp = false;

   /**
    * @param array<string,mixed> $guzzleOptions além das opções do Guzzle, `nextool_context` (class-string
    *                                           do contexto do core, ex.: `Auth`) e `nextool_allow_http` (bool)
    */
   public function __construct(array $guzzleOptions = []) {
      $ctx = $guzzleOptions[PluginNextoolCompat::HTTP_CONTEXT_OPTION] ?? null;
      if (is_string($ctx) && preg_match('/^[A-Za-z0-9_\\\\]{1,128}$/', $ctx) === 1) {
         $this->context = $ctx;
      }
      $this->allowHttp = !empty($guzzleOptions[PluginNextoolCompat::HTTP_ALLOW_HTTP_OPTION]);
      unset($guzzleOptions[PluginNextoolCompat::HTTP_CONTEXT_OPTION], $guzzleOptions[PluginNextoolCompat::HTTP_ALLOW_HTTP_OPTION]);
      $this->options = self::mapOptions($guzzleOptions);
   }

   /**
    * Converte opções do Guzzle para as do Symfony HttpClient, por lista fechada.
    * Pública para o teste (tests/glpi-compat.test.php).
    *
    * @param array<string,mixed> $guzzle
    * @return array<string,mixed>
    */
   public static function mapOptions(array $guzzle): array {
      $out = [];
      if (isset($guzzle['timeout']) && is_numeric($guzzle['timeout'])) {
         // Guzzle: tempo total da chamada. Symfony: `max_duration`.
         $out['max_duration'] = (float) $guzzle['timeout'];
      }
      if (isset($guzzle['connect_timeout']) && is_numeric($guzzle['connect_timeout'])) {
         // Guzzle: tempo para conectar. O Symfony não implementa `max_connect_duration` no
         // CurlHttpClient 7.4; o limite efetivo mais próximo é o `timeout` de inatividade.
         $out['timeout'] = (float) $guzzle['connect_timeout'];
      }
      if (array_key_exists('allow_redirects', $guzzle) && $guzzle['allow_redirects'] === false) {
         $out['max_redirects'] = 0;
      }
      if (isset($guzzle['headers']) && is_array($guzzle['headers'])) {
         $out['headers'] = $guzzle['headers'];
      }
      if (array_key_exists('verify', $guzzle) && $guzzle['verify'] === false) {
         // Só o `false` EXPLÍCITO desliga a verificação de TLS (#288): é o que um módulo manda quando o
         // admin desmarca "verificar TLS" no conector (zabbix, vaultsend). `true`, ausente ou caminho de
         // CA em string mantêm o padrão do Symfony (verificado), como antes.
         $out['verify_peer'] = false;
         $out['verify_host'] = false;
      }
      return $out;
   }

   /**
    * @param array<string,mixed> $options só `headers` e `body` são usados
    */
   public function request(string $method, string $uri, array $options = []): PluginNextoolHttpClientResponse {
      if (!preg_match($this->allowHttp ? '#^https?://#i' : '#^https://#i', $uri)) {
         // O curl do Guzzle era limitado a https por CURLOPT_PROTOCOLS, que o Symfony recusa.
         // `http://` só por opção explícita de quem chama (ex.: discovery de IdP interno do SSO Login).
         throw new \InvalidArgumentException($this->allowHttp ? 'Only http(s):// is allowed' : 'Only https:// is allowed');
      }
      $request = [];
      if (isset($options['headers']) && is_array($options['headers'])) {
         $request['headers'] = $options['headers'];
      }
      if (array_key_exists('body', $options)) {
         $request['body'] = $options['body'];
      }

      $client = new \Glpi\Toolbox\HttpClient($this->context, $this->options);
      try {
         $response = $client->request(strtoupper($method), $uri, $request);
      } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
         // 3xx (sem seguir, max_redirects=0), 4xx e 5xx são resposta para quem chama.
         $response = $e->getResponse();
      }
      return new PluginNextoolHttpClientResponse($response);
   }
}

final class PluginNextoolHttpClientResponse {

   /** @var \Symfony\Contracts\HttpClient\ResponseInterface */
   private $response;
   /** @var PluginNextoolHttpClientBody|null */
   private $body = null;

   public function __construct(\Symfony\Contracts\HttpClient\ResponseInterface $response) {
      $this->response = $response;
   }

   public function getStatusCode(): int {
      return (int) $this->response->getStatusCode();
   }

   /** Como no PSR-7: valores do cabeçalho separados por vírgula, '' se ausente. */
   public function getHeaderLine(string $name): string {
      $headers = $this->response->getHeaders(false);
      $values  = $headers[strtolower($name)] ?? [];
      return implode(', ', array_map('strval', $values));
   }

   public function getBody(): PluginNextoolHttpClientBody {
      if ($this->body === null) {
         $this->body = new PluginNextoolHttpClientBody($this->response);
      }
      return $this->body;
   }

   /** Interrompe a transferência (teto de bytes atingido). */
   public function cancel(): void {
      $this->response->cancel();
   }
}

final class PluginNextoolHttpClientBody {

   /** @var \Symfony\Contracts\HttpClient\ResponseInterface */
   private $response;
   /** @var resource|null */
   private $stream = null;

   public function __construct(\Symfony\Contracts\HttpClient\ResponseInterface $response) {
      $this->response = $response;
   }

   /** @return resource */
   private function stream() {
      if ($this->stream === null) {
         $this->stream = $this->response->toStream(false);
      }
      return $this->stream;
   }

   public function read(int $length): string {
      $chunk = fread($this->stream(), max(1, $length));
      return is_string($chunk) ? $chunk : '';
   }

   public function eof(): bool {
      return feof($this->stream());
   }

   public function getContents(): string {
      $rest = stream_get_contents($this->stream());
      return is_string($rest) ? $rest : '';
   }

   public function __toString(): string {
      try {
         return (string) $this->response->getContent(false);
      } catch (\Throwable $e) {
         return '';
      }
   }
}

}
