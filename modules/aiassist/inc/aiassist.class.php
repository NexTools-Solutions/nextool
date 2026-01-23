<?php
/**
 * Módulo AI Assist - Classe principal
 *
 * Responsável por gerenciar configurações, instalação e futuras
 * integrações de IA dentro do NexTool Solutions.
 *
 * @version 1.4.1
 *
 * @author NexTool Solutions
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolAiassist extends PluginNextoolBaseModule {

   public const FEATURE_SUMMARY  = 'summary';
   public const FEATURE_REPLY    = 'reply';
   public const FEATURE_SENTIMENT = 'sentiment';

   public const PROVIDER_MODE_DIRECT = 'direct';
   public const PROVIDER_MODE_PROXY  = 'proxy';

   public const PROVIDER_OPENAI = 'openai';
   public const PROVIDER_GEMINI = 'gemini';

   /** @var array|null */
   private $cachedSettings = null;

   /** @var array|null */
   private $cachedQuota = null;

    /** @var PluginNextoolAiassistProviderInterface|null */
   private $providerInstance = null;

   /** @var array */
   private $serviceCache = [];

   /** @var array */
   private $ticketDataCache = [];

   /** @var array */
   private $latestFollowupCache = [];

   /**
    * {@inheritdoc}
    */
   public function getModuleKey() {
      return 'aiassist';
   }

   /**
    * {@inheritdoc}
    */
   public function getName() {
      return 'AI Assist';
   }

   /**
    * {@inheritdoc}
    */
   public function getDescription() {
      return 'Assistente de IA para tickets: resumos, sugestões de resposta e detecção de sentimento/urgência.';
   }

   /**
    * {@inheritdoc}
    */
   public function getVersion() {
      // 1.4.4: Suporte a múltiplos provedores (OpenAI + Gemini)
      return '1.5.0';
   }

   /**
    * {@inheritdoc}
    */
   public function getIcon() {
      return 'ti ti-robot';
   }

   /**
    * {@inheritdoc}
    */
   public function getAuthor() {
      return 'NexTool Solutions';
   }

   public function getBillingTier() {
      return 'FREE';
   }

   /**
    * {@inheritdoc}
    */
   public function install() {
      if (!$this->executeInstallSql()) {
         return false;
      }

      $this->ensureSchema();
      $this->initializeDefaultSettings();
      return true;
   }

   /**
    * {@inheritdoc}
    */
   public function uninstall() {
      // A remoção de dados agora é opcional (botão "Apagar dados").
      return true;
   }

   /**
    * {@inheritdoc}
    */
   public function hasConfig() {
      return true;
   }

   /**
    * {@inheritdoc}
    */
   public function getConfigPage() {
      return $this->getFrontPath('aiassist.config.php');
   }

   /**
    * {@inheritdoc}
    */
   public function onInit() {
      $this->ensureSchema();

      require_once $this->getIncPath('aiassistticket.class.php');

      Plugin::registerClass('PluginNextoolAiassistTicket', [
         'addtabon' => ['Ticket']
      ]);

      $settings = $this->getSettings();

      global $PLUGIN_HOOKS;

      // Sempre registramos o hook post_item_form para poder injetar
      // integrações do módulo no formulário do chamado (sentimento, resumo, etc.)
      // Registro no formato "global" (já testado e compatível com sua instância),
      // deixando a filtragem por Ticket dentro do próprio método postTicketForm.
      $PLUGIN_HOOKS['post_item_form']['nextool'] = [self::class, 'postTicketForm'];

      // Hooks específicos de sentimento dependem da flag da feature
      if (!empty($settings['feature_sentiment_enabled'])) {
         if (!isset($PLUGIN_HOOKS['pre_item_form']['nextool'])) {
            $PLUGIN_HOOKS['pre_item_form']['nextool'] = [];
         }
         $PLUGIN_HOOKS['pre_item_form']['nextool']['Ticket'] = [self::class, 'preTicketForm'];
      }
      
      // Hooks de CSS e JavaScript apenas para usuários com permissão READ
      if (PluginNextoolPermissionManager::canViewModule('aiassist')) {
         // Hook para adicionar CSS customizado do botão
         if (!isset($PLUGIN_HOOKS['add_css']['nextool'])) {
            $PLUGIN_HOOKS['add_css']['nextool'] = [];
         }
         $PLUGIN_HOOKS['add_css']['nextool'][] = $this->getCssPath('aiassist-timeline-button.css.php');

         // Hook para adicionar JS global responsável por injetar o botão "Resumo (AI)" na timeline
         if (!isset($PLUGIN_HOOKS['add_javascript']['nextool'])) {
            $PLUGIN_HOOKS['add_javascript']['nextool'] = [];
         }
         // Usa o roteador module_assets.php para servir o JS do módulo
         $PLUGIN_HOOKS['add_javascript']['nextool'][] = 'front/module_assets.php?module=aiassist&file=aiassist-timeline-button.js.php';
      }
   }

   /**
    * Configuração padrão do módulo.
    *
    * @return array
    */
   public function getDefaultConfig() {
      return [];
   }

   /**
    * Configurações padrão de funcionamento (aplicadas na tabela própria).
    *
    * @return array
    */
   public function getDefaultSettings() {
      $defaultProvider = self::PROVIDER_OPENAI;
      return [
         'provider_mode'            => self::PROVIDER_MODE_DIRECT,
         'provider'                 => $defaultProvider,
         'model'                    => $this->getDefaultModelForProvider($defaultProvider),
         'allow_sensitive'          => 0,
         'payload_max_chars'        => 10000,
         'timeout_seconds'          => 120,
         'rate_limit_minutes'       => 5,
         'tokens_limit_month'       => 100000,
         'feature_summary_enabled'  => 1,
         'feature_reply_enabled'    => 1,
         'feature_sentiment_enabled' => 1,
         'feature_summary_model'    => '', // Vazio = usa modelo padrão
         'feature_reply_model'      => '', // Vazio = usa modelo padrão
         'feature_sentiment_model'  => '', // Vazio = usa modelo padrão
         'api_key'                  => '',
         'has_api_key'              => false,
      ];
   }

   /**
    * Retorna o modelo padrão conforme o provedor.
    *
    * @param string $provider
    * @return string
    */
   public function getDefaultModelForProvider($provider) {
      switch ($provider) {
         case self::PROVIDER_GEMINI:
            return 'gemini-3-flash-preview';
         case self::PROVIDER_OPENAI:
         default:
            return 'gpt-4o-mini';
      }
   }

   /**
    * Retorna a lista de modelos suportados por provedor.
    *
    * @param string $provider
    * @return array
    */
   public function getProviderModels($provider) {
      switch ($provider) {
         case self::PROVIDER_GEMINI:
            return [
               'gemini-3-flash-preview',
               'gemini-2.0-flash',
            ];
         case self::PROVIDER_OPENAI:
         default:
            return [
               'gpt-4o-mini',
               'gpt-4o',
               'o4-mini',
               'gpt-4.1-mini',
            ];
      }
   }

   /**
    * Retorna o rótulo amigável do provedor.
    *
    * @param string $provider
    * @return string
    */
   public function getProviderLabel($provider) {
      switch ($provider) {
         case self::PROVIDER_GEMINI:
            return 'Gemini';
         case self::PROVIDER_OPENAI:
         default:
            return 'OpenAI';
      }
   }

   /**
    * Retorna endpoint padrão de exibição para o provedor.
    *
    * @param string $provider
    * @param string $model
    * @return string
    */
   public function getProviderEndpointForDisplay($provider, $model) {
      if ($provider === self::PROVIDER_GEMINI) {
         $model = $model ?: $this->getDefaultModelForProvider(self::PROVIDER_GEMINI);
         return 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
      }

      return 'https://api.openai.com/v1/chat/completions';
   }

   /**
    * Verifica se o módulo está operando via proxy.
    *
    * @param array|null $settings
    * @return bool
    */
   public function isProxyMode(array $settings = null) {
      $settings = $settings ?? $this->getSettings();
      return ($settings['provider_mode'] ?? self::PROVIDER_MODE_DIRECT) === self::PROVIDER_MODE_PROXY;
   }

   /**
    * Retorna identificador do cliente (armazenado no config JSON do módulo).
    *
    * @param bool $generateIfMissing
    * @return string
    */
   public function getClientIdentifier($generateIfMissing = true) {
      require_once GLPI_ROOT . '/plugins/nextool/inc/config.class.php';
      $global = PluginNextoolConfig::getConfig();
      return (string)($global['client_identifier'] ?? '');
   }

   /**
    * Define (força) o identificador do cliente.
    *
    * @param string $identifier
    * @return void
    */
   public function setClientIdentifier($identifier) {
      // Identificador agora é global. Método mantido por compatibilidade, sem efeito.
      $this->cachedSettings = null;
      $this->cachedQuota = null;
   }

   /**
    * Configurações salvas no banco (sem expor o segredo).
    *
    * @param array $options ['include_secret' => bool]
    * @return array
    */
   public function getSettings(array $options = []) {
      $includeSecret = (bool)($options['include_secret'] ?? false);

      if ($this->cachedSettings !== null && !$includeSecret) {
         return $this->cachedSettings;
      }

      global $DB;
      $defaults = $this->getDefaultSettings();
      $clientIdentifier = $this->getClientIdentifier();

      $proxyRow = $this->getConfigRowByIdentifier($clientIdentifier);
      $directRow = $this->getConfigRowByIdentifier('default');

      if (!$proxyRow && !$directRow) {
         $this->ensureConfigRow($clientIdentifier, $defaults);
         $proxyRow = $this->getConfigRowByIdentifier($clientIdentifier);
      }

      $candidates = array_values(array_filter([
         $proxyRow ? array_merge($proxyRow, ['_source_identifier' => $clientIdentifier]) : null,
         $directRow ? array_merge($directRow, ['_source_identifier' => 'default']) : null,
      ]));

      if (empty($candidates)) {
         $row = [];
         $settings = $defaults;
      } else {
         usort($candidates, function ($a, $b) {
            return strcmp($b['date_mod'] ?? '', $a['date_mod'] ?? '');
         });
         $row = $candidates[0];
         $settings = array_merge($defaults, $row);
      }

      if ($includeSecret) {
         $settings['api_key'] = !empty($row['api_key']) ? $this->decryptValue($row['api_key']) : '';
      } else {
         $settings['has_api_key'] = !empty($row['api_key']);
         $settings['api_key'] = '';
      }

      if (!$includeSecret) {
         $this->cachedSettings = $settings;
      }

      return $settings;
   }

   /**
    * Salva configurações principais.
    *
    * @param array $settings
    * @return bool
    */
   public function saveSettings(array $settings) {
      global $DB;

      $clientIdentifier = (string)($settings['client_identifier'] ?? $this->getClientIdentifier());
      if ($clientIdentifier === '') {
         $clientIdentifier = 'default';
      }

      $proxyIdentifier = trim((string)($settings['proxy_identifier'] ?? ''));
      if ($proxyIdentifier === '') {
         $proxyIdentifier = null;
      }

      $providerMode = $settings['provider_mode'] ?? self::PROVIDER_MODE_DIRECT;
      $targetIdentifier = $providerMode === self::PROVIDER_MODE_PROXY ? $clientIdentifier : 'default';
      $provider = $settings['provider'] ?? self::PROVIDER_OPENAI;
      if (!in_array($provider, [self::PROVIDER_OPENAI, self::PROVIDER_GEMINI], true)) {
         $provider = self::PROVIDER_OPENAI;
      }

      $model = trim((string)($settings['model'] ?? ''));
      if ($model === '') {
         $model = $this->getDefaultModelForProvider($provider);
      }
      $allowedModels = $this->getProviderModels($provider);
      if (!in_array($model, $allowedModels, true)) {
         $model = $this->getDefaultModelForProvider($provider);
      }

      $payload = [
         'provider_mode'            => $providerMode,
         'provider'                 => $provider,
         'model'                    => $model,
         'proxy_identifier'         => $providerMode === self::PROVIDER_MODE_PROXY ? $proxyIdentifier : null,
         'allow_sensitive'          => !empty($settings['allow_sensitive']) ? 1 : 0,
         'payload_max_chars'        => (int)($settings['payload_max_chars'] ?? 6000),
         'timeout_seconds'          => (int)($settings['timeout_seconds'] ?? 25),
         'rate_limit_minutes'       => (int)($settings['rate_limit_minutes'] ?? 5),
         'tokens_limit_month'       => (int)($settings['tokens_limit_month'] ?? 100000),
         'feature_summary_enabled'  => !empty($settings['feature_summary_enabled']) ? 1 : 0,
         'feature_reply_enabled'    => !empty($settings['feature_reply_enabled']) ? 1 : 0,
         'feature_sentiment_enabled' => !empty($settings['feature_sentiment_enabled']) ? 1 : 0,
         'feature_summary_model'    => trim($settings['feature_summary_model'] ?? ''),
         'feature_reply_model'      => trim($settings['feature_reply_model'] ?? ''),
         'feature_sentiment_model'  => trim($settings['feature_sentiment_model'] ?? ''),
         'date_mod'                 => date('Y-m-d H:i:s'),
      ];

      if (!empty($settings['api_key'])) {
         $payload['api_key'] = $this->encryptValue($settings['api_key']);
      }

      // Mantém limites mínimos
      $payload['payload_max_chars'] = max(1000, $payload['payload_max_chars']);
      $payload['timeout_seconds'] = max(5, $payload['timeout_seconds']);
      $payload['rate_limit_minutes'] = max(0, $payload['rate_limit_minutes']); // Permite 0 = sem rate limit
      $payload['tokens_limit_month'] = max(1000, $payload['tokens_limit_month']);

      $exists = $DB->request([
         'FROM'  => $this->getConfigTableName(),
         'WHERE' => ['client_identifier' => $targetIdentifier],
         'LIMIT' => 1
      ]);

      $oldSettings = [];
      $configId = null;
      $result = false;
      
      if (count($exists)) {
         $row = $exists->current();
         $configId = (int)$row['id'];
         $oldSettings = $row;
         $result = $DB->update(
            $this->getConfigTableName(),
            $payload,
            ['id' => $configId]
         );
      } else {
         $payload['client_identifier'] = $targetIdentifier;
         $payload['date_creation'] = date('Y-m-d H:i:s');
         $result = $DB->insert(
            $this->getConfigTableName(),
            $payload
         );
         if ($result) {
            $configId = $DB->insertId();
         }
      }

      if ($result && $configId) {
         // Registra mudanças no histórico
         $this->recordConfigChanges($configId, $oldSettings, $payload);
         
         $this->cachedSettings = null;
         $this->providerInstance = null;
         if ($payload['provider_mode'] === self::PROVIDER_MODE_PROXY) {
            $this->syncQuotaLimit($clientIdentifier, $payload['tokens_limit_month']);
         } else {
            $this->cachedQuota = [
               'tokens_limit' => null,
               'tokens_used'  => 0,
               'period_start' => null,
               'period_end'   => null,
               'last_reset_at'=> null
            ];
         }
      }

      if ($result === false) {
         Toolbox::logInFile('plugin_nextool_aiassist', '[SETTINGS] Falha ao salvar configurações para ' . $clientIdentifier . ' - ' . $DB->error());
      }

      return $result !== false;
   }

   /**
    * Obtém dados da quota de tokens.
    *
    * @return array
    */
   public function getQuotaData() {
      if (!$this->isProxyMode()) {
         $this->cachedQuota = [
            'tokens_limit' => null,
            'tokens_used'  => 0,
            'period_start' => null,
            'period_end'   => null,
            'last_reset_at'=> null
         ];
         return $this->cachedQuota;
      }

      if ($this->cachedQuota !== null) {
         return $this->cachedQuota;
      }

      global $DB;
      $clientIdentifier = $this->getClientIdentifier();

      $iterator = $DB->request([
         'FROM'  => $this->getQuotaTableName(),
         'WHERE' => ['client_identifier' => $clientIdentifier],
         'LIMIT' => 1
      ]);

      if (!count($iterator)) {
         $this->ensureQuotaRow($clientIdentifier, $this->getSettings()['tokens_limit_month']);
         $iterator = $DB->request([
            'FROM'  => $this->getQuotaTableName(),
            'WHERE' => ['client_identifier' => $clientIdentifier],
            'LIMIT' => 1
         ]);
      }

      $this->cachedQuota = $iterator->current();
      return $this->cachedQuota;
   }

   /**
    * Atualiza limite de tokens no registro da quota.
    *
    * @param string $clientIdentifier
    * @param int $tokensLimit
    * @return void
    */
   public function syncQuotaLimit($clientIdentifier, $tokensLimit) {
      global $DB;

      $iterator = $DB->request([
         'FROM'  => $this->getQuotaTableName(),
         'WHERE' => ['client_identifier' => $clientIdentifier],
         'LIMIT' => 1
      ]);

      if (!count($iterator)) {
         $this->ensureQuotaRow($clientIdentifier, $tokensLimit);
         return;
      }

      $row = $iterator->current();
      $DB->update(
         $this->getQuotaTableName(),
         [
            'tokens_limit' => $tokensLimit,
            'date_mod'     => date('Y-m-d H:i:s')
         ],
         ['id' => $row['id']]
      );

      $this->cachedQuota = null;
   }

   /**
    * Reseta saldo de tokens manualmente.
    *
    * @return void
    */
   public function resetQuota() {
      global $DB;
      $clientIdentifier = $this->getClientIdentifier();

      $start = date('Y-m-01');
      $end   = date('Y-m-t');

      $DB->update(
         $this->getQuotaTableName(),
         [
            'tokens_used'   => 0,
            'period_start'  => $start,
            'period_end'    => $end,
            'last_reset_at' => date('Y-m-d H:i:s'),
            'date_mod'      => date('Y-m-d H:i:s')
         ],
         ['client_identifier' => $clientIdentifier]
      );

      $this->cachedQuota = null;
   }

   /**
    * Cria registro padrão de quota se não existir.
    *
    * @param string $clientIdentifier
    * @param int $tokensLimit
    * @return void
    */
   protected function ensureQuotaRow($clientIdentifier, $tokensLimit) {
      global $DB;

      $iterator = $DB->request([
         'FROM'  => $this->getQuotaTableName(),
         'WHERE' => ['client_identifier' => $clientIdentifier],
         'LIMIT' => 1
      ]);

      if (count($iterator)) {
         return;
      }

      $DB->insert(
         $this->getQuotaTableName(),
         [
            'client_identifier' => $clientIdentifier,
            'tokens_limit'      => $tokensLimit,
            'tokens_used'       => 0,
            'period_start'      => date('Y-m-01'),
            'period_end'        => date('Y-m-t'),
            'last_reset_at'     => date('Y-m-d H:i:s'),
            'date_creation'     => date('Y-m-d H:i:s'),
            'date_mod'          => date('Y-m-d H:i:s')
         ]
      );
   }

   /**
    * Garante que exista uma linha na tabela de configuração para o identificador informado.
    *
    * @param string $clientIdentifier
    * @param array  $defaults
    * @return void
    */
   protected function ensureConfigRow($clientIdentifier, array $defaults) {
      global $DB;

      if ($clientIdentifier === '') {
         $clientIdentifier = 'default';
      }

      $iterator = $DB->request([
         'FROM'  => $this->getConfigTableName(),
         'WHERE' => ['client_identifier' => $clientIdentifier],
         'LIMIT' => 1
      ]);

      if (count($iterator)) {
         return;
      }

      // Reaproveita linha legacy "default" caso exista
      $legacyIterator = $DB->request([
         'FROM'  => $this->getConfigTableName(),
         'WHERE' => ['client_identifier' => 'default'],
         'LIMIT' => 1
      ]);

      if (count($legacyIterator)) {
         $row = $legacyIterator->current();
         $DB->update(
            $this->getConfigTableName(),
            [
               'client_identifier' => $clientIdentifier,
               'date_mod'          => date('Y-m-d H:i:s')
            ],
            ['id' => (int)$row['id']]
         );
         return;
      }

      $payload = $defaults;
      unset($payload['api_key'], $payload['has_api_key']);

      $payload['client_identifier'] = $clientIdentifier;
      $payload['date_creation'] = date('Y-m-d H:i:s');
      $payload['date_mod'] = date('Y-m-d H:i:s');

      $DB->insert(
         $this->getConfigTableName(),
         $payload
      );
   }

   /**
    * Recupera linha de configuração pelo identificador informado.
    *
    * @param string $clientIdentifier
    * @return array|null
    */
   protected function getConfigRowByIdentifier($clientIdentifier) {
      if ($clientIdentifier === '' || $clientIdentifier === null) {
         $clientIdentifier = 'default';
      }

      global $DB;
      $iterator = $DB->request([
         'FROM'  => $this->getConfigTableName(),
         'WHERE' => ['client_identifier' => $clientIdentifier],
         'LIMIT' => 1
      ]);

      if (!count($iterator)) {
         return null;
      }

      return $iterator->current();
   }

   /**
    * Inicializa registros padrão após instalação.
    *
    * @return void
    */
   protected function initializeDefaultSettings() {
      $defaults = $this->getDefaultSettings();
      $defaults['client_identifier'] = $this->getClientIdentifier();
      $defaults['api_key'] = '';
      $defaults['has_api_key'] = false;

      $this->saveSettings($defaults);
   }

   /**
    * Ajusta o schema caso tenha sido instalado em versão anterior.
    *
    * @return void
    */
   protected function ensureSchema() {
      global $DB;

      $pluginInfo = plugin_version_nextool();
      $pluginVersion = $pluginInfo['version'] ?? '1.0.0';

      $table = 'glpi_plugin_nextool_aiassist_ticketdata';
      if ($DB->tableExists($table)) {
         $ticketMigration = null;

         if (!$DB->fieldExists($table, 'reply_text')) {
            $ticketMigration = $ticketMigration ?? new \Migration($pluginVersion);
            $ticketMigration->addField($table, 'reply_text', 'longtext', [
               'after' => 'last_summary_followup_id'
            ]);
         }

         if (!$DB->fieldExists($table, 'last_reply_at')) {
            $ticketMigration = $ticketMigration ?? new \Migration($pluginVersion);
            $ticketMigration->addField($table, 'last_reply_at', 'datetime', [
               'after' => 'reply_text',
               'default' => null
            ]);
         }

         if (!$DB->fieldExists($table, 'last_reply_followup_id')) {
            $ticketMigration = $ticketMigration ?? new \Migration($pluginVersion);
            $ticketMigration->addField($table, 'last_reply_followup_id', 'integer', [
               'after' => 'last_reply_at',
               'default' => null
            ]);
         }

         if (!$DB->fieldExists($table, 'last_sentiment_followup_id')) {
            $ticketMigration = $ticketMigration ?? new \Migration($pluginVersion);
            $ticketMigration->addField($table, 'last_sentiment_followup_id', 'integer', [
               'unsigned' => true,
               'default'  => null,
               'after'    => 'last_sentiment_at'
            ]);
         }

         if (!$DB->fieldExists($table, 'sentiment_rationale')) {
          $ticketMigration = $ticketMigration ?? new \Migration($pluginVersion);
            $ticketMigration->addField($table, 'sentiment_rationale', 'text', [
               'after' => 'urgency_level'
            ]);
         }

         if ($ticketMigration !== null) {
            $ticketMigration->executeMigration();
         }
      }

      $configTable = $this->getConfigTableName();
      if ($DB->tableExists($configTable)) {
         $configMigration = null;

         if (!$DB->fieldExists($configTable, 'feature_summary_enabled')) {
            $configMigration = $configMigration ?? new \Migration($pluginVersion);
            $configMigration->addField($configTable, 'feature_summary_enabled', 'integer', [
               'after'   => 'tokens_limit_month',
               'default' => 1
            ]);
         }
         if (!$DB->fieldExists($configTable, 'feature_reply_enabled')) {
            $configMigration = $configMigration ?? new \Migration($pluginVersion);
            $configMigration->addField($configTable, 'feature_reply_enabled', 'integer', [
               'after'   => 'feature_summary_enabled',
               'default' => 1
            ]);
         }
         if (!$DB->fieldExists($configTable, 'feature_sentiment_enabled')) {
            $configMigration = $configMigration ?? new \Migration($pluginVersion);
            $configMigration->addField($configTable, 'feature_sentiment_enabled', 'integer', [
               'after'   => 'feature_reply_enabled',
               'default' => 1
            ]);
         }
         if (!$DB->fieldExists($configTable, 'feature_summary_model')) {
            $configMigration = $configMigration ?? new \Migration($pluginVersion);
            $configMigration->addField($configTable, 'feature_summary_model', 'string', [
               'after'   => 'feature_sentiment_enabled',
               'value'   => 100,
               'default' => null
            ]);
         }
         if (!$DB->fieldExists($configTable, 'feature_reply_model')) {
            $configMigration = $configMigration ?? new \Migration($pluginVersion);
            $configMigration->addField($configTable, 'feature_reply_model', 'string', [
               'after'   => 'feature_summary_model',
               'value'   => 100,
               'default' => null
            ]);
         }
         if (!$DB->fieldExists($configTable, 'feature_sentiment_model')) {
            $configMigration = $configMigration ?? new \Migration($pluginVersion);
            $configMigration->addField($configTable, 'feature_sentiment_model', 'string', [
               'after'   => 'feature_reply_model',
               'value'   => 100,
               'default' => null
            ]);
         }

         if ($configMigration !== null) {
            $configMigration->executeMigration();
         }
      }

      // Cria tabela de histórico se não existir
      $historyTable = 'glpi_plugin_nextool_aiassist_config_history';
      if (!$DB->tableExists($historyTable)) {
         $query = "CREATE TABLE IF NOT EXISTS `$historyTable` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `config_id` INT UNSIGNED NOT NULL,
            `field_name` VARCHAR(100) NOT NULL,
            `old_value` TEXT NULL,
            `new_value` TEXT NULL,
            `users_id` INT UNSIGNED NULL,
            `date_creation` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_config_id` (`config_id`),
            KEY `idx_field_name` (`field_name`),
            KEY `idx_users_id` (`users_id`),
            KEY `idx_date_creation` (`date_creation`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

         // GLPI 11: evitar DB->query() direto, usar doQuery() e logar eventual erro
         if (!$DB->doQuery($query)) {
            Toolbox::logInFile(
               'plugin_nextool',
               'Erro ao criar tabela de histórico de configuração do aiassist: ' . (method_exists($DB, 'error') ? $DB->error() : 'erro desconhecido')
            );
         }
      }
   }

   /**
    * Nome da tabela de configurações.
    *
    * @return string
    */
   protected function getConfigTableName() {
      return 'glpi_plugin_nextool_aiassist_config';
   }

   /**
    * Nome da tabela de quotas.
    *
    * @return string
    */
   protected function getQuotaTableName() {
      return 'glpi_plugin_nextool_aiassist_quota';
   }

   /**
    * Criptografa valor usando GLPIKey quando disponível.
    *
    * @param string $value
    * @return string
    */
   protected function encryptValue($value) {
      if (empty($value)) {
         return '';
      }

      if (class_exists('GLPIKey')) {
         $glpiKey = new GLPIKey();
         return $glpiKey->encrypt($value) ?? '';
      }

      return base64_encode($value);
   }

   /**
    * Descriptografa valor usando GLPIKey quando disponível.
    *
    * @param string $value
    * @return string
    */
   protected function decryptValue($value) {
      if (empty($value)) {
         return '';
      }

      if (class_exists('GLPIKey')) {
         $glpiKey = new GLPIKey();
         $plaintext = $glpiKey->decrypt($value);
         if ($plaintext !== null) {
            return $plaintext;
         }
      }

      $decoded = base64_decode($value, true);
      return $decoded !== false ? $decoded : '';
   }

   /**
    * Testa comunicação com o provedor configurado.
    *
    * @return array
    */
   public function testProviderConnection() {
      $settings = $this->getSettings(['include_secret' => true]);
      $provider = $settings['provider'] ?? self::PROVIDER_OPENAI;

      if (($settings['provider_mode'] ?? self::PROVIDER_MODE_DIRECT) !== self::PROVIDER_MODE_DIRECT) {
         return [
            'success' => false,
            'message' => __('Modo proxy ainda não suportado para teste automático.', 'nextool'),
            'details' => []
         ];
      }

      if (empty($settings['api_key'])) {
         $label = $this->getProviderLabel($provider);
         return [
            'success' => false,
            'message' => sprintf(__('Informe a chave do provedor %s antes de testar.', 'nextool'), $label),
            'details' => []
         ];
      }

      $model = $settings['model'] ?? $this->getDefaultModelForProvider($provider);

      try {
         $client = new \GuzzleHttp\Client([
            'timeout' => max(5, (int)($settings['timeout_seconds'] ?? 25)),
            'verify'  => true,
         ]);

         if ($provider === self::PROVIDER_GEMINI) {
            $endpoint = sprintf(
               'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
               rawurlencode($model)
            );

            $response = $client->request('POST', $endpoint, [
               'headers' => [
                  'Content-Type' => 'application/json',
                  'x-goog-api-key' => $settings['api_key'],
               ],
               'body' => json_encode([
                  'contents' => [
                     [
                        'role' => 'user',
                        'parts' => [
                           ['text' => 'Ping'],
                        ],
                     ],
                  ],
               ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
         } else {
            $response = $client->request('GET', 'https://api.openai.com/v1/models/' . urlencode($model), [
               'headers' => [
                  'Authorization' => 'Bearer ' . $settings['api_key'],
                  'Content-Type'  => 'application/json',
               ],
            ]);
         }

         $status = $response->getStatusCode();
         if ($status >= 200 && $status < 300) {
            return [
               'success' => true,
               'message' => sprintf(__('Conexão bem-sucedida com o modelo %s.', 'nextool'), $model),
               'details' => [
                  'status' => $status
               ]
            ];
         }

         return [
            'success' => false,
            'message' => sprintf(__('%s respondeu com status %s.', 'nextool'), $this->getProviderLabel($provider), $status),
            'details' => [
               'status' => $status,
               'body'   => (string)$response->getBody()
            ]
         ];
      } catch (\GuzzleHttp\Exception\RequestException $e) {
         $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
         $body   = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();

         Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
            '[TEST] Falha ao testar %s: %s',
            $this->getProviderLabel($provider),
            $body
         ));

         return [
            'success' => false,
            'message' => sprintf(__('Falha ao conectar na API do %s. Verifique a chave e permissões.', 'nextool'), $this->getProviderLabel($provider)),
            'details' => [
               'status' => $status,
               'body'   => $body
            ]
         ];
      } catch (\Exception $e) {
         Toolbox::logInFile('plugin_nextool_aiassist', sprintf(
            '[TEST] Erro inesperado: %s',
            $e->getMessage()
         ));

         return [
            'success' => false,
            'message' => __('Erro inesperado ao testar a conexão.', 'nextool'),
            'details' => [
               'body' => $e->getMessage()
            ]
         ];
      }
   }

   /**
    * Caminho para arquivo de log do módulo.
    *
    * @return string
    */
   public function getLogPath() {
      return GLPI_ROOT . '/files/_log/plugin_nextool_aiassist.log';
   }

   /**
    * Retorna instância do provider configurado.
    *
    * @return PluginNextoolAiassistProviderInterface
    */
   public function getProviderInstance() {
      if ($this->providerInstance === null) {
         $settings = $this->getSettings(['include_secret' => true]);
         $provider = $settings['provider'] ?? self::PROVIDER_OPENAI;

         switch ($provider) {
            case self::PROVIDER_GEMINI:
               require_once __DIR__ . '/providers/geminiprovider.class.php';
               $this->providerInstance = new PluginNextoolAiassistGeminiProvider($settings);
               break;
            case self::PROVIDER_OPENAI:
            default:
               require_once __DIR__ . '/providers/openaiprovider.class.php';
               $this->providerInstance = new PluginNextoolAiassistOpenAiProvider($settings);
               break;
         }
      }

      return $this->providerInstance;
   }

   /**
    * Retorna o modelo a ser usado para uma funcionalidade específica.
    * Se não houver modelo específico configurado, retorna o modelo padrão.
    *
    * @param string $feature
    * @return string
    */
   public function getFeatureModel($feature) {
      $settings = $this->getSettings();
      $defaultModel = $settings['model'] ?? 'gpt-4o-mini';

      switch ($feature) {
         case self::FEATURE_SUMMARY:
            return !empty($settings['feature_summary_model']) ? $settings['feature_summary_model'] : $defaultModel;
         case self::FEATURE_REPLY:
            return !empty($settings['feature_reply_model']) ? $settings['feature_reply_model'] : $defaultModel;
         case self::FEATURE_SENTIMENT:
            return !empty($settings['feature_sentiment_model']) ? $settings['feature_sentiment_model'] : $defaultModel;
         default:
            return $defaultModel;
      }
   }

   /**
    * @return PluginNextoolAiassistSummaryService
    */
   public function getSummaryService() {
      if (!isset($this->serviceCache['summary'])) {
         require_once __DIR__ . '/services/summaryservice.class.php';
         $this->serviceCache['summary'] = new PluginNextoolAiassistSummaryService($this);
      }
      return $this->serviceCache['summary'];
   }

   /**
    * @return PluginNextoolAiassistReplySuggestionService
    */
   public function getReplyService() {
      if (!isset($this->serviceCache['reply'])) {
         require_once __DIR__ . '/services/replyservice.class.php';
         $this->serviceCache['reply'] = new PluginNextoolAiassistReplyService($this);
      }
      return $this->serviceCache['reply'];
   }

   /**
    * @return PluginNextoolAiassistSentimentService
    */
   public function getSentimentService() {
      if (!isset($this->serviceCache['sentiment'])) {
         require_once __DIR__ . '/services/sentimentservice.class.php';
         $this->serviceCache['sentiment'] = new PluginNextoolAiassistSentimentService($this);
      }
      return $this->serviceCache['sentiment'];
   }

   /**
    * Retorna estatísticas mensais (últimos N meses).
    *
    * @param int $months
    * @return array
    */
   public function getMonthlyUsageStats($months = 3) {
      $months = max(1, (int)$months);
      $stats = [];
      for ($i = $months - 1; $i >= 0; $i--) {
         $periodStart = date('Y-m-01', strtotime("-{$i} month"));
         $periodEnd = date('Y-m-t', strtotime("-{$i} month"));
         $stats[] = array_merge(
            $this->buildUsageStatsForPeriod($periodStart, $periodEnd),
            [
               'period_start' => $periodStart,
               'period_end'   => $periodEnd,
               'label'        => Html::convDate($periodStart, false) . ' → ' . Html::convDate($periodEnd, false)
            ]
         );
      }
      return $stats;
   }

   /**
    * Retorna consumo por feature para um período.
    *
    * @param string $startDate
    * @param string $endDate
    * @return array
    */
   public function getFeatureUsageStats($startDate, $endDate) {
      global $DB;

      $start = date('Y-m-d 00:00:00', strtotime($startDate));
      $end   = date('Y-m-d 23:59:59', strtotime($endDate));

      $rows = $DB->request([
         'SELECT' => [
            'feature',
            new \QueryExpression('SUM(tokens_prompt) AS tokens_prompt_sum'),
            new \QueryExpression('SUM(tokens_completion) AS tokens_completion_sum'),
            new \QueryExpression('COUNT(*) AS total_calls')
         ],
         'FROM'   => 'glpi_plugin_nextool_aiassist_requests',
         'WHERE'  => [
            'client_identifier' => $this->getClientIdentifier(false),
            ['date_creation' => ['>=', $start]],
            ['date_creation' => ['<=', $end]],
         ],
         'GROUP'  => ['feature'],
      ]);

      $result = [];
      foreach ($rows as $row) {
         $feature = $row['feature'] ?: 'unknown';
         $result[$feature] = [
            'tokens_prompt'     => (int)($row['tokens_prompt_sum'] ?? 0),
            'tokens_completion' => (int)($row['tokens_completion_sum'] ?? 0),
            'tokens_total'      => (int)($row['tokens_prompt_sum'] ?? 0) + (int)($row['tokens_completion_sum'] ?? 0),
            'total_calls'       => (int)$row['total_calls'],
         ];
      }

      return $result;
   }

   /**
    * Constrói estatísticas básicas para um período.
    *
    * @param string $startDate
    * @param string $endDate
    * @return array
    */
   protected function buildUsageStatsForPeriod($startDate, $endDate) {
      global $DB;

      $start = date('Y-m-d 00:00:00', strtotime($startDate));
      $end   = date('Y-m-d 23:59:59', strtotime($endDate));

      $rows = $DB->request([
         'SELECT' => [
            new \QueryExpression('SUM(tokens_prompt) AS tokens_prompt_sum'),
            new \QueryExpression('SUM(tokens_completion) AS tokens_completion_sum'),
            new \QueryExpression("SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS success_calls"),
            new \QueryExpression("SUM(CASE WHEN status!='success' THEN 1 ELSE 0 END) AS error_calls"),
         ],
         'FROM'  => 'glpi_plugin_nextool_aiassist_requests',
         'WHERE' => [
            'client_identifier' => $this->getClientIdentifier(false),
            ['date_creation' => ['>=', $start]],
            ['date_creation' => ['<=', $end]],
         ],
      ]);

      $row = $rows->current() ?: [];
      $tokensPrompt = (int)($row['tokens_prompt_sum'] ?? 0);
      $tokensCompletion = (int)($row['tokens_completion_sum'] ?? 0);
      $tokensTotal = $tokensPrompt + $tokensCompletion;
      $successCalls = (int)($row['success_calls'] ?? 0);
      $errorCalls = (int)($row['error_calls'] ?? 0);
      $limit = (int)($this->getQuotaData()['tokens_limit'] ?? 0);

      $percentage = $limit > 0 ? min(100, round(($tokensTotal / $limit) * 100, 2)) : null;

      return [
         'tokens_prompt'     => $tokensPrompt,
         'tokens_completion' => $tokensCompletion,
         'tokens_total'      => $tokensTotal,
         'success_calls'     => $successCalls,
         'error_calls'       => $errorCalls,
         'limit'             => $limit,
         'percentage'        => $percentage,
      ];
   }

   /**
    * Registra requisição em glpi_plugin_nextool_aiassist_requests.
    *
    * @param array $data
    * @return void
    */
   public function logFeatureRequest(array $data) {
      global $DB;

      $payload = [
         'client_identifier' => $this->getClientIdentifier(false),
         'tickets_id'        => $data['tickets_id'] ?? null,
         'users_id'          => $data['users_id'] ?? null,
         'feature'           => $data['feature'] ?? 'unknown',
         'status'            => $data['success'] ?? false ? 'success' : 'error',
         'tokens_prompt'     => $data['tokens_prompt'] ?? 0,
         'tokens_completion' => $data['tokens_completion'] ?? 0,
         'payload_hash'      => $data['payload_hash'] ?? null,
         'error_code'        => $data['error_code'] ?? null,
         'error_message'     => $data['error_message'] ?? null,
         'date_creation'     => date('Y-m-d H:i:s'),
      ];

      $DB->insert(
         'glpi_plugin_nextool_aiassist_requests',
         $payload
      );

      $this->incrementTokensUsed(
         (int)$payload['tokens_prompt'] + (int)$payload['tokens_completion']
      );
   }

   /**
    * Atualiza saldo consumido.
    *
    * @param int $tokens
    * @return void
    */
   public function incrementTokensUsed($tokens) {
      if ($tokens <= 0) {
         return;
      }

      if (!$this->isProxyMode()) {
         // Sem controle local de quota quando usa chave própria.
         return;
      }

      global $DB;
      $clientIdentifier = $this->getClientIdentifier();
      $quota = $this->getQuotaData();

      $current = (int)($quota['tokens_used'] ?? 0);
      $limit   = (int)($quota['tokens_limit'] ?? 0);
      $newValue = min($limit, $current + (int)$tokens);

      $DB->update(
         $this->getQuotaTableName(),
         [
            'tokens_used' => $newValue,
            'date_mod'    => date('Y-m-d H:i:s')
         ],
         ['client_identifier' => $clientIdentifier]
      );

      $this->cachedQuota = null;
   }

   /**
    * Verifica se há saldo disponível.
    *
    * @param int $tokensNeeded
    * @return bool
    */
   public function hasTokensAvailable($tokensNeeded) {
      if (!$this->isProxyMode()) {
         return true;
      }

      $quota = $this->getQuotaData();
      if (empty($quota)) {
         return false;
      }

      $limit = (int)($quota['tokens_limit'] ?? 0);
      $used  = (int)($quota['tokens_used'] ?? 0);

      return ($used + $tokensNeeded) <= $limit;
   }

   /**
    * Armazena o resumo em glpi_plugin_nextool_aiassist_ticketdata.
    *
    * @return void
    */
   public function saveSummaryData(int $ticketId, array $data) {
      global $DB;

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_aiassist_ticketdata',
         'WHERE' => ['tickets_id' => $ticketId],
         'LIMIT' => 1
      ]);

      $payload = [
         'summary_text' => $data['summary_text'],
         'summary_hash' => $data['summary_hash'],
         'last_summary_at' => date('Y-m-d H:i:s'),
         'last_summary_followup_id' => $data['last_followup_id'],
         'cache_payload_hash' => $data['payload_hash'],
         'date_mod' => date('Y-m-d H:i:s')
      ];

      if (count($iterator)) {
         $row = $iterator->current();
         $DB->update(
            'glpi_plugin_nextool_aiassist_ticketdata',
            $payload,
            ['id' => $row['id']]
         );
      } else {
         $payload['tickets_id'] = $ticketId;
         $payload['date_creation'] = date('Y-m-d H:i:s');
         $DB->insert(
            'glpi_plugin_nextool_aiassist_ticketdata',
            $payload
         );
      }
   }

   /**
    * Atualiza informações de sentimento/urgência.
    *
    * @param int   $ticketId
    * @param array $data
    * @return void
    */
   public function saveSentimentData(int $ticketId, array $data) {
      global $DB;

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_aiassist_ticketdata',
         'WHERE' => ['tickets_id' => $ticketId],
         'LIMIT' => 1
      ]);

      $payload = [
         'sentiment_label' => $data['sentiment_label'],
         'sentiment_score' => $data['sentiment_score'],
         'urgency_level'   => $data['urgency_level'],
         'sentiment_rationale' => $data['sentiment_rationale'] ?? null,
         'last_sentiment_at' => date('Y-m-d H:i:s'),
         'last_sentiment_followup_id' => $data['last_followup_id'],
         'date_mod' => date('Y-m-d H:i:s')
      ];

      if (count($iterator)) {
         $row = $iterator->current();
         $DB->update(
            'glpi_plugin_nextool_aiassist_ticketdata',
            $payload,
            ['id' => $row['id']]
         );
      } else {
         $payload['tickets_id'] = $ticketId;
         $payload['date_creation'] = date('Y-m-d H:i:s');
         $DB->insert(
            'glpi_plugin_nextool_aiassist_ticketdata',
            $payload
         );
      }

      unset($this->ticketDataCache[$ticketId]);
   }

   /**
    * Persiste última sugestão de resposta.
    *
    * @param int   $ticketId
    * @param array $data
    * @return void
    */
   public function saveReplyData(int $ticketId, array $data) {
      global $DB;

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_aiassist_ticketdata',
         'WHERE' => ['tickets_id' => $ticketId],
         'LIMIT' => 1
      ]);

      $payload = [
         'reply_text' => $data['reply_text'],
         'last_reply_at' => date('Y-m-d H:i:s'),
         'last_reply_followup_id' => $data['last_followup_id'],
         'date_mod' => date('Y-m-d H:i:s')
      ];

      if (count($iterator)) {
         $row = $iterator->current();
         $DB->update(
            'glpi_plugin_nextool_aiassist_ticketdata',
            $payload,
            ['id' => $row['id']]
         );
      } else {
         $payload['tickets_id'] = $ticketId;
         $payload['date_creation'] = date('Y-m-d H:i:s');
         $DB->insert(
            'glpi_plugin_nextool_aiassist_ticketdata',
            $payload
         );
      }

      unset($this->ticketDataCache[$ticketId]);
   }

   /**
    * Recupera os dados consolidados do ticket na tabela do módulo.
    *
    * @param int $ticketId
    * @return array
    */
   public function getTicketData($ticketId) {
      $ticketId = (int)$ticketId;
      if ($ticketId <= 0) {
         return [];
      }

      if (!isset($this->ticketDataCache[$ticketId])) {
         global $DB;
         $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_nextool_aiassist_ticketdata',
            'WHERE' => ['tickets_id' => $ticketId],
            'LIMIT' => 1
         ]);
         $this->ticketDataCache[$ticketId] = count($iterator) ? $iterator->current() : [];
      }

      return $this->ticketDataCache[$ticketId];
   }

   /**
    * Retorna o último acompanhamento público do ticket.
    *
    * @param int $ticketId
    * @return int|null
    */
   public function getLatestFollowupId($ticketId) {
      $ticketId = (int)$ticketId;
      if ($ticketId <= 0) {
         return null;
      }

      if (!isset($this->latestFollowupCache[$ticketId])) {
         global $DB;

         $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_itilfollowups',
            'WHERE'  => [
               'itemtype' => 'Ticket',
               'items_id' => $ticketId,
               'is_private' => 0
            ],
            'ORDER'  => 'date DESC',
            'LIMIT'  => 1
         ]);

         $this->latestFollowupCache[$ticketId] = count($iterator) ? (int)$iterator->current()['id'] : null;
      }

      return $this->latestFollowupCache[$ticketId];
   }

   /**
    * Retorna minutos configurados de rate limit.
    */
   public function getRateLimitMinutes() {
      return max(0, (int)($this->getSettings()['rate_limit_minutes'] ?? 0));
   }

   /**
    * Verifica se o recurso está em período de cooldown.
    */
   public function isFeatureRateLimited($feature, $ticketId, $userId) {
      global $DB;

      $minutes = $this->getRateLimitMinutes();
      if ($minutes <= 0) {
         return false;
      }

      $threshold = date('Y-m-d H:i:s', time() - ($minutes * 60));

      $where = [
         'feature'    => $feature,
         'tickets_id' => $ticketId,
      ];

      if ($userId > 0) {
         $where['users_id'] = $userId;
      }

      $where[] = ['date_creation' => ['>=', $threshold]];

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_aiassist_requests',
         'WHERE' => $where,
         'LIMIT' => 1
      ]);

      return count($iterator) > 0;
   }

   /**
    * Retorna mensagem de bloqueio para o recurso (ou string vazia).
    */
   public function getFeatureBlockReason($feature, $ticketId, $userId) {
      $message = '';
      $this->checkFeatureAvailability($feature, $ticketId, $userId, $message);
      return $message;
   }

   /**
    * Valida se o recurso pode ser executado.
    *
    * @return bool
    */
   public function checkFeatureAvailability($feature, $ticketId, $userId, &$message = null) {
      $settings = $this->getSettings();

      // Verifica se a funcionalidade está habilitada
      switch ($feature) {
         case self::FEATURE_SUMMARY:
            if (empty($settings['feature_summary_enabled'])) {
               $message = __('A funcionalidade de resumo está desabilitada nas configurações do módulo.', 'nextool');
               return false;
            }
            break;

         case self::FEATURE_REPLY:
            if (empty($settings['feature_reply_enabled'])) {
               $message = __('A funcionalidade de sugestão de resposta está desabilitada nas configurações do módulo.', 'nextool');
               return false;
            }
            break;

         case self::FEATURE_SENTIMENT:
            if (empty($settings['feature_sentiment_enabled'])) {
               $message = __('A funcionalidade de análise de sentimento está desabilitada nas configurações do módulo.', 'nextool');
               return false;
            }
            break;
      }

      $ticketData = $this->getTicketData($ticketId);
      $latestFollowupId = $this->getLatestFollowupId($ticketId);

      switch ($feature) {
         case self::FEATURE_SUMMARY:
            // Temporariamente desativado: permitir regenerar resumo mesmo sem novo acompanhamento
            // if (!empty($ticketData['last_summary_at'])) {
            //    if (empty($latestFollowupId) || (int)$ticketData['last_summary_followup_id'] === (int)$latestFollowupId) {
            //       $message = __('É necessário registrar um novo acompanhamento antes de gerar outro resumo.', 'nextool');
            //       return false;
            //    }
            // }
            break;

         case self::FEATURE_REPLY:
            // Bloqueio removido: permitir regenerar sugestão mesmo sem novo acompanhamento
            // O sistema agora retorna cache quando não há novos followups (com opção de forçar nova geração)
            // if (!empty($ticketData['last_reply_at'])) {
            //    if (empty($latestFollowupId) || (int)$ticketData['last_reply_followup_id'] === (int)$latestFollowupId) {
            //       $message = __('Somente é possível solicitar nova sugestão após um novo acompanhamento.', 'nextool');
            //       return false;
            //    }
            // }
            break;

         case self::FEATURE_SENTIMENT:
            // Permitimos recalcular sentimento mesmo sem novos registros.
            break;
      }

      if ($this->isFeatureRateLimited($feature, $ticketId, $userId)) {
         $minutes = $this->getRateLimitMinutes();
         if ($minutes > 0) {
            $message = sprintf(__('Aguarde %d minutos para solicitar novamente.', 'nextool'), $minutes);
         } else {
            $message = __('Aguarde antes de solicitar novamente.', 'nextool');
         }
         return false;
      }

      $message = '';
      return true;
   }

   /**
    * Renderiza badge antes do formulário do ticket.
    *
    * @param CommonGLPI $item
    * @param array      $options
    * @return void
    */
   public static function preTicketForm(CommonGLPI $item, $options = []) {
      // Verifica permissão de visualização do módulo
      if (!PluginNextoolPermissionManager::canViewModule('aiassist')) {
         return;
      }

      if (!($item instanceof Ticket)) {
         return;
      }

      static $rendered = [];
      $ticketId = (int)$item->getID();
      if (isset($rendered[$ticketId])) {
         return;
      }

      $rendered[$ticketId] = true;

      $module = new self();
      $settings = $module->getSettings();
      if (empty($settings['feature_sentiment_enabled'])) {
         return;
      }
      $data = $module->getTicketData($ticketId);

      if (empty($data['sentiment_label'])) {
         return;
      }

      echo $module->buildSentimentBadge($data);
   }

   /**
    * Renderiza campo dentro do formulário do ticket.
    *
    * @param CommonGLPI $item
    * @param array      $options
    *
    * @return void
    */
   public static function postTicketForm($param1 = null, $param2 = null) {
      // Verifica permissão de visualização do módulo
      if (!PluginNextoolPermissionManager::canViewModule('aiassist')) {
         return;
      }

      $item = null;
      $options = [];

      if ($param1 instanceof CommonGLPI) {
         $item = $param1;
         $options = is_array($param2) ? $param2 : [];
      } elseif (is_array($param1)) {
         $item = $param1['item'] ?? null;
         $options = $param1['options'] ?? [];
      }

      if (!($item instanceof Ticket)) {
         return;
      }

      static $rendered = [];
      $ticketId = (int)$item->getID();
      if (isset($rendered[$ticketId])) {
         return;
      }

      $module   = new self();
      $settings = $module->getSettings();
      $data     = $module->getTicketData($ticketId);

      $rendered[$ticketId] = true;

      // Marcador simples para depuração: confirma que o hook post_item_form foi executado
      echo "\n<!-- AIASSIST postTicketForm ticket #{$ticketId} -->\n";

      // Campo de sentimento permanece condicionado à feature
      if (!empty($settings['feature_sentiment_enabled'])) {
         echo $module->buildSentimentField($data, $ticketId);
      }

      // Botão rápido de resumo no formulário do chamado (ações da timeline)
      if (!empty($settings['feature_summary_enabled'])) {
         echo $module->buildSummaryQuickButtonScript($ticketId);
      }
   }

   /**
    * Monta HTML do badge de sentimento/urgência.
    *
    * @param array $data
    * @return string
    */
   protected function buildSentimentBadge(array $data) {
      $label   = Html::entities_deep($data['sentiment_label']);
      $score   = isset($data['sentiment_score']) ? (float)$data['sentiment_score'] : null;
      $urgency = isset($data['urgency_level']) ? Html::entities_deep($data['urgency_level']) : '';
      $updated = $data['last_sentiment_at'] ?? ($data['date_mod'] ?? null);

      $sentimentPalette = $this->resolveSentimentPalette($label);
      $urgencyPalette   = $this->resolveUrgencyPalette($urgency);

      $html  = '<div class="aiassist-sentiment-banner shadow-sm mb-3" style="background:linear-gradient(120deg,#7c3aed,#c084fc);color:#fff;border:none;border-radius:12px;padding:16px;">';
      $html .= '<div class="d-flex flex-wrap justify-content-between align-items-center gap-3">';
      $html .= '<div>';
      $html .= '<strong><i class="ti ti-robot me-2"></i>AI Assist</strong>';
      if (!empty($updated)) {
         $html .= '<div class="small text-white-50">' . sprintf(__('Última análise: %s', 'nextool'), Html::convDateTime($updated)) . '</div>';
      }
      $html .= '</div>';
      $html .= '<div class="d-flex flex-wrap gap-2">';
      $html .= '<span class="aiassist-chip" style="background:' . $sentimentPalette['bg'] . ';color:' . $sentimentPalette['color'] . ';">';
      $html .= __('Sentimento', 'nextool') . ': ' . $label;
      if ($score !== null) {
         $html .= sprintf(' (%.2f)', $score);
      }
      $html .= '</span>';
      if (!empty($urgency)) {
         $html .= '<span class="aiassist-chip" style="background:' . $urgencyPalette['bg'] . ';color:' . $urgencyPalette['color'] . ';">';
         $html .= __('Urgência', 'nextool') . ': ' . $urgency;
         $html .= '</span>';
      }
      $html .= '</div>';
      $html .= '</div>';
      $html .= '</div>';

      static $stylePrinted = false;
      if (!$stylePrinted) {
         $html .= '<style>
            .aiassist-sentiment-banner .aiassist-chip {
               padding:0.25rem 0.85rem;
               border-radius:999px;
               font-weight:600;
               font-size:0.75rem;
               text-transform:uppercase;
            }
         </style>';
         $stylePrinted = true;
      }

      return $html;
   }

   /**
    * Monta bloco semelhante aos campos nativos do GLPI evidenciando sentimento/urgência.
    *
    * @param array $data
    * @param int   $ticketId
    * @return string
    */
   protected function buildSentimentField(array $data, $ticketId = 0) {
      $hasSentiment = !empty($data['sentiment_label']);
      $rawLabel = $hasSentiment ? $data['sentiment_label'] : '';
      $label   = $hasSentiment ? Html::entities_deep($data['sentiment_label']) : '';
      $score   = $hasSentiment && isset($data['sentiment_score']) ? (float)$data['sentiment_score'] : null;
      $updated = $hasSentiment ? ($data['last_sentiment_at'] ?? ($data['date_mod'] ?? null)) : null;

      $sentimentEmoji   = $this->resolveSentimentEmoji($rawLabel);

      $scoreText = $score !== null ? sprintf('%.1f', $score) : null;

      $ticketId   = (int)$ticketId > 0 ? (int)$ticketId : (int)($data['tickets_id'] ?? 0);
      $fieldId    = 'aiassist-sentiment-field-' . ($ticketId > 0 ? $ticketId : uniqid());
      $toneSlug   = $hasSentiment ? Toolbox::slugify($rawLabel) : '';

      $cardBackground = $hasSentiment ? $this->resolveSentimentBackground($rawLabel) : null;
      $cardStyle = ($hasSentiment && $cardBackground)
         ? sprintf(' style="background:%s;border-left:4px solid %s;border-color:%s"', $cardBackground['background'], $cardBackground['border'], $cardBackground['border'])
         : '';

      $fieldHtml  = '<div id="' . $fieldId . '" class="form-field col-12 glpi-full-width aiassist-sentiment-field" data-ticket-id="' . $ticketId . '">';
      $fieldHtml .= '   <div class="aiassist-sentiment-card' . ($hasSentiment ? ' has-data' : '') . '" data-tone="' . Html::entities_deep($toneSlug) . '"' . $cardStyle . '>';

      $fieldHtml .= '      <div class="aiassist-sentiment-card__header">';
      $fieldHtml .= '         <div>';
      $titleText = $hasSentiment
         ? sprintf('%s %s%s',
            $sentimentEmoji,
            sprintf(__('Sentimento %s', 'nextool'), $label),
            $scoreText !== null ? ' ' . $scoreText : ''
         )
         : __('Sentimento (AI)', 'nextool');
      $fieldHtml .= '            <span class="aiassist-card-title">' . Html::entities_deep($titleText) . '</span>';
      if (!empty($updated)) {
         $fieldHtml .= '            <small><i class="ti ti-clock me-1"></i>' . sprintf(__('Atualizado em %s', 'nextool'), Html::convDateTime($updated)) . '</small>';
      } elseif (!$hasSentiment) {
         $fieldHtml .= '            <small>' . __('Nenhuma análise registrada ainda.', 'nextool') . '</small>';
      }
      if ($hasSentiment && !empty($data['sentiment_rationale'])) {
         $fieldHtml .= '            <p class="aiassist-inline-rationale">' . Html::entities_deep($data['sentiment_rationale']) . '</p>';
      }
      $fieldHtml .= '         </div>';
      $fieldHtml .= '         <button type="button" class="btn btn-aiassist-request btn-sm aiassist-sentiment-request" data-aiassist-request="sentiment">';
      $fieldHtml .= '            <i class="ti ti-robot me-1"></i>';
      $fieldHtml .= '            <span>' . ($hasSentiment ? __('Recalcular', 'nextool') : __('Analisar agora', 'nextool')) . '</span>';
      $fieldHtml .= '         </button>';
      $fieldHtml .= '      </div>';

      if (!$hasSentiment) {
         $fieldHtml .= '      <div class="aiassist-sentiment-empty">';
         $fieldHtml .= '         <i class="ti ti-mood-plus"></i>';
         $fieldHtml .= '         <p>' . __('Solicite a primeira análise para visualizar sentimento e urgência deste chamado.', 'nextool') . '</p>';
         $fieldHtml .= '      </div>';
      }

      $fieldHtml .= '   </div>';
      $fieldHtml .= '</div>';

      $html = $fieldHtml;
      static $fieldStylePrinted = false;
      if (!$fieldStylePrinted) {
         $html .= '<style>
            .aiassist-sentiment-field {
               margin-bottom:1rem;
            }
            .aiassist-sentiment-card {
               border:1px solid rgba(124,58,237,0.15);
               border-radius:16px;
               padding:1.1rem 1.25rem;
               background:linear-gradient(180deg,#ffffff 0%,#fdf7ff 100%);
               box-shadow:0 8px 24px rgba(15,23,42,0.05);
            }
            .aiassist-sentiment-card.has-data {
               padding-left:1.15rem;
            }
            .aiassist-sentiment-card__header {
               display:flex;
               justify-content:space-between;
               align-items:flex-start;
               gap:1rem;
            }
            .aiassist-card-title {
               display:block;
               font-weight:700;
               font-size:0.95rem;
               color:#312e81;
            }
            .aiassist-sentiment-card__header small {
               display:flex;
               align-items:center;
               gap:0.35rem;
               color:#64748b;
            }
            .aiassist-sentiment-empty {
               margin-top:1rem;
               padding:1.5rem;
               border:1px dashed rgba(124,58,237,0.3);
               border-radius:12px;
               text-align:center;
               color:#6941c6;
               background:rgba(249,245,255,0.8);
            }
            .aiassist-sentiment-empty i {
               font-size:1.5rem;
               display:block;
               margin-bottom:0.5rem;
            }
            .aiassist-inline-rationale {
               margin:0.5rem 0 0;
               color:#475569;
               line-height:1.4;
            }
            .aiassist-sentiment-field .aiassist-sentiment-request {
               align-self:flex-start;
               background: linear-gradient(120deg,#7c3aed,#c084fc);
               border: none;
               color: #fff;
               font-weight: 600;
               box-shadow: 0 6px 16px rgba(124,58,237,0.25);
            }
            .aiassist-sentiment-field .aiassist-sentiment-request:hover {
               filter: brightness(1.05);
            }
            .aiassist-sentiment-field .aiassist-sentiment-request:focus-visible {
               outline: 2px solid rgba(124,58,237,0.3);
               outline-offset: 2px;
            }
         </style>';
         $fieldStylePrinted = true;
      }

      $endpoint = Plugin::getWebDir('nextool') . '/ajax/module_ajax.php?module=aiassist&file=aiassist.action.php';
      $quickMessages = [
         'processing'        => __('Processando...', 'nextool'),
         'generic_error'     => __('Não foi possível concluir a análise.', 'nextool'),
         'unexpected_error'  => __('Erro inesperado. Tente novamente em instantes.', 'nextool')
      ];
      
      $quickConfig = [
         'selector' => '#' . $fieldId,
         'endpoint' => $endpoint,
         'ticketId' => $ticketId,
         'messages' => $quickMessages
      ];
      
      $configJson = json_encode($quickConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
      $configId = 'aiassist_config_' . md5($fieldId . $ticketId);

      $script = <<<JS
(function() {
   var configId = '{$configId}';
   var quickConfig = window[configId] || {};
   var selector = quickConfig.selector || '';

   function moveField() {
      var field = document.querySelector(selector);
      if (!field) {
         return false;
      }

      var form = document.querySelector('#itil-form') || document.querySelector('form[name="ticketform"]');
      if (!form) {
         return false;
      }

      var accordion = form.querySelector('#item-main .accordion-body') || form.querySelector('.accordion-body');
      if (!accordion) {
         return false;
      }

      var host = accordion.querySelector('.row') || accordion;

      if (field.parentElement !== host || field !== host.firstElementChild) {
         host.insertAdjacentElement('afterbegin', field);
      }

      field.classList.add('aiassist-sentiment-field--hydrated');
      bindQuickTrigger(field);
      return true;
   }

   function ensureAiTabVisible() {
      var tabTrigger = null;
      var tabs = document.querySelectorAll('[data-bs-target], a[href]');
      for (var i = 0; i < tabs.length; i++) {
         var target = tabs[i].getAttribute('data-bs-target') || tabs[i].getAttribute('href');
         if (target === '#aiassist-tab') {
            tabTrigger = tabs[i];
            break;
         }
      }
      if (!tabTrigger) {
         return false;
      }

      try {
         if (window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
         } else if (typeof tabTrigger.click === 'function') {
            tabTrigger.click();
         }
      } catch (error) {
         if (typeof tabTrigger.click === 'function') {
            tabTrigger.click();
         }
      }

      return true;
   }

   function triggerSentimentAction() {
      var tab = document.getElementById('aiassist-tab');
      var moduleButton = tab ? tab.querySelector('[data-aiassist-action="sentiment"]') : null;
      if (moduleButton) {
         moduleButton.click();
         return;
      }

      var attempts = 0;
      var watcher = setInterval(function() {
         attempts++;
         var tabEl = document.getElementById('aiassist-tab');
         var btn = tabEl ? tabEl.querySelector('[data-aiassist-action="sentiment"]') : null;
         if (btn) {
            btn.click();
            clearInterval(watcher);
         } else if (attempts > 40) {
            clearInterval(watcher);
         }
      }, 200);
   }

   function executeQuickAnalysis(button) {
      if (!window.fetch || !window.URLSearchParams) {
         return false;
      }

      var form = document.querySelector('#itil-form');
      var csrfInput = form ? form.querySelector('input[name="_glpi_csrf_token"]') : null;
      var csrfToken = csrfInput ? csrfInput.value : '';
      if (!csrfToken) {
         return false;
      }

      if (button.dataset.loading === '1') {
         return true;
      }

      var originalHtml = button.dataset.originalHtml || button.innerHTML;
      button.dataset.originalHtml = originalHtml;
      button.dataset.loading = '1';
      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + quickConfig.messages.processing;

      var params = new URLSearchParams();
      params.append('_glpi_csrf_token', csrfToken);
      params.append('tickets_id', quickConfig.ticketId);
      params.append('action', 'sentiment');

      fetch(quickConfig.endpoint, {
         method: 'POST',
         headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': csrfToken,
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
         },
         credentials: 'same-origin',
         body: params.toString()
      })
      .then(function(response) {
         return response.text().then(function(text) {
            var payload = null;
            try {
               payload = text ? JSON.parse(text) : null;
            } catch (error) {
               payload = null;
            }
            return { ok: response.ok, payload: payload };
         });
      })
      .then(function(result) {
         var payload = result.payload || {};
         if (payload.next_csrf_token && csrfInput) {
            csrfInput.value = payload.next_csrf_token;
         }

         if (result.ok && payload.success) {
            window.location.reload();
            return;
         }

         notifyError(payload.message || quickConfig.messages.generic_error);
      })
      .catch(function() {
         notifyError(quickConfig.messages.unexpected_error);
      })
      .finally(function() {
         button.disabled = false;
         button.dataset.loading = '0';
         button.innerHTML = button.dataset.originalHtml || originalHtml;
      });

      return true;
   }

   function notifyError(message) {
      if (typeof glpi_toast_error === 'function') {
         glpi_toast_error(message);
         return;
      }
      console.error('[AI Assist] ' + message);
   }

   function bindQuickTrigger(field) {
      var quickButton = field.querySelector('.aiassist-sentiment-request');
      if (!quickButton || quickButton.dataset.bound === '1') {
         return;
      }
      quickButton.dataset.bound = '1';

      quickButton.addEventListener('click', function() {
         if (!executeQuickAnalysis(quickButton)) {
            ensureAiTabVisible();
            triggerSentimentAction();
         }
      });
   }

   function bootstrap() {
      if (moveField()) {
         return;
      }

      var attempts = 0;
      var watcher = setInterval(function() {
         attempts++;
         if (moveField() || attempts > 50) {
            clearInterval(watcher);
         }
      }, 120);
   }

   if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bootstrap);
   } else {
      bootstrap();
   }
})();
JS;

      $html .= Html::scriptBlock('window.' . $configId . ' = ' . $configJson . ';');
      $html .= Html::scriptBlock($script);

      return $html;
   }

   /**
    * Script JS para injetar o botão "Resumo (AI)" ao lado das ações do chamado.
    *
    * @param int $ticketId
    * @return string
    */
   protected function buildSummaryQuickButtonScript($ticketId) {
      $ticketId = (int)$ticketId;
      if ($ticketId <= 0) {
         return '';
      }

      $endpoint = Plugin::getWebDir('nextool') . '/ajax/module_ajax.php?module=aiassist&file=aiassist.action.php';

      $messages = [
         'label'            => __('Resumo (AI)', 'nextool'),
         'title'            => __('Resumo do Chamado', 'nextool'),
         'close'            => __('Fechar', 'nextool'),
         'copy'             => __('Copiar', 'nextool'),
         'copied'           => __('Copiado!', 'nextool'),
         'insert'           => __('Inserir no editor', 'nextool'),
         'inserted'         => __('Inserido!', 'nextool'),
         'editor_error'     => __('Não foi possível encontrar o editor de texto para inserir o conteúdo.', 'nextool'),
         'processing'       => __('Gerando resumo...', 'nextool'),
         'generic_error'    => __('Não foi possível gerar o resumo.', 'nextool'),
         'unexpected_error' => __('Erro inesperado. Tente novamente em instantes.', 'nextool'),
      ];

      $config = [
         'endpoint' => $endpoint,
         'ticketId' => (int)$ticketId,
         'messages' => $messages
      ];
      
      $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
      $configId = 'aiassist_summary_config_' . md5((string)$ticketId);

      $script = <<<JS
(function() {
   'use strict';
   
   try {
      var configId = '{$configId}';
      var config = window[configId] || {};

   function findActionsContainer() {
      var container = document.querySelector('.timeline-buttons');
      if (!container) {
         container = document.querySelector('.answer-actions') || document.querySelector('.ticket-actions');
      }
      return container;
   }

   function ensureButton(container) {
      if (!container) {
         return;
      }

      // Evita duplicar o botão
      if (container.querySelector('.action-summary')) {
         return;
      }

      var btn = document.createElement('button');
      btn.type = 'button';
      // Usa mesmas classes dos botões principais (btn-primary, answer-action, ms-2)
      btn.className = 'ms-2 btn btn-primary answer-action action-summary';
      btn.innerHTML = '<i class="ti ti-file-text me-1"></i><span>' + config.messages.label + '</span>';
      btn.addEventListener('click', function() {
         runSummary(btn);
      });

      // Tenta adicionar dentro do bloco de ações principais para manter alinhamento
      var mainActions = container.querySelector('.main-actions');
      if (mainActions) {
         mainActions.appendChild(btn);
      } else {
         container.appendChild(btn);
      }
   }

   function runSummary(button) {
      try {
         if (!window.fetch || !window.URLSearchParams) {
            notifyError(config.messages.generic_error);
            return;
         }

         var form = document.querySelector('#itil-form') || document.querySelector('form[name="ticketform"]');
         if (!form) {
            notifyError(config.messages.generic_error);
            return;
         }

         var csrfInput = form.querySelector('input[name="_glpi_csrf_token"]');
         var csrfToken = csrfInput ? csrfInput.value : '';
         if (!csrfToken) {
            notifyError(config.messages.generic_error);
            return;
         }

         if (button.dataset.loading === '1') {
            return;
         }

         var originalHtml = button.dataset.originalHtml || button.innerHTML;
         button.dataset.originalHtml = originalHtml;
         button.dataset.loading = '1';
         button.disabled = true;
         button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + config.messages.processing;

         var params = new URLSearchParams();
         params.append('_glpi_csrf_token', csrfToken);
         params.append('tickets_id', config.ticketId);
         params.append('action', 'summary');
         
         // Se botão tem flag forceNew, envia force=1
         if (button.dataset.forceNew === '1') {
            params.append('force', '1');
         }

         fetch(config.endpoint, {
            method: 'POST',
            headers: {
               'X-Requested-With': 'XMLHttpRequest',
               'X-Glpi-Csrf-Token': csrfToken,
               'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            credentials: 'same-origin',
            body: params.toString()
         })
         .then(function(response) {
            return response.text().then(function(text) {
               var payload = null;
               try {
                  payload = text ? JSON.parse(text) : null;
               } catch (e) {
                  payload = null;
               }
               return { ok: response.ok, payload: payload };
            });
         })
         .then(function(result) {
            var payload = result.payload || {};
            var data = payload.data || null;

            if (payload.next_csrf_token && csrfInput) {
               csrfInput.value = payload.next_csrf_token;
            }

            if (!result.ok || !payload.success) {
               var msg = payload.message || config.messages.generic_error;
               notifyError(msg);
               return;
            }

            // Prioriza summary_html (já formatado) ou fallback para summary_text
            var summaryHtml = '';
            var summaryText = '';
            
            if (typeof data === 'string') {
               summaryText = data;
               summaryHtml = data;
            } else if (data) {
               summaryHtml = data.summary_html || data.summary_text || '';
               summaryText = data.summary_text || '';
            }

            // Detecta se veio de cache (pode estar em payload.data ou payload)
            var fromCache = (data && data.from_cache) || payload.from_cache || false;
            var cachedAt = (data && data.cached_at) || payload.cached_at || null;

            // Usa o mesmo modal da timeline (definido em aiassist-timeline-button.js.php)
            if (typeof window.aiassistShowModal === 'function') {
               // Parâmetros: title, content, allowCopy, targetEditorId, fromCache, cachedAt, ticketId, showInsertButton, isHtml
               window.aiassistShowModal(config.messages.title, summaryHtml, true, null, fromCache, cachedAt, config.ticketId, false, true);
            } else {
               // Fallback simples se modal não estiver carregado ainda
               alert(summaryText);
            }
         })
         .catch(function() {
            notifyError(config.messages.unexpected_error);
         })
         .finally(function() {
            button.disabled = false;
            button.dataset.loading = '0';
            button.innerHTML = button.dataset.originalHtml || originalHtml;
         });

      } catch (e) {
         notifyError(config.messages.unexpected_error);
      }
   }

   function notifyError(message) {
      if (typeof glpi_toast_error === 'function') {
         glpi_toast_error(message);
      } else {
         console.error('[AI Assist] ' + message);
         alert(message);
      }
   }

   function bootstrap() {
      var container = findActionsContainer();
      if (container) {
         ensureButton(container);
         return;
      }

      var attempts = 0;
      var watcher = setInterval(function() {
         attempts++;
         var host = findActionsContainer();
         if (host || attempts > 40) {
            clearInterval(watcher);
            if (host) {
               ensureButton(host);
            }
         }
      }, 200);
   }

   // Proteção: só executa se estiver em contexto válido
   if (!document || !document.readyState) {
      return;
   }

   if (document.readyState === 'loading') {
      if (document.addEventListener) {
         document.addEventListener('DOMContentLoaded', bootstrap);
      }
   } else {
      bootstrap();
   }
   
   } catch (e) {
      // Silencia erros para evitar poluir console em perfis sem permissão
      console.debug('[AI Assist] Script não executado:', e.message);
   }
})();
JS;

      return Html::scriptBlock('window.' . $configId . ' = ' . $configJson . ';') . Html::scriptBlock($script);
   }

   protected function resolveSentimentPalette($label) {
      $slug = strtolower($label);
      switch ($slug) {
         case 'positivo':
            return ['bg' => 'rgba(34,197,94,0.35)', 'color' => '#0f172a'];
         case 'neutro':
            return ['bg' => 'rgba(251,191,36,0.25)', 'color' => '#0f172a'];
         case 'negativo':
            return ['bg' => 'rgba(248,113,113,0.45)', 'color' => '#0f172a'];
         case 'crítico':
         case 'critico':
            return ['bg' => 'rgba(220,38,38,0.55)', 'color' => '#0f172a'];
         default:
            return ['bg' => 'rgba(148,163,184,0.35)', 'color' => '#0f172a'];
      }
   }

   protected function resolveSentimentEmoji($label) {
      $slug = strtolower(trim((string)$label));
      switch ($slug) {
         case 'positivo':
            return '🙂';
         case 'neutro':
            return '😐';
         case 'negativo':
            return '☹️';
         case 'crítico':
         case 'critico':
            return '⚠️';
         default:
            return '🤖';
      }
   }

   protected function resolveSentimentBackground($label) {
      $slug = strtolower($label);
      switch ($slug) {
         case 'positivo':
            return [
               'background' => 'linear-gradient(135deg, rgba(34,197,94,0.20), #ffffff)',
               'border'     => 'rgba(34,197,94,0.55)'
            ];
         case 'neutro':
            return [
               'background' => 'linear-gradient(135deg, rgba(251,191,36,0.20), #ffffff)',
               'border'     => 'rgba(251,191,36,0.45)'
            ];
         case 'negativo':
            return [
               'background' => 'linear-gradient(135deg, rgba(248,113,113,0.20), #ffffff)',
               'border'     => 'rgba(248,113,113,0.50)'
            ];
         case 'crítico':
         case 'critico':
            return [
               'background' => 'linear-gradient(135deg, rgba(220,38,38,0.25), #ffffff)',
               'border'     => 'rgba(220,38,38,0.55)'
            ];
         default:
            return [
               'background' => 'linear-gradient(135deg, rgba(148,163,184,0.15), #ffffff)',
               'border'     => 'rgba(148,163,184,0.4)'
            ];
      }
   }

   protected function resolveUrgencyPalette($level) {
      $slug = strtolower($level);
      switch ($slug) {
         case 'baixa':
            return ['bg' => 'rgba(34,197,94,0.3)', 'color' => '#dcfce7'];
         case 'média':
         case 'media':
            return ['bg' => 'rgba(251,191,36,0.35)', 'color' => '#fef3c7'];
         case 'alta':
            return ['bg' => 'rgba(249,115,22,0.45)', 'color' => '#ffedd5'];
         case 'crítica':
         case 'critica':
            return ['bg' => 'rgba(239,68,68,0.45)', 'color' => '#fee2e2'];
         default:
            return ['bg' => 'rgba(148,163,184,0.35)', 'color' => '#fff'];
      }
   }

   /**
    * Prepara texto consolidado do ticket para envio à IA.
    *
    * @param Ticket $ticket
    * @param array  $options
    * @return array
    */
   public function buildTicketContext(Ticket $ticket, array $options = []) {
      global $DB;

      $maxChars = (int)($this->getSettings()['payload_max_chars'] ?? 6000);
      $ticketId = (int)$ticket->getID();

      $limitFollowups = isset($options['limit_followups'])
         ? max(1, (int)$options['limit_followups'])
         : 10;

      $parts = [];
      $parts[] = sprintf(
         "Chamado #%d - %s\nStatus: %s | Prioridade: %s | Solicitante: %s",
         $ticketId,
         $ticket->fields['name'] ?? '',
         $ticket->fields['status'] ?? '',
         $ticket->fields['priority'] ?? '',
         $this->getUserDisplayName($ticket->fields['users_id_recipient'] ?? 0)
      );

      if (!empty($ticket->fields['content'])) {
         $parts[] = "Descrição inicial:\n" . strip_tags($ticket->fields['content']);
      }

      $followupsText = [];
      $lastFollowupId = null;

      $query = [
         'SELECT' => ['id', 'date', 'content', 'users_id', 'is_private'],
         'FROM'   => 'glpi_itilfollowups',
         'WHERE'  => [
            'itemtype' => 'Ticket',
            'items_id' => $ticketId
         ],
         'ORDER'  => 'date DESC'
      ];

      if ($limitFollowups > 0) {
         $query['LIMIT'] = $limitFollowups;
      }

      $rows = iterator_to_array($DB->request($query));
      $rows = array_reverse($rows);

      foreach ($rows as $row) {
         if ($row['is_private']) {
            continue;
         }

         $lastFollowupId = $row['id'];
         $author = $this->getUserDisplayName($row['users_id']);
         $content = trim(strip_tags($row['content']));
         if ($content === '') {
            continue;
         }
         $followupsText[] = sprintf(
            "[%s - %s]\n%s",
            Html::convDateTime($row['date']),
            $author,
            $content
         );
      }

      if (!empty($followupsText)) {
         $parts[] = "Interações:\n" . implode("\n\n", $followupsText);
      }

      $solutionIter = $DB->request([
         'SELECT' => ['content', 'date_creation'],
         'FROM'   => 'glpi_itilsolutions',
         'WHERE'  => [
            'items_id' => $ticketId,
            'itemtype' => 'Ticket'
         ],
         'ORDER' => 'date_creation DESC',
         'LIMIT' => 1
      ]);

      if (count($solutionIter)) {
         $solution = $solutionIter->current();
         $parts[] = "Solução registrada:\n" . strip_tags($solution['content']);
      }

      $text = implode("\n\n", $parts);
      $text = $this->truncateText($text, $maxChars);

      return [
         'text' => $text,
         'hash' => sha1($text),
         'last_followup_id' => $lastFollowupId,
         'payload_hash' => sha1($ticketId . ':' . $text),
      ];
   }

   /**
    * Estima tokens aproximados por comprimento.
    */
   public function estimateTokensFromText($text) {
      $length = mb_strlen($text);
      return (int)ceil($length / 4);
   }

   /**
    * Obtém nome amigável do usuário.
    */
   public function getUserDisplayName($userId) {
      $userId = (int)$userId;
      if ($userId <= 0) {
         return __('Desconhecido', 'nextool');
      }

      $user = new User();
      if ($user->getFromDB($userId)) {
         return $user->getFriendlyName();
      }

      return sprintf(__('Usuário #%d', 'nextool'), $userId);
   }

   /**
    * Trunca texto respeitando limite.
    */
   private function truncateText($text, $limit) {
      if (mb_strlen($text) <= $limit) {
         return $text;
      }
      return mb_substr($text, 0, $limit - 3) . '...';
   }

   /**
    * Registra mudanças de configuração no histórico.
    *
    * @param int $configId
    * @param array $oldSettings
    * @param array $newSettings
    * @return void
    */
   protected function recordConfigChanges($configId, array $oldSettings, array $newSettings) {
      global $DB;

      $historyTable = 'glpi_plugin_nextool_aiassist_config_history';
      if (!$DB->tableExists($historyTable)) {
         return;
      }

      $userId = isset($_SESSION['glpiID']) ? (int)$_SESSION['glpiID'] : null;

      // Campos que devem ser rastreados (exclui api_key por segurança)
      $trackedFields = [
         'provider_mode', 'provider', 'model',
         'allow_sensitive', 'payload_max_chars', 'timeout_seconds',
         'rate_limit_minutes', 'tokens_limit_month',
         'feature_summary_enabled', 'feature_reply_enabled', 'feature_sentiment_enabled',
         'feature_summary_model', 'feature_reply_model', 'feature_sentiment_model',
      ];

      foreach ($trackedFields as $field) {
         $oldValue = $oldSettings[$field] ?? null;
         $newValue = $newSettings[$field] ?? null;

         // Normaliza valores para comparação
         $oldValue = $this->normalizeValueForHistory($oldValue);
         $newValue = $this->normalizeValueForHistory($newValue);

         // Só registra se o valor mudou
         if ($oldValue !== $newValue) {
            $DB->insert($historyTable, [
               'config_id'  => $configId,
               'field_name' => $field,
               'old_value'  => $oldValue !== null ? (string)$oldValue : null,
               'new_value'  => $newValue !== null ? (string)$newValue : null,
               'users_id'   => $userId,
            ]);
         }
      }
   }

   /**
    * Normaliza valor para histórico (converte para string comparável).
    *
    * @param mixed $value
    * @return string|null
    */
   protected function normalizeValueForHistory($value) {
      if ($value === null) {
         return null;
      }
      if (is_bool($value)) {
         return $value ? '1' : '0';
      }
      return (string)$value;
   }

   /**
    * Retorna histórico de mudanças de configuração.
    *
    * @param array $filters ['field_name' => string, 'users_id' => int, 'date_from' => string, 'date_to' => string, 'limit' => int]
    * @return array
    */
   public function getConfigHistory(array $filters = []) {
      global $DB;

      $historyTable = 'glpi_plugin_nextool_aiassist_config_history';
      if (!$DB->tableExists($historyTable)) {
         return [];
      }

      // Obtém config_id atual
      $configRow = $DB->request([
         'FROM'  => $this->getConfigTableName(),
         'LIMIT' => 1
      ]);

      if (!count($configRow)) {
         return [];
      }

      $configId = (int)$configRow->current()['id'];

      $where = ['config_id' => $configId];

      if (!empty($filters['field_name'])) {
         $where['field_name'] = $filters['field_name'];
      }

      if (!empty($filters['users_id'])) {
         $where['users_id'] = (int)$filters['users_id'];
      }

      if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
         if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $where[] = ['date_creation' => ['>=', $filters['date_from'] . ' 00:00:00']];
            $where[] = ['date_creation' => ['<=', $filters['date_to'] . ' 23:59:59']];
         } elseif (!empty($filters['date_from'])) {
            $where['date_creation'] = ['>=', $filters['date_from'] . ' 00:00:00'];
         } elseif (!empty($filters['date_to'])) {
            $where['date_creation'] = ['<=', $filters['date_to'] . ' 23:59:59'];
         }
      }

      $limit = !empty($filters['limit']) ? (int)$filters['limit'] : 100;

      $rows = $DB->request([
         'FROM'   => $historyTable,
         'WHERE'  => $where,
         'ORDER'  => 'date_creation DESC',
         'LIMIT'  => $limit
      ]);

      $result = [];
      foreach ($rows as $row) {
         $result[] = [
            'id'         => (int)$row['id'],
            'field_name' => $row['field_name'],
            'old_value'  => $row['old_value'],
            'new_value'  => $row['new_value'],
            'users_id'   => !empty($row['users_id']) ? (int)$row['users_id'] : null,
            'date_creation' => $row['date_creation'],
         ];
      }

      return $result;
   }
}

