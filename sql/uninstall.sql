-- Desinstalação do plugin Nextool (remove apenas estrutura principal e licenciamento)
--
-- Este arquivo é executado na desinstalação do PLUGIN (Configurar > Plugins > Desinstalar nextool).
-- As tabelas dos MÓDULOS (ex.: glpi_plugin_nextool_telegrambot_*) NÃO são removidas aqui.
-- Elas só são removidas quando o usuário aciona "Apagar dados" no card do módulo (purgeModuleData).

-- Remove tabelas de licenciamento do operacional
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_validation_attempts`;
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_license_config`;
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_module_audit`;
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_config_audit`;

-- Remove tabela de módulos
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_modules`;

-- Remove tabela de configuração global
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_configs`;