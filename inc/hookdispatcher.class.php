<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Hook Dispatcher
 * -------------------------------------------------------------------------
 * Dispatcher central para hooks de Ticket. Módulos registram em onInit();
 * setup registra os dispatch após loadActiveModules().
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
            // Módulo abortou intencionalmente (sinal de pre_item_add/update do GLPI
            // para impedir a criação/atualização). Rethrow sem log.
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
    * Dispatcher para item_add['nextool']['Ticket'].
    * GLPI chama com $item (CommonDBTM). Repassa a todos os handlers registrados.
    *
    * @param CommonDBTM $item
    * @return CommonDBTM
    */
   public static function dispatchItemAddTicket(CommonDBTM $item) {
      foreach (self::$itemAdd['Ticket'] ?? [] as $cb) {
         try {
            $ret = call_user_func($cb, $item);
            if ($ret instanceof CommonDBTM) {
               $item = $ret;
            }
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (sinal de pre_item_add/update do GLPI
            // para impedir a criação/atualização). Rethrow sem log.
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_add Ticket: %s - %s',
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   /**
    * Dispatcher para item_update['nextool']['Ticket'].
    */
   public static function dispatchItemUpdateTicket(CommonDBTM $item) {
      foreach (self::$itemUpdate['Ticket'] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (sinal de pre_item_add/update do GLPI
            // para impedir a criação/atualização). Rethrow sem log.
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_update Ticket: %s - %s',
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   /**
    * Dispatcher para item_add['nextool']['TicketValidation'].
    */
   public static function dispatchItemAddTicketValidation(CommonDBTM $item) {
      foreach (self::$itemAdd['TicketValidation'] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (sinal de pre_item_add/update do GLPI
            // para impedir a criação/atualização). Rethrow sem log.
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_add TicketValidation: %s - %s',
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   /**
    * Dispatcher para item_update['nextool']['TicketValidation'].
    */
   public static function dispatchItemUpdateTicketValidation(CommonDBTM $item) {
      foreach (self::$itemUpdate['TicketValidation'] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (sinal de pre_item_add/update do GLPI
            // para impedir a criação/atualização). Rethrow sem log.
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_update TicketValidation: %s - %s',
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   /**
    * Dispatcher para item_add['nextool']['TicketTask'].
    */
   public static function dispatchItemAddTicketTask(CommonDBTM $item) {
      foreach (self::$itemAdd['TicketTask'] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (sinal de pre_item_add/update do GLPI
            // para impedir a criação/atualização). Rethrow sem log.
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_add TicketTask: %s - %s',
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   /**
    * Dispatcher para item_update['nextool']['TicketTask'].
    * Port GLPI 11: 5983240.
    */
   public static function dispatchItemUpdateTicketTask(CommonDBTM $item) {
      foreach (self::$itemUpdate['TicketTask'] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (sinal de pre_item_add/update do GLPI
            // para impedir a criação/atualização). Rethrow sem log.
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_update TicketTask: %s - %s',
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   // ========================================
   // ITILFollowup
   // ========================================

   /**
    * Dispatcher para item_add['nextool']['ITILFollowup'].
    * Port GLPI 11: e248c1f.
    */
   public static function dispatchItemAddITILFollowup(CommonDBTM $item) {
      foreach (self::$itemAdd['ITILFollowup'] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (sinal de pre_item_add/update do GLPI
            // para impedir a criação/atualização). Rethrow sem log.
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_add ITILFollowup: %s - %s',
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   // ========================================
   // ITILSolution
   // ========================================

   /**
    * Dispatcher para item_add['nextool']['ITILSolution'].
    * Port GLPI 11: e248c1f.
    */
   public static function dispatchItemAddITILSolution(CommonDBTM $item) {
      foreach (self::$itemAdd['ITILSolution'] ?? [] as $cb) {
         try {
            call_user_func($cb, $item);
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (sinal de pre_item_add/update do GLPI
            // para impedir a criação/atualização). Rethrow sem log.
            throw $e;
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf(
               '[HookDispatcher] item_add ITILSolution: %s - %s',
               $e->getMessage(),
               $e->getTraceAsString()
            ));
         }
      }
      return $item;
   }

   // ========================================
   // POST SHOW ITEM (timeline separator, etc.)
   // ========================================

   /**
    * Registra callback para post_show_item.
    * Port GLPI 11: 7c9a403.
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
   public static function dispatchPostShowItem(string $itemType, array $params): void {
      foreach (self::$postShowItem[$itemType] ?? [] as $cb) {
         try {
            call_user_func($cb, $params);
         } catch (PluginNextoolValidationException $e) {
            // Módulo abortou intencionalmente (sinal de pre_item_add/update do GLPI
            // para impedir a criação/atualização). Rethrow sem log.
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
}
