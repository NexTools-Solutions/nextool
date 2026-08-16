<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Hook Dispatcher
 * -------------------------------------------------------------------------
 * Dispatcher central para hooks de Ticket (e outros item types). Módulos
 * registram via registerPreItemAdd/registerItemAdd em onInit(); o setup
 * registra os dispatch* nos hooks após loadActiveModules().
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

require_once __DIR__ . '/validationexception.class.php';

class PluginNextoolHookDispatcher {

   /** @var array[] preItemAdd[itemType] = [ [class, method], ... ] */
   private static $preItemAdd = [];

   /** @var array[] itemAdd[itemType] = [ [class, method], ... ] */
   private static $itemAdd = [];

   /** @var array[] itemUpdate[itemType] = [ [class, method], ... ] */
   private static $itemUpdate = [];

   /** @var array[] preItemUpdate[itemType] = [ [class, method], ... ] */
   private static $preItemUpdate = [];

   /** @var array[] itemPurge[itemType] = [ [class, method], ... ] */
   private static $itemPurge = [];

   /** @var array[] postShowItem[itemType] = [ [class, method], ... ] */
   private static $postShowItem = [];

   /**
    * Registra callback para pre_item_add.
    *
    * @param string $itemType Ex.: 'Ticket'
    * @param array  $callback [className, methodName]
    */
   public static function registerPreItemAdd($itemType, array $callback) {
      if (!isset(self::$preItemAdd[$itemType])) {
         self::$preItemAdd[$itemType] = [];
      }
      self::$preItemAdd[$itemType][] = $callback;
   }

   /**
    * Registra callback para item_add.
    *
    * @param string $itemType Ex.: 'Ticket'
    * @param array  $callback [className, methodName]
    */
   public static function registerItemAdd($itemType, array $callback) {
      if (!isset(self::$itemAdd[$itemType])) {
         self::$itemAdd[$itemType] = [];
      }
      self::$itemAdd[$itemType][] = $callback;
   }

   /**
    * Registra callback para item_update.
    */
   public static function registerItemUpdate($itemType, array $callback) {
      if (!isset(self::$itemUpdate[$itemType])) {
         self::$itemUpdate[$itemType] = [];
      }
      self::$itemUpdate[$itemType][] = $callback;
   }

   /**
    * Registra callback para pre_item_update (BLOQUEAR atualização antes da gravação).
    * O callback recebe o CommonDBTM $item (com $item->input contendo os novos valores)
    * e, para bloquear, lança PluginNextoolValidationException($mensagem). O dispatcher
    * traduz isso no abort nativo do GLPI (input=false) + mensagem ao usuário.
    *
    * @param string $itemType Ex.: 'Ticket'
    * @param array  $callback [className, methodName]
    */
   public static function registerPreItemUpdate($itemType, array $callback) {
      if (!isset(self::$preItemUpdate[$itemType])) {
         self::$preItemUpdate[$itemType] = [];
      }
      self::$preItemUpdate[$itemType][] = $callback;
   }

   /**
    * Registra callback para item_purge (APÓS a exclusão definitiva do item).
    * O callback recebe o CommonDBTM $item já purgado (fields ainda populados).
    * Uso: módulos que mantêm dados vinculados a sub-itens nativos (ex.:
    * contracthours limpa timer/task_map quando uma TicketTask é excluída).
    *
    * @param string $itemType Ex.: 'TicketTask'
    * @param array  $callback [className, methodName]
    */
   public static function registerItemPurge($itemType, array $callback) {
      if (!isset(self::$itemPurge[$itemType])) {
         self::$itemPurge[$itemType] = [];
      }
      self::$itemPurge[$itemType][] = $callback;
   }

   /**
    * Dispatcher para pre_item_add['nextool']['Ticket'].
    * GLPI chama com $input (array ou objeto, conforme versão). Repassa a todos os handlers.
    *
    * @param mixed $input
    * @return mixed
    */
   public static function dispatchPreItemAddTicket($input) {
      $out = $input;
      foreach (self::$preItemAdd['Ticket'] ?? [] as $cb) {
         try {
            $ret = call_user_func($cb, $out);
            if ($ret !== null) {
               $out = $ret;
            }
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (pre_item_add do GLPI usa este sinal
            // para impedir a criação/atualização do item). Rethrow sem log.
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] pre_item_add Ticket: %s - %s',
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $out;
   }

   /**
    * Dispatcher genérico para item_add[itemType]. Itera handlers registrados,
    * captura exceptions individuais (exceto PluginNextoolValidationException,
    * que é rethrown para abortar a operação no GLPI core).
    *
    * Os métodos dispatchItemAdd<Type>() abaixo são wrappers desta função.
    */
   public static function dispatchItemAdd(string $itemType, CommonDBTM $item): CommonDBTM {
      foreach (self::$itemAdd[$itemType] ?? [] as $cb) {
         try {
            $ret = call_user_func($cb, $item);
            if ($ret instanceof CommonDBTM) {
               $item = $ret;
            }
         } catch (PluginNextoolValidationException $e) {
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_add %s: %s - %s',
               $itemType,
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   /**
    * Dispatcher genérico para item_update[itemType]. Mesma semântica de
    * dispatchItemAdd. Os métodos dispatchItemUpdate<Type>() abaixo são wrappers.
    */
   public static function dispatchItemUpdate(string $itemType, CommonDBTM $item): CommonDBTM {
      foreach (self::$itemUpdate[$itemType] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (PluginNextoolValidationException $e) {
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_update %s: %s - %s',
               $itemType,
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   /**
    * Dispatcher genérico para pre_item_update[itemType]. O GLPI 11 chama o hook
    * PRE_ITEM_UPDATE com o objeto ($this) ANTES de gravar; logo depois, o core faz
    * `if ($this->input && is_array($this->input))` (CommonDBTM::update) e PULA a
    * gravação quando input=false. Por isso, para BLOQUEAR de forma limpa (sem 500 e
    * sem depender de propagação de exceção), este dispatcher captura a
    * PluginNextoolValidationException do validador, enfileira a mensagem e seta
    * `$item->input = false`. Erros técnicos comuns são logados e ignorados (fail-open:
    * nunca travar o chamado por um bug de validador).
    */
   public static function dispatchPreItemUpdate(string $itemType, CommonDBTM $item): CommonDBTM {
      foreach (self::$preItemUpdate[$itemType] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (PluginNextoolValidationException $e) {
            $msg = trim($e->getMessage());
            if ($msg !== '') {
               Session::addMessageAfterRedirect($msg, false, ERROR);
            }
            // Abort: array VAZIO (não `false`). O core faz `if ($this->input && is_array(...))`
            // e `[]` é falsy → pula a gravação; mas continua sendo array, então outros plugins
            // que rodam depois neste mesmo hook (ex.: Fields, que faz array_key_exists no input)
            // não quebram com TypeError. `false` abortaria igual mas estouraria os vizinhos.
            $item->input = [];
            return $item;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] pre_item_update %s: %s - %s',
               $itemType,
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   /**
    * Dispatcher genérico para item_purge[itemType]. Dispara APÓS a exclusão
    * definitiva -- não há o que abortar, então toda exceção é apenas logada
    * (fail-open: um bug de handler nunca pode impedir a exclusão nativa).
    */
   public static function dispatchItemPurge(string $itemType, CommonDBTM $item): CommonDBTM {
      foreach (self::$itemPurge[$itemType] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_purge %s: %s - %s',
               $itemType,
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   // ========================================
   // Wrappers nomeados (compat com callers $PLUGIN_HOOKS em setup.php)
   // ========================================

   public static function dispatchItemAddTicket(CommonDBTM $item) {
      return self::dispatchItemAdd('Ticket', $item);
   }

   public static function dispatchItemUpdateTicket(CommonDBTM $item) {
      return self::dispatchItemUpdate('Ticket', $item);
   }

   public static function dispatchPreItemUpdateTicket(CommonDBTM $item) {
      return self::dispatchPreItemUpdate('Ticket', $item);
   }

   /**
    * Dispatcher genérico para pre_item_add[itemType] com BLOQUEIO (mesmo mecanismo do
    * pre_item_update: o core faz `if ($this->input && is_array(...))` após o PRE_ITEM_ADD,
    * então abortamos com input=[] -- array-safe, não quebra vizinhos). Usado para sub-itens
    * (TicketTask/ITILFollowup/ITILSolution). NÃO substitui o dispatchPreItemAddTicket legado
    * (que faz rethrow, compat com pendingsurvey) -- registries compartilhados, sem conflito.
    */
   public static function dispatchPreItemAddBlocking(string $itemType, CommonDBTM $item): CommonDBTM {
      foreach (self::$preItemAdd[$itemType] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (PluginNextoolValidationException $e) {
            $msg = trim($e->getMessage());
            if ($msg !== '') {
               Session::addMessageAfterRedirect($msg, false, ERROR);
            }
            $item->input = [];
            return $item;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] pre_item_add %s: %s', $itemType, $e->getMessage()
            ));
         }
      }
      return $item;
   }

   // Sub-itens de chamado (add + update) -- usados pelo módulo ticketrules.
   public static function dispatchPreItemAddTicketTask(CommonDBTM $item)      { return self::dispatchPreItemAddBlocking('TicketTask', $item); }
   public static function dispatchPreItemUpdateTicketTask(CommonDBTM $item)   { return self::dispatchPreItemUpdate('TicketTask', $item); }
   public static function dispatchPreItemAddITILFollowup(CommonDBTM $item)    { return self::dispatchPreItemAddBlocking('ITILFollowup', $item); }
   public static function dispatchPreItemUpdateITILFollowup(CommonDBTM $item) { return self::dispatchPreItemUpdate('ITILFollowup', $item); }
   public static function dispatchPreItemAddITILSolution(CommonDBTM $item)    { return self::dispatchPreItemAddBlocking('ITILSolution', $item); }

   public static function dispatchItemAddTicketValidation(CommonDBTM $item) {
      return self::dispatchItemAdd('TicketValidation', $item);
   }

   public static function dispatchItemUpdateTicketValidation(CommonDBTM $item) {
      return self::dispatchItemUpdate('TicketValidation', $item);
   }

   public static function dispatchItemPurgeTicketTask(CommonDBTM $item) {
      return self::dispatchItemPurge('TicketTask', $item);
   }

   public static function dispatchItemAddTicketTask(CommonDBTM $item) {
      return self::dispatchItemAdd('TicketTask', $item);
   }

   public static function dispatchItemUpdateTicketTask(CommonDBTM $item) {
      return self::dispatchItemUpdate('TicketTask', $item);
   }

   public static function dispatchItemAddITILFollowup(CommonDBTM $item) {
      return self::dispatchItemAdd('ITILFollowup', $item);
   }

   public static function dispatchItemAddITILSolution(CommonDBTM $item) {
      return self::dispatchItemAdd('ITILSolution', $item);
   }

   // ========================================
   // POST SHOW ITEM (timeline separator, etc.)
   // ========================================

   /**
    * Registra callback para post_show_item.
    *
    * @param string $itemType Ex.: 'Ticket', 'Change', 'Problem'
    * @param array  $callback [className, methodName]
    */
   public static function registerPostShowItem(string $itemType, array $callback): void {
      if (!isset(self::$postShowItem[$itemType])) {
         self::$postShowItem[$itemType] = [];
      }
      self::$postShowItem[$itemType][] = $callback;
   }

   /**
    * Dispatcher generico para post_show_item.
    * Chamado pelo hook post_show_item registrado no setup.php.
    *
    * @param string $itemType Ex.: 'Ticket'
    * @param array  $params   Parametros do hook GLPI
    */
   /**
    * Adaptador chamado diretamente pelo hook `post_show_item` do GLPI (LO-09).
    * Resolve $item a partir do payload e delega para dispatchPostShowItem.
    * Substitui a closure que vivia em setup.php - método estático é serializável,
    * mais fácil de testar e não retém escopo.
    */
   public static function dispatchPostShowItemHook(array $params): void {
      $item = $params['options']['item'] ?? $params['item'] ?? null;
      if ($item instanceof CommonGLPI) {
         self::dispatchPostShowItem($item::getType(), $params);
      }
   }

   public static function dispatchPostShowItem(string $itemType, array $params): void {
      foreach (self::$postShowItem[$itemType] ?? [] as $cb) {
         try {
            call_user_func($cb, $params);
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (pre_item_add do GLPI usa este sinal
            // para impedir a criação/atualização do item). Rethrow sem log.
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] post_show_item %s: %s',
               $itemType,
               $e->getMessage()
            ));
         }
      }
   }

   // ========================================
   // RULE ACTIONS (extensão do motor de regras nativo do GLPI)
   // ========================================
   //
   // O GLPI resolve o hook `getRuleActions` como a função global
   // `plugin_nextool_getRuleActions` (ver hook.php), invocada por
   // Rule::doHookAndMergeResults(Hooks::AUTO_GET_RULE_ACTIONS, ...). Cada módulo
   // que queira contribuir ações para um tipo de regra (ex.: 'RuleRight')
   // registra um provider aqui no onInit() e declara o itemtype em
   // $PLUGIN_HOOKS['use_rules']['nextool']. A persistência do valor atribuído é
   // nativa: RuleRight::executeActions() trata qualquer ação `assign` no `default`
   // do switch e o resultado é mesclado nos campos do usuário (User.php).

   /** @var array<string, callable[]> ruleActions[ruleItemtype] = [ provider, ... ] */
   private static $ruleActions = [];

   /**
    * Registra um provider de ações para um tipo de regra.
    *
    * @param string   $ruleItemtype Ex.: 'RuleRight'
    * @param callable $provider     fn(array $params): array - devolve [actionKey => definição]
    */
   public static function registerRuleActions(string $ruleItemtype, callable $provider): void {
      if (!isset(self::$ruleActions[$ruleItemtype])) {
         self::$ruleActions[$ruleItemtype] = [];
      }
      self::$ruleActions[$ruleItemtype][] = $provider;
   }

   /**
    * Despacha a coleta de ações para o tipo de regra do hook. Mescla os arrays
    * de todos os providers registrados para aquele itemtype.
    *
    * @param array $params Payload do hook: ['rule_itemtype' => string, 'values' => array]
    * @return array [actionKey => definição] - vazio se não houver provider
    */
   public static function dispatchRuleActions(array $params): array {
      $itemtype = $params['rule_itemtype'] ?? '';
      if ($itemtype === '' || empty(self::$ruleActions[$itemtype])) {
         return [];
      }

      $actions = [];
      foreach (self::$ruleActions[$itemtype] as $provider) {
         try {
            $ret = call_user_func($provider, $params);
            if (is_array($ret)) {
               $actions = array_merge($actions, $ret);
            }
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] getRuleActions %s: %s',
               $itemtype,
               $e->getMessage()
            ));
         }
      }
      return $actions;
   }

   // ========================================
   // POST ITEM FORM (modificar formulários nativos - ex.: dropdown de técnico)
   // ========================================

   /** @var array<string, array[]> postItemForm[itemType] = [ callback, ... ] */
   private static $postItemForm = [];

   /**
    * Registra callback para post_item_form (chamado após render do formulário
    * nativo de um itemtype - permite injetar JS/campos, ex.: filtrar dropdown).
    *
    * @param string $itemType Ex.: 'TicketTask'
    * @param array  $callback [className, methodName] - recebe array $params do GLPI
    */
   public static function registerPostItemForm(string $itemType, array $callback): void {
      if (!isset(self::$postItemForm[$itemType])) {
         self::$postItemForm[$itemType] = [];
      }
      self::$postItemForm[$itemType][] = $callback;
   }

   /**
    * Adaptador chamado diretamente pelo hook `post_item_form` do GLPI (registrado
    * em setup.php). Resolve o item do payload e delega aos callbacks do itemtype.
    */
   public static function dispatchPostItemFormHook(array $params): void {
      $item = $params['item'] ?? null;
      if (!($item instanceof CommonGLPI)) {
         return;
      }
      foreach (self::$postItemForm[$item::getType()] ?? [] as $cb) {
         try {
            call_user_func($cb, $params);
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] post_item_form %s: %s',
               $item::getType(),
               $e->getMessage()
            ));
         }
      }
   }

   // ========================================
   // SEARCH OPTIONS (colunas extras em itemtypes nativos)
   // ========================================
   //
   // O GLPI resolve search options de plugin como a função global
   // plugin_nextool_getAddSearchOptions($itemtype) (ver hook.php). Módulos que
   // queiram adicionar colunas de busca a itemtypes nativos registram um provider
   // aqui no onInit(). ATENÇÃO: IDs de search option devem ser únicos por
   // itemtype entre TODOS os módulos (usar faixas altas e documentar no módulo).

   /** @var array<string, callable[]> searchOptions[itemType] = [ provider, ... ] */
   private static $searchOptions = [];

   /**
    * Registra provider de search options para um itemtype nativo.
    *
    * @param string   $itemType Ex.: 'TicketTask'
    * @param callable $provider fn(): array - devolve [id => definição de search option]
    */
   public static function registerSearchOptions(string $itemType, callable $provider): void {
      if (!isset(self::$searchOptions[$itemType])) {
         self::$searchOptions[$itemType] = [];
      }
      self::$searchOptions[$itemType][] = $provider;
   }

   /**
    * Mescla as search options de todos os providers do itemtype.
    * Chamado por plugin_nextool_getAddSearchOptions() (hook.php).
    *
    * @return array vazio se não houver provider (fast-path para todo itemtype)
    */
   public static function dispatchGetAddSearchOptions(string $itemType): array {
      if (empty(self::$searchOptions[$itemType])) {
         return [];
      }
      $options = [];
      foreach (self::$searchOptions[$itemType] as $provider) {
         try {
            $ret = call_user_func($provider);
            if (is_array($ret)) {
               $options += $ret;
            }
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] getAddSearchOptions %s: %s',
               $itemType,
               $e->getMessage()
            ));
         }
      }
      return $options;
   }

   // NOTA: render de search options 'specific' (giveItem) NÃO fica aqui - já
   // existe ponto de extensão via PluginNextoolHookProviderInterface::giveItem()
   // (módulo declara getHookProviders(); ver hookprovidersdispatcher.class.php).

   // ==========================================================================
   // NOTIFICAÇÕES ENTRE MÓDULOS (publish/sink)
   // ==========================================================================
   // Um módulo PUBLICA um evento; outro módulo (hoje o smartnotify) o CONSOME.
   // O emissor não conhece o consumidor, não conhece o schema dele e não quebra
   // quando ele não está instalado.
   //
   // Por que PUSH e não PULL: o sino do smartnotify roda em polling por usuário
   // logado. Abrir esse caminho quente para N provedores de terceiros faria cada
   // ciclo pagar o custo de todos os módulos instalados. Aqui o emissor publica
   // uma vez, o consumidor persiste, e a leitura é uma query só.
   //
   // AUDIÊNCIA, NÃO DESTINATÁRIOS: o emissor declara uma audiência LÓGICA
   // (ex.: 'module_admins') e a resolução acontece na LEITURA, dentro da sessão
   // de quem abre o sino. Sem isso, quem emite em cron (sem sessão) não teria
   // como resolver destinatário, e cada evento viraria N linhas no banco - além
   // de criar uma ACL paralela ao modelo de permissões do plugin.

   /** Teto do buffer de publicações feitas antes de existir um sink. */
   private const NOTIFICATION_BUFFER_MAX = 200;

   /** @var array<string, array> sources[sourceKey] = meta declarada pelo módulo */
   private static $notificationSources = [];

   /** @var callable[] sinks registrados (na prática, um: o smartnotify) */
   private static $notificationSinks = [];

   /** @var array[] publicações feitas antes de o sink existir */
   private static $notificationBuffer = [];

   /**
    * Declara uma fonte de notificação (metadado puro, sem efeito colateral).
    * O consumidor usa isto para montar a tela de configuração e as preferências
    * por usuário - por isso a declaração acontece no onInit() do módulo, mesmo
    * que nenhum evento seja publicado naquele request.
    *
    * @param string $sourceKey formato '<modulo>.<evento>', ex.: 'glpisync.sync_failed'
    * @param array  $meta      label, description, icon, color, severity,
    *                          default_enabled, dedup_window (segundos)
    */
   public static function registerNotificationSource(string $sourceKey, array $meta): void {
      if (!preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $sourceKey)) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            '[HookDispatcher] notification source ignorada - chave inválida "%s" (esperado <modulo>.<evento>)',
            $sourceKey
         ));
         return;
      }
      $module = substr($sourceKey, 0, (int)strpos($sourceKey, '.'));

      // Bit que o usuário precisa ter NO MÓDULO EMISSOR para receber este aviso.
      // A permissão vive no direito do emissor (`plugin_nextool_module_<module>`),
      // NUNCA no módulo de notificações: centralizar lá significaria um bit por
      // módulo emissor num único direito, e `rights` é int(11) = 22 colunas no
      // total. Com 30+ módulos publicando, o orçamento estoura -- e o módulo de
      // notificações passaria a hospedar permissões que não são dele.
      $scope = (string)($meta['required_scope'] ?? 'use');
      if (!in_array($scope, ['use', 'admin'], true)) {
         $scope = 'use';
      }

      self::$notificationSources[$sourceKey] = [
         'key'             => $sourceKey,
         'module'          => $module,
         'label'           => (string)($meta['label'] ?? $sourceKey),
         'description'     => (string)($meta['description'] ?? ''),
         'icon'            => (string)($meta['icon'] ?? 'ti ti-bell'),
         'color'           => (string)($meta['color'] ?? 'secondary'),
         'severity'        => (string)($meta['severity'] ?? 'info'),
         'default_enabled' => !isset($meta['default_enabled']) || (bool)$meta['default_enabled'],
         'dedup_window'    => max(0, (int)($meta['dedup_window'] ?? 0)),
         'required_bit'    => max(0, (int)($meta['required_bit'] ?? 0)),
         'required_scope'  => $scope,
      ];
   }

   /**
    * Fontes declaradas por todos os módulos ativos. Consumido pelo sink para
    * montar configuração e preferências.
    */
   public static function getNotificationSources(): array {
      return self::$notificationSources;
   }

   /**
    * Registra o consumidor. Ao registrar, DRENA o buffer: um módulo que publicou
    * durante o próprio onInit() não pode perder o evento só porque carregou antes
    * do consumidor - a ordem de boot dos módulos é alfabética e não é contrato.
    *
    * @param callable $sink fn(array $notification): void
    */
   public static function registerNotificationSink(callable $sink): void {
      self::$notificationSinks[] = $sink;

      if (!empty(self::$notificationBuffer)) {
         $pending = self::$notificationBuffer;
         self::$notificationBuffer = [];
         foreach ($pending as $notification) {
            self::deliverNotification($notification);
         }
      }
   }

   /**
    * Publica um evento. Sem sink registrado, guarda no buffer (limitado) e o
    * request termina sem efeito nenhum - publicar NUNCA pode quebrar o emissor.
    *
    * @return int quantidade de sinks que receberam (0 = ninguém consumiu ainda)
    */
   public static function dispatchNotification(array $payload): int {
      $notification = self::normalizeNotification($payload);
      if ($notification === null) {
         return 0;
      }

      if (empty(self::$notificationSinks)) {
         if (count(self::$notificationBuffer) < self::NOTIFICATION_BUFFER_MAX) {
            self::$notificationBuffer[] = $notification;
         }
         return 0;
      }

      return self::deliverNotification($notification);
   }

   /**
    * Entrega a todos os sinks. Sink que estoura é registrado e ignorado: falha
    * do consumidor não pode derrubar a operação de quem publicou.
    */
   private static function deliverNotification(array $notification): int {
      $delivered = 0;
      foreach (self::$notificationSinks as $sink) {
         try {
            call_user_func($sink, $notification);
            $delivered++;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] notification sink falhou (%s): %s',
               $notification['source_key'],
               $e->getMessage()
            ));
         }
      }
      return $delivered;
   }

   /**
    * Valida e completa o payload. Devolve null quando o evento é inutilizável
    * (sem chave ou sem título) - descartar cedo evita lixo no feed do consumidor.
    */
   private static function normalizeNotification(array $payload): ?array {
      $sourceKey = trim((string)($payload['source_key'] ?? ''));
      $title     = trim((string)($payload['title'] ?? ''));
      if ($sourceKey === '' || $title === '') {
         return null;
      }

      $meta   = self::$notificationSources[$sourceKey] ?? [];
      $module = $meta['module'] ?? (strpos($sourceKey, '.') !== false
         ? substr($sourceKey, 0, (int)strpos($sourceKey, '.'))
         : $sourceKey);

      // Audiência LÓGICA - resolvida na leitura, na sessão do leitor.
      // module_admins (default): quem administra o módulo emissor.
      $audience = $payload['audience'] ?? [];
      if (!is_array($audience) || empty($audience['type'])) {
         $audience = ['type' => 'module_admins'];
      }
      if (empty($audience['module'])) {
         $audience['module'] = $module;
      }

      $severity = (string)($payload['severity'] ?? $meta['severity'] ?? 'info');
      if (!in_array($severity, ['info', 'success', 'warning', 'critical'], true)) {
         $severity = 'info';
      }

      // 'resolves' (2026-08-10): dedup_keys de avisos que ESTE evento encerra --
      // um "resolvido" verde expira os vermelhos correspondentes no consumidor.
      // Saneado a lista curta de strings; consumidor antigo simplesmente ignora.
      $resolves = [];
      foreach ((array)($payload['resolves'] ?? []) as $chave) {
         if (is_string($chave) && trim($chave) !== '') {
            $resolves[] = substr(trim($chave), 0, 191);
         }
         if (count($resolves) >= 10) {
            break;
         }
      }

      // 'on_duplicate' (2026-08-10): comportamento na janela de dedup.
      //   increment (default) - só conta a repetição (texto congela na 1ª ocorrência);
      //   refresh            - aviso VIVO: atualiza título/mensagem/url além de contar
      //                        (ex.: "fila com 500 pendentes" -> 300 -> 100).
      $onDuplicate = (string)($payload['on_duplicate'] ?? 'increment');
      if (!in_array($onDuplicate, ['increment', 'refresh'], true)) {
         $onDuplicate = 'increment';
      }

      // 'actions' (2026-08-10): até 3 ações rápidas {label, url} para o consumidor
      // renderizar como LINKS (nunca executar ação por GET - a página de destino é
      // quem confirma/valida CSRF). Contrato preparado; consumidor pode adotar depois.
      $actions = [];
      foreach ((array)($payload['actions'] ?? []) as $acao) {
         if (!is_array($acao)) {
            continue;
         }
         $label = trim((string)($acao['label'] ?? ''));
         $url   = trim((string)($acao['url'] ?? ''));
         if ($label === '' || $url === '') {
            continue;
         }
         $actions[] = ['label' => substr($label, 0, 60), 'url' => substr($url, 0, 255)];
         if (count($actions) >= 3) {
            break;
         }
      }

      return [
         'source_key'   => $sourceKey,
         'module'       => $module,
         'title'        => $title,
         'message'      => (string)($payload['message'] ?? ''),
         'url'          => (string)($payload['url'] ?? ''),
         'severity'     => $severity,
         'audience'     => $audience,
         'entities_id'  => isset($payload['entities_id']) ? (int)$payload['entities_id'] : null,
         'dedup_key'    => (string)($payload['dedup_key'] ?? $sourceKey),
         'dedup_window' => max(0, (int)($payload['dedup_window'] ?? $meta['dedup_window'] ?? 0)),
         'expires_at'   => $payload['expires_at'] ?? null,
         'resolves'     => $resolves,
         'on_duplicate' => $onDuplicate,
         'actions'      => $actions,
         'data'         => is_array($payload['data'] ?? null) ? $payload['data'] : [],
         'date'         => (string)($payload['date'] ?? $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
      ];
   }

   /**
    * Ocupa um slot $PLUGIN_HOOKS[<hook>]['nextool'][<itemType>] com um dispatcher
    * desta classe de forma APPEND-AWARE (retrocompat com módulos antigos).
    *
    * Chamado pelo setup.php DEPOIS do loadActiveModules. Se um módulo em versão
    * antiga já registrou callback direto no slot durante o onInit() (padrão
    * pré-registry, atribuição plana ou append-safe), a atribuição plana do setup
    * o sobrescreveria -- regressão real de 2026-07-23 (subtaskflow E2E 8 FAIL).
    * Este helper encadeia: dispatcher primeiro (módulos novos, via registry),
    * depois o callback pré-existente (módulos antigos). Ambos convivem durante
    * a janela de transição base-nova + módulo-velho no parque instalado.
    *
    * @param array   $PLUGIN_HOOKS   por referência (global do GLPI)
    * @param string  $hookName       ex.: 'pre_item_update'
    * @param ?string $itemType       ex.: 'TicketTask'; null para hooks globais (sem sub-chave)
    * @param string  $dispatchMethod método estático desta classe, ex.: 'dispatchPreItemUpdateTicketTask'
    */
   public static function installHook(array &$PLUGIN_HOOKS, string $hookName, ?string $itemType, string $dispatchMethod): void {
      $dispatcher = [self::class, $dispatchMethod];
      if ($itemType === null) {
         $existing = $PLUGIN_HOOKS[$hookName]['nextool'] ?? null;
      } else {
         $existing = $PLUGIN_HOOKS[$hookName]['nextool'][$itemType] ?? null;
      }

      $value = $dispatcher;
      if ($existing !== null && $existing !== $dispatcher
          && !(is_array($existing) && ($existing[0] ?? null) === self::class)
          && !(is_array($existing) && ($existing[0] ?? null) === 'PluginNextoolHookDispatcher')) {
         // Slot ocupado por callback de módulo antigo: encadeia (dispatcher + legado).
         $value = static function ($param) use ($dispatcher, $existing) {
            $mid = call_user_func($dispatcher, $param);
            // Hooks de OBJETO (maioria): mutação por referência, retornos irrelevantes.
            // Hooks de VALOR (ex.: pre_item_add com $input array): o valor processado
            // pelo dispatcher segue para o legado, e o retorno do legado prevalece.
            $next = is_object($param) ? $param : ($mid ?? $param);
            $out  = call_user_func($existing, $next);
            return $out ?? $mid ?? $param;
         };
      }

      if ($itemType === null) {
         $PLUGIN_HOOKS[$hookName]['nextool'] = $value;
      } else {
         $PLUGIN_HOOKS[$hookName]['nextool'][$itemType] = $value;
      }
   }

   /**
    * Registra um callback ARBITRÁRIO ([classe, método]) num slot por-itemtype
    * `$PLUGIN_HOOKS[$hook]['nextool'][$itemType]` de forma append-safe: slot
    * vazio -> set; igual -> idempotente; ocupado por outro -> encadeia os dois
    * em sequência. Fonte ÚNICA do helper que estava copiado em
    * `workflow.class.php` e `ticketrules.class.php` (ME-11).
    *
    * Difere de installHook(): aqui o callback é do MÓDULO (não um dispatchMethod
    * desta classe), e a semântica é de hook de OBJETO (`Plugin::doHook($name,
    * $item)`, branch is_object) -- o retorno do callback NÃO é usado pelo core
    * (mutação por referência ao objeto), então encadear é só "chamar os dois".
    *
    * @param array  $PLUGIN_HOOKS por referência (global do GLPI)
    * @param string $hookName     ex.: 'pre_item_update'
    * @param string $itemType     ex.: 'Ticket', 'TicketTask'
    * @param array  $callback     [class, method]
    */
   public static function appendItemHook(array &$PLUGIN_HOOKS, string $hookName, string $itemType, array $callback): void {
      $existing = $PLUGIN_HOOKS[$hookName]['nextool'][$itemType] ?? null;

      if ($existing === null) {
         $PLUGIN_HOOKS[$hookName]['nextool'][$itemType] = $callback;
         return;
      }
      if ($existing === $callback) {
         return; // já registrado (idempotência)
      }
      // Slot ocupado por handler de outro módulo -- encadeia sem substituir.
      $PLUGIN_HOOKS[$hookName]['nextool'][$itemType] = static function ($data) use ($existing, $callback) {
         call_user_func($existing, $data);
         call_user_func($callback, $data);
      };
   }
}
