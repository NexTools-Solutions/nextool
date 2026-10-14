<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Rota pública dos módulos: /plugins/nextool/sso/{module}/{action}
 * -------------------------------------------------------------------------
 * Controller Symfony do plugin base (GLPI 11 e 12; o GLPI 10 não tem kernel e ignora
 * este diretório). Serve as ações PÚBLICAS que um módulo declara em
 * `PluginNextoolBaseModule::getPublicActions()`, cada uma o arquivo
 * `<modulo>/front/public/<acao>.php`, sem login:
 *
 *  - firewall `NO_CHECK` (atributo SÓ neste método: classe + método juntos lançam
 *    RuntimeException no FirewallStrategyListener);
 *  - a ação `callback` é stateless (registrada em plugin_nextool_boot(): sem sessão, sem
 *    cookie, sem CSRF); as demais rodam com sessão ANÔNIMA e só por GET (POST com sessão
 *    passa pelo CSRF do core);
 *  - ação não declarada, módulo inexistente ou arquivo ausente: 404; módulo fora dos ATIVOS
 *    (desabilitado ou que falhou no onInit): página neutra 503, antes de o arquivo rodar;
 *  - o arquivo recebe `$request` e `$nxModule`, pode ecoar ou retornar um Response, redireciona
 *    com Html::redirect() (RedirectException) e NUNCA chama exit/die.
 *
 * ESTE ARQUIVO É INCLUÍDO EM TODA REQUEST DO GLPI 11/12 (PluginContainer registra src/Controller
 * como serviço): erro de compilação aqui derruba o GLPI inteiro. php -l antes de gravar.
 * Só funções que existem nos três majors (guard de publish do artefato único): escape por htmlspecialchars.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Nextool\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\RedirectException;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class SsoController extends AbstractController {

   private const NAME_PATTERN = '/^[a-z0-9_]+$/';

   #[Route(
      '/sso/{module}/{action}',
      name: 'sso',
      requirements: ['module' => '[a-z0-9_]+', 'action' => '[a-z0-9_]+'],
      methods: ['GET', 'POST'],
   )]
   #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
   public function __invoke(Request $request, string $module, string $action): Response {
      // Defesa em profundidade: os requirements da rota já filtram, mas o arquivo é montado
      // com estes nomes e nada de fora deles pode virar caminho.
      if (preg_match(self::NAME_PATTERN, $module) !== 1 || preg_match(self::NAME_PATTERN, $action) !== 1) {
         throw new NotFoundHttpException('Invalid public action.');
      }
      if (!class_exists('PluginNextoolModuleManager') || !defined('NEXTOOL_MODULES_BASE')) {
         // plugin_init do base não rodou (sem tabela, fast-path ou plugin não ativo)
         throw new NotFoundHttpException('NexTool is not initialized.');
      }

      $manager = \PluginNextoolModuleManager::getInstance();
      $nxModule = $manager->getModule($module);
      if (!is_object($nxModule) || !method_exists($nxModule, 'getPublicActions')) {
         throw new NotFoundHttpException('Unknown module.');
      }
      $actions = $nxModule->getPublicActions();
      if (!is_array($actions) || !in_array($action, $actions, true)) {
         throw new NotFoundHttpException('Public action not declared.');
      }
      $file = NEXTOOL_MODULES_BASE . '/' . $module . '/front/public/' . $action . '.php';
      if (!is_file($file)) {
         throw new NotFoundHttpException('Public action file not found.');
      }
      // "Ativo" = carregado neste request (is_enabled=1 E onInit sem falha). Um módulo
      // desabilitado ou quebrado nunca executa código público; a tela de login local
      // continua intacta porque nada aqui toca nela.
      $active = $manager->getActiveModules();
      if (!is_array($active) || !isset($active[$module])) {
         return $this->neutralResponse(503, 'MODULE_DISABLED');
      }

      return $this->runPublicFile($file, $request, $nxModule, $module . '/' . $action);
   }

   /**
    * Executa o arquivo público do módulo isolado num buffer de saída (molde do
    * LegacyFileLoadController do core): o retorno `Response` do arquivo prevalece; senão a
    * saída ecoada vira o corpo, com o status e os cabeçalhos que o arquivo definiu.
    * HttpException e RedirectException atravessam (o kernel as transforma em resposta);
    * qualquer outro Throwable vira página neutra 500 com id de correlação no log.
    */
   private function runPublicFile(string $file, Request $request, object $nxModule, string $label): Response {
      $level = ob_get_level();
      ob_start();
      try {
         $ret = (static function () use ($file, $request, $nxModule) {
            return require $file;
         })();
         while (ob_get_level() > $level + 1) {
            ob_end_flush();
         }
         $out = ob_get_level() === $level + 1 ? (string) ob_get_clean() : '';
      } catch (HttpExceptionInterface | RedirectException $e) {
         while (ob_get_level() > $level) {
            ob_end_clean();
         }
         throw $e;
      } catch (Throwable $e) {
         while (ob_get_level() > $level) {
            ob_end_clean();
         }
         $ref = bin2hex(random_bytes(6));
         \Toolbox::logInFile('plugin_nextool', sprintf(
            "[sso] %s ref=%s %s: %s em %s:%d\n",
            $label,
            $ref,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
         ));
         return $this->neutralResponse(500, 'INTERNAL', $ref);
      }

      if ($ret instanceof Response) {
         return $ret;
      }

      $headers = [];
      foreach (headers_list() as $header) {
         $pos = strpos($header, ':');
         if ($pos === false) {
            continue;
         }
         $headers[trim(substr($header, 0, $pos))] = trim(substr($header, $pos + 1));
      }
      header_remove();

      $status = (int) (http_response_code() ?: 200);
      return new Response($out, $status, $headers);
   }

   /**
    * Página neutra (sem layout do GLPI: no `callback` não há sessão nem idioma do usuário,
    * e Html::nullHeader() leria $_SESSION). Marcadores fixos para os testes:
    * `data-sso-error` (código) e `data-sso-ref` (id de correlação do log).
    */
   private function neutralResponse(int $status, string $code, string $ref = ''): Response {
      global $CFG_GLPI;

      $rootDoc = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
      $title   = __('Este serviço de login não está disponível no momento.', 'nextool');
      $back    = __('Voltar à tela de login', 'nextool');
      $esc     = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

      $refHtml = $ref !== ''
         ? '<p class="nx-sso-ref">' . $esc($ref) . '</p>'
         : '';

      $html = '<!DOCTYPE html>'
         . '<html lang="' . $esc(str_replace('_', '-', (string) ($CFG_GLPI['language'] ?? 'en'))) . '">'
         . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
         . '<meta name="robots" content="noindex"><title>' . $esc($title) . '</title>'
         . '<style>body{font-family:system-ui,sans-serif;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#f4f6fa;color:#1d273b}'
         . 'main{max-width:420px;padding:32px;background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);text-align:center}'
         . '.nx-sso-ref{font-family:monospace;color:#6c7a91;font-size:.85em}a{color:#206bc4}</style></head>'
         . '<body><main data-sso-error="' . $esc($code) . '" data-sso-ref="' . $esc($ref) . '">'
         . '<p>' . $esc($title) . '</p>' . $refHtml
         . '<p><a href="' . $esc($rootDoc . '/index.php') . '">' . $esc($back) . '</a></p>'
         . '</main></body></html>';

      return new Response($html, $status, [
         'Content-Type'  => 'text/html; charset=UTF-8',
         'Cache-Control' => 'no-store',
      ]);
   }
}
