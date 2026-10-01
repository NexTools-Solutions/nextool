<?php
/**
 * Cloud link -- ferramentas embutidas da BASE (serviço `_base`, contrato 1.1 §2.13.1).
 *
 * Hoje só o `ping`: depois de um `rotate`, o cérebro chama `_base`/`ping` duas vezes. A 1ª chega com o
 * kid novo, leva o 401 de `kid_unknown` e dispara o /validate aqui (PluginNextoolCloudResync); a 2ª,
 * uns 10 s depois, volta `{"pong": true, "kid": "<o desta instalação>"}`, o que prova que as duas
 * pontas trocaram de chave. A chave vazada deixa de valer em segundos, não no próximo Sincronizar.
 *
 * Não há módulo, produto nem ator: é a base provando que tem a chave. Por isso o executor despacha o
 * `_base` antes de procurar módulo, sem licença de produto e sem sessão de usuário. O prefixo `_` não
 * existe em chave de módulo (catálogo), então não disputa nome com nenhum.
 *
 * Formato das ferramentas, o mesmo do `getCloudTools()` dos módulos: `kind` (read|write), `handler` e
 * `schema` (`['args' => [...]]`). Sem `bit`: não há sessão em que conferir permissão, e o ping não
 * expõe dado nenhum além do kid, que não é segredo.
 *
 * @since pós-6.24.1 (contrato 1.1 do cloud link, K1)
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudBaseTools {

   /** Serviço das ferramentas da base no envelope do `exec` (`service`). */
   public const SERVICE = '_base';

   /** @return array<string, array{kind:string, handler:callable, schema:array}> */
   public static function tools(): array {
      return [
         'ping' => [
            'kind'    => 'read',
            'handler' => [self::class, 'ping'],
            'schema'  => ['args' => []],
         ],
      ];
   }

   /**
    * `_base`/`ping`: a chamada chegou até aqui com assinatura e kid aceitos. Devolve o kid da chave
    * local, para o cérebro conferir que é o mesmo que ele calcula. Não lê nem grava mais nada.
    *
    * @param array $args ignorado (o contrato define `{}`)
    * @return array{pong:bool, kid:string}
    */
   public static function ping(array $args = []): array {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      $cred = PluginNextoolCloudCreds::get();

      return ['pong' => true, 'kid' => $cred !== null ? (string) $cred['kid'] : ''];
   }

   /**
    * Entrada `_base` do anúncio (`ferramentas` da declaração do endereço, §2.13.4), no formato das dos
    * módulos. Só é anunciada quando o executor já despacha o `_base` (ver
    * PluginNextoolCloudClient::ferramentas()).
    *
    * @return array{versao:string, tools:array}
    */
   public static function announcement(string $baseVersion): array {
      $tools = [];
      foreach (self::tools() as $nome => $spec) {
         $tools[$nome] = ['kind' => $spec['kind'], 'args' => new \stdClass()];
      }

      return ['versao' => $baseVersion, 'tools' => $tools];
   }
}
