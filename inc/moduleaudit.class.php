<?php
/**
 * Nextools - Module Audit
 *
 * Auditoria de ações em módulos (instalação, ativação, dados) em glpi_plugin_nextool_main_module_audit.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolModuleAudit extends CommonDBTM {

   public static $rightname = 'config';

   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_main_module_audit';
   }

   /**
    * Registra uma ação de módulo
    *
    * @param array $data
    */
   public static function log(array $data) {
      global $DB;

      if (!$DB->tableExists(self::getTable())) {
         return false;
      }

      $userId = $data['user_id'] ?? null;
      if ($userId === null && class_exists('Session')) {
         $userId = Session::getLoginUserID();
      }

      $allowedModules = $data['allowed_modules'] ?? null;
      if (is_array($allowedModules)) {
         $allowedModules = json_encode(array_values($allowedModules));
      }

      $requestedModules = $data['requested_modules'] ?? null;
      if (is_array($requestedModules)) {
         $requestedModules = json_encode(array_values($requestedModules));
      }

      $record = [
         'module_key'       => $data['module_key'] ?? null,
         'action'           => $data['action'] ?? null,
         'result'           => !empty($data['result']) ? 1 : 0,
         'message'          => $data['message'] ?? null,
         'user_id'          => $userId,
         'origin'           => isset($data['origin']) ? substr((string)$data['origin'], 0, 64) : null,
         'source_ip'        => $data['source_ip'] ?? null,
         'license_status'   => isset($data['license_status']) ? substr((string)$data['license_status'], 0, 32) : null,
         'contract_active'  => array_key_exists('contract_active', $data)
            ? ($data['contract_active'] === null ? null : (!empty($data['contract_active']) ? 1 : 0))
            : null,
         'plan'             => isset($data['plan']) ? substr((string)$data['plan'], 0, 32) : null,
         'allowed_modules'  => $allowedModules,
         'requested_modules'=> $requestedModules,
      ];

      $audit = new self();
      return $audit->add($record);
   }

   /**
    * Exibe tabela resumida
    */
   public static function showSimpleList() {
      global $DB;

      if (!$DB->tableExists(self::getTable())) {
         echo "<div class='alert alert-warning'>";
         echo "<i class='ti ti-alert-triangle me-2'></i>";
         echo __('Tabela de auditoria de módulos não encontrada. Execute as migrations do plugin.', 'nextool');
         echo "</div>";
         return;
      }

      $iterator = $DB->request([
         'FROM'  => self::getTable(),
         'ORDER' => 'action_date DESC',
         'LIMIT' => 50,
      ]);

      echo "<div class='table-responsive' style='max-height: 400px; overflow-y: auto;'>";
      echo "<table class='table table-sm table-hover table-striped'>";
      echo "<thead class='table-light sticky-top'>";
      echo "<tr>";
      echo "<th>" . __('Data', 'nextool') . "</th>";
      echo "<th>" . __('Módulo', 'nextool') . "</th>";
      echo "<th>" . __('Ação', 'nextool') . "</th>";
      echo "<th>" . __('Resultado', 'nextool') . "</th>";
      echo "<th>" . __('Usuário / Origem', 'nextool') . "</th>";
      echo "<th>" . __('Licença', 'nextool') . "</th>";
      echo "<th>" . __('Mensagem', 'nextool') . "</th>";
      echo "</tr>";
      echo "</thead>";
      echo "<tbody>";

      if (!count($iterator)) {
         echo "<tr><td colspan='7' class='text-center text-muted'>";
         echo __('Nenhuma ação registrada ainda.', 'nextool');
         echo "</td></tr>";
      } else {
         foreach ($iterator as $row) {
            $when      = $row['action_date'] ?? null;
            $moduleKey = $row['module_key'] ?? '';
            $action    = strtoupper($row['action'] ?? '');
            $result    = isset($row['result']) ? (int)$row['result'] : null;
            $message   = $row['message'] ?? '';
            $origin    = $row['origin'] ?? '';
            $sourceIp  = $row['source_ip'] ?? '';
            $plan      = $row['plan'] ?? '';
            $status    = $row['license_status'] ?? '';
            $contract  = $row['contract_active'] ?? null;
            $userId    = isset($row['user_id']) ? (int)$row['user_id'] : 0;

            if (class_exists('Html') && !empty($when)) {
               $when = Html::convDateTime($when);
            }

            echo "<tr>";
            echo "<td>" . (!empty($when) ? Html::entities_deep($when) : '-') . "</td>";
            echo "<td><span class='fw-semibold'>" . Html::entities_deep($moduleKey) . "</span></td>";
            echo "<td><span class='badge bg-gray-lt'>" . Html::entities_deep($action) . "</span></td>";
            echo "<td>";
            if ($result === 1) {
               echo "<span class='badge bg-green'>" . __('Sucesso', 'nextool') . "</span>";
            } elseif ($result === 0) {
               echo "<span class='badge bg-red'>" . __('Falhou', 'nextool') . "</span>";
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
            if ($origin !== '') {
               echo "<br><small class='text-muted'>" . Html::entities_deep($origin) . "</small>";
            }
            if ($sourceIp !== '') {
               echo "<br><small class='text-muted'>" . Html::entities_deep($sourceIp) . "</small>";
            }
            echo "</td>";

            echo "<td>";
            if ($status !== '') {
               echo "<span class='badge bg-secondary me-1'>" . Html::entities_deep($status) . "</span>";
            }
            if ($plan !== '') {
               echo "<span class='badge bg-blue-lt me-1'>" . Html::entities_deep($plan) . "</span>";
            }
            if ($contract !== null) {
               $label = $contract ? __('Contrato ativo', 'nextool') : __('Contrato inativo', 'nextool');
               $class = $contract ? 'bg-green' : 'bg-red';
               echo "<span class='badge {$class}'>" . Html::entities_deep($label) . "</span>";
            }
            echo "</td>";

            echo "<td>";
            echo Html::entities_deep($message);

            $extraLines = [];
            if (!empty($row['allowed_modules'])) {
               $allowed = json_decode($row['allowed_modules'], true);
               if (is_array($allowed) && count($allowed)) {
                  $allowedLabels = array_map(function ($value) {
                     return Html::entities_deep($value);
                  }, $allowed);
                  $extraLines[] = __('Módulos permitidos:', 'nextool') . ' ' . implode(', ', $allowedLabels);
               }
            }
            if (!empty($row['requested_modules'])) {
               $req = json_decode($row['requested_modules'], true);
               if (is_array($req) && count($req)) {
                  $reqLabels = array_map(function ($value) {
                     return Html::entities_deep($value);
                  }, $req);
                  $extraLines[] = __('Módulos solicitados:', 'nextool') . ' ' . implode(', ', $reqLabels);
               }
            }

            if (count($extraLines)) {
               echo "<br><small class='text-muted'>" . implode(' • ', $extraLines) . "</small>";
            }

            echo "</td>";
            echo "</tr>";
         }
      }

      echo "</tbody>";
      echo "</table>";
      echo "</div>";
      echo "<p class='text-muted small mb-0 mt-2'>" . __('Exibindo até as 50 ações mais recentes.', 'nextool') . "</p>";
   }
}


