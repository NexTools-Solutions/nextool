<?php
/**
 * Cloud link v1 -- credencial local do vínculo com a nuvem NexTool (cérebro).
 *
 * A credencial NÃO é configurada pelo cliente: chega pelo trilho `managed_services` do
 * `/api/licensing/validate` (serviço `nextool_cloud`) e é gravada cifrada pela base. Aqui só se LÊ.
 *
 * Duas chaves derivam do mesmo `api_secret` (o `instance_token` da linha):
 *  - ENTRADA (cérebro -> base, este executor): `inbound`;
 *  - SAÍDA (base -> cérebro, ex.: empurrar o token do bot do Telegram): `outbound`.
 * Ambas por HKDF, com `info` distintas, para que o vazamento de um lado não assine o outro.
 * NÃO usar o segredo de outro serviço (ex.: `whatsapp_cerebro`) para a saída: ambiente sem aquele
 * produto ficaria sem canal de volta.
 *
 *   inbound  = bin2hex(hash_hkdf('sha256', <api_secret como STRING>, 32, 'nextool-cloud-inbound-v1'))
 *   outbound = bin2hex(hash_hkdf('sha256', <api_secret como STRING>, 32, 'nextool-cloud-outbound-v1'))
 *
 * Salt vazio nas duas. Vetores conferidos PHP x Node (`tools/cloud-link-vetores.js` do cérebro,
 * contrato §2.10), incluindo o caso `inbound != outbound` para o mesmo segredo.
 *
 * Do mesmo segredo sai também o `kid` (contrato 1.1, §2.13.1): o id da chave, que viaja no
 * `X-Nx-Kid` nos dois sentidos, FORA da canônica. Não é segredo e não assina nada: serve para uma
 * ponta perceber que a outra está com outra chave (rotação) e se ressincronizar sozinha.
 *
 *   kid = bin2hex(hash_hkdf('sha256', <api_secret como STRING>, 8, 'nextool-cloud-kid-v1'))  -- 16 hex
 *
 * @since 6.23.0
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginNextoolCloudCreds {

   /** Chave do serviço no trilho managed_services. */
   public const SERVICE = 'nextool_cloud';

   /** `info` do HKDF. Muda junto com a versão do contrato -- nunca reusar entre versões. */
   public const INBOUND_INFO = 'nextool-cloud-inbound-v1';

   /** `info` do HKDF da chave de SAÍDA (contrato §2.10). */
   public const OUTBOUND_INFO = 'nextool-cloud-outbound-v1';

   /** `info` do HKDF do id da chave (contrato 1.1, §2.13.1). */
   public const KID_INFO = 'nextool-cloud-kid-v1';

   /** Tamanho do kid em bytes (16 hex). */
   private const KID_BYTES = 8;

   /** Status que autorizam execução. `suspended` e `pending_provisioning` NÃO executam. */
   private const STATUS_OK = 'active';

   /**
    * Credencial do vínculo, ou null quando não há linha entregue.
    *
    * Vínculo com `api_url` que não seja https válido NÃO é aceito: é para lá que vão os pedidos
    * assinados e o token do bot. A entrega já descarta esse endereço (`persistManagedServices`); a
    * checagem aqui cobre a linha gravada por uma versão anterior.
    *
    * `kid` é o id da chave (contrato 1.1): o mesmo valor que o cérebro calcula do segredo dele.
    *
    * @return array{status:string, ambiente:string, api_url:string, inbound:string, outbound:string,
    *               kid:string, expires_at:string, grace_until:string, synced_at:int}|null
    */
   public static function get(): ?array {
      require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';
      $svc = PluginNextoolConfig::getManagedService(self::SERVICE);
      if ($svc === null) {
         return null;
      }
      $secret = (string) ($svc['instance_token_plain'] ?? '');
      if ($secret === '') {
         // Linha sem token (TOKENLESS_SERVICES): serve para entitlement, não para assinar.
         return null;
      }
      if (!PluginNextoolConfig::isHttpsUrl((string) $svc['api_url'])) {
         return null;
      }

      return [
         'status'      => (string) $svc['status'],
         'ambiente'    => (string) $svc['instance_name'],
         'api_url'     => (string) $svc['api_url'],
         'inbound'     => self::deriveInbound($secret),
         'outbound'    => self::deriveOutbound($secret),
         'kid'         => self::deriveKid($secret),
         'expires_at'  => (string) ($svc['expires_at'] ?? ''),
         'grace_until' => (string) ($svc['grace_until'] ?? ''),
         'synced_at'   => (int) ($svc['synced_at'] ?? 0),
      ];
   }

   /**
    * Deriva o segredo de ENTRADA a partir do `api_secret`.
    *
    * @param string $apiSecret segredo da linha, como string (não decodificar de hex antes)
    * @return string 64 caracteres hex
    */
   public static function deriveInbound(string $apiSecret): string {
      return bin2hex(hash_hkdf('sha256', $apiSecret, 32, self::INBOUND_INFO));
   }

   /**
    * Deriva o segredo de SAÍDA (base -> cérebro) a partir do `api_secret`.
    *
    * A chave do HMAC é esta string hex de 64 caracteres, não os bytes crus -- igual ao inbound.
    *
    * @param string $apiSecret segredo da linha, como string (não decodificar de hex antes)
    * @return string 64 caracteres hex
    * @since 6.24.0
    */
   public static function deriveOutbound(string $apiSecret): string {
      return bin2hex(hash_hkdf('sha256', $apiSecret, 32, self::OUTBOUND_INFO));
   }

   /**
    * Deriva o id da chave (`kid`, contrato 1.1 §2.13.1) a partir do `api_secret`.
    *
    * Mesmas duas armadilhas do inbound: o segredo entra como STRING e o salt é vazio. Vetores do
    * contrato (conferidos em PHP e Node): `segredo-de-teste-kid` -> `a047c21490ebeaef`; `ab` repetido
    * 32 vezes -> `823133363128e5af`.
    *
    * @param string $apiSecret segredo da linha, como string (não decodificar de hex antes)
    * @return string 16 caracteres hex minúsculos
    */
   public static function deriveKid(string $apiSecret): string {
      return bin2hex(hash_hkdf('sha256', $apiSecret, self::KID_BYTES, self::KID_INFO));
   }

   /**
    * O vínculo está vigente? Considera status, a validade local e a janela `expires_at`/`grace_until`.
    *
    * Validade local (auditoria de 2026-09-24, HI-02 e N8 do plano): a credencial vale até a graça
    * offline da licença, contada da última entrega pelo servidor (`synced_at`). Sem isso, um GLPI que
    * deixasse de sincronizar (rede, ou a saída para o ContainerAPI bloqueada de propósito) seguia com
    * o vínculo para sempre, inclusive depois de uma rotação ou suspensão que nunca chegou.
    *
    * Regra do contrato: dentro do `grace_until` o serviço SEGUE executando (degradação suave);
    * depois dele, não. Data vazia = sem prazo (não expira).
    *
    * @param array $cred saída de get()
    * @param int   $now  timestamp (injetável no teste)
    */
   public static function isActive(array $cred, int $now = 0): bool {
      if (($cred['status'] ?? '') !== self::STATUS_OK) {
         return false;
      }
      $now = $now > 0 ? $now : time();
      if (!self::fresh((int) ($cred['synced_at'] ?? 0), $now)) {
         return false;
      }
      $limit = '';
      foreach (['grace_until', 'expires_at'] as $field) {
         if (!empty($cred[$field])) {
            $limit = (string) $cred[$field];
            break; // grace_until manda quando existe
         }
      }
      if ($limit === '') {
         return true;
      }
      $ts = strtotime($limit);
      return $ts === false ? true : ($now <= $ts);
   }

   /**
    * O ambiente tem DIREITO de usar as funções de nuvem deste módulo?
    *
    * Fonte ÚNICA: a **licença**. O `/validate` entrega `modules_entitlement` com uma linha por item
    * pago (`status` + `ever_licensed`), o mesmo mecanismo que libera os módulos pagos. A chave é a que o
    * módulo declara em `getCloudLicenseKey()`: a do PRODUTO (`feature_*`) quando o módulo é FREE com
    * feature paga. Ter licença com o produto É ter o direito -- não existe ativação por fora.
    *
    * O direito local tem VALIDADE (auditoria de 2026-09-24, ME-11, N8 do plano): vale até a graça
    * offline da licença, contada da última resposta com `valid=true` que confirmou o mapa. Até a 6.24.1
    * ele valia para sempre: bloquear a saída do GLPI para o ContainerAPI congelava o produto como
    * ativo, e a base mostrava FREE com o módulo em modo nuvem. A perda de licença, o mapa completo
    * vazio e o uninstall o apagam (ver `PluginNextoolLicenseValidator::syncModulesEntitlement`).
    *
    * A segunda fonte (uma linha de `managed_services` que o módulo declarasse em
    * `getCloudEntitlement()`) foi REMOVIDA (decisão h do plano, ME-05): ela autorizava sem olhar datas
    * nem chaves, e um módulo que declarasse o próprio vínculo (`nextool_cloud`) ficava liberado em
    * todo ambiente com vínculo. Produto vendido como serviço entra como item `feature_*` da licença.
    * O parâmetro `$managedService` fica na assinatura só para quem já o passa, e é ignorado.
    *
    * Esta é a régua ÚNICA: o executor e a tela (modo de operação do módulo) chamam este método. Se
    * cada um decidisse por conta, voltaria o defeito de a interface anunciar nuvem enquanto toda
    * ferramenta responde `unavailable`.
    *
    * @param string      $moduleKey       chave de licença (a de `getCloudLicenseKey()` ou a do módulo)
    * @param string|null $managedService  IGNORADO (era a segunda fonte, removida)
    * @param int         $now             timestamp (injetável no teste)
    * @since 6.23.0
    */
   public static function entitled(string $moduleKey, ?string $managedService = null, int $now = 0): bool {
      if ($moduleKey === '') {
         return false;
      }
      $file = NEXTOOL_PHP_DIR . '/inc/licensevalidator.class.php';
      if (!is_file($file)) {
         return false;
      }
      require_once $file;
      $mapa = PluginNextoolLicenseValidator::getModulesEntitlement();
      $linha = $mapa[$moduleKey] ?? null;
      if (!is_array($linha) || (string) ($linha['status'] ?? '') !== 'active') {
         return false;
      }

      return self::fresh(PluginNextoolLicenseValidator::getModulesEntitlementSyncedAt(), $now);
   }

   /**
    * Um dado sincronizado em `$syncedAt` ainda vale? Vale até a graça offline da licença (a vida do
    * token assinado do F2), a mesma que mantém os módulos pagos quando o ContainerAPI está fora.
    *
    * `$syncedAt` <= 0 = nunca sincronizado ou invalidado: não vale. Pública para o E2E injetar o tempo.
    */
   public static function fresh(int $syncedAt, int $now = 0): bool {
      if ($syncedAt <= 0) {
         return false;
      }
      require_once NEXTOOL_PHP_DIR . '/inc/entitlementtoken.class.php';
      $now = $now > 0 ? $now : time();

      return $now <= $syncedAt + PluginNextoolEntitlementToken::offlineGraceSeconds();
   }

   /**
    * Segredo dummy para a comparação em tempo constante quando NÃO há credencial local.
    *
    * Sem isso, "não configurado" responderia mais rápido que "assinatura errada" e viraria um
    * oráculo: dá para descobrir quais ambientes têm o cloud link ligado só medindo o tempo.
    */
   public static function dummyInbound(): string {
      return str_repeat('0', 64);
   }
}
