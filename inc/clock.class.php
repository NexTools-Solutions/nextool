<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Clock (relógio compensado pelo horário do ContainerAPI)
 * -------------------------------------------------------------------------
 * As assinaturas HMAC levam o Unix epoch (igual em qualquer fuso) e o ContainerAPI
 * só as aceita com até 300 s de diferença do relógio dele. Um servidor GLPI com o
 * relógio do SISTEMA errado (VM sem NTP) perdia toda a comunicação sem pista do
 * motivo: caso Softvale, 7h37min atrasado, 247 recusas "Timestamp fora da janela"
 * entre 21/09 e 19/10/2026 com a licença ativa no servidor.
 *
 * Mesmo caminho dos SDKs da AWS no RequestTimeTooSkewed: no 401, o plugin descobre
 * o horário oficial (server_time do code timestamp_skew ou o cabeçalho Date), grava
 * a diferença e repete a chamada uma vez. A proteção contra replay continua no
 * servidor, que compara com o relógio dele; a compensação só muda o que o cliente
 * envia. Corrigido o NTP, a diferença gravada vira 401 de novo e se recalcula (0).
 *
 * Estado em glpi_configs 'plugin:nextool_clock' (sem migração SQL).
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2026 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

final class PluginNextoolClock {

   public const CONTEXT = 'plugin:nextool_clock';

   /** Código do 401 do ContainerAPI para timestamp fora da janela. */
   public const CODE_TIMESTAMP_SKEW = 'timestamp_skew';

   /**
    * Diferença a partir da qual o relógio local é considerado errado. Bem abaixo da
    * janela do servidor (300 s) e acima da imprecisão do cabeçalho Date (1 s) + latência.
    */
   public const TOLERANCE_SECONDS = 60;

   private static ?int $offsetCache = null;

   /** Horário oficial estimado (Unix epoch): relógio local + diferença aprendida. */
   public static function now(): int {
      return time() + self::getOffset();
   }

   /** Diferença gravada (servidor - local), em segundos. 0 = relógio local confiável. */
   public static function getOffset(): int {
      if (self::$offsetCache !== null) {
         return self::$offsetCache;
      }
      $offset = 0;
      try {
         if (class_exists('Config')) {
            $values = Config::getConfigurationValues(self::CONTEXT, ['offset_seconds']);
            $offset = (int) ($values['offset_seconds'] ?? 0);
         }
      } catch (Throwable $e) {
         $offset = 0;
      }
      self::$offsetCache = $offset;
      return $offset;
   }

   /** Quando a diferença foi medida (Unix epoch local); 0 = nunca. */
   public static function getMeasuredAt(): int {
      try {
         $values = Config::getConfigurationValues(self::CONTEXT, ['measured_at']);
         return (int) ($values['measured_at'] ?? 0);
      } catch (Throwable $e) {
         return 0;
      }
   }

   /**
    * Chamado depois de uma resposta do ContainerAPI. Num 401 de horário, aprende a diferença
    * e responde se vale repetir a chamada (com timestamp novo).
    *
    * @param array<string,mixed>|null $data       corpo JSON decodificado da resposta
    * @param string|null              $dateHeader valor do cabeçalho Date da resposta, se o chamador o tiver
    * @param string                   $url        endpoint chamado (HEAD nele quando não há Date nem server_time)
    */
   public static function syncAfterRejection(?int $httpCode, ?array $data, string $url, ?string $dateHeader = null): bool {
      if ($httpCode !== 401) {
         return false;
      }
      $code = is_array($data) ? (string) ($data['code'] ?? '') : '';
      if ($code !== '' && $code !== self::CODE_TIMESTAMP_SKEW) {
         return false; // signature_mismatch, environment_not_provisioned...: não é relógio
      }

      $serverTime = null;
      if (is_array($data) && isset($data['server_time']) && is_numeric($data['server_time'])) {
         $serverTime = (int) $data['server_time'];
      }
      if ($serverTime === null && $dateHeader !== null) {
         $serverTime = self::parseHttpDate($dateHeader);
      }
      if ($serverTime === null && $url !== '') {
         $serverTime = self::fetchServerTime($url);
      }
      if ($serverTime === null) {
         return false;
      }

      return self::learn($serverTime);
   }

   /**
    * Grava a diferença para o horário oficial. Devolve true quando ela mudou o bastante para
    * uma nova tentativa fazer sentido.
    */
   public static function learn(int $serverTime): bool {
      $delta = $serverTime - time();
      $newOffset = abs($delta) > self::TOLERANCE_SECONDS ? $delta : 0;
      $current = self::getOffset();
      if (abs($newOffset - $current) <= self::TOLERANCE_SECONDS) {
         return false;
      }
      self::persist($newOffset);
      if (class_exists('Toolbox')) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            'Clock: relógio deste servidor difere %s do horário do ContainerAPI; compensação %s (antes %d s, agora %d s).',
            self::humanize($delta),
            $newOffset === 0 ? 'removida' : 'aplicada',
            $current,
            $newOffset
         ));
      }
      return true;
   }

   /** "7 h 37 min", "4 min", "45 s" (sem sinal). */
   public static function humanize(int $seconds): string {
      $s = abs($seconds);
      if ($s >= 3600) {
         $h = intdiv($s, 3600);
         $m = intdiv($s % 3600, 60);
         return $m > 0 ? sprintf('%d h %d min', $h, $m) : sprintf('%d h', $h);
      }
      if ($s >= 60) {
         return sprintf('%d min', intdiv($s, 60));
      }
      return sprintf('%d s', $s);
   }

   /** Epoch de um cabeçalho Date (RFC 7231, sempre GMT); null se ilegível. */
   public static function parseHttpDate(string $value): ?int {
      $value = trim($value);
      if ($value === '') {
         return null;
      }
      $dt = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));
      if ($dt === false) {
         $ts = strtotime($value);
         return $ts === false ? null : $ts;
      }
      return $dt->getTimestamp();
   }

   /** HEAD no endpoint só para ler o Date (servidor antigo, sem server_time no 401). */
   private static function fetchServerTime(string $url): ?int {
      if (!function_exists('curl_init')) {
         return null;
      }
      $ch = curl_init($url);
      if ($ch === false) {
         return null;
      }
      $date = null;
      curl_setopt_array($ch, [
         CURLOPT_NOBODY         => true,
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_TIMEOUT        => 10,
         CURLOPT_SSL_VERIFYPEER => true,
         CURLOPT_SSL_VERIFYHOST => 2,
         CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$date): int {
            if (stripos($line, 'Date:') === 0) {
               $date = trim(substr($line, 5));
            }
            return strlen($line);
         },
      ]);
      curl_exec($ch);
      curl_close($ch);
      return $date === null ? null : self::parseHttpDate($date);
   }

   private static function persist(int $offset): void {
      self::$offsetCache = $offset;
      try {
         Config::setConfigurationValues(self::CONTEXT, [
            'offset_seconds' => (string) $offset,
            'measured_at'    => (string) time(),
         ]);
      } catch (Throwable $e) {
         // sem banco (boot parcial): vale só para este processo
      }
   }
}
