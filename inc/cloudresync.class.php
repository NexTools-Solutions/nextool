<?php
/**
 * Cloud link -- ressincronização da credencial pelo `kid` (contrato 1.1, §2.13.1; decisão d do plano
 * da auditoria de 2026-09-24, HI-02).
 *
 * Depois de uma rotação do segredo, o cérebro passa a assinar com a chave nova na hora, e a base só a
 * recebe no próximo `/validate` (até 6 h pelo catalogSync, ou nunca, se ele falhar). No intervalo, só
 * quem tem a chave antiga, que pode ser a vazada, consegue executar no GLPI. Com o kid, as duas pontas
 * percebem a diferença: um `X-Nx-Kid` que não é o desta instalação na entrada (`kid_unknown`) ou o
 * `401 E_AUTH_KID` na saída pedem um `/validate` agora, e a chave nova chega em segundos.
 *
 * Três regras, e o porquê de cada uma:
 *  - FORA do caminho da resposta (PluginNextoolCloudDeferred). O `/validate` leva até 15 s, e quem o
 *    disparou ainda não provou nada: o kid não é segredo, e a assinatura nem foi aceita. Servidor que
 *    não consegue soltar a resposta antes (Apache com mod_php, CGI) não roda aqui: fica a marca
 *    `kid_resync_pending`, que o próximo ciclo atende (o catalogSync já faz o `/validate`; uma tarefa
 *    agendada pode chamar `runPending()`).
 *  - No máximo UM a cada 5 minutos por ambiente, contado por uma marca persistida e reservado sob
 *    `GET_LOCK`: uma rajada de kid falso, que qualquer um pode mandar, gera no máximo um `/validate` por
 *    janela, e duas requisições simultâneas não passam juntas pela conferência.
 *    **A janela só fica gasta quando o `/validate` trouxe um kid novo** (6.27.0). Até a 6.26.2, um kid
 *    desconhecido qualquer (de teste, forjado, uma chamada atrasada) gastava a janela, e a rotação legítima
 *    que viesse nos 5 minutos seguintes ficava sem ressincronizar até a próxima chamada depois da janela ou
 *    até a `cloudPurge` (até 1 h): foi o que aconteceu com o `suporte` em 26/09, na rotação das 23:44.
 *    Agora, `/validate` sem efeito devolve a janela, e as tentativas sem efeito têm teto próprio
 *    (NOOP_LIMIT a cada INTERVAL), que continua protegendo o ContainerAPI de martelada.
 *  - O `/validate` roda como o do cron (`origin` = `cron_sync`): contexto NÃO supervisionado. Com outra
 *    origem, o `applyModulesEntitlement` trataria a chamada como ação de um humano na tela e poderia
 *    desinstalar módulo pago e apagar arquivos (nextool-dev#246), disparado por um pedido anônimo.
 *
 * @since pós-6.24.1 (contrato 1.1 do cloud link, K1)
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudResync {

   /** Teto: um `/validate` disparado por kid a cada INTERVAL segundos, por ambiente. */
   public const INTERVAL = 300;

   /** Marca persistida (epoch) do último `/validate` disparado por kid. */
   public const MARK = 'kid_resync_at';

   /** Pedido que não pôde rodar depois da resposta (servidor sem como soltá-la antes): epoch. */
   public const PENDING = 'kid_resync_pending';

   /** Tentativas de `/validate` por kid nos últimos INTERVAL segundos (lista de epochs, JSON). */
   public const ATTEMPTS = 'kid_resync_attempts';

   /** Teto das tentativas por janela, com ou sem efeito (6.27.0). */
   public const NOOP_LIMIT = 3;

   /** Marca anterior à reserva em curso, para devolver a janela quando o `/validate` não muda o kid. */
   private static $markAnterior = null;

   /** Chave da tarefa em PluginNextoolCloudDeferred. */
   public const TASK = 'kid_resync';

   private const LOG = 'plugin_nextool_cloud';

   /**
    * Pede um `/validate` para depois da resposta. Barato de propósito (só agenda): roda no caminho de
    * uma recusa, e o tempo dela não pode ser diferente do de qualquer outra. Um pedido por processo.
    *
    * @param string $motivo  `kid_unknown` (entrada) ou `E_AUTH_KID` (saída)
    * @param string $detalhe o que ajuda a achar o caso no log (o kid recebido, que não é segredo)
    */
   public static function request(string $motivo, string $detalhe = ''): void {
      require_once NEXTOOL_PHP_DIR . '/inc/clouddeferred.class.php';
      $motivo  = self::limpo($motivo);
      $detalhe = self::limpo($detalhe);
      PluginNextoolCloudDeferred::add(
         self::TASK,
         static function () use ($motivo, $detalhe): void {
            self::run($motivo, $detalhe);
         },
         static function () use ($motivo, $detalhe): void {
            require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
            PluginNextoolCloudState::set(self::PENDING, (string) time());
            self::log(sprintf(
               "[CLOUD_KID] /validate adiado para o proximo ciclo: este servidor nao encerra a resposta antes (%s)\n",
               self::rotulo($motivo, $detalhe)
            ));
         }
      );
   }

   /** Este processo pediu um `/validate` que ainda não rodou? (E2E e diagnóstico.) */
   public static function wasRequested(): bool {
      require_once NEXTOOL_PHP_DIR . '/inc/clouddeferred.class.php';

      return PluginNextoolCloudDeferred::has(self::TASK);
   }

   /**
    * Desiste do pedido deste processo. Para o E2E: um `verify()` com kid falso, chamado direto num
    * teste, não pode terminar num `/validate` de verdade.
    */
   public static function cancel(): void {
      require_once NEXTOOL_PHP_DIR . '/inc/clouddeferred.class.php';
      PluginNextoolCloudDeferred::cancel(self::TASK);
   }

   /**
    * Dispara o `/validate` agora, se o teto deixar. Não é para o caminho de uma resposta: quem decide
    * quando chamar é o `request()` (ou uma tarefa agendada, pelo `runPending()`).
    *
    * @param callable|null $validar  injetável no teste: faz o `/validate` e devolve o resultado
    * @param callable|null $kidAtual injetável no teste: devolve o kid local ('' sem vínculo)
    * @return string `validated` | `throttled` | `failed`
    */
   public static function run(string $motivo, string $detalhe = '', int $now = 0, ?callable $validar = null, ?callable $kidAtual = null): string {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      $now      = $now > 0 ? $now : time();
      $rotulo   = self::rotulo($motivo, $detalhe);
      $kidAtual = $kidAtual ?? static function (): string {
         $c = PluginNextoolCloudCreds::get();
         return $c !== null ? (string) $c['kid'] : '';
      };
      $validar  = $validar ?? static function () {
         require_once NEXTOOL_PHP_DIR . '/inc/licensevalidator.class.php';
         return PluginNextoolLicenseValidator::validateLicense([
            'force_refresh' => true,
            // Contexto NÃO supervisionado, igual ao do cron (ver o cabeçalho): nunca remove módulo.
            'context'       => ['origin' => 'cron_sync'],
         ]);
      };
      if (!self::claim($now)) {
         self::log(sprintf("[CLOUD_KID] /validate nao disparado: teto de um a cada %d s (%s)\n", self::INTERVAL, $rotulo));
         return 'throttled';
      }
      $anterior = self::$markAnterior;
      PluginNextoolCloudState::delete(self::PENDING);
      self::log(sprintf("[CLOUD_KID] /validate disparado (%s)\n", $rotulo));
      $antes = $kidAtual();
      try {
         $r = $validar();
      } catch (\Throwable $e) {
         self::log(sprintf("[CLOUD_KID] /validate falhou: %s (%s)\n", get_class($e), $rotulo));
         self::refund($now, $anterior);
         return 'failed';
      }
      $depois = $kidAtual();
      $mudou  = $depois !== '' && $depois !== $antes;
      if (!$mudou) {
         self::refund($now, $anterior);
      }
      self::log(sprintf(
         "[CLOUD_KID] /validate concluido: source=%s valid=%s kid local=%s %s (%s)\n",
         is_array($r) ? (string) ($r['source'] ?? '') : '',
         is_array($r) && !empty($r['valid']) ? '1' : '0',
         $depois !== '' ? $depois : '-',
         $mudou ? 'mudou' : 'sem mudanca, janela devolvida',
         $rotulo
      ));

      return 'validated';
   }

   /**
    * Devolve a janela: a marca volta ao valor de antes da reserva, se ninguém a trocou nesse meio-tempo.
    * A tentativa continua contada (ATTEMPTS), e é esse teto que segura a martelada.
    */
   private static function refund(int $now, ?int $anterior): void {
      global $DB;
      $trava = 'nxcloud_resync_' . substr(md5((string) ($DB->dbdefault ?? '')), 0, 16);
      if (!self::lock($trava)) {
         return; // sem a trava, a janela fica gasta: o comportamento antigo, seguro
      }
      try {
         if ((string) (PluginNextoolCloudState::get(self::MARK) ?? '') !== (string) $now) {
            return;
         }
         if ($anterior !== null && $anterior > 0) {
            PluginNextoolCloudState::set(self::MARK, (string) $anterior);
         } else {
            PluginNextoolCloudState::delete(self::MARK);
         }
      } finally {
         self::unlock($trava);
      }
   }

   /**
    * Pedido adiado (servidor que não solta a resposta antes): roda agora, respeitando o teto. Para
    * quem roda fora do caminho de uma resposta, como uma tarefa agendada.
    *
    * @return string `none` | o retorno de run()
    */
   public static function runPending(): string {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
      if ((string) PluginNextoolCloudState::get(self::PENDING) === '') {
         return 'none';
      }

      return self::run('pendente');
   }

   /** Um `/validate` acabou de rodar por outro caminho (o catalogSync): o pedido adiado está atendido. */
   public static function satisfied(): void {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
      PluginNextoolCloudState::delete(self::PENDING);
   }

   /**
    * Reserva a vez do `/validate`: true para no máximo um pedido a cada INTERVAL segundos.
    *
    * A marca é lida e gravada sob `GET_LOCK` (sem espera): dois processos simultâneos não leem os dois
    * a marca velha. Marca "do futuro" além do intervalo (relógio que voltou) não prende a fila. Sem a
    * trava do banco, não reserva: o próximo ciclo do catalogSync cobre, e uma rajada nunca passa.
    * Pública para o E2E provar o teto com o tempo injetado.
    */
   public static function claim(int $now): bool {
      global $DB;
      require_once NEXTOOL_PHP_DIR . '/inc/cloudstate.class.php';
      // O GET_LOCK vale para o SERVIDOR de banco inteiro: com o nome do banco, dois GLPIs no mesmo
      // MariaDB não disputam a mesma trava.
      $trava = 'nxcloud_resync_' . substr(md5((string) ($DB->dbdefault ?? '')), 0, 16);
      if (!self::lock($trava)) {
         return false;
      }
      try {
         $ultimo = (int) (PluginNextoolCloudState::get(self::MARK) ?? 0);
         if ($ultimo > 0 && abs($now - $ultimo) < self::INTERVAL) {
            return false;
         }
         // Teto das tentativas (6.27.0): a janela pode ter sido devolvida por `/validate` sem efeito, mas
         // no máximo NOOP_LIMIT tentativas por INTERVAL passam, com ou sem efeito.
         $tentativas = json_decode((string) (PluginNextoolCloudState::get(self::ATTEMPTS) ?? '[]'), true);
         $tentativas = array_values(array_filter(is_array($tentativas) ? $tentativas : [], static function ($t) use ($now) {
            return is_int($t) && abs($now - $t) < self::INTERVAL;
         }));
         if (count($tentativas) >= self::NOOP_LIMIT) {
            return false;
         }
         $tentativas[] = $now;
         if (!PluginNextoolCloudState::set(self::ATTEMPTS, (string) json_encode($tentativas))) {
            return false;
         }
         self::$markAnterior = $ultimo > 0 ? $ultimo : null;

         return PluginNextoolCloudState::set(self::MARK, (string) $now);
      } finally {
         self::unlock($trava);
      }
   }

   private static function lock(string $trava): bool {
      global $DB;
      $metodo = method_exists($DB, 'doQuery') ? 'doQuery' : 'query'; // doQuery só a partir do GLPI 10.0.7
      try {
         $res = $DB->$metodo(sprintf("SELECT GET_LOCK('%s', 0) AS l", $trava));
      } catch (\Throwable $e) {
         return false;
      }
      if (!$res instanceof \mysqli_result) {
         return false;
      }
      $row = $res->fetch_assoc();

      return (int) ($row['l'] ?? 0) === 1;
   }

   private static function unlock(string $trava): void {
      global $DB;
      $metodo = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
      try {
         $DB->$metodo(sprintf("SELECT RELEASE_LOCK('%s')", $trava));
      } catch (\Throwable $e) {
         // a trava cai sozinha quando a conexão fecha
      }
   }

   private static function rotulo(string $motivo, string $detalhe): string {
      return $detalhe !== '' ? $motivo . ', ' . $detalhe : $motivo;
   }

   /** Só o que é seguro no log: letras, números e poucos sinais (o detalhe pode vir de fora). */
   private static function limpo(string $texto): string {
      return substr((string) preg_replace('/[^A-Za-z0-9_=:,. -]/', '', $texto), 0, 120);
   }

   private static function log(string $linha): void {
      if (class_exists('Toolbox')) {
         Toolbox::logInFile(self::LOG, $linha);
      }
   }
}
