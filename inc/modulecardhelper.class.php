<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module Card Helper
 * -------------------------------------------------------------------------
 * Helper responsavel por renderizar os botoes/acoes dos cards de modulos
 * na UI do NexTool Solutions (Download, Instalar, Atualizar, Licenciar,
 * Apagar dados, Acessar dados, etc.).
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

class PluginNextoolModuleCardHelper {

   public static function renderActions(array $state): string {

      $canManage      = !empty($state['can_manage_module'] ?? $state['can_manage_modules']);
      $canPurge       = !empty($state['can_purge_module'] ?? $state['can_purge_modules']);
      $canView        = !empty($state['can_view_module'] ?? $state['can_view_modules']);
      $canManageAdmin = !empty($state['can_manage_admin_tabs']);

      if (!$canView) {
         return self::renderBadge(__('Sem permissão para visualizar ações deste módulo.', 'nextool'));
      }

      $catalogDisabled = empty($state['catalog_is_enabled']);

      if (!$canManage) {
         $out = self::renderBadge(__('Permissão de visualização: não é possível gerenciar este módulo.', 'nextool'), 'badge bg-info text-white me-1');
         $secondary = [];
         self::appendDataItems($state, $secondary);
         // Não inclui "Configurações" aqui: a página de config exige canManage e bate em erro.
         // Saiba Mais e Changelogs são informativos e devem aparecer para perfis READ-only.
         self::appendExternalLinksForCompat($state, $secondary);
         return $out . self::wrapDropdown($secondary);
      }

      // Módulo incompatível com a versão atual do GLPI: aparece no catálogo
      // (para o cliente conhecer o ecossistema), mas sem botões de instalação.
      // Dropdown mantém Saiba Mais + Changelogs apontando para a plataforma onde
      // o módulo realmente existe (primeira major do compat_glpi_majors).
      if (!self::isCompatibleWithCurrentGlpi($state)) {
         $compatLabel = self::resolveCompatLabel($state);
         $primary = '<span class="badge bg-warning text-dark me-1" title="' .
            Html::entities_deep(__('Este módulo não suporta sua versão do GLPI', 'nextool')) . '">' .
            '<i class="ti ti-alert-triangle me-1"></i>' .
            Html::entities_deep($compatLabel) .
            '</span>';
         $secondary = [];
         self::appendExternalLinksForCompat($state, $secondary);
         return $primary . self::wrapDropdown($secondary);
      }

      if (!$state['has_validated_plan']) {
         $out = self::renderBadge(__('Plano não validado. Solicite a um administrador para realizar este passo.', 'nextool'));
         $secondary = [];
         self::appendDataItems($state, $secondary);
         self::appendExternalLinksForCompat($state, $secondary);
         return $out . self::wrapDropdown($secondary);
      }

      $isSuspended = !empty($state['is_license_suspended']);
      $primary = '';
      $secondary = [];

      // ── Determinar CTA primario ──────────────────────────────

      if ($state['requires_remote_download']) {

         if ($isSuspended && $state['is_paid']) {
            $primary = self::renderBadge(__('Download bloqueado: licença suspensa', 'nextool'), 'badge bg-warning text-dark me-1');
         } else {
            $canDownload = !empty($state['can_download_module'] ?? $state['can_use_module']);
            // Gate de versão mínima do plugin base — mesmo critério do caminho de
            // UPDATE (abaixo): sem o base mínimo, o ContainerAPI recusaria o
            // manifesto (nextool_upgrade_required); exibir o botão Download só
            // prometia um erro. Etiqueta âmbar no lugar (paridade GLPI 11, 2026-06-10).
            $dlPluginVersion = $state['plugin_version'] ?? '';
            $dlMinVer = isset($state['min_version_nextools']) && $state['min_version_nextools'] !== '' && $state['min_version_nextools'] !== null
               ? trim((string) $state['min_version_nextools']) : null;
            $dlBlocked = $dlMinVer !== null && ($dlPluginVersion === '' || version_compare($dlPluginVersion, $dlMinVer, '<'));

            if ($state['is_paid'] && !$canDownload) {
               $primary = self::renderLicensingButton($state);
            } elseif ($catalogDisabled) {
               $primary = self::renderBadge(__('Download indisponível (catálogo desativado)', 'nextool'));
            } elseif (empty($state['has_zip_extension'])) {
               $primary = self::renderBadge(__('Pré-requisito: extensão php-zip não instalada', 'nextool'), 'badge bg-danger text-white me-1');
            } elseif ($dlBlocked) {
               $msg = sprintf(__('Nextool %s necessário para baixar', 'nextool'), $dlMinVer);
               $primary = '<span class="badge bg-warning text-dark me-1">' . Html::entities_deep($msg) . '</span>';
            } elseif (!$state['is_paid'] && !empty($state['account_link_required'])) {
               // Gate de vínculo (FREE): em vez de um Download que tomaria 403 account_link_required
               // no servidor, oferece o vínculo direto (abre o mesmo modal do hero "Vincular conta").
               $primary = self::renderAccountLinkButton();
            } else {
               $primary = self::renderActionForm(
                  $state, 'download', __('Download', 'nextool'),
                  'btn btn-sm btn-success module-action', 'ti ti-cloud-download',
                  !$state['distribution_configured'] || !$canDownload,
                  !$state['distribution_configured'] ? __('Configure o ContainerAPI para liberar o download.', 'nextool') : null
               );
            }
         }

      } elseif (!$state['is_installed']) {

         if (!$state['can_use_module'] && $state['is_paid']) {
            $primary = self::renderLicensingButton($state);
         } elseif ($catalogDisabled) {
            $primary = self::renderBadge(__('Módulo desativado no catálogo', 'nextool'));
         } else {
            $primary = self::renderActionForm(
               $state, 'install', __('Instalar', 'nextool'),
               'btn btn-sm btn-success module-action', 'ti ti-download'
            );
         }

      } else {
         // Modulo instalado -- determinar CTA principal por prioridade

         $canDownloadCurrent = !empty($state['can_download_module']);

         // Prioridade 1: Licenciar (PAID sem licenca)
         if ($state['is_paid'] && !$canDownloadCurrent && !$isSuspended) {
            $primary = self::renderLicensingButton($state);
         }
         // Prioridade 2: Update disponivel
         elseif (!empty($state['update_available']) && !$catalogDisabled) {
            if (empty($state['has_zip_extension'])) {
               $primary = self::renderBadge(__('Atualização indisponível: extensão php-zip não instalada', 'nextool'), 'badge bg-danger text-white me-1');
            } elseif ($isSuspended && $state['is_paid']) {
               $primary = self::renderBadge(__('Atualização bloqueada: licença suspensa', 'nextool'), 'badge bg-warning text-dark me-1');
            } else {
               $pluginVersion = $state['plugin_version'] ?? '';
               $minVerNextool = isset($state['min_version_nextools']) && $state['min_version_nextools'] !== '' && $state['min_version_nextools'] !== null
                  ? trim((string) $state['min_version_nextools']) : null;
               $blocked = $minVerNextool !== null && ($pluginVersion === '' || version_compare($pluginVersion, $minVerNextool, '<'));
               if ($blocked) {
                  $msg = sprintf(__('Nextool %s necessário para atualizar', 'nextool'), $minVerNextool);
                  $primary = '<span class="badge bg-warning text-dark me-1">' . Html::entities_deep($msg) . '</span>';
               } else {
                  $canDl = !empty($state['can_download_module'] ?? $state['can_use_module']);
                  $primary = self::renderActionForm(
                     $state, 'update', __('Atualização Disponível', 'nextool'),
                     'btn btn-sm btn-outline-info module-action', 'ti ti-arrow-up',
                     !$canDl || $catalogDisabled
                  );
               }
            }
         }
         // Prioridade 3: Desativado -> Ativar
         elseif (!$state['is_enabled']) {
            $primary = self::renderActionForm(
               $state, 'enable', __('Ativar', 'nextool'),
               'btn btn-sm btn-success module-action', 'ti ti-player-play',
               !$state['can_use_module'] || $catalogDisabled
            );
         }
         // Prioridade 4: Ativo com config -> Configuracoes
         elseif ($state['show_config_button']) {
            $primary = self::renderLink(
               __('Configurações', 'nextool'), 'btn btn-sm btn-primary', 'ti ti-settings', $state['config_url']
            );
         }

         // ── Acoes secundarias (dropdown) ──────────────────────

         if ($catalogDisabled) {
            $secondary[] = '<li><span class="dropdown-item text-muted"><i class="ti ti-alert-circle me-2"></i>' . __('Catálogo desativado', 'nextool') . '</span></li>';
         }

         if ($state['is_enabled']) {
            $secondary[] = self::renderDropdownAction($state, 'disable', __('Desativar', 'nextool'), 'ti ti-player-pause');
         } else {
            if ($primary !== '' && strpos($primary, 'data-action=\'enable\'') === false) {
               $secondary[] = self::renderDropdownAction($state, 'enable', __('Ativar', 'nextool'), 'ti ti-player-play',
                  !$state['can_use_module'] || $catalogDisabled);
            }
            $secondary[] = self::renderDropdownAction($state, 'uninstall', __('Desinstalar', 'nextool'), 'ti ti-trash text-danger', false,
               __('Tem certeza? O módulo será desativado, mas tabelas e dados permanecerão no banco.', 'nextool'));
         }

         if ($state['show_config_button'] && strpos($primary, 'ti-settings') === false) {
            $secondary[] = self::renderDropdownItem(__('Configurações', 'nextool'), 'ti ti-settings', $state['config_url']);
         }

      }

      // Re-baixar: disponivel apenas quando desinstalado (arquivos podem existir no disco)
      if (!$state['is_installed'] && !empty($state['distribution_configured'])) {
         $secondary[] = self::renderDropdownAction(
            $state,
            'redownload',
            __('Re-baixar', 'nextool'),
            'ti ti-cloud-download',
            false,
            __('Os arquivos do módulo serão substituídos por uma versão fresca do servidor. Dados do banco serão preservados.', 'nextool')
         );
      }

      // Links externos: Saiba Mais e Changelogs
      if (!empty($state['website_url'])) {
         $secondary[] = self::renderDropdownItem(__('Saiba Mais', 'nextool'), 'ti ti-external-link', $state['website_url'], true);
      }
      $moduleKey = $state['module_key'] ?? '';
      if ($moduleKey !== '') {
         $changelogUrl = 'https://github.com/NexTools-Solutions/nextool/releases?q='
            . urlencode('Etiqueta: modulo:' . $moduleKey . '[GLPI_10]');
         $secondary[] = self::renderDropdownItem(__('Changelogs', 'nextool'), 'ti ti-history', $changelogUrl, true);
      }

      // Dados (purge/view) vao no dropdown
      self::appendDataItems($state, $secondary);

      return $primary . self::wrapDropdown($secondary);
   }

   /**
    * Verifica se o módulo é compatível com a versão atual do GLPI a partir do
    * CSV compat_glpi_majors. Quando o campo está vazio (catálogo antigo sem
    * platforms), assume compatibilidade — fallback retrocompat.
    */
   private static function isCompatibleWithCurrentGlpi(array $state): bool {
      $csv = isset($state['compat_glpi_majors']) ? trim((string) $state['compat_glpi_majors']) : '';
      if ($csv === '') {
         return true;
      }
      $list = array_filter(array_map('trim', explode(',', $csv)));
      if (empty($list)) {
         return true;
      }
      $currentMajor = (string) (int) explode('.', GLPI_VERSION)[0];
      return in_array($currentMajor, $list, true);
   }

   /**
    * Resolve a primeira major compatível do módulo a partir do CSV.
    */
   private static function resolveFirstCompatMajor(array $state): ?string {
      $csv = isset($state['compat_glpi_majors']) ? trim((string) $state['compat_glpi_majors']) : '';
      $list = array_filter(array_map('trim', explode(',', $csv)));
      return $list ? (string) reset($list) : null;
   }

   /**
    * Dropdown items para card de módulo incompatível: Saiba Mais + Changelogs
    * apontando para a major correta.
    */
   private static function appendExternalLinksForCompat(array $state, array &$items): void {
      if (!empty($state['website_url'])) {
         $items[] = self::renderDropdownItem(__('Saiba Mais', 'nextool'), 'ti ti-external-link', $state['website_url'], true);
      }
      $moduleKey = $state['module_key'] ?? '';
      $major = self::resolveFirstCompatMajor($state);
      if ($moduleKey !== '' && $major !== null) {
         $changelogUrl = 'https://github.com/NexTools-Solutions/nextool/releases?q='
            . urlencode('Etiqueta: modulo:' . $moduleKey . '[GLPI_' . $major . ']');
         $items[] = self::renderDropdownItem(__('Changelogs', 'nextool'), 'ti ti-history', $changelogUrl, true);
      }
   }

   /**
    * Constrói o label "Disponível apenas para GLPI X".
    */
   private static function resolveCompatLabel(array $state): string {
      $csv = isset($state['compat_glpi_majors']) ? trim((string) $state['compat_glpi_majors']) : '';
      $list = array_filter(array_map('trim', explode(',', $csv)));
      if (empty($list)) {
         return __('Não disponível para sua versão do GLPI', 'nextool');
      }
      $labels = array_map(fn($v) => 'GLPI ' . $v, $list);
      return sprintf(__('Disponível apenas para %s', 'nextool'), implode(' / ', $labels));
   }

   private static function appendDataItems(array $state, array &$items): void {
      if (!$state['is_installed']) {
         $hasDataToPurge = !empty($state['has_module_data']);
         $hasDbDataToView = !empty($state['has_module_db_data'] ?? $state['has_module_data']);

         if ($hasDataToPurge && !empty($state['can_purge_module'] ?? $state['can_purge_modules'])) {
            $items[] = self::renderDropdownAction($state, 'purge_data', __('Apagar dados', 'nextool'), 'ti ti-database-off text-danger', false,
               __('Esta ação remove permanentemente todas as tabelas e registros do módulo no banco de dados.', 'nextool'), 'typed');
         }

         if ($hasDbDataToView && !empty($state['can_view_module'] ?? $state['can_view_modules'])) {
            $items[] = self::renderDropdownItem(__('Acessar dados', 'nextool'), 'ti ti-database-search', $state['data_url'], true);
         }
      }
   }

   private static function wrapDropdown(array $items): string {
      if (empty($items)) {
         return '';
      }
      return '<div class="dropdown d-inline-block">'
         . '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">'
         . '<i class="ti ti-dots"></i></button>'
         . '<ul class="dropdown-menu dropdown-menu-end">'
         . implode('', $items)
         . '</ul></div>';
   }

   private static function renderDropdownAction(
      array $state, string $action, string $label, string $icon,
      bool $disabled = false, ?string $confirmMessage = null, string $confirmType = 'simple'
   ): string {
      $moduleKey = Html::entities_deep($state['module_key']);
      $disabledClass = $disabled ? ' disabled' : '';
      $confirmAttr = '';
      if (!empty($confirmMessage)) {
         $confirmAttr = ' data-confirm="' . htmlspecialchars($confirmMessage, ENT_QUOTES, 'UTF-8') . '"';
         if ($confirmType !== 'simple') {
            $confirmAttr .= ' data-confirm-type="' . htmlspecialchars($confirmType, ENT_QUOTES, 'UTF-8') . '"';
         }
      }
      return '<li><a class="dropdown-item nextool-module-action' . $disabledClass . '" href="#"'
         . ' data-module="' . $moduleKey . '" data-action="' . Html::entities_deep($action) . '"'
         . $confirmAttr . '>'
         . '<i class="' . $icon . ' me-2"></i>' . Html::entities_deep($label) . '</a></li>';
   }

   private static function renderDropdownItem(string $label, string $icon, string $url, bool $newTab = false): string {
      $target = $newTab ? ' target="_blank" rel="noopener"' : '';
      return '<li><a class="dropdown-item" href="' . Html::entities_deep($url) . '"' . $target . '>'
         . '<i class="' . $icon . ' me-2"></i>' . Html::entities_deep($label) . '</a></li>';
   }

   private static function renderLicensingButton(array $state): string {
      $moduleKey = Html::entities_deep($state['module_key']);
      $moduleName = Html::entities_deep($state['name'] ?? $state['module_key']);
      $label = Html::entities_deep(__('Licenciar', 'nextool'));
      return "<button type='button' class='btn btn-sm btn-outline-licensing me-1 nextool-module-action'"
         . " data-module='{$moduleKey}' data-action='licensing' data-module-name='{$moduleName}'>"
         . "<i class='ti ti-certificate me-1'></i>{$label}</button>";
   }

   private static function renderLink(string $label, string $classes, string $icon, string $url, bool $newTab = false): string {
      $target = $newTab ? " target='_blank' rel='noopener'" : '';
      return sprintf(
         "<a href='%s' class='%s me-1'%s><i class='%s me-1'></i>%s</a>",
         Html::entities_deep($url), $classes, $target, $icon, $label
      );
   }

   private static function renderBadge(string $label, string $classes = 'badge bg-secondary'): string {
      return sprintf("<span class='%s me-1'>%s</span>", $classes, $label);
   }

   /**
    * Botão "Vincular conta" que substitui o Download quando o gate de vínculo está ativo (FREE +
    * account_link_required). Abre o mesmo modal do hero (#nextool-account-link-modal).
    */
   private static function renderAccountLinkButton(): string {
      return '<button type="button" class="btn btn-sm btn-warning module-action"'
         . ' data-bs-toggle="modal" data-bs-target="#nextool-account-link-modal">'
         . '<i class="ti ti-user-check me-1"></i>' . __('Vincular conta', 'nextool')
         . '</button>';
   }

   private static function renderActionForm(
      array $state, string $action, string $label, string $buttonClass, string $iconClass,
      bool $disabled = false, ?string $confirmMessage = null, string $confirmType = 'simple'
   ): string {
      $disabledAttr = $disabled ? ' disabled' : '';
      $confirmAttr = '';
      if (!empty($confirmMessage)) {
         $confirmAttr = ' data-confirm="' . htmlspecialchars($confirmMessage, ENT_QUOTES, 'UTF-8') . '"';
         if ($confirmType !== 'simple') {
            $confirmAttr .= ' data-confirm-type="' . htmlspecialchars($confirmType, ENT_QUOTES, 'UTF-8') . '"';
         }
      }
      $moduleKey = Html::entities_deep($state['module_key']);
      $actionEsc = Html::entities_deep($action);
      $labelEsc  = Html::entities_deep($label);
      $classes   = Html::entities_deep(trim($buttonClass . ' me-1 nextool-module-action'));
      return "<button type='button' class='{$classes}' data-module='{$moduleKey}' data-action='{$actionEsc}'{$confirmAttr}{$disabledAttr}><i class='{$iconClass} me-1'></i>{$labelEsc}</button>";
   }
}
