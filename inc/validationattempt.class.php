<?php
/**
 * Nextools - License Validation Attempts
 *
 * Classe de tentativas de validação de licença (camada operacional).
 * Armazena histórico das chamadas à API administrativa (ContainerAPI):
 * data/hora, resultado, mensagem, código HTTP, tempo de resposta.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolValidationAttempt extends CommonDBTM {

   public static $rightname = 'config';

   /**
    * Nome da tabela
    */
   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_main_validation_attempts';
   }

   /**
    * Nome do tipo (usado em logs e exibição)
    */
   public static function getTypeName($nb = 0) {
      return _n('Tentativa de Validação', 'Tentativas de Validação', $nb, 'nextool');
   }

   /**
    * Registra uma tentativa de validação
    *
    * @param array $data
    *   - result (bool|int)
    *   - message (string)
    *   - http_code (int|null)
    *   - response_time_ms (int|null)
    *   - origin (string)
    *   - requested_modules (array|string|null)
    *   - client_identifier (string)
    *   - license_status (string)
    *   - contract_active (bool|int|null)
    *   - plan (string)
    *   - force_refresh (bool|int)
    *   - cache_hit (bool|int)
    *   - user_id (int)
    */
   public static function logAttempt(array $data) {
      global $DB;

      // Se a tabela ainda não existir (ambiente que não rodou as migrations de licenciamento),
      // não tentamos registrar nada para evitar erros de instalação/primeira execução.
      if (!$DB->tableExists(self::getTable())) {
         return false;
      }

      $attempt = new self();

      $requestedModules = $data['requested_modules'] ?? null;
      if (is_array($requestedModules)) {
         $requestedModules = json_encode(array_values($requestedModules));
      }

      $input = [
         'result'           => !empty($data['result']) ? 1 : 0,
         'message'          => $data['message'] ?? null,
         'http_code'        => isset($data['http_code']) ? (int)$data['http_code'] : null,
         'response_time_ms' => isset($data['response_time_ms']) ? (int)$data['response_time_ms'] : null,
         'origin'           => isset($data['origin']) ? substr((string)$data['origin'], 0, 64) : null,
         'requested_modules'=> $requestedModules,
         'client_identifier'=> $data['client_identifier'] ?? null,
         'license_status'   => isset($data['license_status']) ? substr((string)$data['license_status'], 0, 32) : null,
         'contract_active'  => array_key_exists('contract_active', $data)
            ? ($data['contract_active'] === null ? null : (!empty($data['contract_active']) ? 1 : 0))
            : null,
         'plan'             => isset($data['plan']) ? substr((string)$data['plan'], 0, 32) : null,
         'allowed_modules'  => isset($data['allowed_modules']) ? (string)$data['allowed_modules'] : null,
         'force_refresh'    => !empty($data['force_refresh']) ? 1 : 0,
         'cache_hit'        => !empty($data['cache_hit']) ? 1 : 0,
         'user_id'          => isset($data['user_id']) ? (int)$data['user_id'] : null,
      ];

      return $attempt->add($input);
   }

   /**
    * Exibe uma listagem simples das tentativas de validação
    * (similar ao que o plugin administrativo faz em ritecadmin)
    */
   public static function showSimpleList() {
      global $DB;

      if (!$DB->tableExists(self::getTable())) {
         echo "<div class='alert alert-warning'>";
         echo "<i class='ti ti-alert-triangle me-2'></i>";
         echo __('Tabela de tentativas de validação não encontrada. Verifique se as migrations de licenciamento foram executadas.', 'nextool');
         echo "</div>";
         return;
      }

      $iterator = $DB->request([
         'FROM'  => self::getTable(),
         'ORDER' => 'attempt_date DESC',
         'LIMIT' => 100
      ]);

      echo "<div class='table-responsive' style='max-height: 500px; overflow-y: auto;'>";
      echo "<table class='table table-sm table-hover table-striped'>";
      echo "<thead class='table-light sticky-top'>";
      echo "<tr>";
      echo "<th>" . __('Data/Hora', 'nextool') . "</th>";
      echo "<th>" . __('Resultado', 'nextool') . "</th>";
      echo "<th>" . __('Código HTTP', 'nextool') . "</th>";
      echo "<th>" . __('Tempo (ms)', 'nextool') . "</th>";
      echo "<th>" . __('Origem', 'nextool') . "</th>";
      echo "<th>" . __('Status / Plano', 'nextool') . "</th>";
      echo "<th>" . __('Usuário', 'nextool') . "</th>";
      echo "<th>" . __('Mensagem', 'nextool') . "</th>";
      echo "</tr>";
      echo "</thead>";
      echo "<tbody>";

      if (!count($iterator)) {
         echo "<tr>";
         echo "<td colspan='5' class='text-center text-muted'>";
         echo __('Nenhuma tentativa de validação registrada até o momento.', 'nextool');
         echo "</td>";
         echo "</tr>";
      } else {
         foreach ($iterator as $row) {
            $when    = $row['attempt_date'] ?? null;
            $result  = isset($row['result']) ? (int)$row['result'] : null;
            $http    = $row['http_code'] ?? null;
            $timeMs  = $row['response_time_ms'] ?? null;
            $message = $row['message'] ?? '';
            $origin   = $row['origin'] ?? '';
            $plan     = $row['plan'] ?? '';
            $status   = $row['license_status'] ?? '';
            $contract = $row['contract_active'] ?? null;
            $userId   = isset($row['user_id']) ? (int)$row['user_id'] : 0;

            if (class_exists('Html') && !empty($when)) {
               $when = Html::convDateTime($when);
            }

            echo "<tr>";

            // Data/Hora
            echo "<td>" . (!empty($when) ? Html::entities_deep($when) : '-') . "</td>";

            // Resultado
            echo "<td>";
            if ($result === 1) {
               echo "<span class='badge bg-green'>" . __('Válida', 'nextool') . "</span>";
            } elseif ($result === 0) {
               echo "<span class='badge bg-red'>" . __('Inválida', 'nextool') . "</span>";
            } else {
               echo "-";
            }
            echo "</td>";

            // Código HTTP
            echo "<td>";
            if ($http !== null && $http !== '') {
               echo Html::entities_deep($http);
            } else {
               echo "-";
            }
            echo "</td>";

            // Tempo de resposta
            echo "<td>";
            if ($timeMs !== null && $timeMs !== '') {
               echo Html::entities_deep($timeMs);
            } else {
               echo "-";
            }
            echo "</td>";

            // Origem
            echo "<td>";
            echo $origin !== '' ? Html::entities_deep($origin) : '-';
            if (!empty($row['client_identifier'])) {
               echo "<br><small class='text-muted'>" . Html::entities_deep($row['client_identifier']) . "</small>";
            }
            echo "</td>";

            // Status / Plano
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

            // Usuário
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

            // Mensagem
            echo "<td>";
            echo Html::entities_deep($message);

            $extras = [];
            if (!empty($row['requested_modules'])) {
               $modules = json_decode($row['requested_modules'], true);
               if (is_array($modules) && count($modules)) {
                  $modulesLabels = array_map(function ($module) {
                     return Html::entities_deep($module);
                  }, $modules);
                  $extras[] = __('Módulos solicitados:', 'nextool') . ' ' . implode(', ', $modulesLabels);
               }
            }
            if (!empty($row['force_refresh'])) {
               $extras[] = __('Forçou refresh remoto', 'nextool');
            }
            if (!empty($row['cache_hit'])) {
               $extras[] = __('Resposta via cache', 'nextool');
            }

            if (count($extras)) {
               echo "<br><small class='text-muted'>" . implode(' • ', $extras) . "</small>";
            }
            echo "</td>";

            echo "</tr>";
         }
      }

      echo "</tbody>";
      echo "</table>";
      echo "</div>";
      echo "<p class='text-muted small mb-0 mt-2'>" . __('Exibindo até as 100 tentativas mais recentes.', 'nextool') . "</p>";
   }
}


