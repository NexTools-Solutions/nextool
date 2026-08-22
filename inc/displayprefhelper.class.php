<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - DisplayPrefHelper
 * -------------------------------------------------------------------------
 * Semeia as preferências de exibição (colunas padrão das grades de Search) de
 * um itemtype de módulo.
 *
 * Motivação (auditoria 2026-08-22, finding HI-04): o mesmo `ensureDisplayPreferences()`
 * estava definido em 54 arquivos, em 26 módulos, mais uma cópia no próprio
 * plugin base. Nenhuma tinha lógica própria - só o mapa de colunas mudava.
 *
 * É UTILITÁRIO ESTÁTICO, não classe-pai, de propósito: as 54 classes têm pais
 * heterogêneos (CommonDBTM, CommonDBChild, PluginNextoolBaseAuditLog), então
 * resolver por herança obrigaria a migrar a cadeia de herança ANTES - invertendo
 * a ordem de risco. Assim cada classe delega em 3 linhas e mantém seu pai, e os
 * ~99 call sites (`Classe::ensureDisplayPreferences()`) não mudam.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

final class PluginNextoolDisplayPrefHelper {

   /**
    * Garante as preferências de exibição GLOBAIS (users_id = 0) de um itemtype.
    *
    * @param string        $itemtype    classe do item (ex.: PluginNextoolBrandingLog)
    * @param array<int,int> $numToRank  mapa search-option => rank (ordem das colunas)
    * @param string|null   $interface   'central'|'helpdesk'. null = coluna omitida.
    *                                   IGNORADO no GLPI 10, que não tem a coluna
    *                                   (verificado nos dois runtimes) - sem este
    *                                   guard, o primeiro módulo dual-major que
    *                                   passasse o parâmetro daria erro de SQL lá.
    * @param bool          $healMissing false (default) = política da maioria:
    *                                   se JÁ existe qualquer linha para o
    *                                   itemtype, não faz nada - respeita quem
    *                                   customizou a grade.
    *                                   true = política do módulo workflow: insere
    *                                   apenas os `num` ausentes, curando grades
    *                                   parciais. As duas são observavelmente
    *                                   diferentes; migrar alguém de false para
    *                                   true é mudança de comportamento, não
    *                                   refatoração.
    */
   public static function ensure(
      string  $itemtype,
      array   $numToRank,
      ?string $interface = null,
      bool    $healMissing = false
   ): void {
      global $DB;

      if (!isset($DB) || $itemtype === '' || $numToRank === []) {
         return;
      }
      $table = 'glpi_displaypreferences';
      if (!$DB->tableExists($table)) {
         return;
      }

      // GLPI 10 não tem a coluna `interface`.
      $useInterface = $interface !== null && $DB->fieldExists($table, 'interface');

      $baseWhere = ['itemtype' => $itemtype, 'users_id' => 0];
      if ($useInterface) {
         $baseWhere['interface'] = $interface;
      }

      if (!$healMissing) {
         // Bail-out: existindo QUALQUER linha, a grade já foi semeada (ou
         // ajustada pelo admin) e não deve ser tocada.
         $existing = $DB->request(['FROM' => $table, 'WHERE' => $baseWhere, 'LIMIT' => 1]);
         if (count($existing) > 0) {
            return;
         }
         foreach ($numToRank as $num => $rank) {
            $DB->insert($table, $baseWhere + ['num' => (int)$num, 'rank' => (int)$rank]);
         }
         return;
      }

      // healMissing: insere só o que falta, por `num`.
      foreach ($numToRank as $num => $rank) {
         $where = $baseWhere + ['num' => (int)$num];
         $found = $DB->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1]);
         if (count($found) > 0) {
            continue;
         }
         $DB->insert($table, $where + ['rank' => (int)$rank]);
      }
   }
}
