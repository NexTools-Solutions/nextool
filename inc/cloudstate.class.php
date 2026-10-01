<?php
/**
 * Cloud link -- estado leve do canal em `glpi_configs` (contexto `plugin:nextool_cloud`), gravado
 * direto no banco.
 *
 * Por que não `Config::setConfigurationValues`: no GLPI 10 e no 11, cada valor alterado por ele vira
 * uma linha de histórico em `glpi_logs` (`Config::post_updateItem` -> `logConfigChange`) e ainda é
 * copiado para o `$CFG_GLPI` do processo. Aqui entram contadores que mudam a cada chamada da nuvem,
 * inclusive as recusas de quem não tem a chave, e a marca do teto de ressincronização. Pelo caminho do
 * Config, uma rajada de pedidos anônimos viraria uma rajada de linhas no histórico do GLPI.
 *
 * Só guarda o que o próprio cloud link produz: números e JSON com chaves `[a-z_]`. O GLPI 10 não
 * escapa o valor em `$DB->insert/update` (auditoria de 2026-09-24, ME-07), então `set()` recusa valor
 * com aspas simples ou barra invertida em vez de gravar algo que o banco interpretaria.
 *
 * O contexto é o mesmo do segredo do pseudônimo do ator e das marcas do endereço declarado, e sai
 * inteiro no uninstall.
 *
 * @since pós-6.24.1 (contrato 1.1 do cloud link, K1)
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudState {

   public const CONTEXT = 'plugin:nextool_cloud';

   /** Valor gravado, ou null quando não existe (ou o banco não respondeu). */
   public static function get(string $name): ?string {
      global $DB;
      try {
         $row = $DB->request([
            'SELECT' => ['value'],
            'FROM'   => 'glpi_configs',
            'WHERE'  => ['context' => self::CONTEXT, 'name' => $name],
            'LIMIT'  => 1,
         ])->current();
      } catch (\Throwable $e) {
         return null;
      }

      return $row ? (string) $row['value'] : null;
   }

   /** Grava. false = valor recusado (ver o cabeçalho) ou o banco não gravou. Nunca lança. */
   public static function set(string $name, string $value): bool {
      global $DB;
      if (strpbrk($value . $name, "'\\") !== false) {
         return false;
      }
      $where = ['context' => self::CONTEXT, 'name' => $name];
      try {
         $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_configs',
            'WHERE'  => $where,
            'LIMIT'  => 1,
         ])->current();
         if ($row) {
            return $DB->update('glpi_configs', ['value' => $value], ['id' => (int) $row['id']]) !== false;
         }

         return (bool) $DB->insert('glpi_configs', $where + ['value' => $value]);
      } catch (\Throwable $e) {
         // Perdeu a corrida pela UNIQUE (context, name) para outro processo: vale o dele.
         return false;
      }
   }

   public static function delete(string $name): void {
      global $DB;
      try {
         $DB->delete('glpi_configs', ['context' => self::CONTEXT, 'name' => $name]);
      } catch (\Throwable $e) {
         // sem efeito: a marca fica e é sobrescrita na próxima gravação
      }
   }
}
