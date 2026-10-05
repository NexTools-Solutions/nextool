<?php
declare(strict_types=1);
/**
 * Guias de divulgação "Serviços" e "Novidades" (nextool-dev#277). Um arquivo só para as duas: quem inclui define
 * $nextoolVitrineTab ('servicos' | 'novidades').
 * Contexto/variáveis esperadas: $nextool_is_standalone, $nextool_standalone_output_tab, $canViewAdminTabs,
 * $firstTabKey, $nextool_hero_standalone, $nextoolVitrineTab.
 *
 * O conteúdo vem do servidor pelo canal de comunicados (tipos `servicos` e `novidades`; ver
 * PluginNextoolAlertManager::VITRINE_TYPES), então muda sem lançar versão. Sem nada publicado, a guia mostra um
 * conteúdo padrão com links para o site, no idioma do usuário e com UTM. Sem pop-up e sem coleta nova de dados:
 * só links que o usuário escolhe abrir.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

$nextoolVitrineTab = in_array($nextoolVitrineTab ?? '', PluginNextoolAlertManager::VITRINE_TYPES, true) ? $nextoolVitrineTab : 'servicos';
$show_vitrine = (!$nextool_is_standalone || $nextool_standalone_output_tab === $nextoolVitrineTab) && $canViewAdminTabs;

if ($show_vitrine):
   // Site no idioma do usuário (mesma regra do consultingUrl dos módulos) + UTM da base.
   $nextoolSiteUrl = static function (string $path, string $content) use ($nextoolVitrineTab): string {
      $lang   = strtolower((string) ($_SESSION['glpilanguage'] ?? ''));
      $prefix = '';
      if ($lang === 'pt_pt') {
         $prefix = '/pt-pt';
      } elseif (in_array(substr($lang, 0, 2), ['en', 'es', 'fr', 'it'], true)) {
         $prefix = '/' . substr($lang, 0, 2);
      }
      return 'https://nextoolsolutions.com' . $prefix . $path . '?' . http_build_query([
         'utm_source'   => 'glpi',
         'utm_medium'   => 'nextool-base',
         'utm_campaign' => $nextoolVitrineTab,
         'utm_content'  => $content,
      ]);
   };

   $nextoolVitrineItems = PluginNextoolAlertManager::getVitrineItems($nextoolVitrineTab);
   if ($nextoolVitrineTab === 'servicos') {
      $nextoolVitrineTitle = __('Serviços NexTool Solutions', 'nextool');
      $nextoolVitrineIcon  = 'ti ti-briefcase';
      $nextoolVitrineIntro = __('Além dos módulos, a NexTool ajuda a tirar mais do seu GLPI: da implantação à evolução contínua.', 'nextool');
      $nextoolVitrineDefault = [
         ['ti ti-messages', __('Consultoria em GLPI', 'nextool'), __('Processos, catálogo de serviços, SLAs e automações desenhados para a sua operação.', 'nextool'), $nextoolSiteUrl('/servicos/consultoria', 'consultoria'), __('Conhecer a consultoria', 'nextool')],
         ['ti ti-rocket', __('Implantação', 'nextool'), __('GLPI no ar com a sua estrutura, perfis, regras e integrações, pronto para a equipe usar.', 'nextool'), $nextoolSiteUrl('/servicos/implantacao', 'implantacao'), __('Conhecer a implantação', 'nextool')],
         ['ti ti-puzzle', __('Módulos sob medida', 'nextool'), __('Precisa de algo que nenhum módulo faz? Desenvolvemos para o seu cenário.', 'nextool'), $nextoolSiteUrl('/servicos/consultoria', 'sob-medida'), __('Falar com um especialista', 'nextool')],
      ];
   } else {
      $nextoolVitrineTitle = __('Novidades NexTool', 'nextool');
      $nextoolVitrineIcon  = 'ti ti-sparkles';
      $nextoolVitrineIntro = __('Módulos novos, versões e dicas para o seu GLPI.', 'nextool');
      $nextoolVitrineDefault = [
         ['ti ti-news', __('Blog', 'nextool'), __('Guias práticos e novidades do ecossistema GLPI.', 'nextool'), $nextoolSiteUrl('/blog', 'blog'), __('Ler o blog', 'nextool')],
         ['ti ti-apps', __('Catálogo de módulos', 'nextool'), __('Conheça todos os módulos NexTool, gratuitos e licenciados.', 'nextool'), $nextoolSiteUrl('/plugins-glpi', 'catalogo'), __('Ver o catálogo', 'nextool')],
      ];
   }
?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade<?php echo $firstTabKey === $nextoolVitrineTab ? ' show active' : ''; ?>" id="rt-tab-<?php echo $nextoolVitrineTab; ?>" role="tabpanel" aria-labelledby="rt-tab-<?php echo $nextoolVitrineTab; ?>-link"><?php endif; ?>
   <?php echo $nextool_hero_standalone; ?>

   <div class="card shadow-sm nextool-tab-card" id="nextool-vitrine-<?php echo $nextoolVitrineTab; ?>">
      <div class="card-header mb-3 pt-2 border-top rounded-0">
         <h4 class="card-title ms-5 mb-0">
            <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-purple s-1">
               <i class="fs-2x <?php echo $nextoolVitrineIcon; ?>"></i>
            </div>
            <span><?php echo Html::entities_deep($nextoolVitrineTitle); ?></span>
         </h4>
      </div>
      <div class="card-body">
         <p class="mb-3"><?php echo Html::entities_deep($nextoolVitrineIntro); ?></p>
         <?php if (!empty($nextoolVitrineItems)): ?>
            <div class="row g-3" data-nextool-vitrine="remoto">
               <?php foreach ($nextoolVitrineItems as $item): ?>
                  <div class="col-12 col-lg-6">
                     <div class="card h-100">
                        <div class="card-body">
                           <h5 class="card-title mb-2"><?php echo Html::entities_deep((string) $item['title']); ?></h5>
                           <div class="small"><?php echo PluginNextoolAlertManager::sanitizeBody((string) $item['body']); ?></div>
                        </div>
                     </div>
                  </div>
               <?php endforeach; ?>
            </div>
         <?php else: ?>
            <div class="row g-3" data-nextool-vitrine="padrao">
               <?php foreach ($nextoolVitrineDefault as [$icon, $title, $text, $url, $cta]): ?>
                  <div class="col-12 col-md-6 col-xl-4">
                     <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                           <h5 class="card-title mb-2"><i class="<?php echo $icon; ?> me-2 text-purple"></i><?php echo Html::entities_deep($title); ?></h5>
                           <p class="small text-muted flex-grow-1"><?php echo Html::entities_deep($text); ?></p>
                           <a class="btn btn-outline-primary btn-sm align-self-start" target="_blank" rel="noopener"
                              href="<?php echo Html::entities_deep($url); ?>"><?php echo Html::entities_deep($cta); ?></a>
                        </div>
                     </div>
                  </div>
               <?php endforeach; ?>
            </div>
         <?php endif; ?>
      </div>
   </div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
