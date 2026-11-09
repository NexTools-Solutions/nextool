<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Direito exigido por uma rota de API de módulo
 * -------------------------------------------------------------------------
 * Atributo de MÉTODO lido pelo PluginNextoolApiGuardMiddleware antes de a rota rodar
 * (nextool-dev#291). Todo método com #[Route] num controller de módulo o declara; sem ele
 * o PluginNextoolApiRegistry recusa o controller inteiro e o middleware recusa a chamada.
 *
 *   #[PluginNextoolApiRight(bits: [PluginNextoolSsologinRights::VIEW_APPLICATIONS])]
 *
 * - bits: TODOS exigidos (AND), um bit por item. O Session::haveRight com máscara é OR,
 *   por isso a lista: [VIEW, MANAGE] exige os dois; [VIEW | MANAGE] é recusado no registro.
 * - kind: 'admin' (PermissionManager::canAdmin, nega no helpdesk) ou 'use' (canUse).
 * - effect: 'read' ou 'write' (contrato pensado para IA, nextool-dev#295). Omitido = deduzido do método
 *   HTTP (GET/HEAD = read, o resto = write). Só declare para a exceção, ex.: POST de busca que não grava
 *   (`effect: 'read'`). O assistente de IA confirma com o usuário toda rota `write` antes de chamá-la.
 *   ATENÇÃO: base anterior a esta não conhece o parâmetro (newInstance() lança "Unknown named parameter"
 *   e o controller inteiro fica fora): módulo que o usa precisa de min_plugin_version da base nova.
 *
 * Classe simples, sem dependência da API do GLPI: carrega no GLPI 10 sem efeito.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

#[Attribute(Attribute::TARGET_METHOD)]
final class PluginNextoolApiRight {

   public const KIND_ADMIN = 'admin';
   public const KIND_USE   = 'use';

   public const EFFECT_READ  = 'read';
   public const EFFECT_WRITE = 'write';

   /**
    * @param int[]  $bits bits do direito do módulo, todos exigidos
    * @param string $kind self::KIND_ADMIN | self::KIND_USE
    * @param string|null $effect self::EFFECT_READ | self::EFFECT_WRITE | null (deduzido do método HTTP)
    */
   public function __construct(
      public array $bits,
      public string $kind = self::KIND_ADMIN,
      public ?string $effect = null,
   ) {}

   /**
    * Efeito da rota: o declarado, ou o deduzido dos métodos HTTP (só GET/HEAD = read).
    *
    * @param string[] $httpMethods métodos do #[Route]
    */
   public function effectFor(array $httpMethods): string {
      if ($this->effect === self::EFFECT_READ || $this->effect === self::EFFECT_WRITE) {
         return $this->effect;
      }
      $methods = array_map('strtoupper', $httpMethods);
      return $methods !== [] && array_diff($methods, ['GET', 'HEAD']) === [] ? self::EFFECT_READ : self::EFFECT_WRITE;
   }
}
