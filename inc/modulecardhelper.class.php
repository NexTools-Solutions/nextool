<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module Card Helper
 * -------------------------------------------------------------------------
 * Helper responsável por renderizar os botões/ações dos cards de módulos
 * na UI do NexTool Solutions (Download, Instalar, Atualizar, Licenciar,
 * Apagar dados, Acessar dados, etc.).
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

class PluginNextoolModuleCardHelper {

   public static function renderActions(array $state): string {
      $html = [];

      $canManage      = !empty($state['can_manage_module'] ?? $state['can_manage_modules']);
      $canPurge       = !empty($state['can_purge_module'] ?? $state['can_purge_modules']);
      $canView        = !empty($state['can_view_module'] ?? $state['can_view_modules']);
      $canManageAdmin = !empty($state['can_manage_admin_tabs']);

      if (!$canView) {
         return self::renderBadge(__('Sem permissão para visualizar ações deste módulo.', 'nextool'));
      }

      $catalogDisabled = empty($state['catalog_is_enabled']);

      // Se não pode gerenciar, exibe apenas badge informativo + botões de dados/config
      if (!$canManage) {
         $html[] = self::renderBadge(__('Permissão de visualização: não é possível gerenciar este módulo.', 'nextool'), 'badge bg-info text-white me-1');
         self::appendDataButtons($state, $html);
         
         // Adiciona botão de configurações se disponível
         if ($state['show_config_button']) {
            $html[] = self::renderLink(
               __('Configurações', 'nextool'),
               'btn btn-sm btn-primary',
               'ti ti-settings',
               $state['config_url']
            );
         }
         
         return implode('', $html);
      }

      // Antes da validação do plano, exibe apenas aviso
      if (!$state['has_validated_plan']) {
         $html[] = self::renderBadge(__('Plano não validado. Solicite a um administrador para realizar este passo.', 'nextool'));
         self::appendDataButtons($state, $html);
         return implode('', $html);
      }

      if ($state['requires_remote_download']) {
         if ($state['has_validated_plan'] && $state['is_paid'] && !$state['can_use_module']) {
            // Licença válida mas módulo pago não permitido: incentivar licenciamento em vez de download
            $html[] = self::renderLink(
               __('Licenciar', 'nextool'),
               'btn btn-sm btn-outline-licensing',
               'ti ti-certificate',
               $state['upgrade_url'],
               true
            );
            self::appendDataButtons($state, $html);
            return implode('', $html);
         }

         if ($catalogDisabled) {
            $html[] = self::renderBadge(__('Download indisponível (catálogo desativado)', 'nextool'));
            self::appendDataButtons($state, $html);
            return implode('', $html);
         }

         $html[] = self::renderActionForm(
            $state,
            'download',
            __('Download', 'nextool'),
            'btn btn-sm btn-success module-action',
            'ti ti-cloud-download',
            !$state['distribution_configured'] || !$state['can_use_module'],
            !$state['distribution_configured']
               ? __('Configure o ContainerAPI para liberar o download.', 'nextool')
               : null
         );
         self::appendDataButtons($state, $html);
         return implode('', $html);
      }

      if (!$state['is_installed']) {
         if (!$state['can_use_module']) {
            $html[] = self::renderLink(
               __('Licenciar', 'nextool'),
               'btn btn-sm btn-outline-licensing',
               'ti ti-certificate',
               $state['upgrade_url'],
               true
            );
            self::appendDataButtons($state, $html);
            return implode('', $html);
         }

         if ($catalogDisabled) {
            $html[] = self::renderBadge(__('Módulo desativado no catálogo', 'nextool'));
         } else {
            $html[] = self::renderActionForm(
               $state,
               'install',
               __('Instalar', 'nextool'),
               'btn btn-sm btn-success module-action',
               'ti ti-download'
            );
         }
      } else {
         if ($catalogDisabled) {
            $html[] = self::renderBadge(__('Catálogo: módulo desativado', 'nextool'), 'badge bg-secondary me-1');
         }

         if (!empty($state['update_available'])) {
            $pluginVersion = $state['plugin_version'] ?? '';
            $minVerNextool = isset($state['min_version_nextools']) && $state['min_version_nextools'] !== '' && $state['min_version_nextools'] !== null
               ? trim((string) $state['min_version_nextools']) : null;
            $updateBlockedByNextoolVersion = false;
            if ($minVerNextool !== null) {
               $updateBlockedByNextoolVersion = $pluginVersion === '' || version_compare($pluginVersion, $minVerNextool, '<');
            }
            if ($updateBlockedByNextoolVersion) {
               $msg = $minVerNextool !== null
                  ? sprintf(
                     __('Para atualizar é necessário estar utilizando o Nextool versão %s ou superior.', 'nextool'),
                     $minVerNextool
                  )
                  : __('Atualize o Nextool para a versão mais recente para continuar.', 'nextool');
               $html[] = '<span class="badge bg-warning text-dark me-1">' . Html::entities_deep($msg) . ' </span>';
               $html[] = self::renderLink(
                  __('Atualizar Nextool', 'nextool'),
                  'btn btn-sm btn-outline-warning',
                  'ti ti-external-link',
                  'https://nextoolsolutions.ai/produtos/plugin-nextools-glpi',
                  true
               );
            } else {
               $html[] = self::renderActionForm(
                  $state,
                  'update',
                  __('Atualizar', 'nextool'),
                  'btn btn-sm btn-outline-primary module-action',
                  'ti ti-arrow-up',
                  !$state['can_use_module'] || $catalogDisabled
               );
            }
         }

         if ($state['is_enabled']) {
            $html[] = self::renderActionForm(
               $state,
               'disable',
               __('Desativar', 'nextool'),
               'btn btn-sm btn-warning module-action',
               'ti ti-player-pause'
            );
         } else {
            $html[] = self::renderActionForm(
               $state,
               'enable',
               __('Ativar', 'nextool'),
               'btn btn-sm btn-success module-action',
               'ti ti-player-play',
               !$state['can_use_module'] || $catalogDisabled
            );

            $html[] = self::renderActionForm(
               $state,
               'uninstall',
               __('Desinstalar', 'nextool'),
               'btn btn-sm btn-danger module-action',
               'ti ti-trash',
               false,
               __('Tem certeza? O módulo será desativado, mas tabelas e dados permanecerão no banco. Para apagar os dados, use "Apagar dados" após desinstalar.', 'nextool')
            );
         }
      }

      self::appendDataButtons($state, $html);

      if ($state['show_config_button']) {
         $html[] = self::renderLink(
            __('Configurações', 'nextool'),
            'btn btn-sm btn-primary',
            'ti ti-settings',
            $state['config_url']
         );
      }

      return implode('', $html);
   }

   private static function appendDataButtons(array $state, array &$html): void {
      if (!$state['is_installed']) {
         $hasDataToPurge = !empty($state['has_module_data']);
         $hasDbDataToView = !empty($state['has_module_db_data'] ?? $state['has_module_data']);

         if ($hasDataToPurge && !empty($state['can_purge_module'] ?? $state['can_purge_modules'])) {
            $html[] = self::renderActionForm(
               $state,
               'purge_data',
               __('Apagar dados', 'nextool'),
               'btn btn-sm btn-outline-danger module-action',
               'ti ti-database-off',
               false,
               __('Esta ação remove tabelas e registros relacionados ao módulo. Deseja continuar?', 'nextool')
            );
         }

         if ($hasDbDataToView && !empty($state['can_view_module'] ?? $state['can_view_modules'])) {
            $html[] = self::renderLink(
               __('Acessar dados', 'nextool'),
               'btn btn-sm btn-outline-secondary',
               'ti ti-database-search',
               $state['data_url'],
               true
            );
         }
      }
   }

   private static function renderPlainButton(string $label, string $classes, string $icon, string $onclick): string {
      return sprintf(
         "<button type='button' class='%s me-1' onclick=\"%s\"><i class='%s me-1'></i>%s</button>",
         $classes,
         Html::entities_deep($onclick),
         $icon,
         $label
      );
   }

   private static function renderLink(string $label, string $classes, string $icon, string $url, bool $newTab = false): string {
      $target = $newTab ? " target='_blank' rel='noopener'" : '';
      return sprintf(
         "<a href='%s' class='%s me-1'%s><i class='%s me-1'></i>%s</a>",
         Html::entities_deep($url),
         $classes,
         $target,
         $icon,
         $label
      );
   }

   private static function renderBadge(string $label, string $classes = 'badge bg-secondary'): string {
      return sprintf("<span class='%s me-1'>%s</span>", $classes, $label);
   }

   private static function renderActionForm(
      array $state,
      string $action,
      string $label,
      string $buttonClass,
      string $iconClass,
      bool $disabled = false,
      ?string $confirmMessage = null
   ): string {
      $actionUrl = Plugin::getWebDir('nextool') . '/ajax/module_action.php';
      $token = Session::getNewCSRFToken();

      $fields = '';
      $fields .= Html::hidden('_glpi_csrf_token', ['value' => $token]);
      $fields .= "<input type='hidden' name='module' value='" . Html::entities_deep($state['module_key']) . "'>";
      $fields .= "<input type='hidden' name='action' value='" . Html::entities_deep($action) . "'>";

      $disabledAttr = $disabled ? ' disabled' : '';
      $confirmAttr = '';
      if (!empty($confirmMessage)) {
         $confirmAttr = " onclick=\"return confirm(" . json_encode($confirmMessage, JSON_HEX_APOS | JSON_HEX_QUOT) . ");\"";
      }

      $buttonHtml = "<button type='submit' class='{$buttonClass}'{$disabledAttr}{$confirmAttr}><i class='{$iconClass} me-1'></i>{$label}</button>";

      return "<form method='post' action='{$actionUrl}' class='d-inline module-action-form me-1'>{$fields}{$buttonHtml}</form>";
   }
}


