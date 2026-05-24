-- nextool/nextool upgrade.sql
-- Idempotente. Executado por BaseModule::upgrade() em todo update do módulo.

-- BEGIN: datetime→timestamp migration (gerado pelo script de Fase 4)
-- ALTER MODIFY COLUMN para o mesmo tipo é no-op (idempotente).
-- Em instalações antigas com `datetime`, este bloco converte para `timestamp`.

ALTER TABLE `glpi_plugin_nextool_main_configs`
   MODIFY COLUMN `date_creation` timestamp NULL DEFAULT NULL,
   MODIFY COLUMN `date_mod` timestamp NULL DEFAULT NULL;

ALTER TABLE `glpi_plugin_nextool_main_modules`
   MODIFY COLUMN `date_creation` timestamp NULL DEFAULT NULL,
   MODIFY COLUMN `date_mod` timestamp NULL DEFAULT NULL;

ALTER TABLE `glpi_plugin_nextool_main_license_config`
   MODIFY COLUMN `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Data de expiração retornada pelo administrativo',
   MODIFY COLUMN `policies_accepted_at` timestamp NULL DEFAULT NULL COMMENT 'Data/hora do aceite das Políticas de Uso no ambiente operacional',
   MODIFY COLUMN `last_validation_date` timestamp NULL DEFAULT NULL COMMENT 'Data da última validação bem-sucedida',
   MODIFY COLUMN `last_failure_date` timestamp NULL DEFAULT NULL COMMENT 'Data da última falha',
   MODIFY COLUMN `date_creation` timestamp NULL DEFAULT NULL,
   MODIFY COLUMN `date_mod` timestamp NULL DEFAULT NULL;

ALTER TABLE `glpi_plugin_nextool_main_validation_attempts`
   MODIFY COLUMN `attempt_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `glpi_plugin_nextool_main_module_audit`
   MODIFY COLUMN `action_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `glpi_plugin_nextool_main_config_audit`
   MODIFY COLUMN `event_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `glpi_plugin_nextool_core_updates`
   MODIFY COLUMN `finished_at` timestamp NULL DEFAULT NULL COMMENT 'Momento de conclusão da operação',
   MODIFY COLUMN `date_creation` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Momento de criação do registro';

-- END: datetime→timestamp migration
