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
}
