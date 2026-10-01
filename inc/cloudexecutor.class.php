<?php
/**
 * Cloud link v1 -- pipeline de execução de uma ferramenta pedida pelo cérebro.
 *
 * Entra um corpo já AUTENTICADO (ver PluginNextoolCloudRequestGuard) e sai a resposta do contrato:
 *   {ok:true, result:<estruturado>} | {ok:false, error:{code, message, retryable}}
 *
 * Ordem do pipeline e o porquê de cada passo:
 *   1. formato + GLPI    -- corpo mal formado responde `validation`. GLPI 10 responde `unavailable`
 *                           não retentável (ME-07, ver glpiSupported());
 *   2. módulo            -- existe, instalado, ATIVO e no modo nuvem (`isCloudEnabled()`);
 *   3. licença           -- o produto que o módulo declara. Antes de qualquer estado: a recusa por
 *                           licença não grava linha nem monta sessão;
 *   4. ferramenta        -- existe no mapa, tem handler e um `kind` (read|write; ausente = write);
 *   5. tetos             -- SERVICE_RATE_MAX chamadas do serviço e ENV_RATE_MAX do ambiente inteiro por
 *                           RATE_WINDOW, somando todos os atores, inclusive os não vinculados (ME-04).
 *                           Antes do ator, para uma rajada de chats desconhecidos não varrer a tabela de
 *                           vínculo;
 *   6. ator              -- resolvido pelo MÓDULO, na tabela local de vínculo, nunca um `users_id` do
 *                           wire. O pseudônimo usa o `external_id` CANÔNICO que o módulo devolve
 *                           (LO-09: '0123' e '123' são o mesmo balde), ou o cru;
 *   7. request_id        -- já visto? resposta gravada, `idempotency_mismatch`, "em andamento" ou órfã.
 *                           Depois do ator DE PROPÓSITO: a consulta e a gravação usam o MESMO
 *                           pseudônimo canônico; com o cru na consulta, o retry de uma forma não
 *                           canônica não acharia a linha e executaria de novo;
 *   8. limite por ator   -- RATE_MAX por RATE_WINDOW;
 *   9. não vinculado     -- recusa que fica CONTADA (linha sem conteúdo), para o teto do passo 5.
 *                           Exceção: ferramenta com `'actor' => 'any'` (toolAcceptsUnlinked(), hoje só o
 *                           `link_chat`) segue sem vínculo e roda SEM os passos 11 e 12;
 *                           CONTATO EXTERNO (6.27.0, contrato §8.5): `actor.kind = external_contact` só
 *                           passa se a ferramenta declarar que aceita (fail-closed) e se o módulo o
 *                           resolver (`resolveCloudExternalActor()`, que diz não com a opção do cliente
 *                           desligada). Roda como o usuário de serviço do canal, com o contato no
 *                           contexto (PluginNextoolCloudContacts) e uma linha de auditoria por ação;
 *  10. reserva           -- `in_flight` ANTES do handler, com o `kind` e o hash de (service, tool, args);
 *  11. runAs             -- sessão efêmera sob o perfil PADRÃO, com a conta válida como no login;
 *  12. bit do módulo     -- `canUse(<módulo>, <bit>)` com a sessão montada; bit inválido RECUSA;
 *  13. handler           -- a ferramenta roda e usa `can()` nativo do GLPI nos objetos;
 *  14. settle            -- a reserva vira o desfecho.
 *
 * At-most-once (decisão (f) do owner, 2026-09-24: perder uma ação é aceitável; executar duas vezes, não):
 *  - ESCRITA: o desfecho fica gravado com a resposta e responde a todo retry com o mesmo id. Exceção
 *    DEPOIS de o handler começar vira `outcome_unknown`, não retentável (a ação pode ter sido
 *    registrada), nunca uma segunda execução. Só volta a executar a escrita recusada ANTES de gravar:
 *    pela base (bit, conta) ou pela própria ferramenta, com a marca NOT_WRITTEN no resultado.
 *  - LEITURA: nada da resposta fica gravado (ME-08). A linha fica sem conteúdo, só para os limites, e
 *    um retry executa de novo.
 *  - Mesmo id com outro conteúdo (service, tool ou args): `idempotency_mismatch`, sem executar.
 *  - Reserva órfã (o processo morreu no meio): leitura é retomada; escrita vira `outcome_unknown`. A
 *    idade que a torna órfã acompanha o `max_execution_time` do PHP (orphanTtl()).
 *
 * Invariante da tabela: linha COM resposta gravada é desfecho definitivo e nunca é apagada pelo
 * executor (só pela retenção de 24 h, PluginNextoolCronCloudPurge); linha SEM resposta é "só conta
 * nos limites" e pode ser trocada por uma execução nova do mesmo id.
 *
 * Por que a reserva: até a 6.24.0 o `request_id` só era gravado DEPOIS da execução. Um retry do
 * cérebro que chegasse enquanto a primeira chamada ainda rodava (anexo baixando, chamado lento)
 * encontrava o `getSettled` vazio e executava de novo -- dois chamados, dois anexos. A PK de
 * `request_id` é a trava: o INSERT da reserva recusa o segundo, sem janela entre checar e gravar.
 *
 * @since 6.23.0
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudExecutor {

   public const REQUESTS_TABLE = 'glpi_plugin_nextool_cloud_requests';
   public const RETENTION_HOURS = 24;
   public const RATE_WINDOW = 60;
   public const RATE_MAX = 20;
   public const LIST_LIMIT = 20;

   /**
    * Teto por SERVIÇO (ME-04; por serviço desde a 6.27.0), somando todos os atores e as recusas de ator
    * não vinculado, na mesma janela de RATE_WINDOW. 300/min = 15 atores no limite individual, ou 5
    * chamadas/s. O cérebro limita a entrada de cada canal a 120 mensagens por ambiente por minuto, e um
    * comando custa 1 ou 2 chamadas aqui (resolve_actor + ferramenta): o uso legítimo fica abaixo do teto.
    * Acima dele é laço ou segredo vazado.
    *
    * Até a 6.26.2 este era o teto do ambiente inteiro, dimensionado só para o Telegram. Com o WhatsApp e o
    * livechat no mesmo vínculo (contrato §8.7), um serviço em rajada gastaria a cota dos outros.
    */
   public const SERVICE_RATE_MAX = 300;

   /**
    * Teto do AMBIENTE, somando todos os serviços (6.27.0): o quanto o GLPI do cliente segura, 10 chamadas/s.
    * O cérebro mantém os dele 20% abaixo destes dois (contrato §8.7).
    */
   public const ENV_RATE_MAX = 600;

   /** Tipo da ferramenta em `getCloudTools()` (`kind`). Ausente, ou qualquer outro valor, = escrita. */
   public const KIND_READ  = 'read';
   public const KIND_WRITE = 'write';
   /** Ferramenta que aceita chat NÃO vinculado (toolAcceptsUnlinked()). */
   public const ACTOR_ANY  = 'any';

   /**
    * Tipo de ator do contato sem conta no GLPI (contrato §8.5) e a declaração da ferramenta que o aceita:
    * `'actors' => [ACTOR_KIND_GLPI_USER, ACTOR_KIND_EXTERNAL]` (toolAcceptsExternal()). Sem a declaração,
    * a ferramenta só atende usuário do GLPI.
    *
    * @since 6.27.0
    */
   public const ACTOR_KIND_EXTERNAL  = 'external_contact';
   public const ACTOR_KIND_GLPI_USER = 'glpi_user';

   /**
    * Marca de recusa DECLARADA: a ferramenta de escrita que desistiu ANTES de gravar qualquer coisa
    * devolve `NOT_WRITTEN => true` junto do resultado. Só com ela o `request_id` volta a poder
    * executar; a base tira a marca antes de responder. Ver PluginNextoolBaseModule::getCloudTools().
    */
   public const NOT_WRITTEN = '_not_written';

   /** Códigos de erro do contrato. */
   public const E_PERMISSION = 'permission_denied';
   public const E_NOT_FOUND  = 'not_found';
   public const E_VALIDATION = 'validation';
   public const E_EXECUTION  = 'execution_error';
   public const E_UNAVAILABLE = 'unavailable';
   /** Escrita que pode ter sido registrada: não retentável, a pessoa confere antes de repetir. */
   public const E_OUTCOME_UNKNOWN = 'outcome_unknown';
   /** Mesmo `request_id` com outro conteúdo (service, tool ou args): não executa, não retentável. */
   public const E_IDEMPOTENCY_MISMATCH = 'idempotency_mismatch';

   /** Contexto de `glpi_configs` do cloud link (segredo local do pseudônimo). Sai no uninstall. */
   public const CONFIG_CONTEXT = 'plugin:nextool_cloud';

   /** `code` da reserva: a ferramenta está rodando agora. */
   private const IN_FLIGHT = 'in_flight';

   /**
    * Idade de uma reserva órfã, em segundos: `max_execution_time` + folga, entre o piso e o teto (ver
    * orphanTtl()). O piso é o antigo valor fixo e cobre os timeouts do cérebro (20 s; 30 s no anexo).
    */
   private const ORPHAN_TTL_FLOOR  = 120;
   private const ORPHAN_TTL_MARGIN = 30;
   private const ORPHAN_TTL_CAP    = 600;

   /** UTF-8 inválido vira U+FFFD em vez de derrubar a serialização (LO-02). */
   private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

   private const ACTOR_KEY_NAME = 'actor_hash_key';

   /** @var string|null segredo do pseudônimo, lido uma vez por request */
   private static $actorKeyCache = null;

   /**
    * @param array $payload corpo decodificado: {request_id, service, tool, args, actor}
    * @return array resposta do contrato (sempre serializável por encode())
    */
   public static function run(array $payload): array {
      try {
         return self::pipeline($payload);
      } catch (\Throwable $e) {
         // Só chega aqui o que falhou FORA da execução da ferramenta (a reserva em diante trata as
         // próprias exceções): nada foi gravado nem executado, e o retry é seguro.
         $rotulo = static function ($valor): string {
            return substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $valor)), 0, 64) ?: '?';
         };
         self::logThrowable($rotulo($payload['service'] ?? ''), $rotulo($payload['tool'] ?? ''), $e);
         return self::error(self::E_EXECUTION, __('Falha ao executar a ação.', 'nextool'), true);
      }
   }

   /** Resposta de erro no formato do contrato. */
   public static function error(string $code, string $message, bool $retryable): array {
      return ['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'retryable' => $retryable]];
   }

   /**
    * Corpo HTTP da resposta. Sai sempre JSON legível: até a 6.24.1 uma falha do `json_encode` (texto
    * com UTF-8 inválido) virava 200 sem corpo (LO-02).
    */
   public static function encode(array $response): string {
      $json = json_encode($response, self::JSON_FLAGS);

      return is_string($json)
         ? $json
         : '{"ok":false,"error":{"code":"execution_error","message":"","retryable":true}}';
   }

   private static function pipeline(array $payload): array {
      $requestId = (string) ($payload['request_id'] ?? '');
      $service   = (string) ($payload['service'] ?? '');
      $tool      = (string) ($payload['tool'] ?? '');
      $args      = is_array($payload['args'] ?? null) ? $payload['args'] : [];
      $actor     = is_array($payload['actor'] ?? null) ? $payload['actor'] : [];

      if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $requestId)
          || !preg_match('/^[a-z0-9_]{2,32}$/', $service)
          || !preg_match('/^[a-z0-9_]{2,64}$/', $tool)) {
         return self::error(self::E_VALIDATION, __('Requisição inválida.', 'nextool'), false);
      }
      if (!self::glpiSupported()) {
         return self::error(self::E_UNAVAILABLE, __('Serviço indisponível nesta versão do GLPI.', 'nextool'), false);
      }

      // Ferramentas embutidas da base (`_base`, contrato 1.1 §2.13.1): antes do resolveModule, porque
      // não há módulo, produto nem ator. Ver runBaseTool().
      require_once NEXTOOL_PHP_DIR . '/inc/cloudbasetools.class.php';
      if ($service === PluginNextoolCloudBaseTools::SERVICE) {
         return self::runBaseTool($tool, $args);
      }

      $module = self::resolveModule($service);
      if ($module === null) {
         return self::error(self::E_NOT_FOUND, __('Serviço indisponível nesta instância.', 'nextool'), false);
      }
      // Antes do ator de propósito: módulo desligado não consulta nem o vínculo do chat.
      if (!self::moduleServes($module)) {
         return self::error(self::E_UNAVAILABLE, __('Serviço não está ativo para este ambiente.', 'nextool'), false);
      }
      // Direito de usar: vem da LICENÇA, pela chave que o módulo declara -- a do PRODUTO quando o
      // módulo é FREE com feature paga, a dele mesmo quando o módulo é pago. Régua única com a tela do
      // módulo (PluginNextoolCloudCreds::entitled). Conferida antes da reserva e do runAs: até a 6.24.1
      // a recusa por licença montava sessão e ficava gravada 24 h como se fosse uma execução.
      $chaveLicenca = method_exists($module, 'getCloudLicenseKey')
         ? ($module->getCloudLicenseKey() ?? $service)
         : $service;
      if (!self::entitlementActive($chaveLicenca, $module->getCloudEntitlement())) {
         return self::error(self::E_UNAVAILABLE, __('Serviço não está ativo para este ambiente.', 'nextool'), false);
      }

      $tools = $module->getCloudTools();
      $spec  = is_array($tools[$tool] ?? null) ? $tools[$tool] : null;
      if ($spec === null || !is_callable($spec['handler'] ?? null)) {
         return self::error(self::E_NOT_FOUND, __('Ação desconhecida.', 'nextool'), false);
      }
      $kind = self::toolKind($spec);
      if (array_key_exists('kind', $spec) && !in_array($spec['kind'], [self::KIND_READ, self::KIND_WRITE], true)) {
         self::log(sprintf("[cloud_exec] %s/%s: kind inválido no getCloudTools() (esperado 'read' ou 'write'); tratada como escrita\n", $service, $tool));
      }
      $argsHash = self::argsHash($service, $tool, $args);

      // Ferramenta que aceita chat NÃO vinculado (`'actor' => 'any'`; hoje só o `link_chat` do telegrambot,
      // a prova de posse do chat do ME-15) se autoriza sozinha e roda sem sessão. Um bit declarado nela
      // seria uma porta nunca conferida: configuração errada, recusada.
      $semVinculo = self::toolAcceptsUnlinked($spec);
      if ($semVinculo && self::toolBit($spec) !== 0) {
         self::log(sprintf("[cloud_exec] %s/%s: 'actor' => 'any' com 'bit' declarado; recusada\n", $service, $tool));
         return self::error(self::E_PERMISSION, __('Você não tem permissão para esta ação.', 'nextool'), false);
      }

      if (self::countWindow([]) >= self::ENV_RATE_MAX
          || self::countWindow(['service' => $service]) >= self::SERVICE_RATE_MAX) {
         return self::error(self::E_UNAVAILABLE, __('Muitas solicitações neste ambiente. Tente em instantes.', 'nextool'), true);
      }

      if (($actor['kind'] ?? null) === self::ACTOR_KIND_EXTERNAL) {
         return self::runExternal($module, $requestId, $service, $tool, $kind, $spec, $args, $argsHash, $actor);
      }

      $resolved = $module->resolveCloudActor($actor);
      $usersId  = is_array($resolved) ? (int) ($resolved['users_id'] ?? 0) : 0;
      $canonico = null;
      if ($usersId > 0 && (is_string($resolved['external_id'] ?? null) || is_int($resolved['external_id'] ?? null))
          && (string) $resolved['external_id'] !== '') {
         $canonico = (string) $resolved['external_id'];
      }
      $actorHash = self::actorHash($actor, $canonico);
      if ($actorHash === null) {
         self::log("[cloud_exec] segredo local do pseudônimo indisponível (chave da instância do GLPI); recusado\n");
         return self::error(self::E_UNAVAILABLE, __('Serviço não está ativo para este ambiente.', 'nextool'), false);
      }

      $row = self::findRow($requestId);
      if ($row !== null) {
         $pronto = self::answerExisting($row, $actorHash, $service, $tool, $argsHash, true);
         if ($pronto !== null) {
            return $pronto;
         }
      }

      if (self::countWindow(['actor_hash' => $actorHash]) >= self::RATE_MAX) {
         return self::error(self::E_UNAVAILABLE, __('Muitas solicitações. Tente em instantes.', 'nextool'), true);
      }

      $linha = [
         'request_id' => $requestId,
         'service'    => $service,
         'tool'       => $tool,
         'kind'       => $kind,
         'args_hash'  => $argsHash,
         'actor_hash' => $actorHash,
      ];

      if ($usersId <= 0 && !$semVinculo) {
         // A recusa fica gravada, sem conteúdo, para contar no teto do ambiente: sem isso, sondar quais
         // chats estão vinculados (o `resolve_actor` não tem bit) não tinha limite nenhum.
         self::insertRow($linha + ['code' => self::E_PERMISSION]);
         return self::error(self::E_PERMISSION, __('Usuário não vinculado.', 'nextool'), false);
      }

      // Reserva ANTES de executar. Quem perder a corrida pela PK é um retry concorrente: responde com o
      // que o vencedor já tiver (em andamento ou pronto), sem rodar a ferramenta de novo.
      if (!self::insertRow($linha + ['code' => self::IN_FLIGHT])) {
         $atual = self::findRow($requestId);
         if ($atual === null) {
            self::log(sprintf("[cloud_exec] %s/%s: reserva não gravada; nada executado\n", $service, $tool));
            return self::inFlightError();
         }
         return self::answerExisting($atual, $actorHash, $service, $tool, $argsHash, false) ?? self::inFlightError();
      }

      return self::execute($requestId, $service, $tool, $kind, $spec, $args, $usersId, $semVinculo ? $actor : null);
   }

   /**
    * Roda a ferramenta sobre a reserva já gravada e grava o desfecho. Não lança: toda saída daqui
    * passa pelo settle, para a reserva nunca ficar `in_flight` por uma exceção nossa.
    */
   private static function execute(string $requestId, string $service, string $tool, string $kind, array $spec, array $args, int $usersId, ?array $atorSemVinculo = null, ?array $contato = null): array {
      $inicio  = microtime(true);
      $comecou = false; // o handler foi chamado: daqui em diante a escrita pode ter acontecido

      try {
         if ($contato !== null) {
            require_once NEXTOOL_PHP_DIR . '/inc/cloudcontacts.class.php';
            PluginNextoolCloudContacts::begin($contato);
         }
         if ($atorSemVinculo !== null) {
            // Sem sessão de usuário e sem bit (toolAcceptsUnlinked()): a ferramenta recebe o ator cru e
            // decide sozinha, mesmo com o chat já vinculado.
            $handler  = $spec['handler'];
            $comecou  = true;
            $result   = $handler($args, $atorSemVinculo);
            $response = ['ok' => true, 'result' => is_array($result) ? $result : ['value' => $result]];
         } else {
         $response = PluginNextoolCloudSession::runAs($usersId, static function () use ($service, $tool, $spec, $args, &$comecou) {
            $bit = self::toolBit($spec);
            if ($bit === null) {
               self::logInvalidBit($service, $tool);
               return self::error(self::E_PERMISSION, __('Você não tem permissão para esta ação.', 'nextool'), false);
            }
            if ($bit > 0 && !self::canUse($service, $bit)) {
               return self::error(self::E_PERMISSION, __('Você não tem permissão para esta ação.', 'nextool'), false);
            }

            $handler = $spec['handler'];
            $comecou = true;
            $result  = $handler($args);
            return ['ok' => true, 'result' => is_array($result) ? $result : ['value' => $result]];
         });
         }
      } catch (\Throwable $e) {
         $response = self::fromThrowable($e, $comecou, $kind, $service, $tool);
      } finally {
         if ($contato !== null) {
            PluginNextoolCloudContacts::end();
         }
      }

      try {
         $declarada = false;
         if (($response['ok'] ?? false) === true && is_array($response['result'] ?? null)
             && array_key_exists(self::NOT_WRITTEN, $response['result'])) {
            $declarada = $response['result'][self::NOT_WRITTEN] === true;
            unset($response['result'][self::NOT_WRITTEN]);
         }
         // Desfecho definitivo: escrita cujo handler começou e não declarou que desistiu antes de gravar.
         $definitivo = $kind === self::KIND_WRITE && $comecou && !$declarada;

         [$response, $blob] = self::serializable($response, $definitivo);
         self::settle($requestId, $response, $definitivo ? $blob : '', (int) round((microtime(true) - $inicio) * 1000));
         if ($contato !== null) {
            self::auditExternal($contato, $service, $tool, $requestId, $response, $args);
         }
      } catch (\Throwable $e) {
         self::logThrowable($service, $tool, $e);
         $escreveu = $kind === self::KIND_WRITE && $comecou;
         $response = $escreveu
            ? self::outcomeUnknown()
            : self::error(self::E_EXECUTION, __('Falha ao executar a ação.', 'nextool'), true);
         self::settle($requestId, $response, $escreveu ? self::encode($response) : '', (int) round((microtime(true) - $inicio) * 1000));
      }

      return $response;
   }

   /**
    * Ferramenta embutida da base (`_base`, contrato 1.1 §2.13.1), hoje só o `ping`: a prova, depois de
    * um `rotate`, de que as duas pontas usam a mesma chave (assinatura e kid já passaram no guard). Não
    * há módulo, produto nem ator, então não há licença de produto, sessão, bit nem pseudônimo.
    *
    *  - teto do ambiente: conferido (ENV_RATE_MAX), para o ping ser recusado numa rajada como qualquer
    *    chamada. Ele não grava linha e por isso não consome o teto: só chega aqui quem tem a chave, e
    *    com ela poderia chamar qualquer ferramenta, que conta;
    *  - request_id: validado no formato (passo 1), sem reserva nem desfecho gravado. O ping é leitura
    *    sem efeito, repetir é seguro, e a linha exigiria um ator que não existe. Um request_id de ping
    *    reusado numa ferramenta de módulo segue a regra normal dela;
    *  - GLPI 10: recusado antes daqui, como o resto (glpiSupported()).
    */
   private static function runBaseTool(string $tool, array $args): array {
      $spec = PluginNextoolCloudBaseTools::tools()[$tool] ?? null;
      if (!is_array($spec) || !is_callable($spec['handler'] ?? null)) {
         return self::error(self::E_NOT_FOUND, __('Ação desconhecida.', 'nextool'), false);
      }
      if (self::countWindow([]) >= self::ENV_RATE_MAX) {
         return self::error(self::E_UNAVAILABLE, __('Muitas solicitações neste ambiente. Tente em instantes.', 'nextool'), true);
      }
      try {
         $result = ($spec['handler'])($args);
      } catch (\Throwable $e) {
         self::logThrowable(PluginNextoolCloudBaseTools::SERVICE, $tool, $e);
         return self::error(self::E_EXECUTION, __('Falha ao executar a ação.', 'nextool'), true);
      }

      return ['ok' => true, 'result' => is_array($result) ? $result : ['value' => $result]];
   }

   /**
    * Exceção -> resposta. A fronteira é o handler ter COMEÇADO: antes disso nada da ferramenta rodou.
    */
   private static function fromThrowable(\Throwable $e, bool $comecou, string $kind, string $service, string $tool): array {
      if (!$comecou && $e instanceof PluginNextoolCloudSessionException) {
         // A sessão não montou (conta fora da validade, sem perfil, sem entidade...).
         $map = [
            'actor_unresolved' => self::E_PERMISSION,
            'actor_inactive'   => self::E_PERMISSION,
            'actor_no_profile' => self::E_PERMISSION,
            'actor_no_entity'  => self::E_PERMISSION,
            'session_nested'   => self::E_EXECUTION,
         ];
         $code = $map[$e->getMessage()] ?? self::E_EXECUTION;
         return self::error($code, __('Não foi possível executar a ação com o seu usuário.', 'nextool'), false);
      }

      self::logThrowable($service, $tool, $e);
      // Escrita que começou: o `add()` do GLPI grava e só depois roda post_addItem e os hooks de outros
      // plugins, sem transação. A exceção pode ter vindo DEPOIS do efeito, e repetir abriria o segundo
      // chamado. Até a 6.24.1 isto era `execution_error` retentável, com a reserva apagada.
      if ($comecou && $kind === self::KIND_WRITE) {
         return self::outcomeUnknown();
      }

      return self::error(self::E_EXECUTION, __('Falha ao executar a ação.', 'nextool'), true);
   }

   /** A ação pode ter acontecido: a pessoa confere antes de repetir. */
   private static function outcomeUnknown(): array {
      return self::error(self::E_OUTCOME_UNKNOWN, __('A ação pode ter sido registrada. Confira antes de repetir.', 'nextool'), false);
   }

   /**
    * Garante a resposta serializável e devolve o JSON que será gravado. Se nem com a substituição do
    * UTF-8 der, o desfecho de escrita vira `outcome_unknown` (a ação rodou, a resposta não sai) e o
    * resto vira `execution_error`: o estado gravado é sempre legível.
    *
    * @return array{0:array, 1:string}
    */
   private static function serializable(array $response, bool $definitivo): array {
      $json = json_encode($response, self::JSON_FLAGS);
      if (is_string($json)) {
         return [$response, $json];
      }
      self::log(sprintf("[cloud_exec] resposta não serializável (%s)\n", json_last_error_msg()));
      $troca = $definitivo
         ? self::outcomeUnknown()
         : self::error(self::E_EXECUTION, __('Falha ao executar a ação.', 'nextool'), true);

      return [$troca, self::encode($troca)];
   }

   /**
    * Pseudônimo do ator: HMAC-SHA256(kind|external_id) com o segredo local deste GLPI (ME-08).
    *
    * Até a 6.24.1 era sha256 sem chave: o espaço de chat_id é enumerável, e o chat_id fica em claro na
    * tabela do módulo, no mesmo banco. Com o segredo (cifrado pela chave da instância), a tabela de
    * requisições sozinha não diz quem pediu. Linhas gravadas com o hash antigo não casam com o novo e
    * vencem em 24 h.
    *
    * @param array       $actor      `{kind, external_id}` como veio do cérebro
    * @param string|null $externalId forma CANÔNICA devolvida pelo módulo (LO-09); null = a do corpo
    * @return string|null null = segredo local indisponível (chave da instância do GLPI)
    */
   public static function actorHash(array $actor, ?string $externalId = null): ?string {
      $key = self::actorKey();
      if ($key === null) {
         return null;
      }
      $kind = (string) ($actor['kind'] ?? '');
      $ext  = $externalId ?? (string) ($actor['external_id'] ?? '');

      return hash_hmac('sha256', $kind . '|' . $ext, $key);
   }

   /**
    * Segredo local do pseudônimo do ator: 32 bytes aleatórios em hex, gerado na instalação e guardado
    * CIFRADO pelo PluginNextoolSecretVault (chave da instância do GLPI) em CONFIG_CONTEXT. Sai no
    * uninstall, junto com as tabelas do cloud link.
    *
    * A criação é atômica: o INSERT direto em `glpi_configs` bate na UNIQUE (context, name) quando dois
    * processos correm, e o perdedor relê o do vencedor. Com o `Config::setConfigurationValues` os dois
    * gravariam, e as linhas de um ficariam com um pseudônimo que o outro não reconhece.
    *
    * @param bool $create false = só consultar
    * @return string|null null = ausente, ou chave da instância indisponível (nunca grava em claro)
    */
   public static function actorKey(bool $create = true): ?string {
      if (self::$actorKeyCache !== null) {
         return self::$actorKeyCache;
      }
      require_once NEXTOOL_PHP_DIR . '/inc/secretvault.class.php';

      $gravado = self::readActorKey();
      if ($gravado['key'] === null && $create) {
         $cifrado = PluginNextoolSecretVault::encrypt(bin2hex(random_bytes(32)));
         if ($cifrado === '') {
            return null; // fail-secure do vault: sem a chave da instância, não há segredo
         }
         global $DB;
         try {
            if ($gravado['raw'] === null) {
               $DB->insert('glpi_configs', [
                  'context' => self::CONFIG_CONTEXT,
                  'name'    => self::ACTOR_KEY_NAME,
                  'value'   => $cifrado,
               ]);
            } else {
               // Existe e não decifra: a chave da instância (glpicrypt.key) mudou. Troca só se ninguém
               // trocou antes; os pseudônimos das últimas 24 h deixam de casar, o que só zera limites.
               self::log("[cloud_exec] segredo do pseudônimo não decifra (chave da instância mudou); gerando outro\n");
               $DB->update('glpi_configs', ['value' => $cifrado], [
                  'context' => self::CONFIG_CONTEXT,
                  'name'    => self::ACTOR_KEY_NAME,
                  'value'   => $gravado['raw'],
               ]);
            }
         } catch (\Throwable $e) {
            // perdeu a corrida pela UNIQUE: vale o do vencedor, relido abaixo
         }
         $gravado = self::readActorKey();
      }
      if ($gravado['key'] !== null) {
         self::$actorKeyCache = $gravado['key'];
      }

      return $gravado['key'];
   }

   /** @return array{raw:?string, key:?string} valor gravado (cifrado) e o segredo, se válido */
   private static function readActorKey(): array {
      global $DB;
      $row = $DB->request([
         'SELECT' => ['value'],
         'FROM'   => 'glpi_configs',
         'WHERE'  => ['context' => self::CONFIG_CONTEXT, 'name' => self::ACTOR_KEY_NAME],
         'LIMIT'  => 1,
      ])->current();
      if (!$row) {
         return ['raw' => null, 'key' => null];
      }
      $raw   = (string) $row['value'];
      $plain = PluginNextoolSecretVault::decrypt($raw);
      // Só vale o que este código grava: cifrado e 64 hex. Valor em claro não é aceito.
      $valido = PluginNextoolSecretVault::isEncrypted($raw) && preg_match('/^[0-9a-f]{64}$/', $plain) === 1;

      return ['raw' => $raw, 'key' => $valido ? $plain : null];
   }

   /**
    * Impressão digital do pedido: sha256 de (service, tool, args), com as chaves de objeto ordenadas
    * (reordenar os campos não é outro pedido; mudar um valor ou o tipo dele é).
    */
   public static function argsHash(string $service, string $tool, array $args): string {
      return hash('sha256', serialize([$service, $tool, self::canonical($args)]));
   }

   /** @param mixed $value */
   private static function canonical($value) {
      if (!is_array($value)) {
         return $value;
      }
      $lista = $value === [] || array_keys($value) === range(0, count($value) - 1);
      if (!$lista) {
         ksort($value, SORT_STRING);
      }
      foreach ($value as $k => $v) {
         $value[$k] = self::canonical($v);
      }

      return $value;
   }

   /**
    * O cloud link roda neste GLPI? Só do 11 em diante (ME-07).
    *
    * No GLPI 10 a camada de banco não escapa o valor (`DBmysql::quoteValue`): conta com o Sanitizer
    * que o core aplica a $_POST/$_GET, e o exec lê o corpo cru. Um apóstrofo digitado no Telegram
    * quebraria o SQL do settle ou do `add()` da ferramenta (ou injetaria). Recusar é mais simples que
    * normalizar e não tira nada de ninguém: nenhum módulo de nuvem roda no G10 hoje. Quando algum
    * rodar, a normalização entra e esta recusa sai.
    */
   private static function glpiSupported(): bool {
      return defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '11.0.0-dev', '>=');
   }

   /** Módulo dono do serviço, resolvido preguiçosamente e só se expuser o contrato do cloud link. */
   private static function resolveModule(string $service) {
      if (!class_exists('PluginNextoolModuleManager')) {
         return null;
      }
      try {
         $module = PluginNextoolModuleManager::getInstance()->getModule($service);
      } catch (\Throwable $e) {
         return null;
      }
      if (!is_object($module)
          || !method_exists($module, 'getCloudTools')
          || !method_exists($module, 'resolveCloudActor')
          || !method_exists($module, 'getCloudEntitlement')) {
         return null;
      }
      return $module;
   }

   /**
    * O módulo está em condição de atender a nuvem? Instalado, ATIVO e, quando ele sabe dizer
    * (`isCloudEnabled()`), no modo nuvem -- o admin pode forçar o modo local.
    *
    * O `getModule()` instancia qualquer módulo descoberto em disco, ativo ou não. É o mesmo defeito
    * que o `statelessModuleGate()` fechou nos webhooks (nextool-dev#253) e que este executor, nascido
    * depois, não herdou: o admin desativava o módulo e a nuvem seguia abrindo chamado e aprovando.
    */
   private static function moduleServes($module): bool {
      try {
         if (!$module->isInstalled() || !$module->isEnabled()) {
            return false;
         }

         return !method_exists($module, 'isCloudEnabled') || (bool) $module->isCloudEnabled();
      } catch (\Throwable $e) {
         return false;
      }
   }

   /**
    * Bit do módulo que a ferramenta exige. FAIL-CLOSED:
    *  - sem a chave `bit`: ferramenta sem porta própria, DE PROPÓSITO (ex.: `resolve_actor`) -> 0;
    *  - inteiro > 0: o bit;
    *  - qualquer outro valor (0, negativo, string, nome de constante): configuração errada -> null,
    *    e a ferramenta é RECUSADA.
    *
    * Até a 6.24.1 o `(int)` transformava tudo isso em 0 = "sem porta": um módulo que seguisse o
    * docblock antigo (`'bit' => '<CONST>'`) ou renomeasse uma constante abria a ferramenta para todo
    * usuário vinculado, sem nenhum teste quebrar.
    *
    * @return int|null
    */
   public static function toolBit(array $spec): ?int {
      if (!array_key_exists('bit', $spec)) {
         return 0;
      }
      $bit = $spec['bit'];

      return (is_int($bit) && $bit > 0) ? $bit : null;
   }

   /**
    * Tipo da ferramenta. FAIL-CLOSED: só é leitura o que declara exatamente `'kind' => 'read'`; sem a
    * chave, ou com qualquer outro valor, é escrita -- tratar uma escrita como leitura permitiria
    * repeti-la, e tratar uma leitura como escrita só guarda uma resposta a mais por 24 h.
    */
   public static function toolKind(array $spec): string {
      return ($spec['kind'] ?? null) === self::KIND_READ ? self::KIND_READ : self::KIND_WRITE;
   }

   /**
    * A ferramenta aceita chat NÃO vinculado? Só quando declara exatamente `'actor' => self::ACTOR_ANY`.
    * Ela roda sem sessão de usuário e sem bit, e recebe o ator cru como segundo argumento do handler:
    * quem autoriza é ela (hoje só o `link_chat` do telegrambot, pelo código de uso único; ME-15). Sem a
    * chave, ou com outro valor, vale o padrão: chat vinculado, sessão do usuário e bit.
    */
   public static function toolAcceptsUnlinked(array $spec): bool {
      return ($spec['actor'] ?? null) === self::ACTOR_ANY;
   }

   /**
    * A ferramenta atende CONTATO EXTERNO? Só quando declara exatamente
    * `'actors' => [..., ACTOR_KIND_EXTERNAL, ...]`. Sem a chave, ou com outro valor: não (fail-closed).
    * `'actor' => 'any'` (prova de posse, sem sessão) nunca atende contato externo.
    *
    * @since 6.27.0
    */
   public static function toolAcceptsExternal(array $spec): bool {
      if (self::toolAcceptsUnlinked($spec)) {
         return false;
      }
      $atores = $spec['actors'] ?? null;

      return is_array($atores) && in_array(self::ACTOR_KIND_EXTERNAL, $atores, true);
   }

   /**
    * Pipeline do CONTATO EXTERNO (contrato §8.5), a partir do passo 6. Os passos 1-5 (formato, módulo,
    * licença, ferramenta, tetos) já passaram.
    *
    * Duas barreiras, e as duas são necessárias: o cérebro só manda contato externo com a opção do ambiente
    * ligada, e a base confere de novo pela opção do CLIENTE (quem diz é o módulo, em
    * `resolveCloudExternalActor()`: null = desligada ou contato inválido). A ferramenta precisa declarar que
    * atende contato externo. As recusas ficam contadas, como a do chat não vinculado.
    *
    * O resto é o pipeline comum: idempotência pelo pseudônimo do contato (HMAC de kind|canal:contato),
    * limite por ator por contato, reserva e desfecho. Roda como o usuário de serviço do canal, com o contato
    * no contexto: as ferramentas usam a ligação contato <-> chamado para decidir o que ele vê.
    *
    * @since 6.27.0
    */
   private static function runExternal($module, string $requestId, string $service, string $tool, string $kind, array $spec, array $args, string $argsHash, array $actor): array {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcontacts.class.php';

      $partes   = PluginNextoolCloudContacts::parseExternalId((string) ($actor['external_id'] ?? ''));
      $resolved = null;
      if ($partes !== null && method_exists($module, 'resolveCloudExternalActor')) {
         try {
            $resolved = $module->resolveCloudExternalActor($actor + ['channel' => $partes['channel'], 'contact_ref' => $partes['ref']]);
         } catch (\Throwable $e) {
            self::logThrowable($service, $tool, $e);
            $resolved = null;
         }
      }
      $usersId = is_array($resolved) ? (int) ($resolved['users_id'] ?? 0) : 0;
      $canal   = is_array($resolved) ? (string) ($resolved['channel'] ?? '') : '';
      $ref     = is_array($resolved) ? (string) ($resolved['contact_ref'] ?? '') : '';
      $valido  = $usersId > 0 && $canal !== '' && $ref !== ''
         && PluginNextoolCloudContacts::parseExternalId($canal . ':' . $ref) !== null;

      $actorHash = self::actorHash($actor, $valido ? $canal . ':' . $ref : null);
      if ($actorHash === null) {
         self::log("[cloud_exec] segredo local do pseudônimo indisponível (chave da instância do GLPI); recusado\n");
         return self::error(self::E_UNAVAILABLE, __('Serviço não está ativo para este ambiente.', 'nextool'), false);
      }

      $row = self::findRow($requestId);
      if ($row !== null) {
         $pronto = self::answerExisting($row, $actorHash, $service, $tool, $argsHash, true);
         if ($pronto !== null) {
            return $pronto;
         }
      }
      if (self::countWindow(['actor_hash' => $actorHash]) >= self::RATE_MAX) {
         return self::error(self::E_UNAVAILABLE, __('Muitas solicitações. Tente em instantes.', 'nextool'), true);
      }

      $linha = [
         'request_id' => $requestId,
         'service'    => $service,
         'tool'       => $tool,
         'kind'       => $kind,
         'args_hash'  => $argsHash,
         'actor_hash' => $actorHash,
      ];
      if (!self::toolAcceptsExternal($spec)) {
         self::insertRow($linha + ['code' => self::E_PERMISSION]);
         return self::error(self::E_PERMISSION, __('Esta ação não está disponível para contato sem conta no GLPI.', 'nextool'), false);
      }
      if (!$valido) {
         self::insertRow($linha + ['code' => self::E_PERMISSION]);
         return self::error(self::E_PERMISSION, __('Contato sem conta no GLPI não é atendido neste ambiente.', 'nextool'), false);
      }

      $contatoId = PluginNextoolCloudContacts::touch($service, $canal, $ref, (string) ($resolved['display_name'] ?? ($actor['display_name'] ?? '')));
      $contato   = $contatoId > 0 ? PluginNextoolCloudContacts::get($contatoId) : null;
      if ($contato === null) {
         // Nada executado e o retry é seguro; a linha sem conteúdo conta nos limites, como as outras recusas.
         self::log(sprintf("[cloud_exec] %s/%s: contato externo não gravado; nada executado\n", $service, $tool));
         self::insertRow($linha + ['code' => self::E_EXECUTION]);
         return self::error(self::E_EXECUTION, __('Falha ao executar a ação.', 'nextool'), true);
      }

      if (!self::insertRow($linha + ['code' => self::IN_FLIGHT])) {
         $atual = self::findRow($requestId);
         if ($atual === null) {
            self::log(sprintf("[cloud_exec] %s/%s: reserva não gravada; nada executado\n", $service, $tool));
            return self::inFlightError();
         }
         return self::answerExisting($atual, $actorHash, $service, $tool, $argsHash, false) ?? self::inFlightError();
      }

      return self::execute($requestId, $service, $tool, $kind, $spec, $args, $usersId, null, [
         'contacts_id'   => (int) $contato['id'],
         'service'       => $service,
         'channel'       => $contato['channel'],
         'contact_ref'   => $contato['contact_ref'],
         'contact_label' => $contato['contact_label'],
      ]);
   }

   /**
    * Linha de auditoria do contato externo: ferramenta, chamado, request_id, código. O chamado é o da
    * resposta (abrir, detalhar e acompanhar devolvem) ou, na recusa, o que foi PEDIDO: a tentativa de mexer
    * no chamado de outro é justamente o que a auditoria precisa mostrar.
    */
   private static function auditExternal(array $contato, string $service, string $tool, string $requestId, array $response, array $args): void {
      $ok     = ($response['ok'] ?? false) === true;
      $result = $ok && is_array($response['result'] ?? null) ? $response['result'] : [];
      $ticket = (int) ($result['ticket_id'] ?? $result['id'] ?? 0);
      if ($ticket <= 0) {
         $ticket = (int) ($args['ticket_id'] ?? $args['id'] ?? 0);
      }
      $code   = $ok ? (string) ($result['reason'] ?? 'ok') : (string) ($response['error']['code'] ?? self::E_EXECUTION);
      PluginNextoolCloudContacts::log((int) $contato['contacts_id'], $service, $tool, $ticket, $requestId, $code);
   }

   /**
    * Idade, em segundos, a partir da qual uma reserva `in_flight` é órfã (o processo que a gravou
    * morreu no meio: fatal, `max_execution_time`, restart do PHP).
    *
    * Acompanha o limite real do processo, o `max_execution_time`, com folga, piso e teto. A folga é
    * porque, no Linux, esse limite conta tempo de CPU e não de relógio (espera de rede e de banco não
    * entram). O piso cobre os timeouts do cérebro. Sem limite (0), vale o teto: nenhuma execução do
    * exec dura 10 min, e a conversa já descarta mensagem mais velha que isso. Até a 6.24.1 eram 120 s
    * fixos, sem relação com o processo.
    */
   public static function orphanTtl(): int {
      $limite = (int) ini_get('max_execution_time');
      if ($limite <= 0) {
         return self::ORPHAN_TTL_CAP;
      }

      return max(self::ORPHAN_TTL_FLOOR, min(self::ORPHAN_TTL_CAP, $limite + self::ORPHAN_TTL_MARGIN));
   }

   /**
    * O ambiente tem direito a esta ferramenta?
    *
    * Delega para a régua única (`PluginNextoolCloudCreds::entitled`), que consulta só a LICENÇA
    * (`modules_entitlement`, válido até a graça offline do F2: N8). A segunda fonte (`managed_services`)
    * saiu na auditoria de 2026-09-24 (decisão h). Módulo que não está coberto pela licença NÃO executa:
    * o cloud link é recurso pago, e liberar por omissão é o mesmo fail-open que já existiu aqui -- o
    * método `getManagedEntitlement` não existia e este gate devolvia `true` para todo mundo.
    *
    * @param string      $moduleKey       módulo dono da ferramenta (chave do catálogo)
    * @param string|null $managedService  ignorado pela régua (era a segunda fonte, removida)
    */
   private static function entitlementActive(string $moduleKey, ?string $managedService): bool {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';

      return PluginNextoolCloudCreds::entitled($moduleKey, $managedService);
   }

   /** Bit do módulo com a sessão do ator montada. */
   private static function canUse(string $service, int $bit): bool {
      if (!class_exists('PluginNextoolPermissionManager')) {
         return false;
      }
      try {
         return (bool) PluginNextoolPermissionManager::canUse($service, $bit);
      } catch (\Throwable $e) {
         return false;
      }
   }

   /**
    * Resposta para um `request_id` que já tem linha. null = executar (a linha era de leitura, de
    * recusa antes de gravar ou uma reserva órfã de leitura, e foi removida).
    *
    * @param bool $reexecutar false = quem chama perdeu a corrida da reserva: nunca executa, no máximo
    *                         pede para tentar de novo
    */
   private static function answerExisting(array $row, string $actorHash, string $service, string $tool, string $argsHash, bool $reexecutar): ?array {
      // Mesmo id com OUTRO ator não é retry: é tentativa de colher a resposta alheia.
      if (!hash_equals($row['actor_hash'], $actorHash)) {
         return self::error(self::E_PERMISSION, __('Requisição não permitida.', 'nextool'), false);
      }
      // Mesmo id com OUTRO conteúdo também não é retry. Até a 6.24.1 só o ator era conferido, e o id
      // reusado em outra ferramenta devolvia a resposta da primeira. Linha anterior à coluna
      // `args_hash` (vazia) confere só serviço e ferramenta; ela vence em 24 h.
      if ($row['service'] !== $service || $row['tool'] !== $tool
          || ($row['args_hash'] !== '' && !hash_equals($row['args_hash'], $argsHash))) {
         return self::error(
            self::E_IDEMPOTENCY_MISMATCH,
            __('Este identificador de requisição já foi usado com outro conteúdo.', 'nextool'),
            false
         );
      }

      if ($row['code'] === self::IN_FLIGHT) {
         if ($row['age'] < self::orphanTtl()) {
            return self::inFlightError();
         }
         // Órfã. Escrita nunca é retomada: a primeira execução pode ter gravado antes de morrer.
         if ($row['kind'] !== self::KIND_READ) {
            return self::settleOrphanWrite($row);
         }
         if (!$reexecutar) {
            return self::inFlightError();
         }
         self::deleteRow($row);
         return null;
      }

      if ($row['blob'] !== '') {
         $gravada = json_decode($row['blob'], true);
         // Linha antiga com o blob corrompido (LO-02: o json_encode falhava e gravava '0'): o desfecho
         // existe, mas não é legível.
         return (is_array($gravada) && isset($gravada['ok'])) ? $gravada : self::outcomeUnknown();
      }

      // Sem conteúdo: leitura, ou recusa antes de gravar. Executa de novo.
      if (!$reexecutar) {
         return self::inFlightError();
      }
      self::deleteRow($row);
      return null;
   }

   /**
    * Reserva órfã de escrita vira o desfecho `outcome_unknown`, gravado. Se a execução original
    * terminar depois, o settle dela não sobrescreve (só troca `in_flight`): o desfecho não muda.
    */
   private static function settleOrphanWrite(array $row): array {
      $desconhecido = self::outcomeUnknown();
      self::updateRow([
         'code'          => self::E_OUTCOME_UNKNOWN,
         'response_blob' => self::encode($desconhecido),
      ], [
         'request_id' => $row['request_id'],
         'code'       => self::IN_FLIGHT,
         'created_at' => $row['created_at'],
      ]);
      // A execução original pode ter gravado o desfecho real no meio do caminho: vale o que ficou.
      $atual = self::findRow($row['request_id']);
      if ($atual !== null && $atual['code'] !== self::IN_FLIGHT && $atual['blob'] !== '') {
         $gravada = json_decode($atual['blob'], true);
         if (is_array($gravada) && isset($gravada['ok'])) {
            return $gravada;
         }
      }

      return $desconhecido;
   }

   /** Retry que chegou com a primeira execução ainda rodando: repetir depois, com o MESMO id. */
   private static function inFlightError(): array {
      return self::error(self::E_UNAVAILABLE, __('Requisição em andamento. Tente de novo em instantes.', 'nextool'), true);
   }

   /**
    * Grava uma linha (reserva ou recusa contada). false = já existe linha para este id (retry
    * concorrente) ou o banco recusou -- nos dois casos, nada é executado.
    */
   private static function insertRow(array $values): bool {
      global $DB;
      try {
         return (bool) $DB->insert(self::REQUESTS_TABLE, $values + [
            'response_blob' => '',
            // Relógio do PHP, o MESMO das comparações (limites, idade da reserva, retenção). O
            // DEFAULT CURRENT_TIMESTAMP da coluna segue o fuso da sessão do banco, que diverge do PHP
            // quando o GLPI não usa timezones.
            'created_at'    => date('Y-m-d H:i:s'),
         ]);
      } catch (\Throwable $e) {
         return false; // violação de PK = outra execução do mesmo id
      }
   }

   /** Só apaga linha SEM resposta gravada, e só a que foi lida (mesmo código e mesma data). */
   private static function deleteRow(array $row): void {
      global $DB;
      try {
         $DB->delete(self::REQUESTS_TABLE, [
            'request_id'    => $row['request_id'],
            'code'          => $row['code'],
            'created_at'    => $row['created_at'],
            'response_blob' => '',
         ]);
      } catch (\Throwable $e) {
         // se não apagou, a reserva seguinte bate na PK e responde "em andamento": nunca executa duas vezes
      }
   }

   private static function updateRow(array $values, array $where): bool {
      global $DB;
      try {
         return $DB->update(self::REQUESTS_TABLE, $values, $where) !== false;
      } catch (\Throwable $e) {
         self::log(sprintf("[cloud_exec] gravação do desfecho falhou: %s\n", self::safeMessage($e->getMessage())));
         return false;
      }
   }

   /**
    * @return array{request_id:string, actor_hash:string, service:string, tool:string, kind:string,
    *               args_hash:string, code:string, blob:string, created_at:string, age:int}|null
    */
   private static function findRow(string $requestId): ?array {
      global $DB;
      $row = $DB->request([
         'FROM'  => self::REQUESTS_TABLE,
         'WHERE' => ['request_id' => $requestId],
         'LIMIT' => 1,
      ])->current();
      if (!$row) {
         return null;
      }
      $criado = strtotime((string) $row['created_at']);

      return [
         'request_id' => $requestId,
         'actor_hash' => (string) $row['actor_hash'],
         'service'    => (string) $row['service'],
         'tool'       => (string) $row['tool'],
         // Sem a coluna (tabela anterior à migração) ou com valor estranho: escrita, fail-closed.
         'kind'       => ($row['kind'] ?? null) === self::KIND_READ ? self::KIND_READ : self::KIND_WRITE,
         'args_hash'  => (string) ($row['args_hash'] ?? ''),
         'code'       => (string) $row['code'],
         'blob'       => (string) $row['response_blob'],
         'created_at' => (string) $row['created_at'],
         'age'        => $criado === false ? PHP_INT_MAX : max(0, time() - $criado),
      ];
   }

   /**
    * A reserva vira o desfecho. Com `$blob`, é o desfecho definitivo de uma escrita; sem, a linha fica
    * só para os limites (leitura, recusa antes de gravar, `execution_error` -- que agora conta no
    * limite por ator: até a 6.24.1 a reserva era apagada e o erro repetido não contava).
    *
    * Falha ao gravar a resposta de uma escrita grava o mínimo definitivo e legível
    * (`outcome_unknown`). Se nem isso gravar, a reserva fica `in_flight` e vira `outcome_unknown`
    * quando envelhecer: nunca volta a executar.
    */
   private static function settle(string $requestId, array $response, string $blob, int $duration): void {
      $code  = ($response['ok'] ?? false) === true ? 'ok' : (string) ($response['error']['code'] ?? self::E_EXECUTION);
      $where = ['request_id' => $requestId, 'code' => self::IN_FLIGHT];

      if (self::updateRow(['code' => $code, 'response_blob' => $blob, 'duration_ms' => $duration], $where)) {
         return;
      }
      if ($blob === '') {
         return; // leitura/recusa: a reserva envelhece e é retomada (leitura) ou vira desconhecida (escrita)
      }
      self::updateRow([
         'code'          => self::E_OUTCOME_UNKNOWN,
         'response_blob' => self::encode(self::outcomeUnknown()),
         'duration_ms'   => $duration,
      ], $where);
   }

   /** Chamadas na janela: do ator (`['actor_hash' => ...]`), do serviço (`['service' => ...]`) ou do ambiente (`[]`). */
   private static function countWindow(array $where): int {
      global $DB;
      $row = $DB->request([
         'COUNT' => 'cnt',
         'FROM'  => self::REQUESTS_TABLE,
         'WHERE' => $where + [
            'created_at' => ['>', date('Y-m-d H:i:s', time() - self::RATE_WINDOW)],
         ],
      ])->current();

      return (int) ($row['cnt'] ?? 0);
   }

   /** Ferramenta com bit inválido é erro do MÓDULO: fica no log para quem o mantém achar. */
   private static function logInvalidBit(string $service, string $tool): void {
      self::log(sprintf("[cloud_exec] %s/%s recusada: bit inválido no getCloudTools() (esperado inteiro > 0, ou sem a chave)\n", $service, $tool));
   }

   private static function logThrowable(string $service, string $tool, \Throwable $e): void {
      self::log(sprintf(
         "[cloud_exec] %s/%s: %s(%s) %s @ %s:%d\n",
         $service,
         $tool,
         get_class($e),
         (string) $e->getCode(),
         self::safeMessage($e->getMessage()),
         $e->getFile(),
         $e->getLine()
      ));
   }

   /**
    * Mensagem de exceção sem dado do chamado (LO-05). No GLPI 11 o erro de SQL traz a consulta inteira
    * ("... in SQL query \"INSERT ... 'título do chamado' ...\""), e o próprio erro do banco pode citar o
    * valor ("Duplicate entry 'x' for key ..."). Corta no "in SQL query", mascara o que estiver entre
    * aspas numa mensagem de banco e limita o tamanho. A consulta completa segue só no log de SQL do
    * próprio GLPI.
    */
   public static function safeMessage(string $message): string {
      $corte = stripos($message, 'in SQL query');
      if ($corte !== false) {
         $message = substr($message, 0, $corte);
      }
      if (preg_match('/\b(my)?sql\b/i', $message)) {
         $message = (string) preg_replace('/\'[^\']*\'|"[^"]*"/', "'...'", $message);
      }
      $message = trim((string) preg_replace('/\s+/', ' ', $message));

      return function_exists('mb_substr') ? mb_substr($message, 0, 300) : substr($message, 0, 300);
   }

   private static function log(string $line): void {
      if (class_exists('Toolbox')) {
         Toolbox::logInFile('plugin_nextool_cloud', $line);
      }
   }
}
