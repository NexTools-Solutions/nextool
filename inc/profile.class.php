<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Profile
 * -------------------------------------------------------------------------
 * Adiciona aba na tela de Perfis do GLPI para configurar direitos do Nextool
 * por perfil (READ/UPDATE/DELETE/PURGE).
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

require_once __DIR__ . '/permissionmanager.class.php';

class PluginNextoolProfile extends Profile {

   public static $rightname = 'profile';

   public static function getTable($classname = null) {
      return 'glpi_profiles';
   }

   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item instanceof Profile && $item->getID()) {
         return self::createTabEntry(__('NexTool', 'nextool'));
      }
      return '';
   }

   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item instanceof Profile) {
         $profile = new self();
         $profile->showFormNextool((int) $item->getID());
      }
      return true;
   }

   public function getRights($interface = 'central') {
      $values = [
         CREATE => __('Criar', 'nextool'),
         READ   => __('Ler', 'nextool'),
         UPDATE => __('Atualizar', 'nextool'),
         DELETE => __('Apagar', 'nextool'),
      ];
      return $values;
   }

   private function showFormNextool(int $profiles_id): void {
      if (!$this->can($profiles_id, READ)) {
         return;
      }

      $canEdit = Session::haveRight(self::$rightname, UPDATE);
      PluginNextoolPermissionManager::syncModuleRights();

      // Detecta se o perfil sendo editado é de interface simplificada (helpdesk)
      $profile = new Profile();
      $profile->getFromDB($profiles_id);
      $isHelpdesk = ($profile->fields['interface'] ?? 'central') === 'helpdesk';

      echo "<div class='spaced'>";
      if ($canEdit) {
         echo "<form method='post' action='" . static::getFormURL() . "'>";
      }

      $matrixOptions = [
         'title'   => __('Permissões NexTool', 'nextool'),
         'canedit' => $canEdit,
      ];

      $rights = [];

      // Para perfis central: exibe tudo (módulos + abas admin + todos os módulos)
      // Para perfis helpdesk: exibe apenas módulos ativos (sem abas admin, sem módulos inativos)
      if (!$isHelpdesk) {
         $rights[] = [
            'itemtype' => self::class,
            'label'    => __('Módulos do NexTool', 'nextool'),
            'field'    => PluginNextoolPermissionManager::RIGHT_MODULES,
         ];
         $rights[] = [
            'itemtype' => self::class,
            'label'    => __('Abas administrativas (Licença, Contato, Logs)', 'nextool'),
            'field'    => PluginNextoolPermissionManager::RIGHT_ADMIN_TABS,
         ];
      }

      // Obter lista de módulos instalados (e ativos, para helpdesk)
      $installedModuleKeys = [];
      $activeModuleKeys = [];
      if (class_exists('PluginNextoolModuleManager')) {
         try {
            $manager = PluginNextoolModuleManager::getInstance();
            foreach ($manager->getAllModules() as $mk => $mod) {
               if ($mod->isInstalled()) {
                  $installedModuleKeys[] = $mk;
                  if ($mod->isEnabled()) {
                     $activeModuleKeys[] = $mk;
                  }
               }
            }
         } catch (Throwable $e) {
            // Fallback: sem filtro
         }
      }

      $moduleRights = PluginNextoolPermissionManager::getModuleRightsMetadata();
      foreach ($moduleRights as $moduleRight) {
         // Exibir apenas módulos instalados
         if (!empty($installedModuleKeys) && !in_array($moduleRight['key'], $installedModuleKeys, true)) {
            continue;
         }
         // Para helpdesk: exibir apenas módulos ativos (instalados + habilitados)
         if ($isHelpdesk && !in_array($moduleRight['key'], $activeModuleKeys, true)) {
            continue;
         }
         $rights[] = [
            'itemtype' => self::class,
            'label'    => sprintf(__('Módulo: %s', 'nextool'), $moduleRight['label']),
            'field'    => $moduleRight['right'],
         ];
      }

      echo "<div id='nextool-rights-matrix'>";
      $this->displayRightsChoiceMatrix($rights, $matrixOptions);
      echo "</div>";

      // Para perfis helpdesk: linhas administrativas já são ocultadas no PHP (acima).
      // Todas as colunas CRUD ficam disponíveis para os módulos exibidos.

      if ($canEdit) {
         echo Html::hidden('id', ['value' => $profiles_id]);
         echo "<div class='text-center'>";
         echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
         echo '</div>';
         Html::closeForm();
      }
      echo '</div>';
   }
}

