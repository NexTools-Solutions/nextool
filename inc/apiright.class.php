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

   /**
    * @param int[]  $bits bits do direito do módulo, todos exigidos
    * @param string $kind self::KIND_ADMIN | self::KIND_USE
    */
   public function __construct(
      public array $bits,
      public string $kind = self::KIND_ADMIN,
   ) {}
}
