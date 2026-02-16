-- Instalação do plugin Nextool (estrutura completa, incluindo licenciamento)
-- 
-- Este arquivo é executado na instalação do plugin.
-- A partir da v2.0.0, o plugin usa arquitetura modular.

-- Tabela de configuração global do plugin
CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_main_configs` (
   `id` int unsigned NOT NULL AUTO_INCREMENT,
   `is_active` tinyint NOT NULL DEFAULT '1' COMMENT 'Plugin ativo (0=não, 1=sim)',
   `client_identifier` varchar(128) DEFAULT NULL,
   `endpoint_url` varchar(255) DEFAULT NULL,
   `date_creation` timestamp NULL DEFAULT NULL,
   `date_mod` timestamp NULL DEFAULT NULL,
   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Tabela de módulos (v2.0.0+)
-- Esta tabela armazena o registro e configuração de cada módulo
CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_main_modules` (
   `id` int unsigned NOT NULL AUTO_INCREMENT,
   `module_key` varchar(100) NOT NULL COMMENT 'Chave única do módulo (ex: emailtools)',
   `name` varchar(255) NOT NULL COMMENT 'Nome amigável do módulo',
   `description` text DEFAULT NULL COMMENT 'Descrição do módulo',
   `version` varchar(20) DEFAULT NULL COMMENT 'Versão instalada do módulo (semantic versioning)',
   `available_version` varchar(20) DEFAULT NULL COMMENT 'Última versão disponível no catálogo oficial',
   `min_version_nextools` varchar(50) DEFAULT NULL COMMENT 'Versão mínima do plugin Nextool para este módulo',
   `is_installed` tinyint NOT NULL DEFAULT '0' COMMENT 'Módulo instalado (0=não, 1=sim)',
   `billing_tier` varchar(16) DEFAULT 'FREE' COMMENT 'Modelo de cobrança (FREE/PAID/...)',
   `is_enabled` tinyint NOT NULL DEFAULT '0' COMMENT 'Módulo ativo (0=não, 1=sim)',
   `is_available` tinyint NOT NULL DEFAULT '0' COMMENT 'Disponível na lista de módulos (0=oculto, 1=visível)',
   `config` text DEFAULT NULL COMMENT 'Configuração específica do módulo (JSON)',
   `date_creation` timestamp NULL DEFAULT NULL,
   `date_mod` timestamp NULL DEFAULT NULL,
   PRIMARY KEY (`id`),
   UNIQUE KEY `module_key` (`module_key`),
   KEY `is_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- NOTA: As tabelas específicas dos módulos são criadas pelo método install() de cada módulo
-- Exemplo: glpi_plugin_nextool_main_helloworld
-- O ModuleManager chama automaticamente install() de cada módulo descoberto

-- ---------------------------------------------------------------------
-- Estrutura de Licenciamento no Operacional (LicenseConfig + Attempts)
-- ---------------------------------------------------------------------

-- Tabela de configuração da licença (inclui campo "plan" a partir da v2.4.1)
CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_main_license_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `license_key` varchar(255) DEFAULT NULL COMMENT 'Chave da licença configurada',
  `plan` varchar(32) DEFAULT NULL COMMENT 'Plano da licença (UNKNOWN/FREE/STARTER/PRO/ENTERPRISE)',
  `contract_active` tinyint DEFAULT NULL COMMENT 'Último estado do contrato retornado pelo administrativo',
  `license_status` varchar(32) DEFAULT NULL COMMENT 'Último status retornado pelo administrativo',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Data de expiração retornada pelo administrativo',
  `policies_accepted_at` timestamp NULL DEFAULT NULL COMMENT 'Data/hora do aceite das Políticas de Uso no ambiente operacional',
  `api_endpoint` varchar(500) DEFAULT NULL COMMENT 'URL do endpoint de validação',
  `api_secret` varchar(255) DEFAULT NULL COMMENT 'Secret para autenticação (opcional)',
  `last_validation_date` timestamp NULL DEFAULT NULL COMMENT 'Data da última validação bem-sucedida',
  `last_validation_result` tinyint DEFAULT NULL COMMENT 'Resultado da última validação (0=inválida, 1=válida)',
  `last_validation_message` text DEFAULT NULL COMMENT 'Mensagem da última validação',
  `cached_modules` text DEFAULT NULL COMMENT 'Módulos permitidos em cache (JSON)',
  `warnings` text DEFAULT NULL COMMENT 'Warnings retornados pelo administrativo (JSON)',
  `licenses_snapshot` longtext DEFAULT NULL COMMENT 'Snapshot consolidado das licenças (JSON)',
  `consecutive_failures` int unsigned NOT NULL DEFAULT '0' COMMENT 'Falhas consecutivas de comunicação',
  `last_failure_date` timestamp NULL DEFAULT NULL COMMENT 'Data da última falha',
  `date_creation` timestamp NULL DEFAULT NULL,
  `date_mod` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de tentativas de validação de licença
CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_main_validation_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `attempt_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `result` tinyint NOT NULL COMMENT '0=falha, 1=sucesso',
  `message` text DEFAULT NULL COMMENT 'Mensagem de erro ou sucesso',
  `http_code` int DEFAULT NULL COMMENT 'Código HTTP da resposta',
  `response_time_ms` int DEFAULT NULL COMMENT 'Tempo de resposta em milissegundos',
  `origin` varchar(64) DEFAULT NULL COMMENT 'Contexto informado pelo chamador',
  `requested_modules` text DEFAULT NULL COMMENT 'Lista de módulos solicitados (JSON)',
  `client_identifier` varchar(191) DEFAULT NULL COMMENT 'Identificador do ambiente local',
  `license_status` varchar(32) DEFAULT NULL COMMENT 'Status retornado pelo administrativo',
  `contract_active` tinyint DEFAULT NULL COMMENT 'Contrato ativo retornado pelo administrativo',
  `plan` varchar(32) DEFAULT NULL COMMENT 'Plano retornado pelo administrativo',
  `force_refresh` tinyint DEFAULT NULL COMMENT '1 quando cache foi ignorado',
  `cache_hit` tinyint DEFAULT NULL COMMENT '1 quando resposta veio do cache local',
  `user_id` int unsigned DEFAULT NULL COMMENT 'Usuário autenticado que originou a chamada',
  PRIMARY KEY (`id`),
  KEY `attempt_date` (`attempt_date`),
  KEY `result` (`result`),
  KEY `origin` (`origin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria de ações de módulos (instalação/ativação/desativação)
CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_main_module_audit` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `action_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `module_key` varchar(100) NOT NULL,
  `action` varchar(32) NOT NULL COMMENT 'install/enable/disable/uninstall',
  `result` tinyint NOT NULL DEFAULT '0' COMMENT '0=falha, 1=sucesso',
  `message` text DEFAULT NULL COMMENT 'Mensagem resumindo o resultado',
  `user_id` int unsigned DEFAULT NULL COMMENT 'Usuário autenticado (se houver)',
  `origin` varchar(64) DEFAULT NULL COMMENT 'Origem declarada pela ação',
  `source_ip` varchar(45) DEFAULT NULL COMMENT 'IP do solicitante (quando aplicável)',
  `license_status` varchar(32) DEFAULT NULL,
  `contract_active` tinyint DEFAULT NULL,
  `plan` varchar(32) DEFAULT NULL,
  `allowed_modules` text DEFAULT NULL COMMENT 'Snapshot de allowed_modules (JSON)',
  `requested_modules` text DEFAULT NULL COMMENT 'Snapshot dos módulos solicitados (JSON)',
  PRIMARY KEY (`id`),
  KEY `module_key` (`module_key`),
  KEY `action_date` (`action_date`),
  KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria de alterações de configuração/licença
CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_main_config_audit` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `section` varchar(32) NOT NULL COMMENT 'global|license|validation',
  `action` varchar(64) DEFAULT NULL,
  `result` tinyint DEFAULT NULL COMMENT '0=falha,1=sucesso',
  `message` text DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `source_ip` varchar(45) DEFAULT NULL,
  `details` text DEFAULT NULL COMMENT 'JSON com campos/valores',
  PRIMARY KEY (`id`),
  KEY `section` (`section`),
  KEY `event_date` (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para página de config standalone (abas verticais: Módulos, Contato, Licenciamento, Logs)
CREATE TABLE IF NOT EXISTS `glpi_plugin_nextool_main_config_display` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
INSERT IGNORE INTO `glpi_plugin_nextool_main_config_display` (`id`) VALUES (1);