<?php
/**
 * Cloud link -- amostragem do que uma requisição ANÔNIMA consegue fazer gravar.
 *
 * O `ajax/cloud_exec.php` é público em todo GLPI com a base e autentica por assinatura. Até a 6.61.1,
 * cada recusa gravava uma linha em `plugin_nextool_cloud.log` e, com vínculo, lia e regravava o resumo
 * de saúde em `glpi_configs`: qualquer cliente na internet mandando POST sem assinatura em laço fazia o
 * log crescer sem limite e custava um UPDATE por pedido no banco do GLPI (auditoria de 2026-11-11,
 * LO-01, nextool-dev#312). A entrada ACEITA já tinha teto (HEALTH_TOUCH); a recusada, não.
 *
 * Como funciona: uma JANELA por chave (motivo da recusa). O primeiro evento da janela "abre" e é
 * tratado por inteiro (linha de log, gravação no resumo). Os seguintes só acrescentam um byte a um
 * arquivo em GLPI_TMP_DIR: o tamanho do arquivo é a contagem do que não teve linha própria, e ela é
 * devolvida a quem abrir a janela seguinte (ou a quem pedir `drain()`), então a CONTAGEM não se perde,
 * só a linha por evento. Sem banco e sem log dentro da janela.
 *
 * Fora do banco de propósito: o estado do limitador não pode custar o que ele existe para poupar. Com
 * mais de um servidor web, cada um tem a sua janela (uma linha por servidor por janela).
 *
 * Falha aberta: se o diretório temporário não aceitar escrita, todo evento abre a janela (o
 * comportamento anterior). O limitador nunca interfere na autenticação.
 *
 * @since pós-6.61.1
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudThrottle {

   /** Janela padrão (s): a mesma do teto de regravação da entrada aceita (HEALTH_TOUCH do guard). */
   public const WINDOW = 60;

   /** Teto da contagem por janela: o arquivo não passa disto (1 byte por evento). */
   private const MAX_COUNT = 1000000;

   private const PREFIX = 'nextool_cloud_thr_';

   /**
    * Registra um evento da chave.
    *
    * @return int|null null = dentro da janela (contado, sem linha própria); int = este evento ABRE uma
    *                  janela, e o número é quantos ficaram sem linha desde a abertura anterior
    */
   public static function open(string $chave, int $now = 0, int $janela = self::WINDOW): ?int {
      $arquivo = self::path($chave);
      if ($arquivo === null) {
         return 0;
      }
      $now = $now > 0 ? $now : time();
      try {
         clearstatcache(true, $arquivo);
         $mt = @filemtime($arquivo);
         if ($mt === false) {
            // Primeira vez: criação exclusiva. Quem perde a corrida cai na contagem.
            $h = @fopen($arquivo, 'x');
            if ($h !== false) {
               fclose($h);
               @touch($arquivo, $now);
               return 0;
            }
            if (!is_file($arquivo)) {
               return 0; // diretório sem escrita: falha aberta
            }
         } elseif ($now - $mt >= $janela || $now < $mt) {
            // Virada da janela: só quem pega a trava abre; os demais contam.
            $h = @fopen($arquivo, 'c+');
            if ($h === false) {
               return 0;
            }
            if (flock($h, LOCK_EX | LOCK_NB)) {
               $st = fstat($h);
               $mtAgora = (int) ($st['mtime'] ?? 0);
               if ($now - $mtAgora >= $janela || $now < $mtAgora) {
                  $suprimidos = (int) ($st['size'] ?? 0);
                  ftruncate($h, 0);
                  flock($h, LOCK_UN);
                  fclose($h);
                  @touch($arquivo, $now);
                  return $suprimidos;
               }
               flock($h, LOCK_UN);
            }
            fclose($h);
         }
         // Dentro da janela: 1 byte por evento (append é atômico), até o teto.
         if ((int) @filesize($arquivo) < self::MAX_COUNT) {
            $mtAntes = @filemtime($arquivo);
            @file_put_contents($arquivo, '.', FILE_APPEND);
            if ($mtAntes !== false) {
               @touch($arquivo, $mtAntes); // o append não pode empurrar o início da janela
            }
         }
         return null;
      } catch (\Throwable $e) {
         return 0;
      }
   }

   /**
    * Recolhe o que está contado e ainda não foi devolvido (janelas que ninguém reabriu), zerando.
    * Para quem monta um resumo: a contagem não pode depender de chegar outro evento igual.
    *
    * @return array<string,int> chave (sem o prefixo pedido) => eventos sem linha própria
    */
   public static function drain(string $prefixoChave): array {
      $out = [];
      $base = self::dir();
      if ($base === null) {
         return $out;
      }
      $prefixo = self::PREFIX . self::clean($prefixoChave);
      foreach ((array) @glob($base . '/' . $prefixo . '*.cnt') as $arquivo) {
         try {
            $h = @fopen($arquivo, 'c+');
            if ($h === false) {
               continue;
            }
            if (flock($h, LOCK_EX | LOCK_NB)) {
               $st = fstat($h);
               $n  = (int) ($st['size'] ?? 0);
               if ($n > 0) {
                  ftruncate($h, 0);
                  $out[substr(basename($arquivo, '.cnt'), strlen($prefixo))] = $n;
               }
               flock($h, LOCK_UN);
               fclose($h);
               if ($n > 0 && !empty($st['mtime'])) {
                  @touch($arquivo, (int) $st['mtime']); // a janela em curso segue a mesma
               }
               continue;
            }
            fclose($h);
         } catch (\Throwable $e) {
            // diagnóstico: na falha, a contagem fica para a próxima
         }
      }
      return $out;
   }

   /** Apaga o estado das chaves com o prefixo (E2E: cada caso começa com a janela fechada). */
   public static function reset(string $prefixoChave = ''): void {
      $base = self::dir();
      if ($base === null) {
         return;
      }
      foreach ((array) @glob($base . '/' . self::PREFIX . self::clean($prefixoChave) . '*.cnt') as $arquivo) {
         @unlink($arquivo);
      }
   }

   private static function clean(string $chave): string {
      return substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower($chave)), 0, 60);
   }

   private static function dir(): ?string {
      return defined('GLPI_TMP_DIR') && is_dir(GLPI_TMP_DIR) ? rtrim((string) GLPI_TMP_DIR, '/') : null;
   }

   private static function path(string $chave): ?string {
      $base  = self::dir();
      $chave = self::clean($chave);
      return $base !== null && $chave !== '' ? $base . '/' . self::PREFIX . $chave . '.cnt' : null;
   }
}
