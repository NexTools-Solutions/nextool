<?php
/**
 * Cloud link -- USUÁRIO DE SERVIÇO de um módulo de canal: a conta do GLPI em nome da qual o contato
 * externo (sem conta no GLPI) abre e acompanha chamados (contrato do cloud link §8.5; decisões do owner de
 * 2026-09-26).
 *
 * Por que uma conta, e não "chamado sem requerente": chamado sem requerente sai das visões do
 * autoatendimento e das regras e notificações por requerente, e quebra a conferência de requerente que o
 * bot usa para aceitar acompanhamento. O requerente de verdade fica em `PluginNextoolCloudContacts`.
 *
 * Regras da conta, todas conferidas a cada `ensure()` (o módulo chama ao ligar a opção e ao salvar a
 * configuração, e o estado volta ao esperado se alguém mexer à mão):
 *  - UMA por módulo de canal, nunca compartilhada entre canais, NUNCA Super-Admin;
 *  - perfil PRÓPRIO, na interface simplificada, com a régua do Self-Service padrão do GLPI: abrir chamado e
 *    ver os próprios (ticket = CREATE | READMY), ver acompanhamentos públicos e acompanhar os próprios
 *    (followup = SEEPUBLIC | ADDMYTICKET), e os bits do módulo que o contato externo usa. Mais nada;
 *  - uma entidade só, sem recursividade: a que o cliente escolheu para os chamados de contato externo;
 *  - sem e-mail (não recebe o aviso de todos os chamados que "abriu") e com senha aleatória que ninguém
 *    conhece (não entra pela tela). Não tem token de API.
 *
 * Premissa: o `ensure()` zera os direitos que o perfil TEM na hora. Um plugin instalado depois que acrescente
 * direito a todos os perfis só é zerado aqui na próxima chamada. Por isso o módulo de canal chama `ensure()`
 * também de tempos em tempos, não só ao salvar a configuração.
 *
 * O que o contato vê NÃO vem deste perfil: o usuário de serviço lê todos os chamados que abriu, e é a
 * ligação contato <-> chamado que filtra (as ferramentas conferem `PluginNextoolCloudContacts`).
 *
 * @since 6.27.0
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudServiceUser {

   /** Prefixo do login e do perfil, para achar (e auditar) as contas criadas pela base. */
   private const LOGIN_PREFIX   = 'nextool-contato-externo-';
   private const PROFILE_PREFIX = 'NexTool - contato externo - ';

   /** Direitos do core: a régua do Self-Service padrão (ver cabeçalho). */
   private const TICKET_RIGHTS   = CREATE | Ticket::READMY;
   private const FOLLOWUP_RIGHTS = ITILFollowup::SEEPUBLIC | ITILFollowup::ADDMYTICKET;

   /**
    * Garante a conta e o perfil do módulo, com os direitos esperados.
    *
    * @param string $moduleKey chave do módulo de canal (ex.: `whatsappbot`)
    * @param string $label     rótulo do canal na tela (ex.: "WhatsApp")
    * @param int    $entityId  entidade dos chamados de contato externo (>= 0; a raiz é 0)
    * @param int    $moduleBits bits do módulo que o contato externo usa (OR dos bits das ferramentas)
    * @return array{users_id:int, profiles_id:int}|null null = não conseguiu (entidade inexistente, falha ao gravar)
    */
   public static function ensure(string $moduleKey, string $label, int $entityId, int $moduleBits): ?array {
      global $DB;
      if (!preg_match('/^[a-z0-9_]{2,32}$/', $moduleKey) || $entityId < 0 || $moduleBits < 0) {
         return null;
      }
      $entity = new Entity();
      if (!$entity->getFromDB($entityId)) {
         return null;
      }

      $profileId = self::ensureProfile($moduleKey, $label, $moduleBits);
      if ($profileId <= 0) {
         return null;
      }

      $login = self::LOGIN_PREFIX . $moduleKey;
      $row   = $DB->request(['SELECT' => ['id'], 'FROM' => User::getTable(), 'WHERE' => ['name' => $login], 'LIMIT' => 1])->current();
      $userId = $row ? (int) $row['id'] : 0;
      if ($userId <= 0) {
         $user   = new User();
         $userId = (int) $user->add([
            'name'      => $login,
            'realname'  => sprintf(__('Contato externo (%s)', 'nextool'), $label),
            'is_active' => 1,
            'authtype'  => Auth::DB_GLPI,
            // Sem `_profiles_id`/`_entities_id`: o vínculo de perfil é feito abaixo, explícito. O
            // `User::add()` pela CLI não cria o Profile_User (memória learning_glpi_cli_user_add_skips_profile).
         ]);
         if ($userId <= 0) {
            return null;
         }
      }

      // Estado esperado, reposto a cada chamada: ativa, sem validade, autenticação interna, perfil e
      // entidade padrão, senha aleatória (ninguém conhece), sem token de API.
      $DB->update(User::getTable(), [
         'is_active'      => 1,
         'is_deleted'     => 0,
         'begin_date'     => null,
         'end_date'       => null,
         'authtype'       => Auth::DB_GLPI,
         'profiles_id'    => $profileId,
         'entities_id'    => $entityId,
         'password'       => Auth::getPasswordHash(bin2hex(random_bytes(32))),
         'password_last_update' => date('Y-m-d H:i:s'),
         'api_token'      => null,
         'personal_token' => null,
      ], ['id' => $userId]);
      // Sem e-mail: não recebe as notificações dos chamados que "abriu".
      $DB->delete(UserEmail::getTable(), ['users_id' => $userId]);

      // Um vínculo só: este perfil, nesta entidade, sem recursividade. Qualquer outro sai.
      $DB->delete(Profile_User::getTable(), [
         'users_id' => $userId,
         'NOT'      => ['profiles_id' => $profileId, 'entities_id' => $entityId, 'is_recursive' => 0],
      ]);
      if (countElementsInTable(Profile_User::getTable(), ['users_id' => $userId, 'profiles_id' => $profileId, 'entities_id' => $entityId]) === 0) {
         $DB->insert(Profile_User::getTable(), [
            'users_id'     => $userId,
            'profiles_id'  => $profileId,
            'entities_id'  => $entityId,
            'is_recursive' => 0,
            'is_dynamic'   => 0,
         ]);
      }

      return ['users_id' => $userId, 'profiles_id' => $profileId];
   }

   /**
    * O perfil é o de uma conta de serviço de contato externo? Para quem copia ou concede direitos em lote (ex.:
    * a instalação do livechat) pular esses perfis sem depender do texto do nome.
    */
   public static function isServiceProfile(int $profilesId): bool {
      if ($profilesId <= 0) {
         return false;
      }
      $p = new Profile();

      return $p->getFromDB($profilesId) && strpos((string) $p->fields['name'], self::PROFILE_PREFIX) === 0;
   }

   /** A conta do módulo, se existe (sem criar). */
   public static function find(string $moduleKey): int {
      global $DB;
      $row = $DB->request(['SELECT' => ['id'], 'FROM' => User::getTable(), 'WHERE' => ['name' => self::LOGIN_PREFIX . $moduleKey], 'LIMIT' => 1])->current();

      return $row ? (int) $row['id'] : 0;
   }

   /**
    * Perfil próprio do módulo, na interface simplificada, com EXATAMENTE os direitos do cabeçalho: os de
    * core que não são estes ficam zerados, e o direito do módulo recebe só `$moduleBits`.
    */
   private static function ensureProfile(string $moduleKey, string $label, int $moduleBits): int {
      global $DB;
      $name = self::PROFILE_PREFIX . $moduleKey;
      $row  = $DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['name' => $name], 'LIMIT' => 1])->current();
      $id   = $row ? (int) $row['id'] : 0;
      if ($id <= 0) {
         $profile = new Profile();
         $id = (int) $profile->add([
            'name'      => $name,
            'interface' => 'helpdesk',
            'is_default' => 0,
            'comment'   => sprintf(__('Perfil da conta de serviço dos contatos externos do canal %s (NexTool). Não atribuir a pessoas.', 'nextool'), $label),
         ]);
         if ($id <= 0) {
            return 0;
         }
      }
      $DB->update(Profile::getTable(), ['interface' => 'helpdesk', 'is_default' => 0, 'create_ticket_on_login' => 0], ['id' => $id]);

      // Zera o que o Profile::add() herdou de padrão e grava só o esperado.
      $DB->update('glpi_profilerights', ['rights' => 0], ['profiles_id' => $id]);
      $esperado = [
         'ticket'                              => self::TICKET_RIGHTS,
         'followup'                            => self::FOLLOWUP_RIGHTS,
         'plugin_nextool_module_' . $moduleKey => $moduleBits,
      ];
      foreach ($esperado as $direito => $valor) {
         if (countElementsInTable('glpi_profilerights', ['profiles_id' => $id, 'name' => $direito]) > 0) {
            $DB->update('glpi_profilerights', ['rights' => $valor], ['profiles_id' => $id, 'name' => $direito]);
         } else {
            $DB->insert('glpi_profilerights', ['profiles_id' => $id, 'name' => $direito, 'rights' => $valor]);
         }
      }

      return $id;
   }
}
