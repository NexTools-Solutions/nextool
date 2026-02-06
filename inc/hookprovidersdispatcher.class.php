<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Hook Providers Dispatcher
 * -------------------------------------------------------------------------
 * Dispatcher genérico para hooks globais do GLPI que precisam existir no
 * plugin base (`plugin_nextool_*`), mas cuja lógica é específica de módulos.
 *
 * Estratégia:
 * - Cada módulo ativo pode expor "providers" via BaseModule::getHookProviders().
 * - O dispatcher instancia esses providers (quando a classe existe) e delega.
 *
 * Benefícios:
 * - Evita acoplamento de `setup.php` / `hook.php` com módulos específicos.
 * - Cria um padrão único para futuros módulos com Search nativo e MassiveActions.
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
               // Não abortar hooks globais por falha em provider
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
            // Ignora erro de provider para não quebrar init do plugin
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
            continue;
         }
      }
      return false;
   }
}

