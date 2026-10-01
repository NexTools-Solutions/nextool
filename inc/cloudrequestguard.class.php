<?php
/**
 * Cloud link v1 -- autenticação da requisição que chega do cérebro.
 *
 * Contrato (mesma canônica do cérebro, `docs/cloud-link/contrato-v1.md`):
 *
 *   headers: X-Nx-Contract: v1 | X-Nx-Ambiente | X-Nx-Timestamp | X-Nx-Nonce | X-Nx-Signature
 *            X-Nx-Kid (opcional, contrato 1.1, FORA da canônica)
 *   canônica: "v1\nPOST\n/plugin/v1/exec\n<ambiente>\n<ts>\n<nonce>\n<sha256 hex do corpo>"
 *   assinatura: hash_hmac('sha256', canônica, inbound)   -- PluginNextoolCloudSignature
 *
 * O caminho na canônica é o CANÔNICO LÓGICO (`/plugin/v1/exec`), nunca a URL física: o plugin pode
 * estar em `plugins/` ou em `files/_plugins/`, e o GLPI do cliente pode viver atrás de um proxy com
 * subcaminho. Assinar o que varia quebraria por infraestrutura, não por segurança.
 *
 * Ordem das verificações (o "porquê" de cada posição):
 *   1. cap de corpo (64 KB)  -- antes de ler tudo na memória;
 *   2. assinatura e kid      -- ANTES de qualquer estado local. Sem credencial, compara contra um
 *                               segredo dummy e responde o mesmo 401: "cloud link desligado" e
 *                               "assinatura errada" são indistinguíveis de fora. Kid diferente do
 *                               desta instalação também é esse 401, e pede um /validate fora do
 *                               caminho da resposta (rotação; ver PluginNextoolCloudResync);
 *   3. janela simétrica      -- ±300 s (contrato 1.1; auditoria ME-10). Até a 6.24.1 era -300/+30:
 *                               quem confere aqui é o relógio do GLPI, e um GLPI 31 s atrasado via o
 *                               horário certo do cérebro "no futuro" e recusava tudo. O nonce de
 *                               600 s cobre o replay na janela inteira;
 *   4. nonce                 -- anti-replay de 10 min, gravado só DEPOIS da assinatura válida
 *                               (senão qualquer um enche a tabela);
 *   5. ambiente              -- o `X-Nx-Ambiente` tem de ser o desta instância;
 *   6. vigência do serviço   -- status + expires_at/grace_until.
 *
 * Antes da assinatura, toda recusa é o mesmo 401. Depois de uma assinatura VÁLIDA, quem provou a
 * chave recebe o motivo (contrato 1.1, §2.13.5; auditoria ME-01): `clock_skew`, com o horário do
 * GLPI, e `ambiente_mismatch`. O corpo desses dois sai no `ajax/cloud_exec.php`.
 *
 * Com vínculo, cada desfecho alimenta um resumo de saúde leve (última entrada aceita, recusas por
 * motivo e desvio do relógio), que vai para o ContainerAPI no /validate (`cloud_link_saude`). Sem
 * isso, o motivo de uma recusa ficava só no log dentro do container do cliente.
 *
 * @since 6.23.0
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/cloudsignature.class.php';

class PluginNextoolCloudRequestGuard {

   // Versão do contrato: PluginNextoolCloudSignature::CONTRACT, definida num lugar só (LO-06). Sem
   // apelido aqui: constante que referencia outra classe é o antipadrão B1 do permission-guard.
   public const CANONICAL_PATH = '/plugin/v1/exec';
   public const MAX_BODY_BYTES = 65536;
   /** Janela de relógio, simétrica (contrato 1.1; ME-10). */
   public const SKEW           = 300;
   public const SKEW_PAST      = self::SKEW;
   public const SKEW_FUTURE    = self::SKEW;
   public const NONCE_TTL      = 600;

   public const NONCE_TABLE = 'glpi_plugin_nextool_cloud_nonces';

   /** Cabeçalhos obrigatórios. O `kid` é opcional (transição) e fica fora desta lista. */
   private const REQUIRED_HEADERS = ['contract', 'ambiente', 'timestamp', 'nonce', 'signature'];

   /** Chave do resumo de saúde em PluginNextoolCloudState. */
   private const HEALTH_KEY = 'saude';

   /**
    * Uma entrada aceita só regrava o resumo quando a anterior tem mais que isto (s): com muito uso, o
    * resumo não vira uma escrita por chamada. A precisão da "última entrada aceita" é a mesma.
    */
   private const HEALTH_TOUCH = 60;

   /**
    * Autentica a requisição.
    *
    * @param array  $headers cabeçalhos já normalizados em minúsculas sem o prefixo (ver fromServer)
    * @param string $body    corpo bruto
    * @param int    $now     timestamp (injetável no teste)
    * @return array{ok:bool, code:string, reason?:string, cred?:array, message?:string, glpi_time?:string}
    *         code: ok | unauthorized | payload_too_large | replay | unavailable | clock_skew |
    *         ambiente_mismatch. Os dois últimos só depois de uma assinatura válida, com `message` (e,
    *         no clock_skew, `glpi_time`) para o corpo da resposta. `reason` é só para o log interno.
    */
   public static function verify(array $headers, string $body, int $now = 0): array {
      $now = $now > 0 ? $now : time();

      if (strlen($body) > self::MAX_BODY_BYTES) {
         return self::recusa($now, 'payload_too_large', 'body_too_large', [], null, null);
      }

      $contract  = (string) ($headers['contract'] ?? '');
      $ambiente  = (string) ($headers['ambiente'] ?? '');
      $timestamp = (string) ($headers['timestamp'] ?? '');
      $nonce     = (string) ($headers['nonce'] ?? '');
      $signature = (string) ($headers['signature'] ?? '');
      $kid       = (string) ($headers['kid'] ?? '');

      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      $cred = PluginNextoolCloudCreds::get();
      $key  = $cred !== null ? $cred['inbound'] : PluginNextoolCloudCreds::dummyInbound();

      $valid = PluginNextoolCloudSignature::verify($signature, self::CANONICAL_PATH, $ambiente, $timestamp, $nonce, $body, $key);

      // Id da chave (contrato 1.1, §2.13.1). Sem o cabeçalho, vale a chave atual (transição: o cérebro
      // ainda não manda). Kid diferente do desta instalação recusa MESMO com assinatura válida, pelo
      // mesmo 401 de qualquer recusa: é o sinal de que uma das pontas está com outra chave.
      $motivoKid = ($cred !== null && $kid !== '') ? self::kidMismatch($kid, (string) $cred['kid']) : '';

      // Sem credencial local: a comparação acima já rodou (tempo constante) e agora recusa igual.
      if (!$valid || $cred === null || $contract !== PluginNextoolCloudSignature::CONTRACT || $motivoKid !== '') {
         // `reason` é só para o log INTERNO: para fora tudo continua `unauthorized`, sem oráculo.
         // Separar "não mandou header" de "assinatura errada" poupou (e teria poupado) rodadas
         // inteiras de depuração: um nó HTTP que descarta os X-Nx-* em silêncio parece erro de
         // assinatura, e não é.
         $faltando = [];
         foreach (self::REQUIRED_HEADERS as $h) {
            if (($headers[$h] ?? '') === '') {
               $faltando[] = $h;
            }
         }
         $reason = $faltando !== [] ? 'missing_headers:' . implode(',', $faltando)
            : ($cred === null ? 'no_local_credential'
            : ($motivoKid !== '' ? $motivoKid
            : ($contract !== PluginNextoolCloudSignature::CONTRACT ? 'contract_mismatch' : 'bad_signature')));
         if (strncmp($reason, 'kid_unknown', 11) === 0) {
            // A chave nova ainda não chegou aqui (ou a do cérebro é a velha): um /validate resolve.
            // Fora do caminho da resposta, e no máximo um a cada 5 min (PluginNextoolCloudResync).
            // A ferramenta não roda: a recusa sai agora, como qualquer outra.
            require_once NEXTOOL_PHP_DIR . '/inc/cloudresync.class.php';
            PluginNextoolCloudResync::request('kid_unknown', 'recebido=' . $kid);
         }
         return self::recusa($now, 'unauthorized', $reason, [], null, $cred !== null);
      }

      // Daqui em diante a assinatura é válida: quem chamou provou ter a chave deste ambiente. É o que
      // permite medir o relógio dele e dizer o motivo das duas recusas abaixo que ele pode corrigir.
      $ts     = (int) $timestamp;
      $desvio = $ts > 0 ? $now - $ts : null;
      if ($ts <= 0 || $ts < ($now - self::SKEW_PAST) || $ts > ($now + self::SKEW_FUTURE)) {
         return self::recusa(
            $now,
            'clock_skew',
            'timestamp_out_of_window' . ($desvio !== null ? ':desvio=' . $desvio . 's' : ''),
            [
               'message'   => sprintf(
                  __('Horário fora da tolerância de %d segundos. Confira o relógio (NTP) deste GLPI e o de quem chamou.', 'nextool'),
                  self::SKEW
               ),
               'glpi_time' => date('c', $now),
            ],
            $desvio
         );
      }

      if (!preg_match('/^[a-zA-Z0-9_-]{16,128}$/', $nonce)) {
         return self::recusa($now, 'unauthorized', 'nonce_format', [], $desvio);
      }
      if (!self::consumeNonce($nonce, $now)) {
         return self::recusa($now, 'replay', 'nonce_reused', [], $desvio);
      }

      if (!hash_equals((string) $cred['ambiente'], $ambiente)) {
         return self::recusa($now, 'ambiente_mismatch', 'ambiente_mismatch', [
            'message' => __('O ambiente informado não é o deste GLPI.', 'nextool'),
         ], $desvio);
      }

      if (!PluginNextoolCloudCreds::isActive($cred, $now)) {
         return self::recusa($now, 'unavailable', 'link_inactive', [], $desvio);
      }

      self::registrar($now, null, $desvio);

      return ['ok' => true, 'code' => 'ok', 'cred' => $cred];
   }

   /**
    * Assinatura esperada para os componentes dados. Fachada: a canônica mora em
    * PluginNextoolCloudSignature, a mesma da saída.
    */
   public static function sign(string $ambiente, string $timestamp, string $nonce, string $body, string $key): string {
      return PluginNextoolCloudSignature::sign(self::CANONICAL_PATH, $ambiente, $timestamp, $nonce, $body, $key);
   }

   /**
    * Cabeçalhos do contrato a partir do $_SERVER, em chaves curtas e minúsculas.
    *
    * @param array $server
    * @return array<string,string>
    */
   public static function fromServer(array $server): array {
      $out = [];
      foreach (array_merge(self::REQUIRED_HEADERS, ['kid']) as $name) {
         $key = 'HTTP_X_NX_' . strtoupper($name);
         $out[$name] = isset($server[$key]) ? trim((string) $server[$key]) : '';
      }
      return $out;
   }

   /**
    * Recusa que não passou pelo verify() (o teto do Content-Length, que o `ajax/cloud_exec.php` confere
    * antes de ler o corpo), para contar no resumo de saúde como as outras.
    */
   public static function noteRefusal(string $reason, int $now = 0): void {
      self::registrar($now > 0 ? $now : time(), $reason, null, null);
   }

   /**
    * Resumo de saúde gravado, normalizado (contadores leves; aproximados sob concorrência, porque a
    * gravação é ler-e-regravar sem trava: é diagnóstico, não contabilidade).
    *
    * @return array{ultima:int, desvio:?int, desvio_em:int, recusas:array<string,int>}
    */
   public static function healthState(): array {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
      $raw   = PluginNextoolCloudState::get(self::HEALTH_KEY);
      $dados = $raw !== null ? json_decode($raw, true) : null;
      $dados = is_array($dados) ? $dados : [];

      $recusas = [];
      foreach ((array) ($dados['recusas'] ?? []) as $motivo => $n) {
         if (is_string($motivo) && preg_match('/^[a-z_]{1,40}$/', $motivo) && (int) $n > 0) {
            $recusas[$motivo] = (int) $n;
         }
      }
      ksort($recusas);

      return [
         'ultima'    => max(0, (int) ($dados['ultima'] ?? 0)),
         'desvio'    => isset($dados['desvio']) && is_numeric($dados['desvio']) ? (int) $dados['desvio'] : null,
         'desvio_em' => max(0, (int) ($dados['desvio_em'] ?? 0)),
         'recusas'   => $recusas,
      ];
   }

   /**
    * Resumo no formato do contrato 1.1 (§2.13.5), para o /validate. null quando esta instalação não
    * tem vínculo (não há o que resumir).
    *
    * `desvio_relogio_s` = relógio do GLPI menos o `X-Nx-Timestamp` da última chamada com assinatura
    * válida: positivo, o GLPI está adiantado; negativo, atrasado. Inclui o tempo de trânsito (alguns
    * segundos a mais num GLPI certo). `recusas` conta desde o último resumo que o ContainerAPI aceitou.
    *
    * @return array{ultima_entrada_aceita:?string, recusas:array<string,int>, kid:string, desvio_relogio_s:?int}|null
    */
   public static function healthSummary(): ?array {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      $cred = PluginNextoolCloudCreds::get();
      if ($cred === null) {
         return null;
      }
      $e = self::healthState();

      return [
         'ultima_entrada_aceita' => $e['ultima'] > 0 ? date('c', $e['ultima']) : null,
         'recusas'               => $e['recusas'],
         'kid'                   => (string) $cred['kid'],
         'desvio_relogio_s'      => $e['desvio'],
      ];
   }

   /**
    * O ContainerAPI recebeu o resumo: tira das contagens o que foi enviado. Subtrai em vez de zerar
    * para não perder o que chegou enquanto o /validate estava no ar.
    *
    * @param array<string,int> $enviadas o `recusas` do resumo enviado
    */
   public static function healthReported(array $enviadas): void {
      if ($enviadas === []) {
         return;
      }
      try {
         $e = self::healthState();
         foreach ($enviadas as $motivo => $n) {
            if (isset($e['recusas'][$motivo])) {
               $resto = $e['recusas'][$motivo] - max(0, (int) $n);
               if ($resto > 0) {
                  $e['recusas'][$motivo] = $resto;
               } else {
                  unset($e['recusas'][$motivo]);
               }
            }
         }
         self::saveHealth($e);
      } catch (\Throwable $ex) {
         // diagnóstico: na falha, a contagem só fica maior no próximo resumo
      }
   }

   /** Motivo da recusa no log, se o kid não serve; '' se serve. */
   private static function kidMismatch(string $kid, string $local): string {
      if (!PluginNextoolCloudSignature::isKid($kid)) {
         return 'bad_kid'; // fora do formato: o valor não vai para o log
      }
      if ($local === '' || !hash_equals($local, $kid)) {
         // Os dois kids vão para o log: não são segredo, e é o que diz qual ponta está com a chave velha.
         return 'kid_unknown:recebido=' . $kid . ',local=' . ($local !== '' ? $local : '-');
      }

      return '';
   }

   /**
    * Resposta de recusa, já contada no resumo de saúde.
    *
    * @param bool|null $temVinculo esta instalação tem credencial do cloud link? null = descobrir
    */
   private static function recusa(int $now, string $code, string $reason, array $extra = [], ?int $desvio = null, ?bool $temVinculo = true): array {
      self::registrar($now, $reason, $desvio, $temVinculo);

      return ['ok' => false, 'code' => $code, 'reason' => $reason] + $extra;
   }

   /**
    * Atualiza o resumo de saúde. `$motivo` null = entrada aceita. Nunca lança: o diagnóstico não pode
    * derrubar a autenticação.
    *
    * Só conta com vínculo: sem credencial o resumo nem é enviado, e o endereço do exec é público em todo
    * GLPI com a base. Contar ali seria uma escrita no banco por pedido de qualquer robô, para nada.
    *
    * @param bool|null $temVinculo null = descobrir (recusas antes da leitura da credencial)
    */
   private static function registrar(int $now, ?string $motivo, ?int $desvio, ?bool $temVinculo = true): void {
      try {
         if ($temVinculo === null) {
            require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
            $temVinculo = PluginNextoolCloudCreds::get() !== null;
         }
         if (!$temVinculo) {
            return;
         }
         $e     = self::healthState();
         $mudou = false;
         if ($motivo === null) {
            if ($now - $e['ultima'] >= self::HEALTH_TOUCH || $now < $e['ultima']) {
               $e['ultima'] = $now;
               $mudou = true;
            }
         } else {
            // O motivo do log pode ter detalhe depois do ':' (missing_headers:<lista>, kid_unknown:...):
            // a contagem é por motivo, sem o detalhe.
            $base = substr((string) preg_replace('/[^a-z_]/', '', strtolower(strtok($motivo, ':') ?: '')), 0, 40);
            $base = $base !== '' ? $base : 'outro';
            $e['recusas'][$base] = min(PHP_INT_MAX - 1, (int) ($e['recusas'][$base] ?? 0)) + 1;
            $mudou = true;
         }
         if ($desvio !== null
             && ($e['desvio'] === null || abs($desvio - $e['desvio']) >= 2 || $now - $e['desvio_em'] >= self::HEALTH_TOUCH)) {
            $e['desvio']    = $desvio;
            $e['desvio_em'] = $now;
            $mudou = true;
         }
         if ($mudou) {
            self::saveHealth($e);
         }
      } catch (\Throwable $ex) {
         // sem resumo, a autenticação segue igual
      }
   }

   private static function saveHealth(array $e): void {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
      $json = json_encode([
         'ultima'    => (int) $e['ultima'],
         'desvio'    => $e['desvio'],
         'desvio_em' => (int) $e['desvio_em'],
         'recusas'   => (object) $e['recusas'],
      ]);
      if (is_string($json)) {
         PluginNextoolCloudState::set(self::HEALTH_KEY, $json);
      }
   }

   /**
    * Grava o nonce; false quando já existia (replay).
    *
    * O INSERT é a própria trava: a PK do nonce faz o banco recusar o segundo. Não há SELECT antes --
    * dois updates simultâneos com o mesmo nonce passariam os dois por uma checagem em dois tempos.
    *
    * Os vencidos são apagados pela tarefa agendada `cloudPurge` (PluginNextoolCronCloudPurge), de hora
    * em hora. Até a 6.24.1 era um sorteio aqui (1 em 100 requisições): sem uso, nada era apagado.
    */
   private static function consumeNonce(string $nonce, int $now): bool {
      global $DB;

      try {
         $ok = $DB->insert(self::NONCE_TABLE, [
            'nonce'      => $nonce,
            'expires_at' => date('Y-m-d H:i:s', $now + self::NONCE_TTL),
         ]);
      } catch (\Throwable $e) {
         return false; // violação de PK = repetido
      }
      return (bool) $ok;
   }
}
