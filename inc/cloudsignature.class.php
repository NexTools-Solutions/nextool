<?php
/**
 * Cloud link v1 -- a assinatura do contrato num lugar só, para os dois sentidos.
 *
 *   canônica   = "v1\nPOST\n<caminho lógico>\n<ambiente>\n<timestamp>\n<nonce>\n<sha256 hex do corpo>"
 *   assinatura = HMAC-SHA256(canônica, <chave do sentido>)       -- hex minúsculo
 *
 * Sete linhas unidas por "\n", sem "\n" no fim (contrato §2.6). A chave do HMAC é a STRING hex de 64
 * caracteres que o `PluginNextoolCloudCreds` deriva (`inbound` na entrada, `outbound` na saída), nunca
 * os bytes crus. O caminho é o LÓGICO (`/plugin/v1/exec`, `/cloud/v1/endpoint`,
 * `/channels/telegram/token`), nunca a URL física: o plugin pode viver em `plugins/` ou em
 * `files/_plugins/`, atrás de proxy com subcaminho, e assinar o que varia quebraria por
 * infraestrutura, não por segurança.
 *
 * Por que uma classe (auditoria de 2026-09-24, LO-06): até a 6.24.1 a canônica era montada em dois
 * lugares, no guard da entrada e no cliente da saída, cada um com a própria constante `CONTRACT`.
 * Mudar o formato exigia mexer nos dois, e nada garantia que continuassem iguais. Os dois passam a
 * chamar esta classe, e os métodos `sign()` deles ficam só como fachada.
 *
 * O `X-Nx-Kid` (contrato 1.1, §2.13.1) fica FORA da canônica de propósito: trocar o kid só troca a
 * chave de verificação, e a assinatura falha. Aqui mora só o formato dele; a derivação fica com a
 * credencial (`PluginNextoolCloudCreds::deriveKid`).
 *
 * @since pós-6.24.1 (contrato 1.1 do cloud link, K1)
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudSignature {

   /** Versão da canônica e do cabeçalho `X-Nx-Contract`. Fonte única para a entrada e a saída. */
   public const CONTRACT = 'v1';

   /** `X-Nx-Kid`: 16 hex minúsculos (8 bytes do HKDF, contrato §2.13.1). `\z`, não `$`: o `$` aceita um "\n" no fim. */
   private const KID_PATTERN = '/\A[0-9a-f]{16}\z/';

   /** A canônica de 7 linhas do contrato (§2.6). */
   public static function canonical(string $path, string $ambiente, string $timestamp, string $nonce, string $body): string {
      return implode("\n", [
         self::CONTRACT,
         'POST',
         $path,
         $ambiente,
         $timestamp,
         $nonce,
         hash('sha256', $body),
      ]);
   }

   /** Assinatura (hex minúsculo) do corpo EXATO que vai ou veio no fio. */
   public static function sign(string $path, string $ambiente, string $timestamp, string $nonce, string $body, string $key): string {
      return hash_hmac('sha256', self::canonical($path, $ambiente, $timestamp, $nonce, $body), $key);
   }

   /**
    * A assinatura recebida confere? Tempo constante, e o HMAC é calculado mesmo quando ela vem vazia:
    * o tempo de resposta não pode dizer se a pessoa mandou o cabeçalho, nem se esta instalação tem
    * credencial (quem chama passa uma chave fictícia quando não tem).
    */
   public static function verify(string $signature, string $path, string $ambiente, string $timestamp, string $nonce, string $body, string $key): bool {
      $expected = self::sign($path, $ambiente, $timestamp, $nonce, $body, $key);

      return $signature !== '' && hash_equals($expected, $signature);
   }

   /** O valor tem o formato de um kid? (Não diz se é o kid desta instalação.) */
   public static function isKid(string $kid): bool {
      return preg_match(self::KID_PATTERN, $kid) === 1;
   }
}
