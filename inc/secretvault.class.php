<?php
/**
 * PluginNextoolSecretVault - cifra de segredos em repouso (LGPD).
 *
 * Wrapper fino sobre o GLPIKey nativo (chave da instancia, sodium XChaCha20).
 * Marca os valores cifrados com um PREFIXO ("NXENC1:") para que a decifra seja
 * deterministica e tolerante a legado: um valor SEM o prefixo e tratado como
 * texto em claro antigo e devolvido intacto (migracao lazy - re-cifrado no
 * proximo save). Assim nunca disparamos o E_USER_WARNING que o GLPIKey emite ao
 * receber um valor nao-cifrado, e nunca confundimos claro com cifrado.
 *
 * Uso:
 *   $armazenar = PluginNextoolSecretVault::encrypt($tokenEmClaro);  // grava isto
 *   $usar      = PluginNextoolSecretVault::decrypt($valorDoBanco);  // usa isto
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolSecretVault {

   /** Marca que identifica um valor cifrado por este vault. */
   private const PREFIX = 'NXENC1:';

   /**
    * Cifra um segredo para persistir. Fail-secure: se o GLPIKey estiver
    * indisponivel, retorna '' e loga - o chamador NUNCA deve gravar o valor em
    * claro; deve tratar '' como "manter o valor atual" (padrao preserva-se-vazio).
    * Um valor ja cifrado por este vault e devolvido como esta (idempotente).
    */
   public static function encrypt(string $value): string {
      if ($value === '') {
         return '';
      }
      if (self::isEncrypted($value)) {
         return $value;
      }
      if (class_exists('GLPIKey')) {
         try {
            $enc = (new GLPIKey())->encrypt($value);
            if (is_string($enc) && $enc !== '') {
               return self::PREFIX . $enc;
            }
         } catch (\Throwable $e) {
            // cai no fail-secure abaixo
         }
      }
      Toolbox::logInFile(
         'plugin_nextool',
         '[SECURITY] GLPIKey indisponivel - recusando cifrar segredo em claro'
      );
      return '';
   }

   /**
    * Decifra um valor gravado por encrypt(). Tolerante a legado: se o valor NAO
    * tiver o prefixo, e texto em claro antigo e e devolvido intacto. Se tiver o
    * prefixo mas nao decifrar (ex.: chave da instancia mudou), retorna '' para
    * NAO vazar o ciphertext para uma API.
    */
   public static function decrypt(string $value): string {
      if ($value === '') {
         return '';
      }
      if (!self::isEncrypted($value)) {
         return $value; // legado em claro
      }
      $payload = substr($value, strlen(self::PREFIX));
      if (class_exists('GLPIKey')) {
         try {
            $plain = (new GLPIKey())->decrypt($payload);
            if (is_string($plain) && $plain !== '') {
               return $plain;
            }
         } catch (\Throwable $e) {
            // chave ausente/trocada: nao vaza o ciphertext
         }
      }
      return '';
   }

   /** Indica se o valor ja esta cifrado por este vault (tem o prefixo). */
   public static function isEncrypted(string $value): bool {
      return $value !== '' && strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
   }

   // ====================================================================
   // Chave AES-256 dos modulos (issue #161)
   //
   // Modulos que cifram credenciais (hoje `automations` e `glpisync`) usam uma
   // chave AES-256-GCM propria, historicamente guardada SO num arquivo oculto em
   // files/_plugins/nextool/.encryption_key. Um arquivo que ninguem sabe que
   // existe nao entra em backup nem em migracao de host: leva-se o dump do banco
   // (com as credenciais cifradas) e a chave fica para tras -> credencial
   // irrecuperavel.
   //
   // A chave passa a viver TAMBEM em glpi_configs, cifrada pelo GLPIKey (o mesmo
   // cofre que o admin ja protege por procedimento do proprio GLPI) -- assim ela
   // viaja no dump. Os dados ja cifrados NAO sao tocados: e a mesma chave, so
   // muda onde ela mora.
   //
   // ESPELHAMENTO (nao remover sem antes aposentar as versoes antigas dos modulos):
   // a chave e escrita nos DOIS lugares (config + arquivo). Durante o rollout um
   // modulo em versao ANTIGA le apenas o arquivo; se a chave nascesse so no
   // config, esse modulo geraria outra chave no arquivo e passariamos a ter dois
   // cofres divergentes -- credenciais cifradas com uma, lidas com a outra.
   // ====================================================================

   private const MODULES_KEY_CONTEXT = 'plugin:nextool';
   private const MODULES_KEY_NAME    = 'modules_encryption_key';
   private const MODULES_KEY_FILE    = '.encryption_key';
   private const MODULES_KEY_BYTES   = 32;

   /** @var ?string Cache da chave por request. */
   private static ?string $modulesKeyCache = null;

   /** Caminho do arquivo legado da chave (espelho). */
   public static function getModulesKeyFilePath(): string {
      return GLPI_VAR_DIR . '/_plugins/nextool/' . self::MODULES_KEY_FILE;
   }

   /**
    * Chave AES-256 (32 bytes crus) usada pelos modulos para cifrar credenciais.
    *
    * Ordem de resolucao:
    *   1. glpi_configs (fonte nova, cifrada pelo GLPIKey)
    *   2. arquivo legado -> se achado, MIGRA para o config e segue
    *   3. gera uma nova (so quando $createIfMissing) e grava nos dois lugares
    *
    * @param bool $createIfMissing false para apenas CONSULTAR sem criar nada.
    * @return ?string 32 bytes crus, ou null se ausente e $createIfMissing=false
    */
   public static function getModulesEncryptionKey(bool $createIfMissing = true): ?string {
      if (self::$modulesKeyCache !== null) {
         return self::$modulesKeyCache;
      }

      // 1. glpi_configs
      $stored = self::readModulesKeyFromConfig();
      if ($stored !== null) {
         self::$modulesKeyCache = $stored;
         self::mirrorModulesKeyToFile($stored); // mantem o espelho p/ modulo antigo
         return self::$modulesKeyCache;
      }

      // 2. arquivo legado -> migra
      $path = self::getModulesKeyFilePath();
      if (is_file($path) && is_readable($path)) {
         $raw = @file_get_contents($path);
         if (is_string($raw) && strlen($raw) === self::MODULES_KEY_BYTES) {
            if (self::writeModulesKeyToConfig($raw)) {
               Toolbox::logInFile('plugin_nextool',
                  "[SECURITY] chave de criptografia dos modulos migrada do arquivo para glpi_configs (cifrada pela chave da instancia)\n");
            }
            self::$modulesKeyCache = $raw;
            return self::$modulesKeyCache;
         }
      }

      if (!$createIfMissing) {
         return null;
      }

      // 3. nao existe em lugar nenhum -> gerar.
      // Observabilidade (decisao 2026-07-30): o comportamento de GERAR foi mantido de
      // proposito -- falhar aqui quebraria ambientes que operam com credencial legada em
      // texto puro, que o decrypt() dos modulos aceita. Mas se ja HA dado cifrado no banco,
      // gerar chave nova torna esse dado ilegivel; antes isso acontecia em silencio absoluto.
      if (self::hasEncryptedCredentials()) {
         Toolbox::logInFile('plugin_nextool',
            "[SECURITY] chave de criptografia dos modulos AUSENTE (config e arquivo) e EXISTEM credenciais cifradas no banco. "
            . "Gerando chave nova -- as credenciais ja cifradas ficarao ILEGIVEIS ate a chave original ser restaurada. "
            . "Restaure o backup ANTES de salvar as configuracoes dos modulos.\n");
      }

      $key = random_bytes(self::MODULES_KEY_BYTES);
      self::writeModulesKeyToConfig($key);
      self::mirrorModulesKeyToFile($key);
      self::$modulesKeyCache = $key;

      return self::$modulesKeyCache;
   }

   /** Le a chave de glpi_configs (decifrando). Null se ausente/corrompida. */
   private static function readModulesKeyFromConfig(): ?string {
      if (!class_exists('Config')) {
         return null;
      }
      try {
         $values = Config::getConfigurationValues(self::MODULES_KEY_CONTEXT, [self::MODULES_KEY_NAME]);
      } catch (\Throwable $e) {
         return null;
      }
      $stored = (string) ($values[self::MODULES_KEY_NAME] ?? '');
      if ($stored === '') {
         return null;
      }
      $b64 = self::decrypt($stored);
      if ($b64 === '') {
         // Tem valor mas nao decifrou: a chave da instancia (glpicrypt.key) mudou ou sumiu.
         // NAO retornamos null silenciosamente sem registrar -- este e o caso em que o
         // admin precisa restaurar o glpicrypt.key, nao a nossa chave.
         Toolbox::logInFile('plugin_nextool',
            "[SECURITY] chave de criptografia dos modulos existe em glpi_configs mas NAO decifra "
            . "(glpicrypt.key da instancia mudou ou esta ausente)\n");
         return null;
      }
      $raw = base64_decode($b64, true);
      return (is_string($raw) && strlen($raw) === self::MODULES_KEY_BYTES) ? $raw : null;
   }

   /** Grava a chave em glpi_configs, cifrada. Bytes crus viajam em base64. */
   private static function writeModulesKeyToConfig(string $rawKey): bool {
      if (!class_exists('Config') || strlen($rawKey) !== self::MODULES_KEY_BYTES) {
         return false;
      }
      $enc = self::encrypt(base64_encode($rawKey));
      if ($enc === '') {
         return false; // fail-secure do encrypt(): nunca grava em claro
      }
      try {
         Config::setConfigurationValues(self::MODULES_KEY_CONTEXT, [self::MODULES_KEY_NAME => $enc]);
         return true;
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool',
            '[SECURITY] falha ao gravar a chave dos modulos em glpi_configs: ' . $e->getMessage() . "\n");
         return false;
      }
   }

   /** Mantem o arquivo legado em dia (modulos em versao antiga leem so dele). */
   private static function mirrorModulesKeyToFile(string $rawKey): void {
      $path = self::getModulesKeyFilePath();
      if (is_file($path)) {
         $current = @file_get_contents($path);
         if ($current === $rawKey) {
            return;
         }
      }
      $dir = dirname($path);
      if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
         return;
      }
      if (@file_put_contents($path, $rawKey, LOCK_EX) !== false) {
         @chmod($path, 0600);
      }
   }

   /**
    * Ha credencial cifrada por modulo no banco? Usado só para decidir se o log de
    * chave-ausente é alarmante. Best-effort: se as tabelas não existem, é não.
    */
   private static function hasEncryptedCredentials(): bool {
      global $DB;

      if (!isset($DB) || !is_object($DB)) {
         return false;
      }
      // Colunas que os módulos cifram (conferidas no schema, não presumidas).
      $targets = [
         'glpi_plugin_nextool_automations_connectors'    => ['credentials'],
         'glpi_plugin_nextool_glpisync_remote_instances' => ['credentials', 'app_token'],
      ];
      foreach ($targets as $table => $columns) {
         try {
            if (!$DB->tableExists($table)) {
               continue;
            }
            foreach ($columns as $column) {
               if (!$DB->fieldExists($table, $column)) {
                  continue;
               }
               foreach ($DB->request(['FROM' => $table, 'WHERE' => ['NOT' => [$column => ['', null]]]]) as $row) {
                  $v = (string) ($row[$column] ?? '');
                  // Cifrado pelos módulos = base64 que NÃO começa com { ou [ (JSON em claro,
                  // que o decrypt() deles trata como legado e devolve intacto).
                  if ($v !== '' && $v[0] !== '{' && $v[0] !== '[') {
                     return true;
                  }
               }
            }
         } catch (\Throwable $e) {
            continue;
         }
      }
      return false;
   }
}
