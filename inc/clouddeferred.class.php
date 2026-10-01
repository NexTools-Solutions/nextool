<?php
/**
 * Cloud link -- trabalho de rede que não pode atrasar a resposta de quem pediu.
 *
 * Dois casos usam (contrato 1.1): o /validate de ressincronização pelo kid, pedido por uma recusa do
 * `exec` (PluginNextoolCloudResync), e a redeclaração do endereço depois de o /validate trocar a
 * credencial, pedida no Sincronizar da tela (PluginNextoolCloudClient). Os dois levam até 15 s na
 * rede, e quem os pediu espera uma resposta rápida: o cérebro (timeout de 20 s no `exec`) ou a pessoa
 * na tela.
 *
 * Como: uma função de encerramento solta a resposta HTTP primeiro e só então roda as tarefas, na
 * ordem em que foram pedidas (tarefa pedida durante outra também roda). No PHP-FPM, pelo
 * `fastcgi_finish_request()`; no LiteSpeed, pelo equivalente; no cron e no console, no fim do
 * processo, onde não há resposta a proteger. Servidor que não sabe soltar a resposta antes (Apache
 * com mod_php, CGI) não roda a tarefa: roda a alternativa dela, se houver, que só pode marcar para o
 * próximo ciclo. Uma tarefa por chave e por processo.
 *
 * @since pós-6.24.1 (contrato 1.1 do cloud link, K1)
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudDeferred {

   /** @var array<string, array{0:callable, 1:?callable}> tarefa e alternativa, por chave */
   private static $tarefas = [];

   /** @var bool a função de encerramento já foi registrada neste processo */
   private static $registrado = false;

   /** @var bool|null a resposta já foi solta (null = ainda não se tentou) */
   private static $solta = null;

   /**
    * Agenda uma tarefa para depois da resposta. Barato de propósito: roda no caminho de uma recusa do
    * `exec`, cujo tempo não pode diferir do de qualquer outra recusa.
    *
    * @param string        $chave      uma tarefa por chave neste processo (a segunda é ignorada)
    * @param callable      $tarefa     o trabalho de rede
    * @param callable|null $semSoltar  o que fazer se este servidor não souber soltar a resposta antes
    */
   public static function add(string $chave, callable $tarefa, ?callable $semSoltar = null): void {
      if (isset(self::$tarefas[$chave])) {
         return;
      }
      self::$tarefas[$chave] = [$tarefa, $semSoltar];
      if (!self::$registrado) {
         self::$registrado = true;
         register_shutdown_function([self::class, 'run']);
      }
   }

   /** Há tarefa agendada com esta chave? (E2E e diagnóstico.) */
   public static function has(string $chave): bool {
      return isset(self::$tarefas[$chave]);
   }

   /**
    * Desiste de uma tarefa. Para o E2E: um teste que provoca o agendamento não pode terminar numa
    * chamada de rede de verdade quando o processo acabar.
    */
   public static function cancel(string $chave): void {
      unset(self::$tarefas[$chave]);
   }

   /** Função de encerramento. Pública só porque o PHP a chama de fora. */
   public static function run(): void {
      while (self::$tarefas !== []) {
         $chave = (string) array_key_first(self::$tarefas);
         [$tarefa, $semSoltar] = self::$tarefas[$chave];
         unset(self::$tarefas[$chave]);
         try {
            if (self::releaseResponse()) {
               $tarefa();
            } elseif ($semSoltar !== null) {
               $semSoltar();
            }
         } catch (\Throwable $e) {
            if (class_exists('Toolbox')) {
               Toolbox::logInFile('plugin_nextool_cloud', sprintf("[CLOUD] tarefa %s falhou depois da resposta: %s\n", $chave, get_class($e)));
            }
         }
      }
   }

   /** Solta a resposta HTTP (uma vez por processo). false = este servidor não sabe fazer isso. */
   private static function releaseResponse(): bool {
      if (self::$solta !== null) {
         return self::$solta;
      }
      if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
         return self::$solta = true; // cron e console: não há resposta a proteger
      }
      if (function_exists('fastcgi_finish_request')) {
         fastcgi_finish_request();
      } elseif (function_exists('litespeed_finish_request')) {
         litespeed_finish_request();
      } else {
         return self::$solta = false;
      }
      ignore_user_abort(true);
      // Sessão de quem estava na tela: sem soltá-la, a próxima página dessa pessoa esperaria a tarefa.
      if (session_status() === PHP_SESSION_ACTIVE) {
         session_write_close();
      }
      if (function_exists('set_time_limit')) {
         @set_time_limit(120);
      }

      return self::$solta = true;
   }
}
