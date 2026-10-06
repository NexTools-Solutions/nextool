<?php
/**
 * NexTool -- contexto de execução de uma rotina (orçamento de tempo, log e volume) (nextool-dev#281).
 *
 * Usado pelo dispatcher de rotinas (`PluginNextoolJobDispatcher`) e reutilizável por qualquer CronTask
 * própria: `PluginNextoolJobContext::forTask($task, 40)`. O orçamento é COOPERATIVO (PHP não interrompe
 * uma função no meio): a rotina confere `timeLeft()`/`expired()` entre unidades de trabalho e repassa
 * `remainingTimeout()` aos clientes HTTP, para que nenhuma chamada passe do prazo.
 *
 * Expõe `log()` e `addVolume()` com a mesma assinatura do CronTask, então código que hoje recebe o
 * `$task` (ex.: `PluginNextoolTelegrambotOutbox::drain($task)`) aceita o contexto sem mudança.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolJobContext {

   private float $deadline;
   private ?CronTask $task;
   private string $prefix;
   private int $volume = 0;
   private string $lastMessage = '';

   private function __construct(float $deadline, ?CronTask $task, string $prefix = '') {
      $this->deadline = $deadline;
      $this->task     = $task;
      $this->prefix   = $prefix;
   }

   /** Contexto com `$seconds` de orçamento a partir de agora, ligado à CronTask (ou sem task, no CLI/botão). */
   public static function forTask(?CronTask $task, float $seconds): self {
      return new self(microtime(true) + max(0.0, $seconds), $task);
   }

   /** Contexto com prazo absoluto (`microtime(true)` + segundos), para repassar um prazo já calculado. */
   public static function withDeadline(float $deadline, ?CronTask $task = null): self {
      return new self($deadline, $task);
   }

   /**
    * Contexto filho com o MESMO prazo e a mesma task, prefixando as mensagens (`[telegrambot/outbox] ...`).
    * O volume e a última mensagem do filho são próprios; o volume também soma na task.
    */
   public function scoped(string $prefix): self {
      return new self($this->deadline, $this->task, $prefix);
   }

   public function deadline(): float {
      return $this->deadline;
   }

   /** Segundos que restam (negativo depois do prazo). */
   public function timeLeft(): float {
      return $this->deadline - microtime(true);
   }

   /** Verdadeiro quando resta `$margin` segundos ou menos: não começar unidade nova de trabalho. */
   public function expired(float $margin = 2.0): bool {
      return $this->timeLeft() <= $margin;
   }

   /** Timeout (s) para uma chamada externa: o menor entre `$max` e o que resta, nunca menos que 1. */
   public function remainingTimeout(int $max): int {
      return max(1, min($max, (int) floor($this->timeLeft())));
   }

   /** Mesmo contrato do `CronTask::log()`. Sem task, vai para o log `plugin_nextool`. */
   public function log($message): void {
      $message = (string) $message;
      $this->lastMessage = $message;
      $linha = $this->prefix !== '' ? '[' . $this->prefix . '] ' . $message : $message;
      if ($this->task !== null) {
         $this->task->log($linha);
      } elseif (class_exists('Toolbox')) {
         Toolbox::logInFile('plugin_nextool', 'jobs: ' . $linha . "\n");
      }
   }

   /** Mesmo contrato do `CronTask::addVolume()`. */
   public function addVolume($volume): void {
      $volume = (int) $volume;
      $this->volume += $volume;
      if ($this->task !== null) {
         $this->task->addVolume($volume);
      }
   }

   public function getVolume(): int {
      return $this->volume;
   }

   /** Última mensagem registrada por `log()` neste contexto (vai para a tela de rotinas). */
   public function lastMessage(): string {
      return $this->lastMessage;
   }
}
