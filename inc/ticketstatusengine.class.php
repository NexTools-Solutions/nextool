<?php
declare(strict_types=1);
/**
 * NexTool - Motor de status de chamado compartilhado
 *
 * Aplica ao Ticket o status decidido por um MOTOR DE PLUGIN (fluxo de aprovação,
 * workflow BPMN, automação). Não é um utilitário de "mudar status": é a disciplina
 * necessária para que essa mudança sobreviva à cascata do core do GLPI.
 *
 * Três problemas que este motor resolve, e que qualquer implementação ingênua
 * (`$ticket->update(['status' => X])` dentro de um hook) sofre:
 *
 * 1. RECURSÃO -- o nosso próprio update redispara item_update[Ticket]. A intenção
 *    é registrada e CONSUMIDA UMA ÚNICA VEZ (takePendingStatus lê e apaga), então
 *    a segunda passagem não encontra nada para aplicar.
 *
 * 2. SER SOBRESCRITO PELA REGRA DE NEGÓCIO -- ao responder uma aprovação, o core
 *    ainda atualiza o chamado DEPOIS do hook do plugin:
 *    CommonITILValidation::post_updateItem() -> recomputeItilStatus() ->
 *    $itil->update(), que passa por processRules(ONUPDATE). Aplicar antes disso faz
 *    uma regra ("Aprovação concedida -> Em atendimento") gravar por último e o
 *    motor perder a palavra final. Por isso a intenção fica VIVA e é aplicada pelo
 *    item_update[Ticket] disparado por aquele update -- ou seja, depois da regra --
 *    com o shutdown como rede de segurança para quando esse update não vier.
 *
 * 3. SER FILTRADO EM SILÊNCIO -- quem aprova costuma ser gestor, sem direito de
 *    editar o chamado; sem bypass o core filtra os campos do update
 *    (handleTemplateFields deixa só 'id') e o status simplesmente não muda, sem
 *    erro nenhum. Daí o glpicronuserrunning, restaurado em finally.
 *
 * Promovido do módulo `workflow` (inc/engine.class.php) para a base quando o
 * `approvalflow` passou a precisar da mesma mecânica -- fonte única, em vez de duas
 * cópias divergindo. O comportamento é o do workflow, preservado.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolTicketStatusEngine {

   /**
    * Intenção de status por chamado.
    * Só existe UMA viva por request: registrar para um chamado descarta a de
    * qualquer outro. É essa invariante que impede o vazamento cruzado -- o cenário
    * real é o cron (ou uma importação em lote) rodando o motor para vários chamados
    * na mesma requisição, onde uma intenção não consumida cairia no chamado seguinte.
    *
    * @var array<int, array{status:int, origin:string, extra:array}>
    */
   private static array $pending = [];

   /** Profundidade de execução por origem: origin => int. */
   private static array $running = [];

   /** Rede de segurança do fim do request: registrada no máximo uma vez. */
   private static bool $shutdownArmed = false;

   /**
    * Registra a intenção de status.
    *
    * @param int    $ticketId
    * @param int    $status    Constante de CommonITILObject (0 = nada a fazer)
    * @param string $origin    Chave do motor (ex.: 'approvalflow', 'workflow') -- usada
    *                          no marcador do input, no log e no isRunning() por origem
    * @param array  $extraInput Campos extras do update (ex.: marcador legado do módulo)
    */
   public static function setPendingStatus(int $ticketId, int $status, string $origin, array $extraInput = []): void {
      if ($ticketId <= 0 || $status <= 0 || $origin === '') {
         return;
      }
      self::$pending = [$ticketId => [
         'status' => $status,
         'origin' => $origin,
         'extra'  => $extraInput,
      ]];
   }

   /**
    * Lê E APAGA a intenção. O consumo único é o que impede a recursão.
    *
    * @return array{status:int, origin:string, extra:array} status 0 = não havia intenção
    */
   public static function takePendingStatus(int $ticketId): array {
      $intent = self::$pending[$ticketId] ?? null;
      unset(self::$pending[$ticketId]);
      if (!is_array($intent)) {
         return ['status' => 0, 'origin' => '', 'extra' => []];
      }
      return [
         'status' => (int) ($intent['status'] ?? 0),
         'origin' => (string) ($intent['origin'] ?? ''),
         'extra'  => (array) ($intent['extra'] ?? []),
      ];
   }

   public static function clearPendingStatus(int $ticketId): void {
      unset(self::$pending[$ticketId]);
   }

   public static function hasPendingStatus(int $ticketId): bool {
      return (int) (self::$pending[$ticketId]['status'] ?? 0) > 0;
   }

   /**
    * Agenda a aplicação para o FIM DO REQUEST em vez de aplicar agora, deixando a
    * intenção viva para o item_update[Ticket] da cascata do core (ver item 2 do
    * cabeçalho). O shutdown só entra quando aquele update não vem -- ex.: conclusão
    * de tarefa, em que o core não mexe no chamado -- e evita que a intenção morra
    * em silêncio.
    */
   public static function schedulePendingStatus(int $ticketId): void {
      if ($ticketId <= 0 || !self::hasPendingStatus($ticketId)) {
         return;
      }
      if (self::$shutdownArmed) {
         return;
      }
      self::$shutdownArmed = true;
      register_shutdown_function(static function (): void {
         foreach (array_keys(self::$pending) as $pendingTicketId) {
            try {
               self::applyPendingStatus((int) $pendingTicketId);
            } catch (Throwable $e) {
               Toolbox::logInFile('plugin_nextool', sprintf(
                  '[StatusEngine] falha ao aplicar o status no fim do request (chamado %d): %s',
                  (int) $pendingTicketId,
                  $e->getMessage()
               ));
            }
         }
      });
   }

   /**
    * Aplica o status pendente, se houver. Pode ser chamado por mais de um gancho
    * (o fim do drain do motor e o item_update[Ticket]): os dois consomem a MESMA
    * intenção e quem chegar primeiro ganha.
    */
   public static function applyPendingStatus(int $ticketId): void {
      $intent = self::takePendingStatus($ticketId);
      $status = $intent['status'];
      if ($status <= 0) {
         return;
      }
      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticketId) || (int) $ticket->fields['status'] === $status) {
         return;
      }

      $origin = $intent['origin'] !== '' ? $intent['origin'] : 'nextool';

      // Bypass do filtro de campos do core (ver item 3 do cabeçalho). Mesmo
      // mecanismo que o GLPI usa nas próprias automações.
      $hadCron = array_key_exists('glpicronuserrunning', $_SESSION);
      $oldCron = $_SESSION['glpicronuserrunning'] ?? null;
      $_SESSION['glpicronuserrunning'] = 'nextool-' . $origin;

      $input = array_merge($intent['extra'], [
         'id'                      => $ticketId,
         'status'                  => $status,
         // A palavra final é do MOTOR: _skip_rules corta o processRules do core
         // (senão a regra de negócio que acabou de rodar mexeria de novo).
         // _no_reopen evita a reabertura automática do requerente.
         '_skip_rules'             => true,
         '_no_reopen'              => true,
         '_nextool_status_engine'  => $origin,
      ]);

      self::enter($origin);
      try {
         $ticket->update($input);
      } finally {
         self::leave($origin);
         if ($hadCron) {
            $_SESSION['glpicronuserrunning'] = $oldCron;
         } else {
            unset($_SESSION['glpicronuserrunning']);
         }
      }
   }

   /**
    * Há motor rodando agora? Consumido pelos guards dos módulos, que não podem
    * vetar os updates do próprio motor (eles existem para disciplinar edição
    * HUMANA).
    *
    * @param ?string $origin null = qualquer motor; string = só aquele
    */
   public static function isRunning(?string $origin = null): bool {
      if ($origin === null) {
         foreach (self::$running as $depth) {
            if ($depth > 0) {
               return true;
            }
         }
         return false;
      }
      return (int) (self::$running[$origin] ?? 0) > 0;
   }

   /** Abre um bloco de execução do motor (par obrigatório com leave(), em finally). */
   public static function enter(string $origin): void {
      self::$running[$origin] = (int) (self::$running[$origin] ?? 0) + 1;
   }

   public static function leave(string $origin): void {
      self::$running[$origin] = max(0, (int) (self::$running[$origin] ?? 0) - 1);
   }
}
