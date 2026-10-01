<?php
/**
 * Cloud link -- ferramentas de chamado COMPARTILHADAS entre os módulos de canal (Telegram, WhatsApp...).
 *
 * Até a 6.26.2 estas ferramentas moravam no telegrambot (`PluginNextoolTelegrambotCloudTools`, 3.0.0 a
 * 3.5.0). Com o segundo canal no cloud link (whatsappbot, plano do livechat omnichannel de 2026-09-26),
 * copiar seria duplicar código de segurança: cada correção da auditoria de 2026-09-24 (o `can(CREATE)` que
 * o `add()` do core não confere, a lixeira, a porta de status do pendenciar, o critério de visibilidade do
 * core na lista) teria de ser refeita em cada módulo, e um esquecimento abriria o mesmo buraco de novo.
 *
 * O que continua do MÓDULO, e não vem daqui:
 *  - o `resolve_actor` (quem é o usuário por trás do chat/telefone, com a tabela de vínculo do canal);
 *  - o que depende do canal (anexo baixado pelo bot, vínculo por código);
 *  - os BITS: cada módulo expõe estas ferramentas sob o próprio `service`, com os próprios bits, e o
 *    direito continua conferido pelo produto dele (`getCloudLicenseKey()`). Ter o produto do Telegram não
 *    abre as ferramentas pelo serviço do WhatsApp.
 *
 * Regras que valem para todas (as mesmas da 3.x do telegrambot):
 *  1. **Devolvem dado estruturado, nunca texto.** Quem redige a mensagem é o cérebro, que conhece o canal,
 *     o idioma e os botões.
 *  2. **Rodam sob a sessão do ator** (`PluginNextoolCloudSession::runAs`), então cada objeto passa pelo
 *     `can()` nativo do GLPI. O bit do módulo é a primeira porta; o `can()` é a segunda.
 *  3. **Datas em ISO 8601 com deslocamento**: o cérebro não conhece o fuso do servidor do cliente.
 *  4. **Resposta única para "não existe" e "não é seu"**, para o retorno não virar oráculo de ids.
 *
 * CONTATO EXTERNO (sem conta no GLPI, contrato §8.5): só as ferramentas de `EXTERNAL_TOOLS` o atendem, e só
 * quando o módulo pede em `map()`. Elas rodam como o usuário de serviço do canal, que é o requerente de
 * todos os chamados de contato externo e lê todos eles; por isso, com o contato no contexto
 * (`PluginNextoolCloudContacts::current()`), o que ele vê é decidido pela LIGAÇÃO contato <-> chamado, e
 * não pelo direito do GLPI. Sem essa ligação, um contato leria os chamados de outro.
 *
 * @since 6.27.0
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudItilTools {

   /** Teto de itens por listagem. O cérebro pagina pedindo de novo. */
   public const LIST_LIMIT = 20;

   /** Tamanho máximo de texto aceito em título/conteúdo (o resto é truncado, não recusado). */
   public const MAX_TITLE   = 255;
   public const MAX_CONTENT = 10000;

   /** Janela do filtro `due=soon`. */
   private const DUE_SOON_HOURS = 24;

   /**
    * Grupo de bit de cada ferramenta. O módulo informa o VALOR do bit por grupo em `map()`; ferramenta de
    * grupo não informado fica de fora (o módulo não a expõe).
    */
   private const TOOL_GROUP = [
      'list_tickets'     => 'view',
      'get_ticket'       => 'view',
      'create_ticket'    => 'create',
      'add_followup'     => 'followup',
      'add_solution'     => 'status',
      'set_pending'      => 'status',
      'list_validations' => 'validate',
      'apply_validation' => 'validate',
   ];

   /**
    * Ferramentas que podem atender contato externo: abrir chamado, listar e detalhar os dele, e acompanhar os
    * dele. Solucionar, pendenciar e aprovar são de quem tem conta.
    */
   public const EXTERNAL_TOOLS = ['list_tickets', 'get_ticket', 'create_ticket', 'add_followup'];

   /**
    * Mapa `tool => [bit, kind, handler, schema]` no formato de `PluginNextoolBaseModule::getCloudTools()`.
    *
    * @param array<string,int> $bits     valor do bit do módulo por grupo: view, create, followup, status,
    *                                    validate. Grupo ausente = ferramentas dele não entram no mapa. O valor
    *                                    passa como está: bit inválido é recusado pelo executor (fail-closed).
    * @param bool              $external o módulo atende contato externo: as de EXTERNAL_TOOLS declaram isso
    *                                    (`actors`) e o executor passa a aceitá-lo nelas
    * @return array<string, array{bit:mixed, kind:string, handler:callable, schema:array}>
    */
   public static function map(array $bits, bool $external = false): array {
      require_once NEXTOOL_PHP_DIR . '/inc/cloudexecutor.class.php';
      require_once NEXTOOL_PHP_DIR . '/inc/cloudcontacts.class.php';
      $specs = [
         'list_tickets' => [
            'kind'    => 'read',
            'handler' => [self::class, 'listTickets'],
            // Com `due`, o `role` é IGNORADO: prazo é assunto do técnico atribuído.
            // `offset` ou `order` = lista paginada: uma linha por chamado (`role` requester|assigned|both), com
            // `total` e `offset`. Sem eles, o formato de antes (uma linha por papel), que o Telegram usa.
            'schema'  => ['args' => ['role' => 'requester|assigned|both', 'limit' => 'int<=20', 'due' => 'overdue|soon?',
               'offset' => 'int?', 'order' => 'opened|updated?']],
         ],
         'get_ticket' => [
            'kind'    => 'read',
            'handler' => [self::class, 'getTicket'],
            // `followups: 0` = só o chamado, sem acompanhamentos.
            'schema'  => ['args' => ['id' => 'int', 'followups' => 'int<=10']],
         ],
         'create_ticket' => [
            'kind'    => 'write',
            'handler' => [self::class, 'createTicket'],
            // Os opcionais vêm do catálogo do cérebro (folha da triagem). O grupo sai da categoria, pelo
            // próprio core (`setTechAndGroupFromItilCategory`), nunca do corpo.
            'schema'  => ['args' => [
               'title' => 'string', 'content' => 'string', 'entities_id' => 'int?', 'itilcategories_id' => 'int?',
               'type' => '1|2?', 'requesttypes_id' => 'int?', 'urgency' => '1..5?',
            ]],
         ],
         'add_followup' => [
            'kind'    => 'write',
            'handler' => [self::class, 'addFollowup'],
            'schema'  => ['args' => ['ticket_id' => 'int', 'content' => 'string', 'is_private' => 'bool?']],
         ],
         'add_solution' => [
            'kind'    => 'write',
            'handler' => [self::class, 'addSolution'],
            'schema'  => ['args' => ['ticket_id' => 'int', 'content' => 'string']],
         ],
         'set_pending' => [
            'kind'    => 'write',
            'handler' => [self::class, 'setPending'],
            'schema'  => ['args' => ['ticket_id' => 'int', 'content' => 'string']],
         ],
         'list_validations' => [
            'kind'    => 'read',
            'handler' => [self::class, 'listValidations'],
            // `ticket_id`: só as do chamado pedido, para "aprovar N" não depender do limite.
            'schema'  => ['args' => ['limit' => 'int<=20', 'ticket_id' => 'int?']],
         ],
         'apply_validation' => [
            'kind'    => 'write',
            'handler' => [self::class, 'applyValidation'],
            'schema'  => ['args' => ['validation_id' => 'int', 'action' => 'approve|refuse', 'comment' => 'string?']],
         ],
      ];

      $map = [];
      foreach ($specs as $tool => $spec) {
         $grupo = self::TOOL_GROUP[$tool];
         if (!array_key_exists($grupo, $bits)) {
            continue;
         }
         $map[$tool] = ['bit' => $bits[$grupo]] + $spec;
         if ($external && in_array($tool, self::EXTERNAL_TOOLS, true)) {
            $map[$tool]['actors'] = [PluginNextoolCloudExecutor::ACTOR_KIND_GLPI_USER, PluginNextoolCloudExecutor::ACTOR_KIND_EXTERNAL];
         }
      }

      return $map;
   }

   /** Contato externo em execução, ou null (ator comum). */
   private static function contato(): ?array {
      if (!class_exists('PluginNextoolCloudContacts')) {
         return null;
      }

      return PluginNextoolCloudContacts::current();
   }

   /** O chamado é do contato externo em execução? (true quando não há contato: o ator comum segue o `can()`) */
   private static function doContato(int $ticketId): bool {
      $c = self::contato();

      return $c === null || PluginNextoolCloudContacts::isLinked((int) $c['contacts_id'], $ticketId);
   }

   /**
    * Chamados abertos do usuário, como requerente e/ou técnico.
    *
    * Ser ator do chamado não basta; valem mais duas restrições, as mesmas da tela:
    *  - entidade: filtrar por dono diz que o chamado é MEU, não que ele está numa entidade que o meu
    *    perfil alcança;
    *  - direito de ver do perfil (LO-07): quem só tem "Ver meus chamados" (READMY) não vê o chamado de
    *    outra pessoa em que é só o técnico atribuído, e quem só tem "Ver chamados atribuídos"
    *    (READASSIGN) não vê o que abriu. O critério é o do core (`Ticket::getCriteriaFromProfile()`), e não
    *    um critério próprio, que envelheceria a cada direito novo do GLPI.
    */
   public static function listTickets(array $args): array {
      global $DB;

      // Sem `role` = os dois papéis. Até a 6.27.x a falta dele virava `null` e a lista saía vazia sem erro.
      $role  = (string) ($args['role'] ?? 'both');
      $role  = in_array($role, ['requester', 'assigned', 'both'], true) ? $role : 'both';
      $limit = (int) ($args['limit'] ?? self::LIST_LIMIT);
      $limit = $limit > 0 ? min($limit, self::LIST_LIMIT) : self::LIST_LIMIT;

      // Lista paginada (6.28.0, pedido do bot do WhatsApp): `offset` e/ou `order` pedidos.
      $paginar = array_key_exists('offset', $args) || array_key_exists('order', $args);
      $offset  = max(0, (int) ($args['offset'] ?? 0));
      $ordem   = ($args['order'] ?? 'opened') === 'updated' ? 'updated' : 'opened';

      $contato = self::contato();
      if ($contato !== null) {
         return self::listExternalTickets((int) $contato['contacts_id'], $limit, $paginar, $offset, $ordem);
      }
      $due   = in_array(($args['due'] ?? ''), ['overdue', 'soon'], true) ? (string) $args['due'] : '';

      // O core devolve o critério vazio em DOIS casos: quem vê todos os chamados e quem não tem direito
      // nenhum de ver. O segundo não pode virar "sem filtro".
      $perfil = Ticket::getCriteriaFromProfile();
      if (!Session::haveRight('ticket', Ticket::READALL) && empty($perfil['WHERE'])) {
         return ['items' => [], 'count' => 0, 'limit' => $limit];
      }

      // A tabela vai SEM apelido: o critério do core cita `glpi_tickets` e traz os próprios LEFT JOIN
      // (`tu`, `gt` e as validações). Por isso o vínculo do ator aqui se chama `ator`, e as linhas
      // repetidas por esses JOIN saem pelo DISTINCT.
      $t = Ticket::getTable();

      // Filtro de prazo: só como técnico atribuído. `soon` = vence em até 24 h, INCLUINDO os já vencidos
      // (o item traz `overdue` para distinguir); `overdue` = só os vencidos. Comparação no fuso da
      // sessão, o mesmo em que o banco devolve as datas.
      $agora = date('Y-m-d H:i:s');
      $prazo = [];
      if ($due !== '') {
         $role   = 'assigned';
         $limite = $due === 'overdue' ? $agora : date('Y-m-d H:i:s', time() + self::DUE_SOON_HOURS * 3600);
         $prazo  = [
            ['NOT' => ["$t.time_to_resolve" => null]],
            ["$t.time_to_resolve" => [$due === 'overdue' ? '<' : '<=', $limite]],
         ];
      }

      $types = [];
      if ($role === 'requester' || $role === 'both') {
         $types['requester'] = CommonITILActor::REQUESTER;
      }
      if ($role === 'assigned' || $role === 'both') {
         $types['assigned'] = CommonITILActor::ASSIGN;
      }

      if ($paginar) {
         return self::listTicketsPage($types, $prazo, $perfil, $due, $ordem, $limit, $offset, $agora);
      }

      $items = [];
      foreach ($types as $label => $type) {
         // array_merge_recursive, como o core faz com este critério: junta o WHERE e acrescenta o
         // LEFT JOIN do perfil sem apagar nada daqui.
         $it = $DB->request(array_merge_recursive([
            'SELECT'     => ["$t.id", "$t.name", "$t.status", "$t.date", "$t.time_to_resolve"],
            'DISTINCT'   => true,
            'FROM'       => $t,
            'INNER JOIN' => ['glpi_tickets_users AS ator' => ['ON' => ['ator' => 'tickets_id', $t => 'id']]],
            // array_merge, não `+`: as condições sem chave são numéricas (0, 1...) nos dois arrays, e o
            // `+` descartaria as do filtro de prazo em silêncio.
            'WHERE'      => array_merge([
               'ator.users_id' => (int) $_SESSION['glpiID'],
               'ator.type'     => $type,
               "$t.is_deleted" => 0,
               ['NOT' => ["$t.status" => [Ticket::SOLVED, Ticket::CLOSED]]],
               getEntitiesRestrictCriteria($t),
            ], $prazo),
            'ORDER'      => $due !== '' ? "$t.time_to_resolve ASC" : "$t.date DESC",
            'LIMIT'      => $limit,
         ], $perfil));
         foreach ($it as $row) {
            $venc    = (string) ($row['time_to_resolve'] ?? '');
            $items[] = [
               'id'           => (int) $row['id'],
               'title'        => (string) $row['name'],
               'status'       => (int) $row['status'],
               'status_label' => Ticket::getStatus((int) $row['status']),
               'opened_at'    => self::iso((string) $row['date']),
               'due_at'       => self::iso($venc),
               'overdue'      => $venc !== '' && $venc < $agora,
               'role'         => $label,
            ];
         }
      }

      return ['items' => $items, 'count' => count($items), 'limit' => $limit];
   }

   /**
    * Página da lista (6.28.0): UMA linha por chamado, com o papel do ator (`requester`, `assigned` ou `both`),
    * o total de chamados que casam e a ordem pedida (`opened` = abertura, `updated` = modificação; com `due`,
    * vale o prazo). Mesmos filtros da lista de sempre: ator, entidade, direito de ver do perfil, sem lixeira e
    * sem solucionado/fechado.
    */
   private static function listTicketsPage(array $types, array $prazo, array $perfil, string $due, string $ordem,
                                           int $limit, int $offset, string $agora): array {
      global $DB;
      $t     = Ticket::getTable();
      $crit  = array_merge_recursive([
         'FROM'       => $t,
         'INNER JOIN' => ['glpi_tickets_users AS ator' => ['ON' => ['ator' => 'tickets_id', $t => 'id']]],
         'WHERE'      => array_merge([
            'ator.users_id' => (int) $_SESSION['glpiID'],
            'ator.type'     => array_values($types),
            "$t.is_deleted" => 0,
            ['NOT' => ["$t.status" => [Ticket::SOLVED, Ticket::CLOSED]]],
            getEntitiesRestrictCriteria($t),
         ], $prazo),
      ], $perfil);

      $total = (int) ($DB->request($crit + ['SELECT' => ['COUNT DISTINCT' => "$t.id AS cpt"]])->current()['cpt'] ?? 0);

      $cols  = ["$t.id", "$t.name", "$t.status", "$t.date", "$t.date_mod", "$t.time_to_resolve"];
      $ord   = $due !== '' ? "$t.time_to_resolve ASC" : ($ordem === 'updated' ? "$t.date_mod DESC" : "$t.date DESC");
      $items = [];
      foreach ($DB->request($crit + [
         'SELECT'  => array_merge($cols, [
            new \Glpi\DBAL\QueryExpression('MIN(' . $DB->quoteName('ator.type') . ') AS ' . $DB->quoteName('tmin')),
            new \Glpi\DBAL\QueryExpression('MAX(' . $DB->quoteName('ator.type') . ') AS ' . $DB->quoteName('tmax')),
         ]),
         'GROUPBY' => $cols,
         'ORDER'   => [$ord, "$t.id DESC"],
         'START'   => $offset,
         'LIMIT'   => $limit,
      ]) as $row) {
         $venc = (string) ($row['time_to_resolve'] ?? '');
         $tmin = (int) $row['tmin'];
         $tmax = (int) $row['tmax'];
         $role = $tmin !== $tmax ? 'both' : ($tmin === CommonITILActor::REQUESTER ? 'requester' : 'assigned');
         $items[] = [
            'id'           => (int) $row['id'],
            'title'        => (string) $row['name'],
            'status'       => (int) $row['status'],
            'status_label' => Ticket::getStatus((int) $row['status']),
            'opened_at'    => self::iso((string) $row['date']),
            'updated_at'   => self::iso((string) $row['date_mod']),
            'due_at'       => self::iso($venc),
            'overdue'      => $venc !== '' && $venc < $agora,
            'role'         => $role,
         ];
      }

      return ['items' => $items, 'count' => count($items), 'limit' => $limit, 'offset' => $offset, 'total' => $total];
   }

   /**
    * Chamados abertos ligados ao contato externo, os mais recentes primeiro. A entidade e o direito de ver do
    * usuário de serviço continuam valendo: a ligação só restringe. Paginada (6.28.0) quando pedida.
    */
   private static function listExternalTickets(int $contactsId, int $limit, bool $paginar = false, int $offset = 0,
                                               string $ordem = 'opened'): array {
      global $DB;
      $t     = Ticket::getTable();
      $items = [];
      $agora = date('Y-m-d H:i:s');
      $crit  = [
         'FROM'       => $t,
         'INNER JOIN' => [PluginNextoolCloudContacts::TICKETS_TABLE . ' AS ct' => ['ON' => ['ct' => 'tickets_id', $t => 'id']]],
         'WHERE'      => [
            'ct.contacts_id' => $contactsId,
            "$t.is_deleted"  => 0,
            ['NOT' => ["$t.status" => [Ticket::SOLVED, Ticket::CLOSED]]],
            getEntitiesRestrictCriteria($t),
         ],
      ];
      $total = $paginar ? (int) ($DB->request($crit + ['COUNT' => 'cpt'])->current()['cpt'] ?? 0) : 0;
      foreach ($DB->request($crit + [
         'SELECT'     => ["$t.id", "$t.name", "$t.status", "$t.date", "$t.date_mod", "$t.time_to_resolve"],
         'ORDER'      => [($paginar && $ordem === 'updated') ? "$t.date_mod DESC" : "$t.date DESC", "$t.id DESC"],
         'START'      => $paginar ? $offset : 0,
         'LIMIT'      => $limit,
      ]) as $row) {
         if (!(new Ticket())->can((int) $row['id'], READ)) {
            continue;
         }
         $venc    = (string) ($row['time_to_resolve'] ?? '');
         $items[] = [
            'id'           => (int) $row['id'],
            'title'        => (string) $row['name'],
            'status'       => (int) $row['status'],
            'status_label' => Ticket::getStatus((int) $row['status']),
            'opened_at'    => self::iso((string) $row['date']),
            'due_at'       => self::iso($venc),
            'overdue'      => $venc !== '' && $venc < $agora,
            'role'         => 'requester',
         ] + ($paginar ? ['updated_at' => self::iso((string) $row['date_mod'])] : []);
      }
      if ($paginar) {
         return ['items' => $items, 'count' => count($items), 'limit' => $limit, 'offset' => $offset, 'total' => $total];
      }

      return ['items' => $items, 'count' => count($items), 'limit' => $limit];
   }

   /** Detalhe de um chamado + últimos acompanhamentos visíveis ao ator. */
   public static function getTicket(array $args): array {
      $id     = (int) ($args['id'] ?? 0);
      $ticket = new Ticket();
      // Na lixeira = não encontrado: o `can(READ)` deixa passar quem vê a lixeira. Contato externo: só os
      // ligados a ele, com a mesma resposta de "não existe".
      if ($id <= 0 || !self::doContato($id) || !$ticket->getFromDB($id) || (int) $ticket->fields['is_deleted'] === 1
         || !$ticket->can($id, READ)) {
         return ['found' => false];
      }

      $max = (int) ($args['followups'] ?? 5);
      $max = $max >= 0 ? min($max, 10) : 5;

      $followups = [];
      $fup       = new ITILFollowup();
      foreach ($max > 0 ? self::visibleFollowups($fup, $id, $max) : [] as $row) {
         // Segunda porta, a do core item a item. Com o filtro do SQL igual ao da linha do tempo, não
         // descarta nada na prática; fica para o caso de o core endurecer a regra de um lado só.
         if (!$fup->getFromDB((int) $row['id']) || !$fup->canViewItem()) {
            continue;
         }
         $followups[] = [
            'id'         => (int) $row['id'],
            'content'    => Glpi\RichText\RichText::getTextFromHtml((string) $row['content']),
            'created_at' => self::iso((string) $row['date_creation']),
            'is_private' => (bool) $row['is_private'],
         ];
      }

      return [
         'found'        => true,
         'id'           => $id,
         'title'        => (string) $ticket->fields['name'],
         'content'      => Glpi\RichText\RichText::getTextFromHtml((string) $ticket->fields['content']),
         'status'       => (int) $ticket->fields['status'],
         'status_label' => Ticket::getStatus((int) $ticket->fields['status']),
         'opened_at'    => self::iso((string) $ticket->fields['date']),
         'due_at'       => self::iso((string) ($ticket->fields['time_to_resolve'] ?? '')),
         'followups'    => $followups,
      ];
   }

   /**
    * Os `$max` acompanhamentos mais recentes que o ator VÊ, com a visibilidade decidida no SQL, antes do
    * limite (LO-08). O critério é o da linha do tempo do chamado no core
    * (`CommonITILObject::getTimelineItems`): sem o direito de ver privados, só os públicos e os privados do
    * próprio usuário, e estes só na interface central, como na tela.
    *
    * @return array<int, array> linhas de glpi_itilfollowups, da mais recente para a mais antiga
    */
   private static function visibleFollowups(ITILFollowup $fup, int $ticketId, int $max): array {
      if (!ITILFollowup::canView()) {
         return [];
      }
      $criterio = ['itemtype' => 'Ticket', 'items_id' => $ticketId];
      if (!Session::haveRight('followup', ITILFollowup::SEEPRIVATE)) {
         $criterio['OR'] = [
            'is_private' => 0,
            'users_id'   => Session::getCurrentInterface() === 'central' ? (int) Session::getLoginUserID() : 0,
         ];
      }

      // `id DESC` desempata acompanhamentos gravados no mesmo segundo, como na linha do tempo.
      return $fup->find($criterio, ['date_creation DESC', 'id DESC'], $max);
   }

   /**
    * Abre um chamado com o ator como requerente.
    *
    * Os campos opcionais vêm do catálogo do cérebro (a folha da triagem) e são CONFERIDOS aqui, nunca
    * aceitos como vieram: o `add()` do core não confere direito nenhum, e o `can(CREATE)` confere só o
    * direito de abrir na entidade.
    *  - `entities_id`: uma das entidades que a sessão alcança; sem ele, a entidade ativa;
    *  - `itilcategories_id`: existe, é visível na entidade, serve ao tipo e, na interface simplificada,
    *    é visível ao autoatendimento (a regra do formulário de abertura do core);
    *  - `requesttypes_id`: existe e está ativa;
    *  - `type` (1 incidente, 2 requisição) e `urgency` (1 a 5).
    * O grupo e o técnico saem da categoria pelo próprio core. Campo opcional inválido recusa o chamado
    * inteiro (`invalid_field`): abrir sem a categoria pedida mandaria o chamado para a fila errada.
    */
   public static function createTicket(array $args): array {
      $title   = trim((string) ($args['title'] ?? ''));
      $content = trim((string) ($args['content'] ?? ''));
      if ($title === '' || $content === '') {
         return ['created' => false, 'reason' => 'missing_fields'];
      }

      $entity = PluginNextoolCloudSession::activeEntity();
      if (array_key_exists('entities_id', $args) && $args['entities_id'] !== null && $args['entities_id'] !== '') {
         $pedida = filter_var($args['entities_id'], FILTER_VALIDATE_INT);
         if ($pedida === false || $pedida < 0 || !Session::haveAccessToEntity((int) $pedida)) {
            return ['created' => false, 'reason' => 'not_allowed'];
         }
         $entity = (int) $pedida;
      }
      if ($entity < 0) {
         return ['created' => false, 'reason' => 'no_entity'];
      }

      $contato = self::contato();
      $input   = [
         'name'                => Toolbox::substr($title, 0, self::MAX_TITLE),
         // Contato externo: o requerente no GLPI é o usuário de serviço; o de verdade vai no cabeçalho do
         // conteúdo (e na ligação, gravada abaixo), para o técnico saber com quem fala.
         'content'             => ($contato !== null ? PluginNextoolCloudContacts::header($contato) : '') . self::richText($content),
         'entities_id'         => $entity,
         '_users_id_requester' => (int) $_SESSION['glpiID'],
      ];

      $opcionais = self::catalogFields($args, $entity);
      if ($opcionais === null) {
         return ['created' => false, 'reason' => 'invalid_field'];
      }
      $input += $opcionais;

      $ticket = new Ticket();
      // O `add()` do core não confere direito nenhum: sem isto, o bit do módulo bastaria para abrir
      // chamado sem o direito nativo de abrir.
      if (!$ticket->can(-1, CREATE, $input)) {
         return ['created' => false, 'reason' => 'not_allowed'];
      }
      $id = $ticket->add($input);

      if (!$id) {
         return ['created' => false, 'reason' => 'rejected'];
      }
      // Sem a ligação o contato não veria o chamado que acabou de abrir. Ela entra logo depois do `add()`;
      // se falhar, o chamado existe, o técnico o vê pelo cabeçalho, e o desfecho continua "criado".
      if ($contato !== null && !PluginNextoolCloudContacts::linkTicket((int) $contato['contacts_id'], (int) $id)) {
         Toolbox::logInFile('plugin_nextool_cloud', sprintf("[cloud_contact] chamado %d aberto sem a ligação com o contato\n", (int) $id));
      }
      return ['created' => true, 'id' => (int) $id];
   }

   /**
    * Campos opcionais do catálogo, conferidos. null = algum inválido.
    *
    * @return array<string,int>|null
    */
   private static function catalogFields(array $args, int $entity): ?array {
      $saida = [];
      $int   = static function ($valor): ?int {
         if ($valor === null || $valor === '') {
            return null;
         }
         $v = filter_var($valor, FILTER_VALIDATE_INT);
         return $v === false ? -1 : (int) $v;
      };

      $type = $int($args['type'] ?? null);
      if ($type !== null) {
         if (!in_array($type, [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true)) {
            return null;
         }
         $saida['type'] = $type;
      }

      $urgency = $int($args['urgency'] ?? null);
      if ($urgency !== null) {
         if ($urgency < 1 || $urgency > 5) {
            return null;
         }
         $saida['urgency'] = $urgency;
      }

      $categoria = $int($args['itilcategories_id'] ?? null);
      if ($categoria !== null && $categoria !== 0) {
         if ($categoria < 0 || !self::categoryUsable($categoria, $entity, $saida['type'] ?? Ticket::INCIDENT_TYPE)) {
            return null;
         }
         $saida['itilcategories_id'] = $categoria;
      }

      $origem = $int($args['requesttypes_id'] ?? null);
      if ($origem !== null && $origem !== 0) {
         $rt = new RequestType();
         if ($origem < 0 || !$rt->getFromDB($origem) || empty($rt->fields['is_active'])) {
            return null;
         }
         $saida['requesttypes_id'] = $origem;
      }

      return $saida;
   }

   /**
    * A categoria pode ser usada para abrir chamado nesta entidade, com este tipo, pela interface do ator?
    * Mesma régua do formulário de abertura do core: visível na entidade (própria ou herdada por
    * recursividade), marcada para o tipo e, no autoatendimento, visível ao autoatendimento.
    */
   private static function categoryUsable(int $categoryId, int $entity, int $type): bool {
      global $DB;
      $c   = ITILCategory::getTable();
      $flag = $type === Ticket::DEMAND_TYPE ? 'is_request' : 'is_incident';
      $where = [
         "$c.id" => $categoryId,
         "$c.$flag" => 1,
      ] + getEntitiesRestrictCriteria($c, '', $entity, true);
      if (Session::getCurrentInterface() !== 'central') {
         $where["$c.is_helpdeskvisible"] = 1;
      }
      $row = $DB->request(['COUNT' => 'cnt', 'FROM' => $c, 'WHERE' => $where])->current();

      return (int) ($row['cnt'] ?? 0) > 0;
   }

   /**
    * Acompanhamento no chamado, com o `can()` do core decidindo.
    *
    * Privado só para quem pode VER privados: o core aceita gravar privado de qualquer um (é a tela que
    * esconde a opção), e um requerente criaria um acompanhamento que ele mesmo não enxerga.
    */
   public static function addFollowup(array $args): array {
      $ticketId = (int) ($args['ticket_id'] ?? 0);
      $content  = trim((string) ($args['content'] ?? ''));
      $private  = filter_var($args['is_private'] ?? false, FILTER_VALIDATE_BOOLEAN);
      if ($ticketId <= 0 || $content === '') {
         return ['added' => false, 'reason' => 'missing_fields'];
      }
      if ($private && (self::contato() !== null || !Session::haveRight('followup', ITILFollowup::SEEPRIVATE))) {
         return ['added' => false, 'reason' => 'not_allowed'];
      }

      $ticket = new Ticket();
      if (!self::doContato($ticketId) || !$ticket->getFromDB($ticketId) || (int) $ticket->fields['is_deleted'] === 1
         || !$ticket->can($ticketId, READ)) {
         return ['added' => false, 'reason' => 'not_allowed'];
      }

      $fup   = new ITILFollowup();
      $input = [
         'itemtype'   => 'Ticket',
         'items_id'   => $ticketId,
         'content'    => self::richText($content),
         'is_private' => $private ? 1 : 0,
         'users_id'   => (int) $_SESSION['glpiID'],
      ];
      // Ler o chamado não é poder acompanhá-lo. Quem decide é o `can(CREATE)` do core -- com o input
      // PÚBLICO: para item PRIVADO do próprio usuário o `can(-1, CREATE)` devolve true antes de olhar
      // direito nenhum (CommonDBTM::can), e um perfil só de leitura acompanhava qualquer chamado, até
      // fechado. O privado só entra na gravação, já coberto pelo SEEPRIVATE acima.
      $checagem = ['is_private' => 0] + $input; // o can() recebe o input por referência
      if (!$fup->can(-1, CREATE, $checagem)) {
         return ['added' => false, 'reason' => 'not_allowed'];
      }
      $id = $fup->add($input);

      if (!$id) {
         return ['added' => false, 'reason' => 'rejected'];
      }
      return ['added' => true, 'id' => (int) $id, 'ticket_id' => $ticketId, 'is_private' => $private];
   }

   /**
    * Pode o ator anexar arquivo a este chamado? A mesma porta do acompanhamento público: o chamado existe, não
    * está na lixeira, é do contato externo em execução (se for o caso), o ator o vê e o `can(CREATE)` do core
    * deixa acompanhar. Conferido ANTES de buscar o arquivo, para não baixar 16 MB e recusar depois.
    */
   public static function canAttach(int $ticketId): bool {
      $ticket = new Ticket();
      if ($ticketId <= 0 || !self::doContato($ticketId) || !$ticket->getFromDB($ticketId)
         || (int) $ticket->fields['is_deleted'] === 1 || !$ticket->can($ticketId, READ)) {
         return false;
      }
      $checagem = ['itemtype' => 'Ticket', 'items_id' => $ticketId, 'is_private' => 0, 'content' => 'x',
         'users_id' => (int) $_SESSION['glpiID']];

      return (new ITILFollowup())->can(-1, CREATE, $checagem);
   }

   /**
    * Anexa ao chamado um arquivo já em disco (6.28.0), pelo caminho da própria tela do GLPI: um acompanhamento
    * PÚBLICO com o arquivo (`_filename`, o core cria o documento e o liga ao acompanhamento). Não exige o
    * direito "Documento: criar", que o autoatendimento padrão não tem: vale o de acompanhar. O arquivo em
    * disco não é apagado aqui (é de quem chamou).
    *
    * @param string $content texto do acompanhamento, já em rich text (a legenda ou um texto do canal)
    * @return array{ok:bool, document_id?:int, followup_id?:int, reason?:string}
    *         reason: not_allowed | type_not_allowed | rejected
    */
   public static function attachFile(int $ticketId, string $localPath, string $fileName, string $content): array {
      if (!is_readable($localPath)) {
         return ['ok' => false, 'reason' => 'rejected'];
      }
      if (!self::canAttach($ticketId)) {
         return ['ok' => false, 'reason' => 'not_allowed'];
      }
      // Nome só como rótulo: sem caminho. O tipo aceito (Configurar > Tipos de documento) sai da extensão.
      $nome = trim(basename(str_replace('\\', '/', $fileName)));
      if ($nome === '' || Document::isValidDoc($nome) === '') {
         return ['ok' => false, 'reason' => 'type_not_allowed'];
      }

      $prefixo = uniqid('nx', true) . '_';
      $prefixo = str_replace('.', '', $prefixo);
      $arquivo = $prefixo . $nome;
      if (!@copy($localPath, GLPI_TMP_DIR . '/' . $arquivo)) {
         return ['ok' => false, 'reason' => 'rejected'];
      }

      $fup   = new ITILFollowup();
      $input = [
         'itemtype'          => 'Ticket',
         'items_id'          => $ticketId,
         'content'           => $content !== '' ? $content : self::richText($nome),
         'is_private'        => 0,
         'users_id'          => (int) $_SESSION['glpiID'],
         '_filename'         => [$arquivo],
         '_prefix_filename'  => [$prefixo],
      ];
      $id = $fup->add($input);
      if (!$id) {
         @unlink(GLPI_TMP_DIR . '/' . $arquivo);
         return ['ok' => false, 'reason' => 'rejected'];
      }

      $doc = (new Document_Item())->find(['itemtype' => ITILFollowup::class, 'items_id' => (int) $id], ['id DESC'], 1);
      if ($doc === []) {
         // O acompanhamento entrou, o arquivo não (o core recusou ao mover para o armazenamento).
         @unlink(GLPI_TMP_DIR . '/' . $arquivo);
         return ['ok' => false, 'reason' => 'rejected', 'followup_id' => (int) $id];
      }

      return ['ok' => true, 'document_id' => (int) reset($doc)['documents_id'], 'followup_id' => (int) $id];
   }

   /**
    * Responde uma solicitação de aprovação.
    *
    * A porta é o `canAnswer()`, a MESMA do core para responder (front/commonitilvalidation.form.php): o
    * ator é o aprovador (ele, um grupo dele ou alguém de quem é substituto). O `can(UPDATE)` é a porta de
    * EDITAR o pedido, e deixava quem pode PEDIR aprovação "responder" validação alheia.
    */
   public static function applyValidation(array $args): array {
      $validationId = (int) ($args['validation_id'] ?? 0);
      $action       = (string) ($args['action'] ?? '');
      $comment      = trim((string) ($args['comment'] ?? ''));

      if ($validationId <= 0 || !in_array($action, ['approve', 'refuse'], true)) {
         return ['applied' => false, 'reason' => 'missing_fields'];
      }

      $validation = new TicketValidation();
      // `canAnswer()` não olha o status: só a validação ainda em espera pode ser respondida.
      if (!$validation->getFromDB($validationId) || !$validation->canAnswer()
         || (int) $validation->fields['status'] !== CommonITILValidation::WAITING) {
         return ['applied' => false, 'reason' => 'not_allowed'];
      }
      $ticket = new Ticket();
      if (!$ticket->getFromDB((int) $validation->fields['tickets_id']) || (int) $ticket->fields['is_deleted'] === 1) {
         return ['applied' => false, 'reason' => 'not_allowed'];
      }

      $status = $action === 'approve' ? CommonITILValidation::ACCEPTED : CommonITILValidation::REFUSED;
      $ok     = $validation->update([
         'id'                 => $validationId,
         'status'             => $status,
         'users_id_validate'  => (int) $_SESSION['glpiID'],
         'comment_validation' => $comment !== '' ? self::richText($comment) : '',
      ]);

      // Relê: o `update()` devolve true mesmo quando o core descarta o status (ex.: recusa sem motivo).
      if (!$ok || !$validation->getFromDB($validationId) || (int) $validation->fields['status'] !== $status) {
         return ['applied' => false, 'reason' => 'rejected'];
      }

      return [
         'applied'   => true,
         'action'    => $action,
         'ticket_id' => (int) $validation->fields['tickets_id'],
      ];
   }

   /**
    * Registra a solução do chamado. Duas autorizações distintas, cobradas ANTES de gravar: criar a
    * solução e alterar o chamado.
    */
   public static function addSolution(array $args): array {
      $ticketId = (int) ($args['ticket_id'] ?? 0);
      $content  = trim((string) ($args['content'] ?? ''));
      if ($ticketId <= 0 || $content === '') {
         return ['added' => false, 'reason' => 'missing_fields'];
      }

      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticketId) || (int) $ticket->fields['is_deleted'] === 1) {
         return ['added' => false, 'reason' => 'not_allowed'];
      }
      $solution = new ITILSolution();
      $input    = [
         'itemtype' => 'Ticket',
         'items_id' => $ticketId,
         'content'  => self::richText($content),
      ];
      if (!$solution->can(-1, CREATE, $input) || !$ticket->can($ticketId, UPDATE)) {
         return ['added' => false, 'reason' => 'not_allowed'];
      }

      $id = $solution->add($input);
      if (!$id) {
         return ['added' => false, 'reason' => 'rejected'];
      }
      // O core já move o chamado para Solucionado (ou Fechado, conforme a entidade). Relê para devolver
      // o status real, não o presumido.
      $ticket->getFromDB($ticketId);

      return [
         'added'        => true,
         'id'           => (int) $id,
         'ticket_id'    => $ticketId,
         'status'       => (int) $ticket->fields['status'],
         'status_label' => Ticket::getStatus((int) $ticket->fields['status']),
      ];
   }

   /**
    * Deixa o chamado Pendente com um acompanhamento público explicando o motivo.
    *
    * Tudo o que autoriza é cobrado ANTES de gravar: a porta de status, acompanhar e alterar o chamado.
    * Se mesmo assim o status não mudar, a resposta diz isso e devolve o acompanhamento gravado.
    */
   public static function setPending(array $args): array {
      $ticketId = (int) ($args['ticket_id'] ?? 0);
      $content  = trim((string) ($args['content'] ?? ''));
      if ($ticketId <= 0 || $content === '') {
         return ['pending' => false, 'reason' => 'missing_fields'];
      }

      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticketId) || (int) $ticket->fields['is_deleted'] === 1) {
         return ['pending' => false, 'reason' => 'not_allowed'];
      }
      // Porta de STATUS, a mesma da tela (ME-13; glpi-dev, armadilha #123). A tela só oferece "Pendente"
      // na interface central, e só quando o ciclo de vida do perfil permite sair do status atual para
      // Pendente. O `update()` do core não confere nenhuma das duas, e o `can(UPDATE)` também não.
      if (Session::getCurrentInterface() !== 'central'
         || !Ticket::isAllowedStatus((int) $ticket->fields['status'], Ticket::WAITING)) {
         return ['pending' => false, 'reason' => 'not_allowed'];
      }
      $fup   = new ITILFollowup();
      $input = [
         'itemtype'   => 'Ticket',
         'items_id'   => $ticketId,
         'content'    => self::richText($content),
         'is_private' => 0,
         'users_id'   => (int) $_SESSION['glpiID'],
      ];
      if (!$fup->can(-1, CREATE, $input) || !$ticket->can($ticketId, UPDATE)) {
         return ['pending' => false, 'reason' => 'not_allowed'];
      }

      $followupId = $fup->add($input);
      if (!$followupId) {
         return ['pending' => false, 'reason' => 'rejected'];
      }
      // Relê: o `update()` devolve true mesmo quando o status não muda (regra de negócio, modelo, plugin).
      if (!$ticket->update(['id' => $ticketId, 'status' => Ticket::WAITING])
         || !$ticket->getFromDB($ticketId) || (int) $ticket->fields['status'] !== Ticket::WAITING) {
         return ['pending' => false, 'reason' => 'status_rejected', 'followup_id' => (int) $followupId];
      }

      return ['pending' => true, 'followup_id' => (int) $followupId, 'ticket_id' => $ticketId];
   }

   /**
    * Aprovações que aguardam o ator. O critério de "sou aprovador" é o do PRÓPRIO core
    * (`getTargetCriteriaForUser`): o usuário, os titulares de quem ele é substituto e os grupos dele. É o
    * mesmo que o `canAnswer()` usa, então tudo o que aparece aqui o `apply_validation` aceita.
    */
   public static function listValidations(array $args): array {
      global $DB;

      $limit     = (int) ($args['limit'] ?? self::LIST_LIMIT);
      $limit     = $limit > 0 ? min($limit, self::LIST_LIMIT) : self::LIST_LIMIT;
      $vt        = TicketValidation::getTable();
      $doChamado = (int) ($args['ticket_id'] ?? 0);

      $it = $DB->request([
         'SELECT'     => [
            "$vt.id AS validation_id", "$vt.tickets_id", "$vt.users_id AS requested_by_id",
            "$vt.submission_date", "$vt.comment_submission", 't.name', 't.status',
         ],
         'FROM'       => $vt,
         'INNER JOIN' => ['glpi_tickets AS t' => ['ON' => [$vt => 'tickets_id', 't' => 'id']]],
         'WHERE'      => [
            "$vt.status"   => CommonITILValidation::WAITING,
            't.is_deleted' => 0,
            ['NOT' => ['t.status' => [Ticket::SOLVED, Ticket::CLOSED]]],
            TicketValidation::getTargetCriteriaForUser((int) $_SESSION['glpiID']),
            getEntitiesRestrictCriteria('t'),
         ] + ($doChamado > 0 ? ["$vt.tickets_id" => $doChamado] : []),
         'ORDER'      => "$vt.submission_date DESC",
         'LIMIT'      => $limit,
      ]);

      $items = [];
      $user  = new User();
      foreach ($it as $row) {
         $quem    = $user->getFromDB((int) $row['requested_by_id']) ? $user->getFriendlyName() : '';
         $items[] = [
            'validation_id'       => (int) $row['validation_id'],
            'ticket_id'           => (int) $row['tickets_id'],
            'ticket_title'        => (string) $row['name'],
            'ticket_status'       => (int) $row['status'],
            'ticket_status_label' => Ticket::getStatus((int) $row['status']),
            'requested_by'        => $quem,
            'requested_at'        => self::iso((string) $row['submission_date']),
            'comment'             => Glpi\RichText\RichText::getTextFromHtml((string) ($row['comment_submission'] ?? '')),
         ];
      }

      return ['items' => $items, 'count' => count($items), 'limit' => $limit];
   }

   /**
    * Texto digitado no canal -> conteúdo rich text do GLPI.
    *
    * Os campos de conteúdo do GLPI 11 são HTML, e o texto chegava cru: uma marcação
    * `<span data-user-mention="true" data-user-id="N">` incluía o usuário N como observador (o
    * UserMention roda no post_addItem sem conferir direito do autor), e `<`/`>` em texto comum faziam o
    * GLPI engolir as quebras de linha. Aqui vira HTML ESCAPADO, com as quebras. Trunca ANTES de escapar,
    * para não cortar uma entidade ao meio.
    *
    * O `<p>` não é enfeite: com `must_unsanitize_db_data` ligado, o core DECODIFICA na leitura o valor que
    * tem entidade e nenhum `<`/`>` cru, e a mensagem de uma linha voltaria como HTML (glpi-dev #126).
    */
   public static function richText(string $texto): string {
      return '<p>' . nl2br(htmlspecialchars(Toolbox::substr($texto, 0, self::MAX_CONTENT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
   }

   /**
    * Data do banco -> ISO 8601 com deslocamento. O banco devolve no fuso da sessão (o GLPI acerta o fuso
    * do PHP e o da conexão juntos), então o fuso padrão do PHP é o certo para interpretar.
    */
   public static function iso(?string $valor): ?string {
      $valor = trim((string) $valor);
      if ($valor === '' || strpos($valor, '0000-00-00') === 0) {
         return null;
      }
      try {
         return (new DateTime($valor, new DateTimeZone(date_default_timezone_get())))->format(DATE_ATOM);
      } catch (\Throwable $e) {
         return null;
      }
   }
}
