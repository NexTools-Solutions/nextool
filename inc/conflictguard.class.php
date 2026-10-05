<?php
declare(strict_types=1);
/**
 * PluginNextoolConflictGuard -- escrita de ator sem a interferencia de Escalade/Behaviors.
 *
 * PROBLEMA
 * Modulos que atribuem grupo ou tecnico ao chamado (smartassign, ticketrules) disparam,
 * no Group_Ticket::add()/Ticket_User::add(), os hooks item_add de dois plugins comuns:
 *  - Escalade: processAfterAddGroup() remove os tecnicos (remove_tech) e cria tarefa de
 *    escalonamento -- mas a atribuicao do modulo NAO e escalonamento;
 *  - Behaviors: single_tech_mode=2 remove os tecnicos ao adicionar grupo e vice-versa.
 *
 * O QUE FAZ
 * Desliga SO esses hooks durante a funcao e restaura no finally. Lista EXPLICITA de
 * conflitos conhecidos, nao supressao geral de terceiros: integracoes legitimas continuam
 * vendo a atribuicao.
 *
 * Protecoes que custaram bug (vieram das copias dos modulos):
 *  - a REMOCAO fica dentro do try: se lancar no meio, o que ja saiu volta no finally, e os
 *    hooks nao ficam desligados ate o fim da requisicao;
 *  - checagem is_array antes do unset: um slot ocupado por STRING
 *    ($PLUGIN_HOOKS['item_add']['x'] = 'func') faria o isset() de offset dar true e o
 *    unset seguinte seria fatal ("Cannot unset string offsets").
 *
 * Promovido para a base em 2026-10-02 (pendencia "Promover withConflictGuard"): havia duas
 * copias identicas, em smartassign (TicketHookHandler) e ticketrules
 * (CategoryParamsApplier). Os modulos migram quando forem tocados, com fallback para a copia
 * local enquanto a base minima exigida por eles nao tiver esta classe.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolConflictGuard {

   /** [hook, plugin, itemtype] desligados durante a escrita de ator. */
   private const TARGETS = [
      ['item_add', 'escalade', 'Group_Ticket'],
      ['pre_item_add', 'escalade', 'Group_Ticket'],
      ['item_add', 'escalade', 'Ticket_User'],
      ['item_add', 'behaviors', 'Group_Ticket'],
      ['item_add', 'behaviors', 'Ticket_User'],
   ];

   /**
    * Executa $fn com os hooks de Escalade/Behaviors sobre atores desligados.
    * Excecoes de $fn sobem normalmente, depois de os hooks serem restaurados.
    */
   public static function run(callable $fn): void {
      global $PLUGIN_HOOKS;

      $saved = [];
      try {
         foreach (self::TARGETS as $i => [$hook, $plugin, $itemtype]) {
            if (!isset($PLUGIN_HOOKS[$hook][$plugin]) || !is_array($PLUGIN_HOOKS[$hook][$plugin])) {
               continue;
            }
            if (!array_key_exists($itemtype, $PLUGIN_HOOKS[$hook][$plugin])) {
               continue;
            }
            $saved[$i] = $PLUGIN_HOOKS[$hook][$plugin][$itemtype];
            unset($PLUGIN_HOOKS[$hook][$plugin][$itemtype]);
         }

         $fn();
      } finally {
         foreach ($saved as $i => $value) {
            [$hook, $plugin, $itemtype] = self::TARGETS[$i];
            $PLUGIN_HOOKS[$hook][$plugin][$itemtype] = $value;
         }
      }
   }
}
