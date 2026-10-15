<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Compat (GLPI 10 / 11 / 12)
 * -------------------------------------------------------------------------
 * Ponto único das APIs do core que mudaram entre as majors. O plugin é UM
 * artefato para GLPI 10, 11 e 12; cada método aqui escolhe a forma certa em
 * tempo de execução, e o resto do código nunca chama direto o que o GLPI 12
 * removeu (`Plugin::getWebDir`, `Html::entities_deep`, `Html::displayRightError`,
 * `Html::displayErrorAndDie`, `\QueryExpression` global, `Toolbox::getGuzzleClient`).
 *
 * Regras do arquivo:
 *  - piso de PHP é 8.0 (GLPI 10): nada de `never`, `readonly`, `enum`;
 *  - nenhuma dependência de outra classe do plugin; classe do core só dentro
 *    dos métodos, nunca em assinatura;
 *  - carregado pelo autoloader do core (inc/compat.class.php) e, por garantia,
 *    por require_once no setup.php e no topo das classes que o usam.
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

if (!class_exists('PluginNextoolCompat', false)) {

final class PluginNextoolCompat {

   /** Chave do plugin nos caminhos web (`/plugins/nextool`). */
   private const PLUGIN_KEY = 'nextool';

   /** @var int|null */
   private static $glpiMajor = null;
   /** @var bool|null */
   private static $typedRightname = null;
   /** @var bool|null */
   private static $csrfTokenless = null;

   private function __construct() {
   }

   /** Major do GLPI em execução (10, 11, 12...). */
   public static function glpiMajor(): int {
      if (self::$glpiMajor === null) {
         $version = defined('GLPI_VERSION') ? (string) GLPI_VERSION : '0';
         self::$glpiMajor = (int) explode('.', $version)[0];
      }
      return self::$glpiMajor;
   }

   /**
    * O core declara `CommonGLPI::$rightname` COM tipo (`public static string`)?
    *
    * GLPI 12 tipou a propriedade; redeclará-la sem tipo é erro de compilação, e
    * declará-la com tipo no 10/11 é o erro inverso. As classes do plugin que a
    * redeclaram usam esta resposta para escolher a declaração (ver inc/config.class.php).
    */
   public static function typedRightname(): bool {
      if (self::$typedRightname === null) {
         try {
            self::$typedRightname = (new ReflectionProperty('CommonGLPI', 'rightname'))->hasType();
         } catch (\Throwable $e) {
            self::$typedRightname = false;
         }
      }
      return self::$typedRightname;
   }

   /**
    * Caminho web do plugin. Substitui `Plugin::getWebDir('nextool', ...)`, removido no GLPI 12.
    *
    * GLPI 10 e 11: o próprio `Plugin::getWebDir` (byte-idêntico ao que os módulos
    * ainda produzem, inclusive `marketplace/nextool` quando a base está em
    * `marketplace/`). GLPI 12: `root_doc` (ou `url_base`) + `/plugins/nextool`; o
    * core 11/12 serve qualquer plugin nesse caminho, esteja ele em `plugins/` ou em
    * `marketplace/`.
    *
    * @param bool $full       true = com o prefixo (`/glpi/plugins/nextool`); false = só `plugins/nextool`
    * @param bool $useUrlBase true = prefixo absoluto (`url_base`) em vez do `root_doc`
    */
   public static function webDir(bool $full = true, bool $useUrlBase = false): string {
      if (method_exists('Plugin', 'getWebDir')) {
         $dir = Plugin::getWebDir(self::PLUGIN_KEY, $full, $useUrlBase);
         if (is_string($dir) && $dir !== '') {
            return $dir;
         }
      }
      $relative = 'plugins/' . self::PLUGIN_KEY;
      if (!$full) {
         return $relative;
      }
      global $CFG_GLPI;
      $base = $useUrlBase ? ($CFG_GLPI['url_base'] ?? '') : ($CFG_GLPI['root_doc'] ?? '');
      return rtrim((string) $base, '/') . '/' . $relative;
   }

   /**
    * Escape para HTML. Substitui `Html::entities_deep`, removido no GLPI 12.
    *
    * Mesmo contrato do original: array é percorrido, valor que não é string passa
    * intacto (null, int, bool). Diferença: `htmlspecialchars` em vez de `htmlentities`
    * (acento sai em UTF-8, não como entidade) e `ENT_SUBSTITUTE` (UTF-8 inválido vira
    * U+FFFD em vez de string vazia). `ENT_QUOTES` cobre atributo e string JS.
    *
    * @param mixed $value
    * @return mixed
    */
   public static function escape($value) {
      if (is_array($value)) {
         $out = [];
         foreach ($value as $key => $item) {
            $out[$key] = self::escape($item);
         }
         return $out;
      }
      if (is_string($value)) {
         return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      }
      return $value;
   }

   /**
    * Acesso negado. Substitui `Html::displayRightError`, removido no GLPI 12.
    * GLPI 11/12: exceção que o kernel transforma na página de erro. GLPI 10: o helper do core.
    */
   public static function rightError(): void {
      if (class_exists('Glpi\Exception\Http\AccessDeniedHttpException')) {
         throw new \Glpi\Exception\Http\AccessDeniedHttpException();
      }
      Html::displayRightError();
      exit;
   }

   /**
    * Requisição inválida com mensagem. Substitui `Html::displayErrorAndDie`, removido no GLPI 12.
    * No 11 o helper do core já lançava a exceção, mas perdia a mensagem; aqui ela é preservada.
    */
   public static function errorAndDie(string $message): void {
      if (class_exists('Glpi\Exception\Http\BadRequestHttpException')) {
         $exception = new \Glpi\Exception\Http\BadRequestHttpException($message);
         if (method_exists($exception, 'setMessageToDisplay')) {
            $exception->setMessageToDisplay($message);
         }
         throw $exception;
      }
      Html::displayErrorAndDie($message);
      exit;
   }

   /**
    * Expressão SQL crua para os critérios do `$DB->request()`.
    * GLPI 11/12: `Glpi\DBAL\QueryExpression`; GLPI 10: a classe global `\QueryExpression`.
    *
    * @return object
    */
   public static function queryExpression(string $sql) {
      if (class_exists('Glpi\DBAL\QueryExpression')) {
         return new \Glpi\DBAL\QueryExpression($sql);
      }
      return new \QueryExpression($sql);
   }

   /**
    * Linhas afetadas pela última escrita. `DBmysql::affectedRows` está depreciado no GLPI 12
    * em favor de `getAffectedRows`, que não existe no 10/11.
    *
    * @param DBmysql $db
    */
   public static function affectedRows($db): int {
      if (method_exists($db, 'getAffectedRows')) {
         return (int) $db->getAffectedRows();
      }
      if (method_exists($db, 'affectedRows')) {
         return (int) $db->affectedRows();
      }
      return 0;
   }

   /**
    * O core valida CSRF sem token? A partir do GLPI 12 a proteção é pelos cabeçalhos
    * `Sec-Fetch-Site`/`Origin`: `Session::getNewCSRFToken()` devolve '' e `checkCSRF()` não faz nada.
    */
   public static function csrfTokenless(): bool {
      if (self::$csrfTokenless === null) {
         self::$csrfTokenless = defined('GLPI_VERSION')
            && version_compare((string) GLPI_VERSION, '12.0.0-dev', '>=');
      }
      return self::$csrfTokenless;
   }

   /**
    * Decide se a requisição é segura contra CSRF pelos cabeçalhos, com a MESMA regra do
    * `CheckCsrfListener` do GLPI 12 (que não expõe helper público e é pulado nos caminhos
    * stateless do plugin):
    *  - `Sec-Fetch-Site` presente: `same-origin` e `none` passam; qualquer outro valor recusa;
    *  - senão, com `Origin` e `Host`: o host do `Origin` (com `:porta` só se o `Origin` a
    *    trouxer, sem normalizar 80/443) tem de ser igual ao `Host`;
    *  - sem nenhum dos dois: passa (não veio de navegador; o cookie de sessão continua exigido).
    * Diferença deliberada: `Origin` presente sem host parseável (ex.: `Origin: null`) RECUSA,
    * onde o core responde 500.
    *
    * @param array<string,mixed>|null $server `$_SERVER` ou um substituto (testes)
    */
   public static function isRequestSafeFromCsrf(?array $server = null): bool {
      $server = $server ?? $_SERVER;

      if (isset($server['HTTP_SEC_FETCH_SITE'])) {
         return in_array((string) $server['HTTP_SEC_FETCH_SITE'], ['same-origin', 'none'], true);
      }

      $origin = $server['HTTP_ORIGIN'] ?? null;
      $host   = $server['HTTP_HOST'] ?? null;
      if ($origin !== null && $host !== null) {
         $originHost = parse_url((string) $origin, PHP_URL_HOST);
         if (!is_string($originHost) || $originHost === '') {
            return false;
         }
         $originPort = parse_url((string) $origin, PHP_URL_PORT);
         if (is_int($originPort)) {
            return $originHost . ':' . $originPort === (string) $host;
         }
         return $originHost === (string) $host;
      }

      return true;
   }

   /**
    * Recusa a requisição (403) quando `isRequestSafeFromCsrf()` falha. Só faz sentido no modo
    * sem token (`csrfTokenless()`); no 10/11 o fluxo continua pelo `Session::checkCSRF`.
    */
   public static function assertRequestSafeFromCsrf(): void {
      if (self::isRequestSafeFromCsrf()) {
         return;
      }
      $message = __('The action you have requested is not allowed.');
      if (class_exists('Glpi\Exception\Http\AccessDeniedHttpException')) {
         $exception = new \Glpi\Exception\Http\AccessDeniedHttpException($message);
         if (method_exists($exception, 'setMessageToDisplay')) {
            $exception->setMessageToDisplay($message);
         }
         throw $exception;
      }
      http_response_code(403);
      exit;
   }

   /**
    * Cliente HTTP com o proxy do GLPI, com a superfície mínima do Guzzle que o plugin usa:
    * `request($method, $url, ['headers', 'body'])` e resposta com `getStatusCode()`,
    * `getHeaderLine()` e `getBody()` (`read`, `eof`, `getContents`, `__toString`).
    *
    * GLPI 10/11: `Toolbox::getGuzzleClient` (aplica o proxy de Configurar > Geral). GLPI 12:
    * adaptador sobre `Glpi\Toolbox\HttpClient` (inc/httpclientadapter.class.php), que converte
    * as opções do Guzzle para as do Symfony e devolve 3xx/4xx/5xx como resposta, não como exceção.
    *
    * @param array<string,mixed> $guzzleOptions opções no vocabulário do Guzzle
    * @return object
    */
   public static function httpClient(array $guzzleOptions = []) {
      if (method_exists('Toolbox', 'getGuzzleClient')) {
         unset($guzzleOptions[self::HTTP_CONTEXT_OPTION], $guzzleOptions[self::HTTP_ALLOW_HTTP_OPTION]);
         return Toolbox::getGuzzleClient($guzzleOptions);
      }
      if (class_exists('Glpi\Toolbox\HttpClient')) {
         require_once __DIR__ . '/httpclientadapter.class.php';
         return new PluginNextoolHttpClientAdapter($guzzleOptions);
      }
      unset($guzzleOptions[self::HTTP_CONTEXT_OPTION], $guzzleOptions[self::HTTP_ALLOW_HTTP_OPTION]);
      return new \GuzzleHttp\Client($guzzleOptions);
   }

   /**
    * Opção extra de `httpClient()`: contexto do `Glpi\Toolbox\HttpClient` do GLPI 12 (class-string, ex.:
    * `Auth`), que decide proxy (`proxy_exclusions`) e rede privada
    * (`GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS`; o padrão do core libera `Agent` e `Auth`).
    * Sem a opção, o adaptador usa o contexto `PluginNextoolCloudClient`. Ignorada no 10/11 (Guzzle).
    * Quem chama confere `defined('PluginNextoolCompat::HTTP_CONTEXT_OPTION')` para seguir compatível
    * com base antiga.
    */
   public const HTTP_CONTEXT_OPTION = 'nextool_context';

   /**
    * Opção extra de `httpClient()`: aceitar `http://` no adaptador do GLPI 12 (que por padrão só aceita
    * `https://`, como o curl do Guzzle da base). No 10/11 o `http` já era aceito pela allowlist do core.
    */
   public const HTTP_ALLOW_HTTP_OPTION = 'nextool_allow_http';
}

}
