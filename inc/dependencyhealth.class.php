<?php
/**
 * NexTool -- Politica de saude de dependencia externa (limiar + cooldown).
 *
 * Um modulo que depende de servico de fora (DocuSeal, Autentique, um GLPI remoto,
 * a Evolution) precisa avisar quando ele cai -- sem virar alarme a cada blip de
 * rede nem um aviso por ciclo de cron (288/dia com poll de 5 min). A regra e
 * sempre a mesma e estava reimplementada modulo a modulo, com os mesmos numeros
 * (glpisync, digitalsignature; audit-deep 2026-09-06, nextool-dev#258):
 *
 *   1. LIMIAR   - so avisa apos N falhas consecutivas;
 *   2. COOLDOWN - depois de avisar, silencia por um periodo;
 *   3. dedup_window no sink do consumidor (terceira camada, do outro lado).
 *
 * Esta classe e a POLITICA, pura: recebe o estado anterior, devolve o proximo e
 * a decisao de avisar. Onde o estado mora (coluna por instancia, key-value do
 * modulo) e como o aviso e publicado continuam com o modulo -- e o que varia de
 * verdade entre eles, e o que nao vale abstrair. Sem I/O aqui: da para testar
 * com um array.
 *
 * Uso tipico:
 *
 *    $next = PluginNextoolDependencyHealth::onFailure([
 *       'fail_count'    => $row['poll_fail_count'],
 *       'fail_since'    => $row['poll_fail_since'],
 *       'last_notified' => $row['poll_notified_at'],
 *    ]);
 *    // grava fail_count/fail_since; se $next['should_notify'], publica E grava notified_at
 *
 * @since 6.16.0
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

final class PluginNextoolDependencyHealth {

   /** Falhas consecutivas antes do primeiro aviso (blip de rede nao vira alarme). */
   public const FAIL_THRESHOLD = 3;

   /** Silencio entre avisos da mesma queda (segundos). */
   public const NOTIFY_COOLDOWN = 6 * HOUR_TIMESTAMP;

   /**
    * Mais uma falha. Devolve o proximo estado e se e hora de avisar.
    *
    * @param array{fail_count?:int|string|null, fail_since?:?string, last_notified?:?string} $prev
    *        estado gravado (ausente/nulo = nunca falhou)
    * @param int|null $threshold  override do limiar (default FAIL_THRESHOLD)
    * @param int|null $cooldown   override do cooldown em segundos (default NOTIFY_COOLDOWN)
    * @param int|null $now        timestamp de referencia (testes)
    * @return array{fail_count:int, fail_since:string, first_failure:bool, should_notify:bool}
    */
   public static function onFailure(array $prev, ?int $threshold = null, ?int $cooldown = null, ?int $now = null): array {
      $now   = $now ?? time();
      $count = (int)($prev['fail_count'] ?? 0) + 1;
      $since = trim((string)($prev['fail_since'] ?? ''));
      if ($since === '') {
         $since = date('Y-m-d H:i:s', $now);
      }
      return [
         'fail_count'    => $count,
         'fail_since'    => $since,
         'first_failure' => $count === 1,
         'should_notify' => self::shouldNotify($count, $prev['last_notified'] ?? null, $threshold, $cooldown, $now),
      ];
   }

   /**
    * Limiar + cooldown, isolados para quem ja conta por conta propria.
    *
    * @param string|null $lastNotified Y-m-d H:i:s do ultimo aviso (null/'' = nunca)
    */
   public static function shouldNotify(int $failCount, ?string $lastNotified, ?int $threshold = null, ?int $cooldown = null, ?int $now = null): bool {
      $threshold = $threshold ?? self::FAIL_THRESHOLD;
      $cooldown  = $cooldown ?? self::NOTIFY_COOLDOWN;
      $now       = $now ?? time();

      if ($failCount < $threshold) {
         return false;   // ainda pode ser blip
      }
      $last = trim((string)$lastNotified);
      if ($last === '') {
         return true;
      }
      $lastTs = strtotime($last);
      if ($lastTs === false) {
         return true;    // carimbo ilegivel: melhor avisar do que silenciar para sempre
      }
      return ($now - $lastTs) >= $cooldown;
   }

   /**
    * Dependencia respondeu: estado zerado. `last_notified` tambem cai para a
    * PROXIMA queda avisar na hora, sem herdar o cooldown da anterior.
    *
    * @return array{fail_count:int, fail_since:null, last_notified:null}
    */
   public static function onSuccess(): array {
      return ['fail_count' => 0, 'fail_since' => null, 'last_notified' => null];
   }

   /** Havia falha registrada? (para o modulo evitar 1 UPDATE por ciclo saudavel) */
   public static function wasFailing(array $prev): bool {
      return (int)($prev['fail_count'] ?? 0) > 0;
   }
}
