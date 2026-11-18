<?php
declare(strict_types=1);
/**
 * Regra ÚNICA da chave de módulo (nextool-dev#310).
 *
 * A chave é o nome da pasta do módulo, o sufixo da classe (`PluginNextool<Chave>`), o prefixo das
 * tabelas (`glpi_plugin_nextool_<chave>_`) e o sufixo do direito (`plugin_nextool_module_<chave>`):
 * minúsculas, dígitos e sublinhado, de 1 a 64 caracteres. Hífen não entra (não cabe em nome de
 * classe nem de tabela). A chave fora da regra é RECUSADA, nunca limpa: limpar em silêncio
 * transformava `ai-assist` em `aiassist` e `../x` em `x`.
 *
 * Arquivo só com função, sem dependência do GLPI carregado: os roteadores (front/modules.php,
 * front/module_assets.php) validam a chave antes de incluir o core.
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

if (!defined('PLUGIN_NEXTOOL_MODULE_KEY_PATTERN')) {
   define('PLUGIN_NEXTOOL_MODULE_KEY_PATTERN', '/^[a-z0-9_]{1,64}$/');
}

if (!function_exists('plugin_nextool_module_key')) {
   /**
    * Chave de módulo normalizada (aparada e em minúsculas), ou '' quando fora da regra.
    *
    * @param mixed $raw valor como veio (parâmetro de URL, POST, banco, resposta do servidor)
    */
   function plugin_nextool_module_key($raw): string {
      if (!is_string($raw)) {
         return '';
      }
      // Apara só espaço em branco: o trim() padrão também tiraria o byte nulo, e aí `chave\0` passaria.
      $key = strtolower(trim($raw, " \t\n\r"));
      return preg_match(PLUGIN_NEXTOOL_MODULE_KEY_PATTERN, $key) === 1 ? $key : '';
   }
}
