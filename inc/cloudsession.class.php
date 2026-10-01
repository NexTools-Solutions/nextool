<?php
/**
 * Cloud link v1 -- sessão efêmera do ator (runAs).
 *
 * Promoção do `modules/telegrambot/inc/session.class.php` para a base: a mesma mecânica passa a
 * servir qualquer módulo que exponha ferramentas ao cérebro. Regras que NÃO mudam na promoção:
 *
 *  - o ator vem SEMPRE da tabela local de vínculo do módulo, nunca do corpo da requisição;
 *  - o executor age sob o perfil PADRÃO do usuário -- nunca varre perfis atrás de um que libere;
 *  - a conta precisa estar VÁLIDA como no login (datas de validade e senha interna), não só ativa;
 *  - `glpicronuserrunning` é removido: com ele, `Session::haveRight()` devolve true
 *    incondicionalmente e todo bit de módulo seria ignorado;
 *  - snapshot do `$_SESSION` INTEIRO (changeProfile dispara hooks de outros plugins).
 *
 * @since 6.23.0
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudSessionException extends RuntimeException {
}

class PluginNextoolCloudSession {

   /** @var int|null usuário da sessão em curso (guarda de reentrância) */
   private static $running = null;

   /**
    * Executa $callback como $users_id e restaura a sessão anterior, aconteça o que acontecer.
    *
    * @param int      $users_id resolvido LOCALMENTE pelo módulo
    * @param callable $callback
    * @return mixed retorno do callback
    * @throws PluginNextoolCloudSessionException
    */
   public static function runAs(int $users_id, callable $callback) {
      if ($users_id <= 0) {
         throw new PluginNextoolCloudSessionException('actor_unresolved');
      }

      if (self::$running !== null) {
         if (self::$running === $users_id) {
            return $callback();
         }
         throw new PluginNextoolCloudSessionException('session_nested');
      }

      $user = new User();
      if (!$user->getFromDB($users_id) || !self::accountUsable($user)) {
         throw new PluginNextoolCloudSessionException('actor_inactive');
      }

      $backup        = $_SESSION ?? [];
      self::$running = $users_id;

      try {
         $_SESSION = [];
         $_SESSION['glpiID']             = $users_id;
         $_SESSION['glpiname']           = $user->fields['name'];
         $_SESSION['glpifriendlyname']   = $user->getFriendlyName();
         $_SESSION['glpirealname']       = $user->fields['realname'] ?? '';
         $_SESSION['glpifirstname']      = $user->fields['firstname'] ?? '';
         $_SESSION['glpidefault_entity'] = (int) $user->fields['entities_id'];
         $_SESSION['glpi_use_mode']      = Session::NORMAL_MODE;
         $_SESSION['glpilanguage']       = self::resolveLanguage($user);
         $_SESSION['glpi_currenttime']   = date('Y-m-d H:i:s');
         $_SESSION['glpilist_limit']     = (int) ($user->fields['list_limit']
            ?? ($GLOBALS['CFG_GLPI']['list_limit'] ?? 20)) ?: 20;
         $_SESSION['glpiis_ids_visible'] = (int) ($GLOBALS['CFG_GLPI']['is_ids_visible'] ?? 0);

         // Preferências do usuário, como no login (Session::init): sem elas o core lê chaves ausentes
         // (`glpinumber_format`, `glpitimeline_order`...) e registra um aviso a cada chamado aberto pela
         // nuvem, e o formato de número e a ordem da linha do tempo saem diferentes dos do usuário (6.27.0).
         // O idioma é reaplicado depois com a regra de sempre (o do usuário, ou o padrão da instância).
         if (method_exists($user, 'computePreferences')) {
            $user->computePreferences();
            foreach ((array) ($GLOBALS['CFG_GLPI']['user_pref_field'] ?? []) as $campo) {
               if (isset($user->fields[$campo])) {
                  $_SESSION['glpi' . $campo] = $user->fields[$campo];
               }
            }
            $_SESSION['glpilanguage'] = self::resolveLanguage($user);
         }

         // Ver cabeçalho: sem este unset, um exec disparado por CLI/cron ignoraria os bits.
         unset($_SESSION['glpicronuserrunning'], $_SESSION['glpiinventoryuserrunning']);

         Session::initEntityProfiles($users_id);
         if (empty($_SESSION['glpiprofiles'])) {
            throw new PluginNextoolCloudSessionException('actor_no_profile');
         }

         $profiles_id = (int) $user->fields['profiles_id'];
         if (!isset($_SESSION['glpiprofiles'][$profiles_id])) {
            $profiles_id = (int) array_key_first($_SESSION['glpiprofiles']);
         }
         Session::changeProfile($profiles_id);

         if (empty($_SESSION['glpiactiveprofile']['id'])) {
            throw new PluginNextoolCloudSessionException('actor_no_entity');
         }

         Session::loadGroups();

         return $callback();
      } finally {
         $_SESSION      = $backup;
         self::$running = null;
      }
   }

   /**
    * A conta pode agir agora? A MESMA régua do login do GLPI (`Session::init` + `Auth::connection_db`).
    *
    * Até a 6.24.1 só `is_active`/`is_deleted` eram conferidos: quem foi desligado pelo "Válido até"
    * (ou está com a senha interna expirada) perdia a interface web e continuava agindo pelo bot.
    *
    *  - `begin_date`/`end_date`: janela de validade da conta (vazio = sem limite);
    *  - senha expirada: só para conta de autenticação INTERNA. O login confere dentro do
    *    `connection_db`; para LDAP/SSO o `hasPasswordExpired()` do core daria falso positivo, porque
    *    olha só o prazo configurado e a data da conta.
    */
   public static function accountUsable(User $user): bool {
      if (!empty($user->fields['is_deleted']) || empty($user->fields['is_active'])) {
         return false;
      }
      $agora  = date('Y-m-d H:i:s');
      $inicio = (string) ($user->fields['begin_date'] ?? '');
      if ($inicio !== '' && $inicio >= $agora) {
         return false; // login: begin_date < agora, ou vazio
      }
      $fim = (string) ($user->fields['end_date'] ?? '');
      if ($fim !== '' && $fim <= $agora) {
         return false; // login: end_date > agora, ou vazio
      }

      return !((int) ($user->fields['authtype'] ?? 0) === Auth::DB_GLPI
         && method_exists($user, 'hasPasswordExpired') && $user->hasPasswordExpired());
   }

   /**
    * Entidade ativa da sessão montada, ou -1 quando ausente.
    *
    * Sentinela -1, NUNCA 0: a entidade raiz tem id 0 e `empty()` a trataria como ausente --
    * foi o que já fez chamado de bot nascer na raiz (armadilha §7.1 do PERMISSIONS.md).
    */
   public static function activeEntity(): int {
      if (!array_key_exists('glpiactive_entity', $_SESSION)) {
         return -1;
      }
      return (int) $_SESSION['glpiactive_entity'];
   }

   /** Idioma do usuário, com queda para o padrão da instância. */
   private static function resolveLanguage(User $user): string {
      $lang = (string) ($user->fields['language'] ?? '');
      if ($lang !== '') {
         return $lang;
      }
      return (string) ($GLOBALS['CFG_GLPI']['language'] ?? 'pt_BR');
   }
}
