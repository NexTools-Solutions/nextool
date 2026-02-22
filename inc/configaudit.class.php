<?php
/**
 * Nextools - Config Audit
 *
 * Auditoria de alterações de configuração e licença (glpi_plugin_nextool_main_config_audit).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolConfigAudit extends CommonDBTM {

   public static $rightname = 'config';

   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_main_config_audit';
   }

   public static function log(array $data) {
      global $DB;

      if (!$DB->tableExists(self::getTable())) {
         return false;
      }

      $userId = $data['user_id'] ?? null;
      if ($userId === null && class_exists('Session')) {
         $userId = Session::getLoginUserID();
      }

      $details = $data['details'] ?? null;
      if (is_array($details)) {
         $details = json_encode($details, JSON_UNESCAPED_UNICODE);
      }

      $record = [
         'section'    => $data['section'] ?? 'global',
         'action'     => $data['action'] ?? null,
         'result'     => array_key_exists('result', $data) ? ($data['result'] ? 1 : 0) : null,
         'message'    => $data['message'] ?? null,
         'user_id'    => $userId,
         'source_ip'  => $data['source_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
         'details'    => $details,
      ];

      $audit = new self();
      return $audit->add($record);
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
               echo "<span class='badge bg-green'>" . __('Sucesso', 'nextool') . "</span>";
            } elseif ($result === 0) {
               echo "<span class='badge bg-red'>" . __('Falha', 'nextool') . "</span>";
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


