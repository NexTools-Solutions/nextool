CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_aiassist_config` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_identifier` VARCHAR(191) NOT NULL,
  `provider_mode` VARCHAR(20) NOT NULL DEFAULT 'direct',
  `provider` VARCHAR(50) NOT NULL DEFAULT 'openai',
  `model` VARCHAR(100) NOT NULL DEFAULT 'gpt-4o-mini',
  `api_key` TEXT NULL,
  `proxy_identifier` VARCHAR(191) DEFAULT NULL,
  `allow_sensitive` TINYINT(1) NOT NULL DEFAULT 0,
  `payload_max_chars` INT NOT NULL DEFAULT 10000,
  `timeout_seconds` INT NOT NULL DEFAULT 120,
  `rate_limit_minutes` INT NOT NULL DEFAULT 5,
  `tokens_limit_month` INT NOT NULL DEFAULT 100000,
  `feature_summary_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `feature_reply_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `feature_sentiment_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `feature_summary_model` VARCHAR(100) NULL,
  `feature_reply_model` VARCHAR(100) NULL,
  `feature_sentiment_model` VARCHAR(100) NULL,
  `date_creation` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `date_mod` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_client_identifier` (`client_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_aiassist_ticketdata` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tickets_id` INT UNSIGNED NOT NULL,
  `summary_text` LONGTEXT NULL,
  `summary_hash` VARCHAR(191) DEFAULT NULL,
  `last_summary_at` TIMESTAMP NULL DEFAULT NULL,
  `last_summary_followup_id` INT UNSIGNED DEFAULT NULL,
  `reply_text` LONGTEXT NULL,
  `last_reply_at` TIMESTAMP NULL DEFAULT NULL,
  `last_reply_followup_id` INT UNSIGNED DEFAULT NULL,
  `sentiment_label` VARCHAR(50) DEFAULT NULL,
  `sentiment_score` DECIMAL(5,2) DEFAULT NULL,
  `urgency_level` VARCHAR(50) DEFAULT NULL,
  `last_sentiment_at` TIMESTAMP NULL DEFAULT NULL,
  `last_sentiment_followup_id` INT UNSIGNED DEFAULT NULL,
  `cache_payload_hash` VARCHAR(191) DEFAULT NULL,
  `date_creation` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `date_mod` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ticket` (`tickets_id`),
  KEY `idx_sentiment_label` (`sentiment_label`),
  KEY `idx_last_summary_at` (`last_summary_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_aiassist_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_identifier` VARCHAR(191) NOT NULL,
  `tickets_id` INT UNSIGNED DEFAULT NULL,
  `users_id` INT UNSIGNED DEFAULT NULL,
  `feature` VARCHAR(50) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'success',
  `tokens_prompt` INT DEFAULT 0,
  `tokens_completion` INT DEFAULT 0,
  `payload_hash` VARCHAR(191) DEFAULT NULL,
  `error_code` VARCHAR(50) DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `date_creation` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_feature` (`tickets_id`, `feature`),
  KEY `idx_client_date` (`client_identifier`, `date_creation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_aiassist_quota` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_identifier` VARCHAR(191) NOT NULL,
  `tokens_limit` INT NOT NULL DEFAULT 100000,
  `tokens_used` INT NOT NULL DEFAULT 0,
  `period_start` DATE DEFAULT NULL,
  `period_end` DATE DEFAULT NULL,
  `last_reset_at` TIMESTAMP NULL DEFAULT NULL,
  `date_creation` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `date_mod` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_quota_client` (`client_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_aiassist_config_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_id` INT UNSIGNED NOT NULL,
  `field_name` VARCHAR(100) NOT NULL,
  `old_value` TEXT NULL,
  `new_value` TEXT NULL,
  `users_id` INT UNSIGNED NULL,
  `date_creation` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_config_id` (`config_id`),
  KEY `idx_field_name` (`field_name`),
  KEY `idx_users_id` (`users_id`),
  KEY `idx_date_creation` (`date_creation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `glpi_plugin_nextool_aiassist_config` (
  `client_identifier`,
  `provider_mode`,
  `provider`,
  `model`,
  `allow_sensitive`,
  `payload_max_chars`,
  `timeout_seconds`,
  `rate_limit_minutes`,
  `tokens_limit_month`
) VALUES (
  'default',
  'direct',
  'openai',
  'gpt-4o-mini',
  0,
  10000,
  120,
  5,
  100000
) ON DUPLICATE KEY UPDATE
  `provider_mode` = VALUES(`provider_mode`),
  `provider` = VALUES(`provider`),
  `model` = VALUES(`model`),
  `allow_sensitive` = VALUES(`allow_sensitive`),
  `payload_max_chars` = VALUES(`payload_max_chars`),
  `timeout_seconds` = VALUES(`timeout_seconds`),
  `rate_limit_minutes` = VALUES(`rate_limit_minutes`),
  `tokens_limit_month` = VALUES(`tokens_limit_month`);

INSERT INTO `glpi_plugin_nextool_aiassist_quota` (
  `client_identifier`,
  `tokens_limit`,
  `tokens_used`,
  `period_start`,
  `period_end`,
  `last_reset_at`
) VALUES (
  'default',
  100000,
  0,
  DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01'),
  LAST_DAY(CURRENT_DATE()),
  NOW()
) ON DUPLICATE KEY UPDATE
  `tokens_limit` = VALUES(`tokens_limit`),
  `period_start` = VALUES(`period_start`),
  `period_end` = VALUES(`period_end`);

