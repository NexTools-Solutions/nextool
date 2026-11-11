<?php
declare(strict_types=1);
/**
 * Ponto de inclusão antigo do "Modo diagnóstico do módulo" (Fase 1). Desde 2026-11-10 (decisão do owner)
 * o bloco com a lista de erros e o "Copiar diagnóstico" deixou de existir: aqui saem só os botões
 * "Modo diagnóstico" e "Suporte" (PluginNextoolModuleChrome::diagnosticButtons), no lugar onde o módulo
 * inclui este arquivo. O que o diagnóstico captura vai no relatório técnico do popup de suporte.
 *
 * Módulo novo ou migrado NÃO inclui este arquivo: chama
 * `PluginNextoolModuleChrome::diagnosticButtons('<chave>')` na linha do botão Salvar da aba de configuração
 * (referência: módulo `template`). Este arquivo fica para os módulos ainda não migrados.
 *
 * Variáveis esperadas via $GLOBALS (contrato antigo, mantido):
 *   'nextool_diag_module_key'          => string (module_key)
 *   'nextool_diag_observability_class' => string (não é mais usada)
 *
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once NEXTOOL_PHP_DIR . '/inc/modulediagnostic.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/modulechrome.class.php';

$nxDiagButtons = PluginNextoolModuleChrome::diagnosticButtons(
   PluginNextoolModuleDiagnostic::cleanKey((string) ($GLOBALS['nextool_diag_module_key'] ?? ''))
);
if ($nxDiagButtons !== '') {
   echo '<div class="d-flex flex-wrap justify-content-end mt-3">' . $nxDiagButtons . '</div>';
}
