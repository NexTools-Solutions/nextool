<?php
/**
 * Nextools - Config View State
 *
 * Centraliza o cálculo de estado e licenciamento usado pela tela de configuração
 * (front/config.form.php), reduzindo acoplamento da view.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
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
      $contractActive = null;
      if (array_key_exists('contract_active', $licenseConfig)) {
         $raw = $licenseConfig['contract_active'];
         if ($raw === '' || $raw === null) {
            $contractActive = null;
         } else {
            $contractActive = (bool) $raw;
         }
      }

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

      $isLicenseActive = ($licenseStatusCode === 'ACTIVE') && ($contractActive !== false);
      $isFreeTier = (!$isLicenseActive) || $licenseStatusCode === 'FREE_TIER' || $licenseTier === 'FREE';
      $hasValidatedPlan = ($licenseTier !== 'UNKNOWN');
      $hasAssignedLicense = !empty($licensesSnapshot);

      $hasAcceptedPolicies = !empty($licenseConfig['policies_accepted_at'] ?? null);

      return [
         'contractActive'           => $contractActive,
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
    * @param array<string, mixed> $licenseConfig
    */
   private static function resolveLicenseTier(array $licenseConfig): string {
      $licenseTier = 'UNKNOWN';
      $lastResult = isset($licenseConfig['last_validation_result'])
         ? (int) $licenseConfig['last_validation_result']
         : null;

      if (isset($licenseConfig['plan']) && is_string($licenseConfig['plan']) && $licenseConfig['plan'] !== '') {
         $licenseTier = strtoupper($licenseConfig['plan']);
         if ($licenseTier === 'STARTER') {
            $licenseTier = 'DESENVOLVIMENTO';
         }
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
               'description' => 'Acesso apenas a módulos FREE. Vincule uma licença para desbloquear módulos adicionais.',
               'badgeClass'  => 'bg-teal',
            ];

         case 'DESENVOLVIMENTO':
            return [
               'label'       => __('Desenvolvimento', 'nextool'),
               'description' => 'Plano de desenvolvimento com acesso a todos os módulos (incluindo DEV).',
               'badgeClass'  => 'bg-blue',
            ];

         case 'PRO':
            return [
               'label'       => __('Licenciado', 'nextool'),
               'description' => 'Plano licenciado com acesso aos módulos permitidos pelo contrato.',
               'badgeClass'  => 'bg-indigo',
            ];

         case 'ENTERPRISE':
            return [
               'label'       => __('Enterprise', 'nextool'),
               'description' => 'Plano corporativo com acesso a todos os módulos exceto os de desenvolvimento (DEV).',
               'badgeClass'  => 'bg-purple',
            ];

         case 'BUSINESS':
            return [
               'label'       => __('Licenciado', 'nextool'),
               'description' => 'Plano pago com acesso a módulos licenciados conforme seu contrato atual.',
               'badgeClass'  => 'bg-primary',
            ];

         case 'UNKNOWN':
         default:
            return [
               'label'       => __('Não validado', 'nextool'),
               'description' => 'Valide sua licença para descobrir seu plano, registrar seu ambiente e desbloquear módulos.',
               'badgeClass'  => 'bg-secondary',
            ];
      }
   }
}
