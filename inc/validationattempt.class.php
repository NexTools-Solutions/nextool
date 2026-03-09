<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - License Validation Attempts
 * -------------------------------------------------------------------------
 * Classe de tentativas de validação de licença do NexTool Solutions
 * (camada operacional).
 *
 * Armazena histórico das chamadas à API administrativa (via ContainerAPI):
 * - data/hora
 * - resultado
 * - mensagem
 * - código HTTP
 * - tempo de resposta
 *
 * Exibição via Search::show() nativo do GLPI (grade com filtros, ordenação
 * e paginação).
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

use Glpi\Search\DefaultSearchRequestInterface;

class PluginNextoolValidationAttempt extends CommonDBTM implements DefaultSearchRequestInterface {

   public static $rightname = 'config';
   public $dont_pass_handled = true;

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

   public static function getIcon() {
      return 'ti ti-report-analytics';
   }

   public static function getSearchURL($full = true) {
      if (!empty($GLOBALS['nextool_validation_attempts_forcetab_url'])) {
         return $GLOBALS['nextool_validation_attempts_forcetab_url'];
      }
      return Plugin::getWebDir('nextool')
         . '/front/nextoolconfig.form.php?id=1&forcetab=PluginNextoolMainConfig$4';
   }

   public static function getDefaultSearchRequest(): array {
      return [
         'sort'  => 2,
         'order' => 'DESC',
      ];
   }

   public function rawSearchOptions() {
      $tab = [];

      $tab[] = [
         'id'   => 'common',
         'name' => __('Tentativas de Validação', 'nextool'),
      ];

      $tab[] = [
         'id'            => '1',
         'table'         => $this->getTable(),
         'field'         => 'id',
         'name'          => __('ID', 'nextool'),
         'searchtype'    => ['equals', 'notequals'],
         'datatype'      => 'number',
         'massiveaction' => false,
      ];

      $tab[] = [
         'id'            => '2',
         'table'         => $this->getTable(),
         'field'         => 'attempt_date',
         'name'          => __('Data', 'nextool'),
         'searchtype'    => ['contains', 'equals', 'notequals'],
         'datatype'      => 'datetime',
         'massiveaction' => false,
      ];

      $tab[] = [
         'id'               => '3',
         'table'            => $this->getTable(),
         'field'            => 'result',
         'name'             => __('Resultado', 'nextool'),
         'searchtype'       => ['equals', 'notequals'],
         'datatype'         => 'specific',
         'massiveaction'    => false,
         'additionalfields' => ['plan', 'http_code'],
      ];

      $tab[] = [
         'id'            => '4',
         'table'         => $this->getTable(),
         'field'         => 'http_code',
         'name'          => __('Código HTTP', 'nextool'),
         'searchtype'    => ['equals', 'notequals'],
         'datatype'      => 'number',
         'massiveaction' => false,
      ];

      $tab[] = [
         'id'            => '5',
         'table'         => $this->getTable(),
         'field'         => 'response_time_ms',
         'name'          => __('Tempo (ms)', 'nextool'),
         'searchtype'    => ['equals', 'notequals'],
         'datatype'      => 'number',
         'massiveaction' => false,
      ];

      $tab[] = [
         'id'            => '6',
         'table'         => $this->getTable(),
         'field'         => 'origin',
         'name'          => __('Origem', 'nextool'),
         'searchtype'    => ['contains', 'equals'],
         'datatype'      => 'string',
         'massiveaction' => false,
      ];

      $tab[] = [
         'id'            => '7',
         'table'         => $this->getTable(),
         'field'         => 'client_identifier',
         'name'          => __('Ambiente', 'nextool'),
         'searchtype'    => ['contains', 'equals'],
         'datatype'      => 'string',
         'massiveaction' => false,
      ];

      $tab[] = [
         'id'            => '8',
         'table'         => $this->getTable(),
         'field'         => 'plan',
         'name'          => __('Plano', 'nextool'),
         'searchtype'    => ['contains', 'equals'],
         'datatype'      => 'string',
         'massiveaction' => false,
      ];

      $tab[] = [
         'id'            => '9',
         'table'         => $this->getTable(),
         'field'         => 'license_status',
         'name'          => __('Status', 'nextool'),
         'searchtype'    => ['contains', 'equals'],
         'datatype'      => 'string',
         'massiveaction' => false,
      ];

      $tab[] = [
         'id'            => '10',
         'table'         => $this->getTable(),
         'field'         => 'message',
         'name'          => __('Mensagem', 'nextool'),
         'searchtype'    => ['contains'],
         'datatype'      => 'string',
         'massiveaction' => false,
      ];

      $tab[] = [
         'id'            => '11',
         'table'         => 'glpi_users',
         'field'         => 'name',
         'name'          => __('Usuário', 'nextool'),
         'searchtype'    => ['contains', 'equals'],
         'datatype'      => 'dropdown',
         'massiveaction' => false,
         'linkfield'     => 'user_id',
      ];

      $tab[] = [
         'id'            => '12',
         'table'         => $this->getTable(),
         'field'         => 'requested_modules',
         'name'          => __('Módulos', 'nextool'),
         'searchtype'    => ['contains'],
         'datatype'      => 'string',
         'massiveaction' => false,
      ];

      return $tab;
   }

   public static function getSpecificValueToDisplay($field, $values, array $options = []) {
      if ($field === 'result') {
         $result = (int)($values['result'] ?? 0);
         $plan = strtoupper($values['plan'] ?? '');
         $httpCode = (int)($values['http_code'] ?? 0);

         if ($result === 1) {
            return "<span class='badge text-white bg-green'>" . __('Válida', 'nextool') . "</span>";
         }
         if ($plan === 'FREE') {
            return "<span class='badge text-white bg-teal'>" . __('Free Tier', 'nextool') . "</span>";
         }
         // Servidor respondeu (2xx-4xx) mas sem licença ativa — comunicação OK, plano free liberado
         if ($httpCode >= 200 && $httpCode < 500) {
            return "<span class='badge text-white bg-orange'>" . __('Sem Licença', 'nextool') . "</span>";
         }
         // Erro real: 5xx, timeout, sem resposta
         return "<span class='badge text-white bg-red'>" . __('Erro', 'nextool') . "</span>";
      }
      return parent::getSpecificValueToDisplay($field, $values, $options);
   }

   public static function ensureDisplayPreferences() {
      global $DB;

      $itemtype = self::class;
      $d_pref = new DisplayPreference();
      $found = $d_pref->find(['itemtype' => $itemtype, 'users_id' => 0]);

      if (count($found) === 0 && isset($DB)) {
         $default_cols = [
            2  => 1,  // Data
            3  => 2,  // Resultado
            4  => 3,  // Código HTTP
            5  => 4,  // Tempo (ms)
            6  => 5,  // Origem
            8  => 6,  // Plano
            11 => 7,  // Usuário
            10 => 8,  // Mensagem
         ];
         foreach ($default_cols as $num => $rank) {
            $DB->insert(DisplayPreference::getTable(), [
               'itemtype' => $itemtype,
               'num'      => $num,
               'rank'     => $rank,
               'users_id' => 0,
            ]);
         }
      }
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
         'plan'             => isset($data['plan']) ? substr((string)$data['plan'], 0, 32) : null,
         'allowed_modules'  => isset($data['allowed_modules']) ? (string)$data['allowed_modules'] : null,
         'force_refresh'    => !empty($data['force_refresh']) ? 1 : 0,
         'cache_hit'        => !empty($data['cache_hit']) ? 1 : 0,
         'user_id'          => isset($data['user_id']) ? (int)$data['user_id'] : null,
      ];

      return $attempt->add($input);
   }
}
