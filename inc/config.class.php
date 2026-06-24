<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Plugin Configuration
 * -------------------------------------------------------------------------
 * Classe responsável por gerenciar as configurações globais do plugin
 * NexTool Solutions dentro do GLPI (identificador, distribuição remota,
 * integrações com ContainerAPI, etc.).
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

class PluginNextoolConfig extends CommonDBTM {

   const DEFAULT_CONTAINERAPI_BASE_URL = 'https://containerapi.nextoolsolutions.ai/';

   /**
    * Context dedicado do vínculo de provisionamento (identifier + segredo HMAC).
    * NÃO é apagado no uninstall -- é estado do ambiente, não config do plugin.
    * Evita o 409 (identifier_already_provisioned) ao reinstalar no mesmo domínio.
    */
   const PROVISIONING_CONTEXT = 'plugin:nextool_provisioning';

   static $rightname = 'config';

   public static function getPluginVersion(): string {
      if (function_exists('plugin_version_nextool')) {
         $info = plugin_version_nextool();
         if (is_array($info) && isset($info['version'])) {
            return (string)$info['version'];
         }
      }
      return '0.0.0';
   }

   public static function generateRequestGroupId(): string {
      return 'evt_' . (int)(microtime(true) * 1000000);
   }

   public static function isDebugEnabled(): bool {
      return isset($_SESSION['glpi_use_mode']) && $_SESSION['glpi_use_mode'] === Session::DEBUG_MODE;
   }

   public static function getTable($classname = null) {
      return 'glpi_plugin_nextool_main_configs';
   }

   // Sem alterações de schema em runtime (GLPI não permite queries diretas aqui).
   // Campos novos devem ser criados via install/upgrade (Migration) e aqui apenas detectados.

   /**
    * F4 -- REMOVIDA. A identidade NÃO é mais gerada localmente: o ContainerAPI a cunha via enroll
    * (server-issued, NX2-) desde a F1a. A geração por hash do host era a CAUSA da colisão localhost
    * (vários ambientes na mesma máquina recebiam o mesmo RITECH-). Os legados RITECH- migram para
    * server-issued via re-enroll (F4 migração / F3 fork). Nada mais gera identidade no cliente.
    */

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

      // F1a -- identidade server-issued: o getConfig NÃO gera mais o client_identifier localmente
      // (a geração por hash do host causava a colisão localhost). Para install NOVO o identificador
      // é cunhado pelo ContainerAPI via enroll (PluginNextoolDistributionClient::enrollEnvironment),
      // disparado no provisionamento ativo (front/config.save.php) e persistido aqui via update.
      // Ambientes LEGADOS já têm o RITECH- na tabela e seguem sendo lidos acima (COMP-03 preservado);
      // installs novos não-provisionados operam com client_identifier vazio (degradam para FREE).
      // generateClientIdentifier() foi REMOVIDA na F4 (identidade server-issued via enroll).

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
      } elseif ($endpoint_url !== null && (!filter_var($endpoint_url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $endpoint_url))) {
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
    * Lê o vínculo de provisionamento persistido (identifier + segredo HMAC).
    * @return array{client_identifier: string, client_secret: string}
    */
   public static function getProvisioning(): array {
      $values = Config::getConfigurationValues(self::PROVISIONING_CONTEXT);
      return [
         'client_identifier' => isset($values['client_identifier']) ? trim((string)$values['client_identifier']) : '',
         'client_secret'     => isset($values['client_secret']) ? trim((string)$values['client_secret']) : '',
      ];
   }

   /**
    * Persiste o vínculo de provisionamento no context dedicado que NÃO é
    * apagado no uninstall. Só grava valores não vazios (não sobrescreve um
    * segredo válido por vazio).
    */
   public static function setProvisioning(string $clientIdentifier, string $clientSecret): void {
      $clientIdentifier = trim($clientIdentifier);
      $clientSecret     = trim($clientSecret);
      $current = Config::getConfigurationValues(self::PROVISIONING_CONTEXT);

      $payload = [];
      if ($clientIdentifier !== '') {
         $payload['client_identifier'] = $clientIdentifier;
      }
      if ($clientSecret !== '') {
         $payload['client_secret'] = $clientSecret;
      }
      if ($payload === []) {
         return;
      }

      Config::setConfigurationValues(self::PROVISIONING_CONTEXT, array_merge($current, $payload));
   }

   /**
    * F1a -- persiste o client_identifier CUNHADO pelo servidor (enroll) em main_configs.
    * Substitui a geração local: o identificador agora vem do ContainerAPI. Só grava valor
    * não-vazio (nunca apaga uma identidade existente por engano). No GLPI 10 não há memoize
    * estático de getConfig (cada chamada lê do banco), então não há cache a invalidar.
    */
   public static function setClientIdentifier(string $clientIdentifier): void {
      global $DB;
      $clientIdentifier = trim($clientIdentifier);
      if ($clientIdentifier === '' || !$DB->tableExists('glpi_plugin_nextool_main_configs')) {
         return;
      }
      $configObj = new self();
      if ($configObj->getFromDB(1)) {
         $configObj->update([
            'id' => 1,
            'client_identifier' => $clientIdentifier,
            'date_mod' => date('Y-m-d H:i:s'),
         ]);
      }
   }

   /**
    * Remove o vínculo de provisionamento persistido (ação "Desvincular
    * ambiente" -- reset intencional pelo cliente).
    */
   public static function clearProvisioning(): void {
      global $DB;
      if ($DB->tableExists('glpi_configs')) {
         $DB->delete('glpi_configs', ['context' => self::PROVISIONING_CONTEXT]);
      }
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

      $clientSecret = isset($values['client_secret']) ? trim((string)$values['client_secret']) : '';

      // Provisionamento persistido é a FONTE DE VERDADE do segredo HMAC e do
      // identifier: sobrevive ao uninstall, então reinstalar no mesmo domínio
      // reusa o segredo (evita o 409 do bootstrap). Fallback para distribution
      // mantém compat com ambientes ainda não migrados.
      $provisioning = self::getProvisioning();
      if ($provisioning['client_identifier'] !== '') {
         $clientIdentifier = $provisioning['client_identifier'];
      }
      if ($provisioning['client_secret'] !== '') {
         $clientSecret = $provisioning['client_secret'];
      }

      return [
         'base_url'  => $baseUrl,
         'client_identifier' => $clientIdentifier,
         'client_secret' => $clientSecret,
      ];
   }
}

