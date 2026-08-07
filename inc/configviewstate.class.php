<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Config View State
 * -------------------------------------------------------------------------
 * Centraliza o cálculo de estado/licenciamento usado pela tela de
 * configuração (front/config.form.php), reduzindo acoplamento da view.
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

class PluginNextoolConfigViewState {

   /**
    * Calcula o estado de licenciamento para uso na view.
    *
    * @param array<string, mixed> $licenseConfig
    * @return array<string, mixed>
    */
   public static function fromLicenseConfig(array $licenseConfig): array {
      $licenseStatusCode = null;
      if (!empty($licenseConfig['license_status'])) {
         $licenseStatusCode = strtoupper((string) $licenseConfig['license_status']);
      }

      $licenseWarnings = [];
      if (!empty($licenseConfig['warnings'])) {
         $decodedWarnings = json_decode((string) $licenseConfig['warnings'], true);
         if (is_array($decodedWarnings)) {
            $licenseWarnings = $decodedWarnings;
         }
      }

      $allowedModules = [];
      $hasWildcardAll = false;
      if (!empty($licenseConfig['cached_modules'])) {
         $decodedModules = json_decode((string) $licenseConfig['cached_modules'], true);
         if (is_array($decodedModules)) {
            $allowedModules = $decodedModules;
            $hasWildcardAll = in_array('*', $allowedModules, true);
         }
      }

      $licensesSnapshot = [];
      if (!empty($licenseConfig['licenses_snapshot'])) {
         $decodedLicenses = json_decode((string) $licenseConfig['licenses_snapshot'], true);
         if (is_array($decodedLicenses)) {
            $licensesSnapshot = $decodedLicenses;
         }
      }

      $licenseTier = self::resolveLicenseTier($licenseConfig);
      $planPresentation = self::resolvePlanPresentation($licenseTier);

      $isLicenseActive = ($licenseStatusCode === 'ACTIVE');
      $isSuspended = ($licenseStatusCode === 'SUSPENDED');
      // SUSPENDED com plano pago NÃO é free tier - módulos já baixados permanecem operáveis
      $isFreeTier = ($licenseTier === 'FREE') || (!$isLicenseActive && !$isSuspended);
      $hasValidatedPlan = ($licenseTier !== 'UNKNOWN');
      $hasAssignedLicense = !empty($licensesSnapshot);

      // Conta vinculada ao portal conta como aceite implícito ("Ao vincular, você concorda com os
      // Termos de Uso e a Política de Privacidade"). Sem isto, um ambiente já identificado mas sem
      // policies_accepted_at local (ex.: vinculado pelo admin via admin-link, ou identificado antes
      // de aceitar) ficaria preso no banner de vínculo com os módulos escondidos, e "Vincular conta"
      // não o liberaria (o enroll que grava o aceite só roda em ambiente ainda cru).
      $accountLinkState = Config::getConfigurationValues('plugin:nextool_account_link');
      $accountLinked = ((string) ($accountLinkState['linked'] ?? '0')) === '1';
      $hasAcceptedPolicies = !empty($licenseConfig['policies_accepted_at'] ?? null) || $accountLinked;

      $commLine = self::resolveCommLine($licenseConfig);

      return [
         'commLineText'             => $commLine['text'],
         'commLineIcon'             => $commLine['icon'],
         'commLineClass'            => $commLine['class'],
         'commLineLevel'            => $commLine['level'],
         'licenseStatusCode'        => $licenseStatusCode,
         'licenseWarnings'          => $licenseWarnings,
         'allowedModules'           => $allowedModules,
         'hasWildcardAll'           => $hasWildcardAll,
         'licensesSnapshot'         => $licensesSnapshot,
         'licenseTier'              => $licenseTier,
         'licensePlanLabel'         => $planPresentation['label'],
         'licensePlanDescription'   => $planPresentation['description'],
         'licensePlanBadgeClass'    => $planPresentation['badgeClass'],
         'isLicenseActive'          => $isLicenseActive,
         'isFreeTier'               => $isFreeTier,
         'hasValidatedPlan'         => $hasValidatedPlan,
         'hasAssignedLicense'       => $hasAssignedLicense,
         'hasAcceptedPolicies'      => $hasAcceptedPolicies,
         'requiresPolicyAcceptance' => !$hasAcceptedPolicies,
      ];
   }

   /**
    * Linha de estado de comunicação com o servidor NexTool (hero, issue #244).
    * Derivada do comm_state (PluginNextoolCommBackoff) + cache de licença. O hero
    * fica burro: só renderiza text/icon/class.
    *
    * @param array<string, mixed> $licenseConfig
    * @return array{text:string, icon:string, class:string, level:string}
    */
   private static function resolveCommLine(array $licenseConfig): array {
      require_once NEXTOOL_PHP_DIR . '/inc/commbackoff.class.php';
      $state = PluginNextoolCommBackoff::getState();
      $suppression = PluginNextoolCommBackoff::shouldSuppress();

      $lastErrorCode = (string) ($state['last_error_code'] ?? '');
      $authStreak = (int) ($state['auth_streak'] ?? 0);
      $lastCommOk = (int) ($state['last_comm_ok_at'] ?? 0);
      $lastNetFail = (int) ($state['last_network_failure_at'] ?? 0);
      $lastAuthFail = (int) ($state['last_auth_failure_at'] ?? 0);
      $lastValidationTs = !empty($licenseConfig['last_validation_date'])
         ? (int) strtotime((string) $licenseConfig['last_validation_date'])
         : 0;

      // 1) Credenciais divergentes (signature_mismatch): cura = Reset no portal.
      if ($authStreak > 0 && $lastErrorCode === 'signature_mismatch') {
         return [
            'text'  => __('Credenciais divergem do servidor – solicite o reset de provisionamento ao suporte NexTool.', 'nextool'),
            'icon'  => 'ti-shield-x',
            'class' => 'text-danger fw-bold',
            'level' => 'mismatch',
         ];
      }

      // 2) Sem provisionamento: a auto-cura (6.7.0) resolve no Sincronizar.
      if ($authStreak > 0 && $lastErrorCode === 'environment_not_provisioned') {
         return [
            'text'  => __('Provisionamento em recuperação automática – clique em Sincronizar para concluir.', 'nextool'),
            'icon'  => 'ti-refresh-alert',
            'class' => 'text-warning fw-semibold',
            'level' => 'recovering',
         ];
      }

      // 2b) Falha de auth genérica (401 sem code — servidor antigo): backoff ativo.
      if ($authStreak > 0 && $lastAuthFail > $lastCommOk) {
         $eta = $suppression !== null
            ? sprintf(__('em %s', 'nextool'), self::humanizeInterval((int) $suppression['retry_in']))
            : __('no próximo Sincronizar', 'nextool');
         return [
            'text'  => sprintf(__('Falha de autenticação com o servidor NexTool – nova tentativa automática %s.', 'nextool'), $eta),
            'icon'  => 'ti-shield-x',
            'class' => 'text-warning fw-semibold',
            'level' => 'auth_failed',
         ];
      }

      // 3) Rede/5xx mais recente que o último OK: servidor inacessível.
      if ($lastNetFail > 0 && $lastNetFail > $lastCommOk) {
         $negativeCacheRemaining = 0;
         if (!empty($licenseConfig['last_failure_date'])) {
            $negativeCacheRemaining = (int) strtotime((string) $licenseConfig['last_failure_date']) + 600 - time();
         }
         $eta = $negativeCacheRemaining > 0
            ? sprintf(__('em %s', 'nextool'), self::humanizeInterval($negativeCacheRemaining))
            : __('no próximo Sincronizar', 'nextool');
         return [
            'text'  => sprintf(__('Servidor NexTool inacessível – nova tentativa automática %s.', 'nextool'), $eta),
            'icon'  => 'ti-plug-connected-x',
            'class' => 'text-warning fw-semibold',
            'level' => 'unreachable',
         ];
      }

      // 4) Conectado (há histórico de validação).
      if ($lastValidationTs > 0) {
         return [
            'text'  => sprintf(
               __('Servidor NexTool: conectado (última validação %s).', 'nextool'),
               self::humanizeAge(time() - $lastValidationTs)
            ),
            'icon'  => 'ti-plug-connected',
            'class' => 'text-white-50 fw-semibold',
            'level' => 'ok',
         ];
      }

      // 5) Nunca validou.
      return [
         'text'  => __('Servidor NexTool: aguardando primeira sincronização.', 'nextool'),
         'icon'  => 'ti-plug',
         'class' => 'text-white-50 fw-semibold',
         'level' => 'unknown',
      ];
   }

   /** "agora", "há 5 min", "há 3 h", "há 2 dias" (sem helpers G11-only). */
   private static function humanizeAge(int $seconds): string {
      if ($seconds < 60) {
         return __('agora', 'nextool');
      }
      if ($seconds < 3600) {
         return sprintf(__('há %d min', 'nextool'), (int) floor($seconds / 60));
      }
      if ($seconds < 86400) {
         return sprintf(__('há %d h', 'nextool'), (int) floor($seconds / 3600));
      }
      return sprintf(_n('há %d dia', 'há %d dias', (int) floor($seconds / 86400), 'nextool'), (int) floor($seconds / 86400));
   }

   /** "45 s", "3 min", "1 h" — duração futura curta (ETA de retentativa). */
   private static function humanizeInterval(int $seconds): string {
      if ($seconds < 60) {
         return sprintf(__('%d s', 'nextool'), max($seconds, 1));
      }
      if ($seconds < 3600) {
         return sprintf(__('%d min', 'nextool'), (int) ceil($seconds / 60));
      }
      return sprintf(__('%d h', 'nextool'), (int) ceil($seconds / 3600));
   }

   /**
    * @param array<string, mixed> $licenseConfig
    */
   private static function resolveLicenseTier(array $licenseConfig): string {
      $licenseTier = 'UNKNOWN';
      $lastResult = isset($licenseConfig['last_validation_result'])
         ? (int) $licenseConfig['last_validation_result']
         : null;

      if (isset($licenseConfig['plan']) && is_string($licenseConfig['plan']) && $licenseConfig['plan'] !== '') {
         $licenseTier = PluginNextoolLicenseValidator::normalizePlan($licenseConfig['plan']);
      } elseif ($lastResult === 1) {
         // Compatibilidade com versões antigas
         $licenseTier = 'BUSINESS';
      } elseif ($lastResult === 0) {
         $licenseTier = 'FREE';
      }

      return $licenseTier;
   }

   /**
    * @return array{label:string,description:string,badgeClass:string}
    */
   private static function resolvePlanPresentation(string $licenseTier): array {
      switch ($licenseTier) {
         case 'FREE':
            return [
               'label'       => __('Não licenciado', 'nextool'),
               'description' => __('Acesso apenas a módulos FREE. Vincule uma licença para desbloquear módulos adicionais.', 'nextool'),
               'badgeClass'  => 'bg-teal',
            ];

         case 'DESENVOLVIMENTO':
            return [
               'label'       => __('Desenvolvimento', 'nextool'),
               'description' => __('Plano de desenvolvimento com acesso a todos os módulos (incluindo DEV).', 'nextool'),
               'badgeClass'  => 'bg-blue',
            ];

         case 'LICENCIADO':
            return [
               'label'       => __('Licenciado', 'nextool'),
               'description' => __('Plano licenciado com acesso aos módulos permitidos pelo contrato.', 'nextool'),
               'badgeClass'  => 'bg-indigo',
            ];

         case 'ENTERPRISE':
            return [
               'label'       => __('Enterprise', 'nextool'),
               'description' => __('Plano corporativo com acesso a todos os módulos exceto os de desenvolvimento (DEV).', 'nextool'),
               'badgeClass'  => 'bg-purple',
            ];

         case 'BUSINESS':
            return [
               'label'       => __('Licenciado', 'nextool'),
               'description' => __('Plano pago com acesso a módulos licenciados conforme seu contrato atual.', 'nextool'),
               'badgeClass'  => 'bg-primary',
            ];

         case 'UNKNOWN':
         default:
            return [
               'label'       => __('Não validado', 'nextool'),
               'description' => __('Valide sua licença para descobrir seu plano, registrar seu ambiente e desbloquear módulos.', 'nextool'),
               'badgeClass'  => 'bg-secondary',
            ];
      }
   }
}
