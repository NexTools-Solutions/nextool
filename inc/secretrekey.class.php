<?php
/**
 * NexTool -- re-cifra dos segredos depois de trocar a chave do GLPI (nextool-dev#269).
 *
 * `php bin/console security:change_key` re-cifra só o que o core e os plugins registram em `secured_fields` /
 * `secured_configs`. O NexTool não registra nada, e não pode: o core faz `encrypt(decrypt($valor_cru))`, e um valor
 * com o prefixo `NXENC1:` do SecretVault não decifra assim, então o segredo viraria a cifra de uma string vazia.
 * Reproduzido em 05/10 num clone do DEV: depois do change_key, os 30 segredos cifrados do NexTool (base e 10 módulos)
 * deixaram de decifrar.
 *
 * Esta classe percorre os lugares onde o NexTool guarda segredo e, com a chave ANTIGA (cópia do glpicrypt.key de antes
 * da troca), regrava cada valor cifrado com a chave atual, no mesmo formato:
 *   - `NXENC1:<GLPIKey>` (SecretVault), em coluna, em linha chave-valor ou dentro de JSON;
 *   - `<GLPIKey>` cru (colunas próprias do aiassist, mercadoeletronico, nexbot; JSON do translate);
 *   - a chave AES dos módulos (`modules_encryption_key`, NXENC1 em glpi_configs): re-embrulhada; os dados que ela
 *     cifra (automations, glpisync) não mudam.
 *
 * Regra de segurança: um valor só é regravado se DECIFRAR com a chave antiga. Valor em claro, valor que já decifra
 * com a chave atual e qualquer texto comum ficam intocados. Por isso é idempotente e pode varrer colunas que misturam
 * segredo e configuração comum.
 *
 * Onde procura (sem depender de cada módulo declarar):
 *   1. glpi_configs com contexto `plugin:nextool%` (valor cru ou JSON);
 *   2. tabelas `glpi_plugin_nextool_%config%` (colunas de texto; JSON percorrido por dentro);
 *   3. `glpi_plugin_nextool_main_modules.config` (JSON de configuração dos módulos);
 *   4. o que um módulo declarar em `getSecretLocations()` (lugar fora dos padrões acima).
 *
 * @since pós-6.31.1 (nextool-dev#269)
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolSecretRekey {

   public const RESULT_REKEYED = 'rekeyed';     // decifrou com a antiga e foi regravado com a atual
   public const RESULT_CURRENT = 'current';     // já decifra com a chave atual
   public const RESULT_FAILED  = 'failed';      // parece cifrado (NXENC1) mas não decifra com nenhuma das duas
   public const RESULT_PLAIN   = 'plain';       // não é segredo cifrado: intocado

   /** Família do aviso "segredo que não decifra" (aba Alertas). */
   public const ALERT_FAMILY = 'secret_undecryptable:';

   /** Tabelas grandes ou sem segredo que a varredura de `%config%` não abre. */
   private const SKIP_TABLES = ['glpi_plugin_nextool_main_modules'];

   /** @var string chave antiga (bytes do glpicrypt.key de antes da troca) */
   private string $oldKey;

   private GLPIKey $glpiKey;

   private bool $dryRun;

   /** @var array<string, array{rekeyed:int, current:int, failed:int}> contagem por local */
   private array $report = [];

   public function __construct(string $oldKey, bool $dryRun = true) {
      $this->oldKey  = $oldKey;
      $this->glpiKey = new GLPIKey();
      $this->dryRun  = $dryRun;
   }

   /**
    * Lê a chave antiga de um arquivo (cópia do glpicrypt.key). Recusa arquivo vazio ou igual à chave atual.
    *
    * @throws RuntimeException
    */
   public static function readOldKeyFile(string $path): string {
      if (!is_file($path) || !is_readable($path)) {
         throw new RuntimeException(sprintf('Arquivo da chave antiga não encontrado ou sem leitura: %s', $path));
      }
      $key = (string) file_get_contents($path);
      if ($key === '') {
         throw new RuntimeException('Arquivo da chave antiga está vazio.');
      }
      $atual = (new GLPIKey())->get();
      if ($atual !== null && hash_equals($atual, $key)) {
         throw new RuntimeException('O arquivo informado é a chave ATUAL do GLPI, não a antiga.');
      }
      return $key;
   }

   /**
    * Percorre todos os locais e regrava (ou, em simulação, só conta).
    *
    * @return array<string, array{rekeyed:int, current:int, failed:int}>
    */
   public function run(): array {
      global $DB;
      $this->report = [];

      // 1. glpi_configs do NexTool
      foreach ($DB->request(['FROM' => 'glpi_configs', 'WHERE' => ['context' => ['LIKE', 'plugin:nextool%']]]) as $row) {
         $this->processRow('glpi_configs', 'value', (int) $row['id'], (string) $row['value'],
            'glpi_configs ' . $row['context'] . '.' . $row['name']);
      }

      // 2. tabelas de configuração dos módulos e da base
      foreach ($this->configTables() as $table) {
         foreach ($this->textColumns($table) as $column) {
            foreach ($DB->request(['SELECT' => ['id', $column], 'FROM' => $table]) as $row) {
               $this->processRow($table, $column, (int) $row['id'], (string) ($row[$column] ?? ''), $table . '.' . $column);
            }
         }
      }

      // 3. JSON de configuração de cada módulo
      if ($DB->tableExists('glpi_plugin_nextool_main_modules')) {
         foreach ($DB->request(['SELECT' => ['id', 'module_key', 'config'], 'FROM' => 'glpi_plugin_nextool_main_modules']) as $row) {
            $this->processRow('glpi_plugin_nextool_main_modules', 'config', (int) $row['id'], (string) ($row['config'] ?? ''),
               'main_modules[' . $row['module_key'] . '].config');
         }
      }

      // 4. locais declarados pelos módulos
      foreach ($this->declaredLocations() as $loc) {
         $table  = (string) $loc['table'];
         $column = (string) $loc['column'];
         if (!$DB->tableExists($table) || !$DB->fieldExists($table, $column)) {
            continue;
         }
         $pk = (string) ($loc['pk'] ?? 'id');
         foreach ($DB->request(['SELECT' => [$pk, $column], 'FROM' => $table, 'WHERE' => (array) ($loc['where'] ?? [])]) as $row) {
            $this->processRow($table, $column, (int) $row[$pk], (string) ($row[$column] ?? ''), $table . '.' . $column, $pk);
         }
      }

      // Nada sobrou sem decifrar: o aviso "segredo que não decifra" deixa de valer.
      if (!$this->dryRun && $this->totals()['failed'] === 0 && class_exists('PluginNextoolAlertManager')) {
         PluginNextoolAlertManager::expireLocalFamily(self::ALERT_FAMILY);
      }

      return $this->report;
   }

   /** @return array{rekeyed:int, current:int, failed:int} */
   public function totals(): array {
      $t = ['rekeyed' => 0, 'current' => 0, 'failed' => 0];
      foreach ($this->report as $r) {
         foreach ($t as $k => $_) {
            $t[$k] += $r[$k];
         }
      }
      return $t;
   }

   /**
    * Um valor de uma linha: cru (segredo direto) ou JSON (percorrido por dentro). Grava só se algo mudou.
    */
   private function processRow(string $table, string $column, int $id, string $value, string $label, string $pk = 'id'): void {
      if ($value === '') {
         return;
      }
      $mudou = false;
      $json  = null;
      $first = ltrim($value)[0] ?? '';
      if ($first === '{' || $first === '[') {
         $json = json_decode($value, true);
      }
      if (is_array($json)) {
         $novo = $this->walk($json, $label, $mudou);
         $final = $mudou ? json_encode($novo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value;
      } else {
         $final = $this->rekeyValue($value, $label, $mudou);
      }
      if (!$mudou || $this->dryRun || !is_string($final)) {
         return;
      }
      global $DB;
      $DB->update($table, PluginNextoolDbCompat::row([$column => $final]), [$pk => $id]);
   }

   /** Percorre um JSON e re-cifra as folhas string. */
   private function walk(array $data, string $label, bool &$mudou): array {
      foreach ($data as $k => $v) {
         if (is_array($v)) {
            $data[$k] = $this->walk($v, $label . '.' . $k, $mudou);
         } elseif (is_string($v) && $v !== '') {
            $data[$k] = $this->rekeyValue($v, $label . '.' . $k, $mudou);
         }
      }
      return $data;
   }

   /**
    * Um valor: NXENC1 ou GLPIKey cru. Devolve o valor a gravar (o mesmo, se nada muda) e conta no relatório.
    */
   private function rekeyValue(string $value, string $label, bool &$mudou): string {
      $vault   = PluginNextoolSecretVault::isEncrypted($value);
      $payload = $vault ? substr($value, strlen(PluginNextoolSecretVault::PREFIX)) : $value;
      if (!$vault && !$this->looksLikeCipher($payload)) {
         return $value; // texto comum: nem conta
      }
      if ($this->decrypts($payload, null)) {
         $this->count($label, self::RESULT_CURRENT);
         return $value;
      }
      $plain = $this->decryptWith($payload, $this->oldKey);
      if ($plain === null) {
         if ($vault) {
            $this->count($label, self::RESULT_FAILED); // marcado como cifrado e não abre com nenhuma chave
         }
         return $value; // cru que não abre: era texto comum parecido com base64
      }
      $nova = $this->glpiKey->encrypt($plain);
      $this->count($label, self::RESULT_REKEYED);
      $mudou = true;
      return $vault ? PluginNextoolSecretVault::PREFIX . $nova : $nova;
   }

   /** Cifra do GLPIKey é base64 de nonce (24 bytes) + texto + tag (16): menos que isso nem tenta. */
   private function looksLikeCipher(string $v): bool {
      if (strlen($v) < 56 || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $v) !== 1) {
         return false;
      }
      $raw = base64_decode($v, true);
      return is_string($raw) && strlen($raw) >= 41;
   }

   private function decrypts(string $payload, ?string $key): bool {
      return $this->decryptWith($payload, $key) !== null;
   }

   /** Decifra sem deixar o warning do GLPIKey ("Unable to decrypt string") ir para o log a cada tentativa. */
   private function decryptWith(string $payload, ?string $key): ?string {
      set_error_handler(static fn () => true);
      try {
         $plain = $this->glpiKey->decrypt($payload, $key);
      } catch (\Throwable $e) {
         $plain = null;
      } finally {
         restore_error_handler();
      }
      return is_string($plain) && $plain !== '' ? $plain : null;
   }

   private function count(string $label, string $result): void {
      if ($result === self::RESULT_PLAIN) {
         return;
      }
      $this->report[$label] ??= ['rekeyed' => 0, 'current' => 0, 'failed' => 0];
      $this->report[$label][$result]++;
   }

   /** @return string[] tabelas `glpi_plugin_nextool_%config%` existentes */
   private function configTables(): array {
      global $DB;
      $out = [];
      foreach ($DB->listTables('glpi_plugin_nextool_%config%') as $t) {
         $name = (string) ($t['TABLE_NAME'] ?? '');
         if ($name !== '' && !in_array($name, self::SKIP_TABLES, true) && $DB->fieldExists($name, 'id')) {
            $out[] = $name;
         }
      }
      return $out;
   }

   /** @return string[] colunas de texto (varchar/text) da tabela */
   private function textColumns(string $table): array {
      global $DB;
      $out = [];
      foreach ($DB->listFields($table) as $name => $f) {
         $type = strtolower((string) ($f['Type'] ?? ''));
         if (str_starts_with($type, 'varchar') || str_contains($type, 'text') || str_starts_with($type, 'char')) {
            $out[] = (string) $name;
         }
      }
      return $out;
   }

   /**
    * Locais declarados pelos módulos instalados (`BaseModule::getSecretLocations()`), para segredo guardado fora
    * dos padrões que a varredura já cobre.
    *
    * @return array<int, array{table:string, column:string, pk?:string, where?:array}>
    */
   private function declaredLocations(): array {
      $out = [];
      if (!class_exists('PluginNextoolModuleManager')) {
         return $out;
      }
      try {
         $manager = PluginNextoolModuleManager::getInstance();
         foreach ($manager->getAllModules() as $module) {
            if (!is_object($module) || !method_exists($module, 'getSecretLocations')) {
               continue;
            }
            foreach ((array) $module->getSecretLocations() as $loc) {
               if (is_array($loc) && !empty($loc['table']) && !empty($loc['column'])) {
                  $out[] = $loc;
               }
            }
         }
      } catch (\Throwable $e) {
         // sem o registro de módulos, a varredura dos padrões continua valendo
      }
      return $out;
   }
}
