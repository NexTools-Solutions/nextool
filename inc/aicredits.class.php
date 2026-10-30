<?php
/**
 * Créditos de IA do ambiente, para QUALQUER módulo que consuma IA no modo gerenciado (plataforma multi-módulo dos
 * créditos, 2026-10-29). Antes isto vivia só no AI Assist (`getAiCreditsInfo()`); o Tradutor é o primeiro consumidor
 * novo e os próximos módulos usam a mesma régua.
 *
 * Régua do direito (a mesma do executor e da tela, como `PluginNextoolBaseModule::hasCloudProductLink()`):
 *   - vínculo do cloud link ATIVO (credencial sincronizada e válida); e
 *   - produto sintético `feature_aiassist_managed` ATIVO no mapa do /validate. O ContainerAPI o publica enquanto o
 *     ambiente tem saldo disponível; o nome ficou do AI Assist, mas o significado é "créditos de IA ativos".
 *
 * O saldo vem do bloco `ai_credits` do /validate (gravado no Sincronizar) e é atualizado pelo `balance_micros` que volta
 * em cada chamada gerenciada (`rememberBalance()`), para a tela não mostrar um saldo velho depois de usar.
 *
 * @since pós-6.49.0 (a versão sai no /release)
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAiCredits {

   /** Produto sintético do ContainerAPI: ativo enquanto o ambiente tem saldo de créditos de IA. */
   public const PRODUCT_MANAGED = 'feature_aiassist_managed';

   /** Página de compra no portal (o bloco `ai_credits` do /validate pode trazer outra em `purchase_url`). */
   public const PORTAL_CREDITS_URL = 'https://app.nextoolsolutions.com/painel/creditos-ia';

   /** Saldo visto na última resposta de uma chamada gerenciada (mais novo que o do Sincronizar). */
   private const LIVE_KEY = 'live_balance';

   /** Vínculo do cloud link ativo neste GLPI? */
   public static function linked(): bool {
      $file = NEXTOOL_PHP_DIR . '/inc/cloudcreds.class.php';
      if (!is_file($file)) {
         return false;
      }
      require_once $file;
      try {
         $cred = PluginNextoolCloudCreds::get();
         return $cred !== null && PluginNextoolCloudCreds::isActive($cred);
      } catch (\Throwable $e) {
         return false;
      }
   }

   /** O ambiente pode usar IA gerenciada agora (vínculo ativo + créditos ativos)? */
   public static function entitled(): bool {
      if (!self::linked()) {
         return false;
      }
      try {
         return PluginNextoolCloudCreds::entitled(self::PRODUCT_MANAGED);
      } catch (\Throwable $e) {
         return false;
      }
   }

   /**
    * Saldo para a tela. null = o ambiente nunca sincronizou o bloco `ai_credits` (base antiga no ContainerAPI ou
    * créditos desligados).
    *
    * @return array{balance_micros:int, available_micros:int, free_remaining_micros:int, free_granted:bool,
    *               balance_brl:string, available_brl:string, purchase_url:string, synced_at:int}|null
    */
   public static function balanceInfo(): ?array {
      $file = NEXTOOL_PHP_DIR . '/inc/licensevalidator.class.php';
      if (!is_file($file)) {
         return null;
      }
      require_once $file;
      try {
         $b = PluginNextoolLicenseValidator::getAiCredits();
      } catch (\Throwable $e) {
         return null;
      }
      if (!is_array($b)) {
         return null;
      }
      $balance   = (int) ($b['balance_micros'] ?? 0);
      $available = (int) ($b['available_micros'] ?? $balance);
      $live = self::liveBalance();
      if ($live !== null && $live['at'] > (int) ($b['synced_at'] ?? 0)) {
         $balance = $available = $live['micros'];
      }
      return [
         'balance_micros'        => $balance,
         'available_micros'      => $available,
         'free_remaining_micros' => (int) ($b['free_remaining_micros'] ?? 0),
         'free_granted'          => !empty($b['free_granted']),
         'balance_brl'           => self::brl($balance),
         'available_brl'         => self::brl($available),
         'purchase_url'          => self::purchaseUrl($b),
         'synced_at'             => (int) ($b['synced_at'] ?? 0),
      ];
   }

   /** URL de compra, aberta na guia Pacotes do portal. */
   public static function purchaseUrl(?array $block = null): string {
      if ($block === null && class_exists('PluginNextoolLicenseValidator')) {
         try {
            $block = PluginNextoolLicenseValidator::getAiCredits();
         } catch (\Throwable $e) {
            $block = null;
         }
      }
      $url = is_array($block) ? (string) ($block['purchase_url'] ?? '') : '';
      $url = $url !== '' ? $url : self::PORTAL_CREDITS_URL;
      return $url . (strpos($url, '?') === false ? '?' : '&') . 'aba=pacotes';
   }

   /** Guarda o saldo que veio numa resposta gerenciada (best-effort). */
   public static function rememberBalance(?int $micros): void {
      if ($micros === null || $micros < 0) {
         return;
      }
      try {
         Config::setConfigurationValues(PluginNextoolLicenseValidator::AI_CREDITS_CONTEXT,
            [self::LIVE_KEY => json_encode(['micros' => $micros, 'at' => time()])]);
      } catch (\Throwable $e) {
         // conveniência da tela
      }
   }

   /** @return array{micros:int, at:int}|null */
   private static function liveBalance(): ?array {
      try {
         $v = Config::getConfigurationValues(PluginNextoolLicenseValidator::AI_CREDITS_CONTEXT, [self::LIVE_KEY]);
         $d = json_decode((string) ($v[self::LIVE_KEY] ?? ''), true);
         if (is_array($d) && isset($d['micros'], $d['at'])) {
            return ['micros' => (int) $d['micros'], 'at' => (int) $d['at']];
         }
      } catch (\Throwable $e) {
         // sem saldo ao vivo
      }
      return null;
   }

   public static function brl(int $micros): string {
      return 'R$ ' . number_format($micros / 1000000, 2, ',', '.');
   }
}
