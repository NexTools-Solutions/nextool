<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Migration Helper
 * -------------------------------------------------------------------------
 * Executa migrações de schema do GLPI (Migration::executeMigration) SEM vazar
 * a tela de progresso para a UI.
 *
 * Problema: Migration::executeMigration() ecoa "Tarefa concluída. (0 segundo)"
 * (e "Work in progress...") direto no HTML via Migration::outputMessageToHtml
 * (`<p class="center">`). Quando uma migração idempotente do plugin roda em
 * fluxo de background (boot dos módulos, install/uninstall, getDefaultConfig,
 * validação de licença), essa mensagem aparece TRAVADA no topo da página --
 * no GLPI 10, onde essas mensagens não são toasts.
 *
 * Solução: capturar o output com um output-handler que retorna ''. Diferente de
 * um ob_start() simples, o handler é IMUNE ao ob_flush() interno que a Migration
 * faz (Html::glpi_flush) -- um ob_start() sem handler seria esvaziado para o
 * cliente ANTES do ob_end_clean(). As queries de schema rodam normalmente; só o
 * eco visual é descartado.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://github.com/RPGMais/nextool
 * @license GPLv3+
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolMigrationHelper {

   /**
    * Executa a migração descartando qualquer output HTML (tela de progresso).
    *
    * @param Migration $migration Migração já preparada (addField/addKey etc.)
    * @return void
    */
   public static function runSilently(Migration $migration): void {
      ob_start(static function () {
         return '';
      });
      try {
         $migration->executeMigration();
      } finally {
         // Fecha o buffer sem enviar o que sobrou (o handler já descartou o que
         // foi flushado durante a execução).
         if (ob_get_level() > 0) {
            @ob_end_clean();
         }
      }
   }
}
