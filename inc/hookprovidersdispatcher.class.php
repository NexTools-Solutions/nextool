<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Hook Providers Dispatcher
 * -------------------------------------------------------------------------
 * Dispatcher para hooks globais do GLPI (plugin_nextool_*) cuja lógica é
 * dos módulos. Módulos ativos expõem providers via BaseModule::getHookProviders();
 * o dispatcher instancia e delega. Evita acoplamento com módulos específicos.
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

require_once GLPI_ROOT . '/plugins/nextool/inc/hookproviderinterface.class.php';

class PluginNextoolHookProvidersDispatcher {

   /** @var PluginNextoolHookProviderInterface[]|null */
   private static ?array $providers = null;

   /**
    * Logger defensivo: evita derrubar o plugin se a API de log do GLPI mudar.
    * GLPI 11 não expõe Toolbox::logWarning() em algumas versões/builds.
    */
   private static function logWarning(string $message): void {
      if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
         // Vai para /var/log/glpi/plugin_nextool.log
         Toolbox::logInFile('plugin_nextool', $message . "\n");
         return;
      }
      error_log($message);
   }

   /**
    * @return PluginNextoolHookProviderInterface[]
    */
   private static function getProviders(): array {
      if (self::$providers !== null) {
         return self::$providers;
      }

      self::$providers = [];

      if (!class_exists('PluginNextoolModuleManager')) {
         $mm = GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
         $bm = GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';
         if (file_exists($bm)) {
            require_once $bm;
         }
         if (file_exists($mm)) {
            require_once $mm;
         }
      }

      if (!class_exists('PluginNextoolModuleManager')) {
         return self::$providers;
      }

      $manager = PluginNextoolModuleManager::getInstance();
      $modules = $manager->getActiveModules();

      foreach ($modules as $module) {
         if (!is_object($module) || !method_exists($module, 'getHookProviders')) {
            continue;
         }
         $providerClasses = (array) $module->getHookProviders();
         foreach ($providerClasses as $className) {
            $className = is_string($className) ? trim($className) : '';
            if ($className === '' || !class_exists($className)) {
               continue;
            }
            try {
               $provider = new $className();
            } catch (Throwable $e) {
               self::logWarning('[NEXTOOL] Hook provider failed: ' . $className . ' - ' . $e->getMessage());
               continue;
            }
            if ($provider instanceof PluginNextoolHookProviderInterface) {
               self::$providers[] = $provider;
            }
         }
      }

      return self::$providers;
   }

   /**
    * Permite que providers registrem classes necessárias para Search.
    *
    * @return void
    */
   public static function registerClasses(): void {
      foreach (self::getProviders() as $provider) {
         try {
            $provider->registerClasses();
         } catch (Throwable $e) {
            self::logWarning('[NEXTOOL] Hook provider registerClasses failed: ' . get_class($provider) . ' - ' . $e->getMessage());
            continue;
         }
      }
   }

   /**
    * @param string $type
    * @return array<string,string>
    */
   public static function getMassiveActions(string $type): array {
      $actions = [];
      foreach (self::getProviders() as $provider) {
         try {
            $actions = array_merge($actions, $provider->getMassiveActions($type));
         } catch (Throwable $e) {
            self::logWarning('[NEXTOOL] Hook provider getMassiveActions failed: ' . get_class($provider) . ' - ' . $e->getMessage());
            continue;
         }
      }
      return $actions;
   }

   public static function massiveActionsFieldsDisplay(array $options = []): bool {
      foreach (self::getProviders() as $provider) {
         try {
            if ($provider->massiveActionsFieldsDisplay($options)) {
               return true;
            }
         } catch (Throwable $e) {
            self::logWarning('[NEXTOOL] Hook provider massiveActionsFieldsDisplay failed: ' . get_class($provider) . ' - ' . $e->getMessage());
            continue;
         }
      }
      return false;
   }

   public static function giveItem($itemtype, $ID, $data, $num) {
      foreach (self::getProviders() as $provider) {
         try {
            $result = $provider->giveItem($itemtype, $ID, $data, $num);
            if ($result !== false) {
               return $result;
            }
         } catch (Throwable $e) {
            self::logWarning('[NEXTOOL] Hook provider giveItem failed: ' . get_class($provider) . ' - ' . $e->getMessage());
            continue;
         }
      }
      return false;
   }
}

