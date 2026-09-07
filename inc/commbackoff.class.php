<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Comm Backoff (falhas de autenticação com o ContainerAPI)
 * -------------------------------------------------------------------------
 * Backoff exponencial COMPARTILHADO POR AMBIENTE (issue #243, pós-incidente
 * smagalhaes: plugin 6.6.1 martelou 401 a cada ~3s por 36h). Estado no context
 * glpi_configs 'plugin:nextool_comm_state' (Config::setConfigurationValues) --
 * vale para todas as abas/sessões desta instância GLPI, sem migração SQL.
 *
 * Semântica:
 * - FALHA DE AUTH (401 sempre; 403 SEM license_status no body) é determinística
 *   (só muda com ação no servidor) -> entra na escada [3s,10s,30s,2min,15min].
 * - 403 COM license_status é negação de NEGÓCIO (SUSPENDED/CANCELLED): a
 *   comunicação funcionou -> conta como sucesso da COMUNICAÇÃO.
 * - Rede/timeout/5xx é transitório -> escada PRÓPRIA e mais curta (#245:
 *   [30s,1min,2min,5min,10min]) -- num incidente prolongado do servidor a cron
 *   e o polling da UI não martelam; a linha do hero mostra a ETA (#244).
 * - Qualquer sucesso zera as duas escadas; falha de AUTH zera a de rede (a
 *   comunicação chegou ao servidor).
 * - Ação MANUAL explícita (Sincronizar, aceitar políticas, gerar código de
 *   vínculo, desvincular) FURA a supressão (bypass_comm_backoff) mas o
 *   resultado é registrado; cron e polling nunca furam.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2026 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

final class PluginNextoolCommBackoff {

   public const CONTEXT = 'plugin:nextool_comm_state';

   /** Escada de supressão de AUTH (segundos). Teto 15min => ~4 tentativas/h em regime. */
   private const LADDER = [3, 10, 30, 120, 900];

   /**
    * Escada de supressão de REDE/5xx (#245). Mais curta que a de auth: a queda é
    * transitória por natureza e o primeiro degrau não pode esconder um blip. O teto
    * (10min) coincide com o cache negativo de licença -- em regime, o servidor caído
    * recebe ~6 tentativas/h deste ambiente, em vez de uma por request/polling.
    */
   private const NET_LADDER = [30, 60, 120, 300, 600];

   /**
    * Supressão ativa? null = liberado; senão detalhes para a UI/logs. Quando as
    * duas janelas (auth e rede) estão abertas, vale a que fecha por último.
    *
    * @return array{kind:string, retry_in:int, until:int, streak:int, last_error_code:?string}|null
    */
   public static function shouldSuppress(): ?array {
      $state = self::getState();
      $now = time();
      $authUntil = (int) ($state['auth_suppress_until'] ?? 0);
      $netUntil  = (int) ($state['net_suppress_until'] ?? 0);
      if ($authUntil <= $now && $netUntil <= $now) {
         return null;
      }
      $kind  = $authUntil >= $netUntil ? 'auth' : 'network';
      $until = max($authUntil, $netUntil);
      return [
         'kind'            => $kind,
         'retry_in'        => $until - $now,
         'until'           => $until,
         'streak'          => (int) ($state[$kind === 'auth' ? 'auth_streak' : 'net_streak'] ?? 0),
         'last_error_code' => isset($state['last_error_code']) && $state['last_error_code'] !== ''
            ? (string) $state['last_error_code']
            : null,
      ];
   }

   /** Falha de AUTH confirmada: sobe a escada e agenda a próxima janela (a de rede zera: o servidor respondeu). */
   public static function registerAuthFailure(?int $httpCode, ?string $errorCode): void {
      $state = self::getState();
      $streak = (int) ($state['auth_streak'] ?? 0) + 1;
      $delay = self::LADDER[min($streak, count(self::LADDER)) - 1];
      self::persist([
         'auth_streak'          => (string) $streak,
         'auth_suppress_until'  => (string) (time() + $delay),
         'last_auth_failure_at' => (string) time(),
         'last_error_code'      => (string) ($errorCode ?? ''),
         'last_http_code'       => (string) ($httpCode ?? ''),
         'net_streak'           => '0',
         'net_suppress_until'   => '0',
      ]);
   }

   /** Falha de REDE/5xx (#245): sobe a escada curta de rede; a de auth não muda. */
   public static function registerNetworkFailure(?int $httpCode, string $message = ''): void {
      $state = self::getState();
      $streak = (int) ($state['net_streak'] ?? 0) + 1;
      $delay = self::NET_LADDER[min($streak, count(self::NET_LADDER)) - 1];
      self::persist([
         'net_streak'              => (string) $streak,
         'net_suppress_until'      => (string) (time() + $delay),
         'last_network_failure_at' => (string) time(),
         'last_http_code'          => (string) ($httpCode ?? ''),
         'last_network_error'      => mb_substr($message, 0, 255),
      ]);
   }

   /** Comunicação OK (inclui negação de negócio): zera as duas escadas. */
   public static function registerSuccess(?int $httpCode = null): void {
      self::persist([
         'auth_streak'         => '0',
         'auth_suppress_until' => '0',
         'net_streak'          => '0',
         'net_suppress_until'  => '0',
         'last_error_code'     => '',
         'last_http_code'      => (string) ($httpCode ?? ''),
         'last_comm_ok_at'     => (string) time(),
      ]);
   }

   /**
    * O que conta como falha de AUTH: 401 sempre; 403 só SEM license_status no
    * body (403 com license_status = negação de negócio, comunicação OK --
    * nuance já reconhecida em config.save.php).
    */
   public static function isAuthFailure(?int $httpCode, ?array $responseData): bool {
      if ($httpCode === 401) {
         return true;
      }
      if ($httpCode === 403) {
         return empty($responseData['license_status']);
      }
      return false;
   }

   /** Dump do estado (ConfigViewState/hero leem daqui). */
   public static function getState(): array {
      try {
         $values = Config::getConfigurationValues(self::CONTEXT);
         return is_array($values) ? $values : [];
      } catch (Throwable $e) {
         return [];
      }
   }

   private static function persist(array $values): void {
      try {
         Config::setConfigurationValues(self::CONTEXT, $values);
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            'CommBackoff: falha ao persistir estado - %s',
            $e->getMessage()
         ));
      }
   }
}
