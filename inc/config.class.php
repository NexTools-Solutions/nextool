<?php
/**
 * Nextools - Plugin Configuration
 *
 * Classe responsável pelas configurações globais do plugin Nextools no GLPI:
 * identificador, distribuição remota, integrações com ContainerAPI, etc.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolConfig extends CommonDBTM {

   const DEFAULT_CONTAINERAPI_BASE_URL = 'https://containerapi.nextoolsolutions.ai/';

   static $rightname = 'config';

   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_main_configs';
   }

   // Sem alterações de schema em runtime (GLPI não permite queries diretas aqui).
   // Campos novos devem ser criados via install/upgrade (Migration) e aqui apenas detectados.

   protected static function generateClientIdentifier() {
      // Padrão simplificado determinístico:
      // RITECH-{ID8}-{CC}
      //
      // - ID8: 8 chars A-Z0-9 derivados de hash estável do host/ambiente
      // - CC:  2 chars de checksum derivados do mesmo hash

      // Descobre host a partir da URL base do GLPI ou variáveis de servidor
      $host = '';
      if (isset($GLOBALS['CFG_GLPI']['url_base']) && $GLOBALS['CFG_GLPI']['url_base']) {
         $parsedHost = parse_url($GLOBALS['CFG_GLPI']['url_base'], PHP_URL_HOST);
         if (!empty($parsedHost)) {
            $host = $parsedHost;
         }
      }
      if ($host === '') {
         if (!empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
         } else if (!empty($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
         } else {
            $host = 'localhost';
         }
      }

      $host = strtolower(trim($host));

      // Base determinística para hash (usa apenas o host normalizado)
      $baseString = $host . '|RITEC_SALT_V2';

      // Gera ID8: 8 chars A-Z0-9
      $hash = hash('sha256', $baseString);
      $id8  = '';
      $i    = 0;
      while (strlen($id8) < 8 && $i < strlen($hash)) {
         $c = strtoupper($hash[$i]);
         if (ctype_xdigit($c)) {
            // Mapeia hex para A-Z0-9
            // 0-9 ficam 0-9; A-F mapeamos para letras fixas
            if (ctype_digit($c)) {
               $id8 .= $c;
            } else {
               // A-F -> letras específicas (A-F)
               $map = [
                  'A' => 'A',
                  'B' => 'B',
                  'C' => 'C',
                  'D' => 'D',
                  'E' => 'E',
                  'F' => 'F',
               ];
               $id8 .= $map[$c];
            }
         }
         $i++;
      }

      if ($id8 === '') {
         $id8 = 'RITECID8';
      }

      // Checksum CC: 2 chars A-Z0-9 a partir de outro hash
      $chkHash = strtoupper(hash('crc32', $baseString));
      $cc      = '';
      $j       = 0;
      while (strlen($cc) < 2 && $j < strlen($chkHash)) {
         $c = $chkHash[$j];
         if (ctype_xdigit($c)) {
            $cc .= strtoupper($c);
         }
         $j++;
      }

      if (strlen($cc) < 2) {
         $cc = str_pad($cc, 2, 'X');
      }

      return sprintf('RITECH-%s-%s', $id8, $cc);
   }

   /**
    * Obtém a configuração atual
    * 
    * @return array Configuração atual
    */
   public static function getConfig() {
      global $DB;

      // Se a tabela principal ainda não existir (plugin recém-detectado, mas não instalado),
      // devolve configuração padrão sem tentar acessar o banco.
      if (!$DB->tableExists('glpi_plugin_nextool_main_configs')) {
         return [
            'is_active'         => 1,
            'client_identifier' => null,
            'endpoint_url'      => null,
         ];
      }

      $config = [
         'is_active' => 1,
         'client_identifier' => null,
         'endpoint_url' => null,
      ];

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_configs',
         'WHERE' => ['id' => 1],
         'LIMIT' => 1
      ]);

      if (count($iterator)) {
         $data = $iterator->current();
         $config['is_active'] = (int)$data['is_active'];
         // Lê apenas se os campos existirem
         if ($DB->fieldExists('glpi_plugin_nextool_main_configs', 'client_identifier')) {
            $config['client_identifier'] = $data['client_identifier'] ?? null;
         }
         if ($DB->fieldExists('glpi_plugin_nextool_main_configs', 'endpoint_url')) {
            $config['endpoint_url'] = $data['endpoint_url'] ?? null;
         }
      } else {
         // cria registro base se não existir
         $configObj = new self();
         $configObj->add([
            'id' => 1,
            'is_active' => 0,
            'date_creation' => date('Y-m-d H:i:s')
         ]);
      }

      // Gera identificador se estiver vazio
      if (empty($config['client_identifier']) && $DB->fieldExists('glpi_plugin_nextool_main_configs', 'client_identifier')) {
         $id = self::generateClientIdentifier();
         $configObj = new self();
         $configObj->getFromDB(1);
         $configObj->update([
            'id' => 1,
            'client_identifier' => $id,
            'date_mod' => date('Y-m-d H:i:s')
         ]);
         $config['client_identifier'] = $id;
      }

      return $config;
   }

   /**
    * Salva a configuração
    * 
    * @param array $data Dados a salvar
    * @return bool True se salvou com sucesso
    */
   public static function saveConfig($data) {
      global $DB;

      $is_active = isset($data['is_active']) && $data['is_active'] == '1' ? 1 : 0;
      $endpoint_url = isset($data['endpoint_url']) ? trim((string)$data['endpoint_url']) : null;
      if ($endpoint_url === '') {
         $endpoint_url = null;
      }

      $configObj = new self();
      $exists = $configObj->getFromDB(1);

      $payload = [
         'is_active' => $is_active,
         'date_mod' => date('Y-m-d H:i:s')
      ];
      if ($DB->fieldExists('glpi_plugin_nextool_main_configs', 'endpoint_url')) {
         $payload['endpoint_url'] = $endpoint_url;
      }

      if ($exists) {
         $payload['id'] = 1;
         return $configObj->update($payload);
      }
      $payload['id'] = 1;
      $payload['date_creation'] = date('Y-m-d H:i:s');
      unset($payload['date_mod']);
      return $configObj->add($payload) !== false;
   }

   /**
    * Configuração de distribuição remota (ContainerAPI)
    *
    * @return array
    */
   public static function getDistributionSettings() {
      $values = Config::getConfigurationValues('plugin:nextool_distribution');
      $updated = [];

      $baseUrl  = isset($values['base_url']) ? trim((string)$values['base_url']) : '';
      if ($baseUrl === '') {
         $baseUrl = self::DEFAULT_CONTAINERAPI_BASE_URL;
         $updated['base_url'] = $baseUrl;
      }

      $clientIdentifier = isset($values['client_identifier']) ? trim((string)$values['client_identifier']) : '';
      if ($clientIdentifier === '') {
         $globalConfig = self::getConfig();
         if (isset($globalConfig['client_identifier'])) {
            $clientIdentifier = trim((string)$globalConfig['client_identifier']);
         }
      }

      if ($clientIdentifier !== '' && ($values['client_identifier'] ?? '') !== $clientIdentifier) {
         $updated['client_identifier'] = $clientIdentifier;
      }

      if (!empty($updated)) {
         Config::setConfigurationValues('plugin:nextool_distribution', array_merge($values, $updated));
         $values = array_merge($values, $updated);
      }

      return [
         'base_url'  => $baseUrl,
         'client_identifier' => $clientIdentifier,
         'client_secret' => isset($values['client_secret']) ? trim((string)$values['client_secret']) : '',
      ];
   }
}

