<?php
/**
 * Cloud link -- CONTATO EXTERNO: quem fala com o GLPI por um canal (WhatsApp, Telegram...) sem ter conta no
 * GLPI (contrato do cloud link §8.5; decisões do owner de 2026-09-26: opção do cliente, desligada por
 * padrão; requerente no GLPI = usuário de serviço do canal; identificação só pelo canal).
 *
 * O GLPI não tem onde guardar "um requerente sem conta", e o requerente de todo chamado de contato externo
 * é o mesmo usuário de serviço do canal. Por isso a base guarda, aqui, quem é o contato e quais chamados
 * são dele. É esta ligação, e não o direito do GLPI, que decide o que o contato vê: o usuário de serviço
 * lê todos os chamados que abriu, e sem o filtro daqui um contato leria os de outro.
 *
 *  - `contacts`: um por (serviço, canal, contato no canal), com o rótulo e as datas de primeiro e último
 *    contato;
 *  - `contact_tickets`: a ligação contato <-> chamado, gravada na abertura;
 *  - `contact_log`: a auditoria, uma linha por ação executada para o contato (ferramenta, chamado,
 *    request_id, desfecho), sem conteúdo. Retenção de LOG_RETENTION_DAYS.
 *
 * O CONTEXTO da execução em curso (quem é o contato) fica em memória só durante o handler
 * (`PluginNextoolCloudExecutor` chama begin/end). As ferramentas perguntam `current()`: null = ator comum.
 *
 * LGPD: o contato externo é uma categoria de titular própria (não é usuário do GLPI do cliente). O RoPA do
 * atendimento e do livechat tem de ter a entrada antes do uso em cliente (F8 do plano).
 *
 * @since 6.27.0
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudContacts {

   public const CONTACTS_TABLE = 'glpi_plugin_nextool_cloud_contacts';
   public const TICKETS_TABLE  = 'glpi_plugin_nextool_cloud_contact_tickets';
   public const LOG_TABLE      = 'glpi_plugin_nextool_cloud_contact_log';

   /** Auditoria por contato: 90 dias (a mesma janela do tenant de ex-cliente no cérebro). */
   public const LOG_RETENTION_DAYS = 90;

   /**
    * Contato sem nenhum chamado ligado e sem falar há mais que isto sai da tabela. Com chamado ligado ele
    * fica: é o requerente de verdade daquele chamado.
    */
   public const CONTACT_RETENTION_DAYS = 90;

   /** `external_id` do contrato: "<canal>:<contato no canal>". */
   private const CHANNEL_RE = '/^[a-z]{2,16}$/';
   private const REF_RE     = '/^[A-Za-z0-9_.+@-]{1,64}$/';

   /** @var array|null contexto da execução em curso */
   private static $current = null;

   /**
    * Separa o `external_id` do contrato em canal e contato. null = formato inválido.
    *
    * @return array{channel:string, ref:string}|null
    */
   public static function parseExternalId(string $externalId): ?array {
      $pos = strpos($externalId, ':');
      if ($pos === false) {
         return null;
      }
      $channel = substr($externalId, 0, $pos);
      $ref     = substr($externalId, $pos + 1);
      if (!preg_match(self::CHANNEL_RE, $channel) || !preg_match(self::REF_RE, $ref)) {
         return null;
      }

      return ['channel' => $channel, 'ref' => $ref];
   }

   /** Rótulo do contato: texto curto, sem quebra de linha nem caracteres de controle. */
   public static function cleanLabel(string $label): string {
      $label = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label));

      return Toolbox::substr($label, 0, 120);
   }

   /**
    * Cadastra o contato, ou atualiza o rótulo e o último contato. Devolve o id, ou 0 se não gravou.
    */
   public static function touch(string $service, string $channel, string $ref, string $label): int {
      global $DB;
      $agora = date('Y-m-d H:i:s');
      $label = self::cleanLabel($label);
      $chave = ['service' => $service, 'channel' => $channel, 'contact_ref' => $ref];

      $row = $DB->request(['SELECT' => ['id'], 'FROM' => self::CONTACTS_TABLE, 'WHERE' => $chave, 'LIMIT' => 1])->current();
      if ($row) {
         $upd = ['last_seen' => $agora];
         if ($label !== '') {
            $upd['contact_label'] = $label;
         }
         $DB->update(self::CONTACTS_TABLE, $upd, ['id' => (int) $row['id']]);
         return (int) $row['id'];
      }
      try {
         $DB->insert(self::CONTACTS_TABLE, $chave + [
            'contact_label' => $label,
            'first_seen'    => $agora,
            'last_seen'     => $agora,
         ]);
      } catch (\Throwable $e) {
         // corrida pela UNIQUE: vale a linha de quem gravou antes, relida abaixo
      }
      $row = $DB->request(['SELECT' => ['id'], 'FROM' => self::CONTACTS_TABLE, 'WHERE' => $chave, 'LIMIT' => 1])->current();

      return $row ? (int) $row['id'] : 0;
   }

   /** @return array{id:int, service:string, channel:string, contact_ref:string, contact_label:string}|null */
   public static function get(int $contactsId): ?array {
      global $DB;
      $row = $DB->request(['FROM' => self::CONTACTS_TABLE, 'WHERE' => ['id' => $contactsId], 'LIMIT' => 1])->current();
      if (!$row) {
         return null;
      }

      return [
         'id'            => (int) $row['id'],
         'service'       => (string) $row['service'],
         'channel'       => (string) $row['channel'],
         'contact_ref'   => (string) $row['contact_ref'],
         'contact_label' => (string) $row['contact_label'],
      ];
   }

   /** Liga o chamado ao contato (idempotente). */
   public static function linkTicket(int $contactsId, int $ticketId): bool {
      global $DB;
      if ($contactsId <= 0 || $ticketId <= 0) {
         return false;
      }
      if (self::isLinked($contactsId, $ticketId)) {
         return true;
      }
      try {
         return (bool) $DB->insert(self::TICKETS_TABLE, [
            'contacts_id'   => $contactsId,
            'tickets_id'    => $ticketId,
            'date_creation' => date('Y-m-d H:i:s'),
         ]);
      } catch (\Throwable $e) {
         return self::isLinked($contactsId, $ticketId);
      }
   }

   public static function isLinked(int $contactsId, int $ticketId): bool {
      return $contactsId > 0 && $ticketId > 0
         && countElementsInTable(self::TICKETS_TABLE, ['contacts_id' => $contactsId, 'tickets_id' => $ticketId]) > 0;
   }

   /** Contato de um chamado aberto por contato externo, para a tela do técnico. null = não é. */
   public static function contactOfTicket(int $ticketId): ?array {
      global $DB;
      $row = $DB->request([
         'SELECT'     => ['c.id'],
         'FROM'       => self::TICKETS_TABLE . ' AS ct',
         'INNER JOIN' => [self::CONTACTS_TABLE . ' AS c' => ['ON' => ['ct' => 'contacts_id', 'c' => 'id']]],
         'WHERE'      => ['ct.tickets_id' => $ticketId],
         'ORDER'      => 'ct.id',
         'LIMIT'      => 1,
      ])->current();

      return $row ? self::get((int) $row['id']) : null;
   }

   /**
    * Começa o contexto de um contato externo. Só o executor chama, em volta do handler.
    *
    * @param array{contacts_id:int, service:string, channel:string, contact_ref:string, contact_label:string} $ctx
    */
   public static function begin(array $ctx): void {
      self::$current = $ctx;
   }

   public static function end(): void {
      self::$current = null;
   }

   /** Contexto do contato externo em execução, ou null (ator comum, usuário do GLPI). */
   public static function current(): ?array {
      return self::$current;
   }

   /**
    * Cabeçalho do conteúdo do chamado aberto por contato externo: quem é, por qual canal, e o contato.
    * O requerente no GLPI é o usuário de serviço; é aqui que o técnico vê o requerente de verdade. HTML
    * escapado, no mesmo formato do conteúdo (`PluginNextoolCloudItilTools::richText`).
    */
   public static function header(array $ctx): string {
      $canal = ucfirst((string) ($ctx['channel'] ?? ''));
      $nome  = (string) ($ctx['contact_label'] ?? '');
      $ref   = (string) ($ctx['contact_ref'] ?? '');
      $quem  = $nome !== '' ? sprintf('%s (%s %s)', $nome, $canal, $ref) : sprintf('%s %s', $canal, $ref);

      return '<p><strong>' . htmlspecialchars(__('Contato externo, sem conta no GLPI:', 'nextool'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
         . '</strong> ' . htmlspecialchars($quem, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
   }

   /**
    * Auditoria: uma linha por ação executada para o contato. Sem conteúdo: ferramenta, chamado, request_id
    * e o desfecho (código). Falha aqui não derruba a ação, que já aconteceu.
    */
   public static function log(int $contactsId, string $service, string $tool, int $ticketId, string $requestId, string $code): void {
      global $DB;
      if ($contactsId <= 0) {
         return;
      }
      try {
         $DB->insert(self::LOG_TABLE, [
            'contacts_id'   => $contactsId,
            'service'       => $service,
            'tool'          => $tool,
            'tickets_id'    => $ticketId > 0 ? $ticketId : 0,
            'request_id'    => Toolbox::substr($requestId, 0, 64),
            'code'          => Toolbox::substr($code, 0, 32),
            'date_creation' => date('Y-m-d H:i:s'),
         ]);
      } catch (\Throwable $e) {
         if (class_exists('Toolbox')) {
            Toolbox::logInFile('plugin_nextool_cloud', "[cloud_contact] auditoria não gravada\n");
         }
      }
   }

   /**
    * Retenção (roda na tarefa `cloudPurge`): auditoria com mais de LOG_RETENTION_DAYS; ligação com chamado
    * que não existe mais; contato sem chamado ligado e sem falar há mais de CONTACT_RETENTION_DAYS.
    *
    * @return array{log:int, links:int, contacts:int}
    */
   public static function purge(int $now = 0): array {
      global $DB;
      $now   = $now > 0 ? $now : time();
      $saida = ['log' => 0, 'links' => 0, 'contacts' => 0];
      if (!$DB->tableExists(self::CONTACTS_TABLE)) {
         return $saida;
      }

      // Conta ANTES de apagar: o affectedRows() do GLPI mente quando o comando gera warning.
      $velhoLog     = ['date_creation' => ['<', date('Y-m-d H:i:s', $now - self::LOG_RETENTION_DAYS * 86400)]];
      $saida['log'] = countElementsInTable(self::LOG_TABLE, $velhoLog);
      if ($saida['log'] > 0) {
         $DB->delete(self::LOG_TABLE, $velhoLog);
      }

      $orfas = [];
      foreach ($DB->request([
         'SELECT'    => ['ct.id'],
         'FROM'      => self::TICKETS_TABLE . ' AS ct',
         'LEFT JOIN' => ['glpi_tickets AS t' => ['ON' => ['ct' => 'tickets_id', 't' => 'id']]],
         'WHERE'     => ['t.id' => null],
      ]) as $row) {
         $orfas[] = (int) $row['id'];
      }
      if ($orfas !== []) {
         $DB->delete(self::TICKETS_TABLE, ['id' => $orfas]);
         $saida['links'] = count($orfas);
      }

      $velhos = [];
      foreach ($DB->request([
         'SELECT'    => ['c.id'],
         'FROM'      => self::CONTACTS_TABLE . ' AS c',
         'LEFT JOIN' => [self::TICKETS_TABLE . ' AS ct' => ['ON' => ['ct' => 'contacts_id', 'c' => 'id']]],
         'WHERE'     => [
            'ct.id'       => null,
            'c.last_seen' => ['<', date('Y-m-d H:i:s', $now - self::CONTACT_RETENTION_DAYS * 86400)],
         ],
      ]) as $row) {
         $velhos[] = (int) $row['id'];
      }
      if ($velhos !== []) {
         $DB->delete(self::LOG_TABLE, ['contacts_id' => $velhos]);
         $DB->delete(self::CONTACTS_TABLE, ['id' => $velhos]);
         $saida['contacts'] = count($velhos);
      }

      return $saida;
   }
}
