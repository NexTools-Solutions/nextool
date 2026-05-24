<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Config Audit
 * -------------------------------------------------------------------------
 * Auditoria das alterações de configuração/licença do NexTool Solutions,
 * registrando seção, ação, resultado, usuário e detalhes em
 * glpi_plugin_nextool_main_config_audit.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/baseauditlog.class.php';

class PluginNextoolConfigAudit extends PluginNextoolBaseAuditLog {

   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_main_config_audit';
   }

   public static function log(array $data) {
      global $DB;

      if (!$DB->tableExists(self::getTable())) {
         return false;
      }

      $record = [
         'section'    => $data['section'] ?? 'global',
         'action'     => $data['action'] ?? null,
         'result'     => array_key_exists('result', $data) ? ($data['result'] ? 1 : 0) : null,
         'message'    => $data['message'] ?? null,
         'user_id'    => self::resolveUserId($data['user_id'] ?? null),
         'source_ip'  => self::resolveSourceIp($data['source_ip'] ?? null),
         'details'    => self::jsonEncodeIfArray($data['details'] ?? null),
      ];

      $audit = new self();
      $result = $audit->add($record);

      self::recordHistory(
         (string)($data['section'] ?? ''),
         sprintf('%s — %s', $data['action'] ?? '', $data['message'] ?? '')
      );

      return $result;
   }

   public static function showSimpleList() {
      global $DB;

      if (!$DB->tableExists(self::getTable())) {
         echo "<div class='alert alert-warning'>";
         echo "<i class='ti ti-alert-triangle me-2'></i>";
         echo __('Tabela de auditoria de configuração não encontrada. Execute as migrations do plugin.', 'nextool');
         echo "</div>";
         return;
      }

      $iterator = $DB->request([
         'FROM'  => self::getTable(),
         'ORDER' => 'event_date DESC',
         'LIMIT' => 50,
      ]);

      echo "<div class='table-responsive' style='max-height: 400px; overflow-y: auto;'>";
      echo "<table class='table table-sm table-hover table-striped'>";
      echo "<thead class='table-light sticky-top'>";
      echo "<tr>";
      echo "<th>" . __('Data', 'nextool') . "</th>";
      echo "<th>" . __('Seção', 'nextool') . "</th>";
      echo "<th>" . __('Ação', 'nextool') . "</th>";
      echo "<th>" . __('Resultado', 'nextool') . "</th>";
      echo "<th>" . __('Usuário', 'nextool') . "</th>";
      echo "<th>" . __('Mensagem', 'nextool') . "</th>";
      echo "</tr>";
      echo "</thead>";
      echo "<tbody>";

      if (!count($iterator)) {
         echo "<tr><td colspan='6' class='text-center text-muted'>";
         echo __('Nenhum evento registrado.', 'nextool');
         echo "</td></tr>";
      } else {
         foreach ($iterator as $row) {
            $when    = $row['event_date'] ?? null;
            $section = strtoupper($row['section'] ?? '');
            $action  = $row['action'] ?? '';
            $result  = isset($row['result']) ? (int)$row['result'] : null;
            $message = $row['message'] ?? '';
            $userId  = isset($row['user_id']) ? (int)$row['user_id'] : 0;
            $details = $row['details'] ?? null;

            if (class_exists('Html') && !empty($when)) {
               $when = Html::convDateTime($when);
            }

            echo "<tr>";
            echo "<td>" . (!empty($when) ? Html::entities_deep($when) : '-') . "</td>";
            echo "<td><span class='badge bg-indigo-lt'>" . Html::entities_deep($section) . "</span></td>";
            echo "<td>" . ($action !== '' ? Html::entities_deep($action) : '-') . "</td>";
            echo "<td>";
            if ($result === 1) {
               echo "<span class='badge bg-green text-white'>" . __('Sucesso', 'nextool') . "</span>";
            } elseif ($result === 0) {
               echo "<span class='badge bg-red text-white'>" . __('Falha', 'nextool') . "</span>";
            } else {
               echo "-";
            }
            echo "</td>";

            echo "<td>";
            if ($userId > 0) {
               $username = null;
               if (class_exists('User')) {
                  $username = User::getFriendlyNameById($userId);
               }
               echo Html::entities_deep($username ?: sprintf('#%d', $userId));
            } else {
               echo "-";
            }
            echo "</td>";

            echo "<td>";
            echo Html::entities_deep($message);
            if (!empty($details)) {
               $decoded = json_decode($details, true);
               if (is_array($decoded) && count($decoded)) {
                  echo "<br><small class='text-muted'>" . self::formatDetails($decoded) . "</small>";
               }
            }
            echo "</td>";
            echo "</tr>";
         }
      }

      echo "</tbody>";
      echo "</table>";
      echo "</div>";
      echo "<p class='text-muted small mb-0 mt-2'>" . __('Exibindo até os 50 eventos mais recentes.', 'nextool') . "</p>";
   }



   private static function formatDetails(array $details) {
      $chunks = [];
      foreach ($details as $key => $value) {
         if (is_array($value)) {
            $chunks[] = Html::entities_deep($key) . ': ' . Html::entities_deep(json_encode($value));
         } else {
            $chunks[] = Html::entities_deep($key) . ': ' . Html::entities_deep($value);
         }
      }
      return implode(' • ', $chunks);
   }
}


