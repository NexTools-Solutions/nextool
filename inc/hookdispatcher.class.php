<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Hook Dispatcher
 * -------------------------------------------------------------------------
 * Dispatcher central para hooks de Ticket (e outros item types). Módulos
 * registram via registerPreItemAdd/registerItemAdd em onInit(); o setup
 * registra os dispatch* nos hooks após loadActiveModules().
 * -------------------------------------------------------------------------
 * @author    Richard Loureiro
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolHookDispatcher {

   /** @var array[] preItemAdd[itemType] = [ [class, method], ... ] */
   private static $preItemAdd = [];

   /** @var array[] itemAdd[itemType] = [ [class, method], ... ] */
   private static $itemAdd = [];

   /** @var array[] itemUpdate[itemType] = [ [class, method], ... ] */
   private static $itemUpdate = [];

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
}
