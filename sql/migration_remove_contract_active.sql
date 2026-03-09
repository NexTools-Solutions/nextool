-- Migration: Remove contract_active field and FREE_TIER status
-- Date: 2026-03-07
-- Phase 1: Simplify license status model

-- 1. Convert any existing FREE_TIER status to NULL
UPDATE `glpi_plugin_nextool_main_license_config`
SET `license_status` = NULL
WHERE `license_status` = 'FREE_TIER';

-- 2. Drop contract_active column from license_config
ALTER TABLE `glpi_plugin_nextool_main_license_config`
DROP COLUMN IF EXISTS `contract_active`;

-- 3. Drop contract_active column from validation_attempts
ALTER TABLE `glpi_plugin_nextool_main_validation_attempts`
DROP COLUMN IF EXISTS `contract_active`;

-- 4. Drop contract_active column from module_audit
ALTER TABLE `glpi_plugin_nextool_main_module_audit`
DROP COLUMN IF EXISTS `contract_active`;
