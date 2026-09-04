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
    * Context dedicado para o VÍNCULO DE PROVISIONAMENTO do ambiente
    * (client_identifier + client_secret HMAC). Diferente de
    * 'plugin:nextool_distribution' (config do plugin), este context NÃO é
    * apagado no uninstall: o segredo HMAC é estado do AMBIENTE/máquina, não
    * config do plugin. Assim, reinstalar no mesmo domínio reusa o segredo
    * (o client_identifier é determinístico por domínio) e evita o 409 do
    * bootstrap (identifier_already_provisioned). Ver hook.php (uninstall) e
    * memória provisioning-survives-uninstall.
    */
   const PROVISIONING_CONTEXT = 'plugin:nextool_provisioning';

   /**
    * Context das credenciais de SERVIÇOS GERENCIADOS entregues pelo servidor no /validate
    * (managed-services-v1). 1 chave por serviço (ex.: 'whatsapp'), valor JSON com o token
    * CIFRADO (SecretVault). Server-driven: o plugin nunca edita -- só consome via
    * getManagedService(). Limpo no uninstall (LGPD); re-entregue no próximo validate.
    */
   const MANAGED_SERVICES_CONTEXT = 'plugin:nextool_managed_services';

   /** Origens permitidas no formulário de contato (LO-01). Fonte única para validação + template. */
   public const CONTACT_SOURCES = [
      'canais_jmba',
      'indicacao',
      'linkedin',
      'telegram',
      'outros',
   ];

   /** Motivos permitidos no formulário de contato (LO-01). */
   public const CONTACT_REASONS = [
      'duvidas',
      'apresentacao',
      'desenvolvimento',
      'melhoria',
      'contratar',
      'outros',
   ];

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

   /**
    * Wrapper sobre Toolbox::logInFile que escreve apenas em DEBUG_MODE.
    * Use para logs marcados como [DEBUG] que não devem poluir produção
    * (LO-03 do audit-deep).
    */
   public static function debugLog(string $message): void {
      if (self::isDebugEnabled()) {
         Toolbox::logInFile('plugin_nextool', $message);
      }
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
    * Cache per-request do resultado de getConfig().
    * Invalidado em saveConfig() e em rotas que mutam glpi_plugin_nextool_main_configs.
    */
   private static ?array $cachedConfig = null;

   /**
    * Obtém a configuração atual
    *
    * @return array Configuração atual
    */
   public static function getConfig() {
      global $DB;

      if (self::$cachedConfig !== null) {
         return self::$cachedConfig;
      }

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
         // O SELECT * ja diz se a coluna existe: chave ausente = coluna ausente.
         // Ate 6.12.1 havia 2 fieldExists() aqui (= 1 SHOW COLUMNS por request,
         // em TODO boot). Ver nextool-dev#249.
         $config['client_identifier'] = $data['client_identifier'] ?? null;
         $config['endpoint_url']      = $data['endpoint_url'] ?? null;
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

      self::$cachedConfig = $config;
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

      self::$cachedConfig = null;

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
    * Configuração de distribuição remota (ContainerAPI)
    *
    * @return array
    */
   /**
    * Lê o vínculo de provisionamento persistido (sobrevive ao uninstall).
    *
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
    * Persiste o vínculo de provisionamento (identifier + segredo HMAC) no
    * context dedicado que NÃO é apagado no uninstall. Só grava valores não
    * vazios (não sobrescreve um segredo válido por vazio).
    */
   /**
    * Credenciais da instância gerenciada de um serviço (ex.: 'whatsapp'), entregues pelo
    * servidor no Sincronizar. Decifra o token INTERNAMENTE (SecretVault) -- o chamador recebe
    * o valor pronto para uso e NUNCA deve logá-lo/ecoá-lo no DOM.
    *
    * @return array{status:string, api_url:string, instance_name:string,
    *               instance_token_plain:string, expires_at:string, grace_until:string,
    *               renewal_url:string}|null null = sem instância entregue/dados inválidos
    */
   public static function getManagedService(string $service): ?array {
      $stored = Config::getConfigurationValues(self::MANAGED_SERVICES_CONTEXT);
      $raw = isset($stored[$service]) ? (string) $stored[$service] : '';
      if ($raw === '') {
         return null;
      }
      $data = json_decode($raw, true);
      if (!is_array($data)) {
         return null;
      }

      $apiUrl = isset($data['api_url']) ? (string) $data['api_url'] : '';
      $instanceName = isset($data['instance_name']) ? (string) $data['instance_name'] : '';
      if ($apiUrl === '' || $instanceName === '') {
         return null;
      }

      $tokenPlain = '';
      $encrypted = isset($data['instance_token']) ? (string) $data['instance_token'] : '';
      if ($encrypted !== '') {
         require_once NEXTOOL_PHP_DIR . '/inc/secretvault.class.php';
         $tokenPlain = PluginNextoolSecretVault::decrypt($encrypted);
      }

      $status = isset($data['status']) ? (string) $data['status'] : '';
      if (!in_array($status, ['active', 'suspended', 'pending_provisioning'], true)) {
         $status = $status !== '' ? $status : 'pending_provisioning';
      }

      return [
         'status'               => $status,
         'api_url'              => $apiUrl,
         'instance_name'        => $instanceName,
         'instance_token_plain' => $tokenPlain,
         'expires_at'           => isset($data['expires_at']) ? (string) $data['expires_at'] : '',
         'grace_until'          => isset($data['grace_until']) ? (string) $data['grace_until'] : '',
         'renewal_url'          => isset($data['renewal_url']) ? (string) $data['renewal_url'] : '',
      ];
   }

   /** Há credencial de serviço gerenciado entregue? (SEM decifrar o token -- barato p/ UI). */
   public static function hasManagedService(string $service): bool {
      $stored = Config::getConfigurationValues(self::MANAGED_SERVICES_CONTEXT);
      $raw = isset($stored[$service]) ? (string) $stored[$service] : '';
      if ($raw === '') {
         return false;
      }
      $data = json_decode($raw, true);

      return is_array($data) && !empty($data['api_url']) && !empty($data['instance_name']);
   }

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
    * F1a -- persiste o client_identifier CUNHADO pelo servidor (enroll) em main_configs e invalida
    * o cache memoizado do request. Substitui a geração local: o identificador agora vem do
    * ContainerAPI. Só grava valor não-vazio (nunca apaga uma identidade existente por engano).
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
      self::$cachedConfig = null; // invalida o memoize (armadilha do cache no mesmo request)
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
    * Adota uma identidade server-issued NOVA descartando a antiga: limpa o provisioning
    * resiliente, persiste o novo par identifier+secret nos 3 lugares (main_configs, context
    * distribution, provisioning). Usada pelo re-enroll coordenado pelo servidor (sinal
    * re_enroll_required do /validate e re-enroll por substituição do bootstrap -- FREE órfão).
    */
   public static function adoptIdentity(string $newIdentifier, string $newSecret): void {
      $newIdentifier = trim($newIdentifier);
      $newSecret     = trim($newSecret);
      if ($newIdentifier === '' || $newSecret === '') {
         return;
      }
      self::clearProvisioning();
      self::setClientIdentifier($newIdentifier);
      self::setProvisioning($newIdentifier, $newSecret);
      $dist = self::getDistributionSettings();
      $dist['client_identifier'] = $newIdentifier;
      $dist['client_secret']     = $newSecret;
      Config::setConfigurationValues('plugin:nextool_distribution', $dist);
   }

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
      // reusa o segredo (evita o 409 do bootstrap). Sobrescreve os valores do
      // context 'distribution' quando presentes. Fallback para distribution
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

