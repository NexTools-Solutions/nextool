<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Profile
 * -------------------------------------------------------------------------
 * Aba "NexTool" na tela de Perfis do GLPI. Renderiza, no modelo aprovado:
 *   1) Super-direito "Administracao global" (nextool_admin_global).
 *   2) Bloco base (nextool_base) - so interface central.
 *   3) Um bloco por modulo instalado (central) / ativo (helpdesk), cada um
 *      com as colunas declaradas em getProfileRights() do modulo (formato P1).
 * As colunas sao coloridas por familia (usabilidade=azul, administracao=ambar)
 * via CSS/JS aditivo sobre a matriz nativa. Ver spec: plugins/nextool/PERMISSIONS.md.
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

   /** @var array<string,array<int,string>> field => [bit => familia 'use'|'admin'|'global'] */
   private array $ntFamilies = [];

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

   /**
    * Fallback generico (nao usado nas matrizes, que passam 'rights' explicito por linha).
    */
   public function getRights($interface = 'central') {
      return [
         READ   => __('Ler', 'nextool'),
         UPDATE => __('Atualizar', 'nextool'),
      ];
   }

   private function showFormNextool(int $profiles_id): void {
      // Carrega o perfil + seus profilerights em $this->fields (post_getFromDB).
      if (!$this->can($profiles_id, READ)) {
         return;
      }

      $canEdit = (bool) Session::haveRight(self::$rightname, UPDATE);

      // Garante que os direitos base/global/modulos existam em glpi_profilerights.
      PluginNextoolPermissionManager::syncModuleRights();

      $isHelpdesk = ($this->fields['interface'] ?? 'central') === 'helpdesk';
      $this->ntFamilies = [];

      echo "<div class='spaced nextool-perms'>";
      $this->renderPermLegend($isHelpdesk);
      if ($canEdit) {
         echo "<form method='post' action='" . static::getFormURL() . "'>";
      }

      // 1) Super-direito global -- SO interface central. "Acesso total ao ecossistema"
      // e o maximo de administracao; nunca deve ser concedivel por perfil simplificado.
      if (!$isHelpdesk) {
         $this->renderBlock(
            PluginNextoolPermissionManager::RIGHT_ADMIN_GLOBAL,
            __('Super-direito', 'nextool'),
            [
               PluginNextoolPermissionManager::GLOBAL_BIT =>
                  __('Acesso total ao ecossistema NexTool (base + todos os modulos)', 'nextool'),
            ],
            $canEdit
         );
      }

      // 2) Bloco base (so interface central).
      if (!$isHelpdesk) {
         $this->renderBlock(
            PluginNextoolPermissionManager::RIGHT_BASE,
            __('NexTool - Plugin base', 'nextool'),
            PluginNextoolPermissionManager::getBaseRights(),
            $canEdit
         );
      }

      // 3) Um bloco por modulo instalado (central) / ativo (helpdesk).
      if (class_exists('PluginNextoolModuleManager')) {
         try {
            $manager = PluginNextoolModuleManager::getInstance();
            foreach ($manager->getAllModules() as $mk => $mod) {
               if (!$mod->isInstalled()) {
                  continue;
               }
               if ($isHelpdesk && !$mod->isEnabled()) {
                  continue;
               }
               // Interface simplificada (helpdesk): SÓ os bits de uso que o módulo declara
               // para o requerente (opt-in via getHelpdeskRights) -- nunca administração.
               // Interface central: usabilidade + administração (getProfileRights).
               if ($isHelpdesk) {
                  $declared = method_exists($mod, 'getHelpdeskRights') ? $mod->getHelpdeskRights() : [];
               } else {
                  $declared = method_exists($mod, 'getProfileRights') ? $mod->getProfileRights() : [];
               }
               if (empty($declared)) {
                  continue;
               }
               $this->renderBlock(
                  PluginNextoolPermissionManager::getModuleRightName((string) $mk),
                  sprintf(__('Modulo: %s', 'nextool'), $mod->getName()),
                  $declared,
                  $canEdit
               );
            }
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', 'Profile: falha ao listar modulos - ' . $e->getMessage() . "\n");
         }
      }

      if ($canEdit) {
         echo Html::hidden('id', ['value' => $profiles_id]);
         // Botao Salvar no padrao nativo das abas de perfil do GLPI:
         // alinhado a direita, com borda superior e icone de disquete.
         echo '<div class="mt-3 pt-3 border-top d-flex flex-row-reverse">';
         echo '<button type="submit" name="update" value="1" class="btn btn-primary">'
            . '<i class="ti ti-device-floppy me-1"></i>' . _sx('button', 'Save')
            . '</button>';
         echo '</div>';
         Html::closeForm();
      }
      echo '</div>';
      $this->renderPermColorScript();
   }

   /**
    * Renderiza uma matriz (1 direito, 1 linha) num wrapper que permite colorir
    * as colunas por familia, e registra o mapa bit=>familia para o script.
    */
   private function renderBlock(string $field, string $title, array $rights, bool $canEdit): void {
      foreach (array_keys($rights) as $bit) {
         $this->ntFamilies[$field][(int) $bit] = PluginNextoolPermissionManager::bitFamily($field, (int) $bit);
      }
      echo '<div class="nt-matrix" data-field="' . htmlspecialchars($field, ENT_QUOTES) . '">';
      // Label da linha propositalmente vazio (um espaco): o titulo do bloco ja identifica o
      // direito. Remover a redundancia libera espaco horizontal para as colunas de permissao.
      // Espaco (nao vazio) e obrigatorio: displayRightsChoiceMatrix ignora linha com label vazio.
      $this->displayRightsChoiceMatrix([
         [
            'itemtype' => self::class,
            'label'    => ' ',
            'field'    => $field,
            'rights'   => $rights,
         ],
      ], ['title' => $title, 'canedit' => $canEdit]);
      echo '</div>';
   }

   /** CSS de familia + legenda (uso=azul, admin=ambar). */
   private function renderPermLegend(bool $isHelpdesk = false): void {
      echo '<style>'
         . '.nextool-perms .nt-legend{display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin:4px 0 14px;font-size:.85rem}'
         . '.nextool-perms .nt-legend span{display:inline-flex;align-items:center;gap:6px}'
         . '.nextool-perms .nt-legend i{width:14px;height:14px;border-radius:3px;display:inline-block}'
         . '.nextool-perms .nt-matrix tbody td:first-child,.nextool-perms .nt-matrix thead tr:last-child th:first-child{width:1px;white-space:nowrap;padding-left:4px;padding-right:4px}'
         . '.nextool-perms th.nt-use{background-color:rgba(37,96,201,.10);border-top:2px solid #2560c9}'
         . '.nextool-perms th.nt-admin{background-color:rgba(179,114,12,.12);border-top:2px solid #b3720c}'
         . '.nextool-perms th.nt-global{background-color:rgba(195,48,68,.14);border-top:2px solid #c33044}'
         . '.nextool-perms td.nt-use{background-color:rgba(37,96,201,.05)}'
         . '.nextool-perms td.nt-admin{background-color:rgba(179,114,12,.06)}'
         . '.nextool-perms td.nt-global{background-color:rgba(195,48,68,.07)}'
         . '</style>';
      // No perfil simplificado so existem colunas de usabilidade -> legenda mostra so essa familia.
      echo '<div class="nt-legend">'
         . '<span><i style="background:#2560c9"></i>' . __s('Usabilidade (usar)', 'nextool') . '</span>';
      if (!$isHelpdesk) {
         echo '<span><i style="background:#b3720c"></i>' . __s('Administracao (configurar)', 'nextool') . '</span>'
            . '<span><i style="background:#c33044"></i>' . __s('Super-direito (acesso total)', 'nextool') . '</span>';
      }
      echo '</div>';
   }

   /** JS que aplica as classes de familia as colunas de cada matriz (por bit no id do th). */
   private function renderPermColorScript(): void {
      echo '<script>window.NT_FAMILIES=' . json_encode($this->ntFamilies) . ';'
         . 'window.NT_ALL=' . json_encode(__('Todos', 'nextool')) . ';(function(){'
         . 'var F=window.NT_FAMILIES||{};var ALL=window.NT_ALL;'
         . 'document.querySelectorAll(".nextool-perms .nt-matrix").forEach(function(m){'
         . 'var fld=m.getAttribute("data-field");var map=F[fld]||{};var t=m.querySelector("table");if(!t)return;'
         . 'var ath=t.querySelector("th[id^=col_of_table_]");if(ath)ath.textContent=ALL;'
         . 'var hr=t.querySelectorAll("thead tr");var cr=hr[hr.length-1];if(!cr)return;'
         . 'Array.prototype.forEach.call(cr.children,function(th,i){'
         . 'var mm=(th.id||"").match(/^col_label_(\\d+)_/);if(!mm)return;var fam=map[mm[1]];if(!fam)return;'
         . 'th.classList.add("nt-"+fam);'
         . 't.querySelectorAll("tbody tr").forEach(function(tr){if(tr.children[i])tr.children[i].classList.add("nt-"+fam);});'
         . '});});'
         // Super-direito como "marcar todos global": ao (des)marcar Acesso total, (des)marca
         // todas as flags de todos os blocos (base + modulos).
         . 'var gm=document.querySelector(".nextool-perms .nt-matrix[data-field=nextool_admin_global]");'
         . 'if(gm){var gcb=gm.querySelector("input[type=checkbox]");if(gcb){gcb.addEventListener("change",function(){'
         . 'var on=gcb.checked;'
         . 'document.querySelectorAll(".nextool-perms input[type=checkbox]").forEach(function(cb){'
         . 'if(cb!==gcb&&cb.checked!==on){cb.checked=on;}});});}}'
         . '})();</script>';
   }
}
