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
DROP TABLE IF EXISTS `glpi_plugin_nextool_core_updates`;

-- ATENÇÃO: glpi_plugin_nextool_main_modules (REGISTRO de módulos: estado
-- is_installed/is_enabled/config por ambiente) NÃO é removido. Preservá-lo, junto
-- com as tabelas de dados e os arquivos dos módulos (também preservados), permite
-- que reinstalar o plugin base no mesmo GLPI traga os módulos de volta exatamente
-- como estavam, sem reativação manual. Remoção total de um módulo é via "Apagar dados".

-- Remove tabela de configuração global do plugin base
DROP TABLE IF EXISTS `glpi_plugin_nextool_main_configs`;
