<?php
/**
 * Nextools - License Configuration
 *
 * Classe de configuração de licença do Nextools (operacional). Armazena
 * client_identifier, endpoint do ContainerAPI, secret, status da última validação
 * e cache de módulos.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolLicenseConfig extends CommonDBTM {

   public static $rightname = 'config';

   /**
    * Nome da tabela
    */
   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_main_license_config';
   }

   /**
    * Nome do tipo (usado em logs e exibição)
    */
   public static function getTypeName($nb = 0) {
      return _n('Configuração de Licença', 'Configurações de Licença', $nb, 'nextool');
   }

   /**
    * Retorna os campos padrão (para formularios/GUI futura)
    */
   public static function getDefaultConfig() {
      global $DB;

      self::ensureSchema();

      $table = self::getTable();
      if ($DB->tableExists($table)) {
         $iterator = $DB->request([
            'FROM'  => $table,
            'ORDER' => 'id ASC',
            'LIMIT' => 1
         ]);
         foreach ($iterator as $row) {
            return $row;
         }
      }

      return [
         'license_key'            => null,
         'plan'                   => null,
         'contract_active'        => null,
         'license_status'         => null,
         'expires_at'             => null,
         'policies_accepted_at'   => null,
         'api_endpoint'           => null,
         'api_secret'             => null,
         'last_validation_date'   => null,
         'last_validation_result' => null,
         'last_validation_message'=> null,
         'cached_modules'         => null,
         'warnings'              => null,
         'licenses_snapshot'     => null,
         'consecutive_failures'   => 0,
         'last_failure_date'      => null,
      ];
   }

   /**
    * Limpa o cache da licença (planos, status, módulos permitidos etc.).
    *
    * @param array $overrides Valores adicionais para sobrescrever após o reset.
    */
   public static function resetCache(array $overrides = []): void {
      global $DB;

      self::ensureSchema();

      $table = self::getTable();
      if (!$DB->tableExists($table)) {
         return;
      }

      $defaults = [
         'plan'                   => null,
         'contract_active'        => null,
         'license_status'         => null,
         'expires_at'             => null,
         'last_validation_date'   => null,
         'last_validation_result' => null,
         'last_validation_message'=> null,
         'cached_modules'         => null,
         'warnings'               => null,
         'licenses_snapshot'      => null,
         'consecutive_failures'   => 0,
         'last_failure_date'      => null,
      ];

      $payload = array_merge($defaults, $overrides);
      $payload['date_mod'] = date('Y-m-d H:i:s');

      $existing = self::getDefaultConfig();
      $obj = new self();
      if (!empty($existing['id'])) {
         $obj->update(array_merge($payload, ['id' => (int)$existing['id']]));
      } else {
         $payload['date_creation'] = date('Y-m-d H:i:s');
         $obj->add($payload);
      }
   }

   /**
    * Garante que a tabela possua os campos mais recentes usados pelo snapshot de licença.
    *
    * Executa migrações em runtime caso o administrador tenha atualizado o plugin
    * sem rodar o fluxo completo de upgrade pelo GLPI.
    */
   protected static function ensureSchema(): void {
      global $DB;

      $table = self::getTable();
      if (!$DB->tableExists($table)) {
         return;
      }

      $schemaUpdated = false;
      $migration = new Migration(2141);

      if (!$DB->fieldExists($table, 'contract_active')) {
         $migration->addField(
            $table,
            'contract_active',
            'tinyint',
            [
               'value'   => null,
               'comment' => 'Último estado do contrato retornado pelo administrativo',
               'after'   => 'plan',
            ]
         );
         $schemaUpdated = true;
      }

      if (!$DB->fieldExists($table, 'license_status')) {
         $migration->addField(
            $table,
            'license_status',
            'varchar(32)',
            [
               'value'   => null,
               'comment' => 'Último status retornado pelo administrativo',
               'after'   => 'contract_active',
            ]
         );
         $schemaUpdated = true;
      }

      if (!$DB->fieldExists($table, 'expires_at')) {
         $migration->addField(
            $table,
            'expires_at',
            'timestamp',
            [
               'value'   => null,
               'comment' => 'Data de expiração retornada pelo administrativo',
               'after'   => 'license_status',
            ]
         );
         $schemaUpdated = true;
      }

      if (!$DB->fieldExists($table, 'warnings')) {
         $migration->addField(
            $table,
            'warnings',
            'text',
            [
               'value'   => null,
               'comment' => 'Warnings retornados pelo administrativo (JSON)',
               'after'   => 'cached_modules',
            ]
         );
         $schemaUpdated = true;
      }

      if (!$DB->fieldExists($table, 'licenses_snapshot')) {
         $migration->addField(
            $table,
            'licenses_snapshot',
            'longtext',
            [
               'value'   => null,
               'comment' => 'Snapshot consolidado das licenças (JSON)',
               'after'   => 'warnings',
            ]
         );
         $schemaUpdated = true;
      }

      if (!$DB->fieldExists($table, 'policies_accepted_at')) {
         $migration->addField(
            $table,
            'policies_accepted_at',
            'timestamp',
            [
               'value'   => null,
               'comment' => 'Data/hora do aceite das Políticas de Uso no ambiente operacional',
               'after'   => 'expires_at',
            ]
         );
         $schemaUpdated = true;
      }

      if ($schemaUpdated) {
         $migration->executeMigration();
      }
   }

   /**
    * Prepara dados antes de adicionar
    */
   public function prepareInputForAdd($input) {
      // Define data de criação se não vier
      if (empty($input['date_creation'])) {
         $input['date_creation'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
      }
      return $input;
   }

   /**
    * Prepara dados antes de atualizar
    */
   public function prepareInputForUpdate($input) {
      // Atualiza date_mod
      $input['date_mod'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
      return $input;
   }
}


