<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Guia "Sobre" (vitrine da empresa)
 * -------------------------------------------------------------------------
 * Quem somos, o que fazemos, como trabalhamos e como contratamos, com os textos
 * do site (nextoolsolutions.com); no fim, a ficha técnica deste ambiente (versão
 * do NexTool e do GLPI, identificador, vínculo). O contato (WhatsApp, agenda, site,
 * LinkedIn) fica na faixa do hero, visível em todas as guias. Guia 92 (fora da
 * faixa das guias de módulo), antes de Logs. Só classes Bootstrap/Tabler presentes
 * no GLPI 10 e 11; cor só em avatar/fundo com texto branco.
 *
 * Variáveis do contexto (front/config.form.php): $nextool_is_standalone,
 * $nextool_standalone_output_tab, $canViewAdminTabs, $firstTabKey,
 * $accountLinked, $nextool_hero_standalone, $catalogMeta.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

$show_sobre = (!$nextool_is_standalone || $nextool_standalone_output_tab === 'sobre') && $canViewAdminTabs;
if (!$show_sobre) {
   return;
}

require_once NEXTOOL_PHP_DIR . '/inc/compat.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/majorreadiness.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/licensevalidator.class.php';

$e = static fn($v): string => (string) PluginNextoolCompat::escape((string) $v);


// Logo embutido (data URI): a raiz do plugin não é servida pelo GLPI 11/12, e front/ vai no pacote.
$sobreLogoFile = NEXTOOL_PHP_DIR . '/front/logo-nextool-192.png';
$sobreLogoUrl  = is_file($sobreLogoFile) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($sobreLogoFile)) : '';

// Majors anunciados: do mínimo instalável até o maior entre o teto atual e o próximo major
// já preparado (o 12 entra na vitrine antes de o teto de instalação ser liberado).
$sobreMajorMin = (int) explode('.', PLUGIN_NEXTOOL_MIN_GLPI_VERSION)[0];
$sobreMajorMax = max((int) explode('.', PLUGIN_NEXTOOL_MAX_GLPI_VERSION)[0], PluginNextoolMajorReadiness::NEXT_MAJOR);
$sobreMajors   = implode(' · ', range($sobreMajorMin, $sobreMajorMax));

// Números do catálogo local (fonte: /validate), só o que dá para afirmar daqui.
$sobreTotal = 0;
$sobreFree  = 0;
foreach ((array) ($catalogMeta ?? []) as $sobreMeta) {
   if (empty($sobreMeta['is_available'])) {
      continue;
   }
   $sobreTotal++;
   if (strtoupper((string) ($sobreMeta['billing_tier'] ?? '')) === 'FREE') {
      $sobreFree++;
   }
}

// Números do ecossistema (bloco `ecosystem_stats` do /validate, calculado pelo portal uma vez por dia; a
// mesma régua da home do site). Sem o dado (servidor antigo, ambiente que nunca validou), fala em "dezenas".
$sobreStats    = PluginNextoolLicenseValidator::getEcosystemStats();
$sobrePaises   = (int) ($sobreStats['paises'] ?? 0);
$sobrePresenca = $sobrePaises > 0 ? sprintf(__('%d países', 'nextool'), $sobrePaises) : __('dezenas de países', 'nextool');

/** Cartão padrão da vitrine: avatar colorido (ícone ou número), título e uma frase. */
$sobreCard = static function (string $col, string $avatarInner, string $color, string $title, string $text) use ($e): string {
   return '<div class="' . $e($col) . '">'
      . '<div class="card h-100 border"><div class="card-body">'
      . '<div class="d-flex align-items-center gap-3 mb-2">'
      . '<span class="avatar ' . $e($color) . ' text-white rounded flex-shrink-0">' . $avatarInner . '</span>'
      . '<h4 class="mb-0">' . $e($title) . '</h4></div>'
      . '<div class="text-muted">' . $e($text) . '</div>'
      . '</div></div></div>';
};
$sobreIcon = static fn(string $icon): string => '<i class="' . $e($icon) . ' fs-2"></i>';

/** Item de lista com check verde (cartões de licenciamento). */
$sobreCheck = static fn(string $text, bool $last = false): string =>
   '<li class="d-flex gap-2' . ($last ? '' : ' mb-1') . '"><i class="ti ti-check text-success"></i><span>' . $e($text) . '</span></li>';
?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade<?php echo $firstTabKey === 'sobre' ? ' show active' : ''; ?>" id="rt-tab-sobre" role="tabpanel" aria-labelledby="rt-tab-sobre-link"><?php endif; ?>
   <div class="d-flex flex-column gap-3">
      <?php echo $nextool_hero_standalone; ?>

      <div class="card shadow-sm nextool-tab-card">
         <div class="card-header mb-3 pt-2 border-top rounded-0">
            <h4 class="card-title ms-5 mb-0">
               <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-purple s-1">
                  <i class="fs-2x ti ti-building"></i>
               </div>
               <span><?php echo __('Sobre a NexTool', 'nextool'); ?></span>
            </h4>
         </div>
         <div class="card-body">

            <!-- Quem somos -->
            <div class="row g-4 align-items-center mb-4">
               <div class="col-12 col-lg-3 text-center">
                  <?php if ($sobreLogoUrl !== ''): ?>
                  <img src="<?php echo $sobreLogoUrl; ?>" alt="NexTool Solutions" class="img-fluid" style="max-height: 96px;">
                  <?php else: ?>
                  <span class="avatar avatar-xl bg-purple text-white rounded"><i class="ti ti-tool fs-1"></i></span>
                  <?php endif; ?>
               </div>
               <div class="col-12 col-lg-9">
                  <h3 class="mb-1"><?php echo __('Governança de TI que resolve problemas de verdade', 'nextool'); ?></h3>
                  <div class="text-muted mb-2"><?php echo __('Conectando soluções, gerando valor', 'nextool'); ?></div>
                  <p class="mb-0">
                     <?php echo $e(sprintf(__('A NexTool nasceu da rotina de quem atende chamados, implanta soluções e entrega projetos de TI. Essa experiência prática virou software: hoje nossas soluções estão presentes em ambientes GLPI de %s, e nossos plugins já somam milhares de downloads. Somos especialistas em GLPI, automações e integração de sistemas.', 'nextool'), $sobrePresenca)); ?>
                  </p>
               </div>
            </div>

            <!-- Números (os do catálogo só depois da primeira sincronização) -->
            <div class="row g-3 mb-4">
               <?php if ($sobreTotal > 0): ?>
               <div class="col-6 col-md-3">
                  <div class="card border h-100"><div class="card-body py-3 text-center">
                     <div class="h2 mb-0"><?php echo (int) $sobreTotal; ?></div>
                     <div class="text-muted small text-uppercase"><?php echo __('módulos no catálogo', 'nextool'); ?></div>
                  </div></div>
               </div>
               <div class="col-6 col-md-3">
                  <div class="card border h-100"><div class="card-body py-3 text-center">
                     <div class="h2 mb-0"><?php echo (int) $sobreFree; ?></div>
                     <div class="text-muted small text-uppercase"><?php echo __('gratuitos', 'nextool'); ?></div>
                  </div></div>
               </div>
               <?php endif; ?>
               <div class="<?php echo $sobreTotal > 0 ? 'col-6 col-md-3' : 'col-6'; ?>">
                  <div class="card border h-100"><div class="card-body py-3 text-center">
                     <div class="h2 mb-0">GLPI <?php echo $e($sobreMajors); ?></div>
                     <div class="text-muted small text-uppercase"><?php echo __('versões suportadas', 'nextool'); ?></div>
                  </div></div>
               </div>
               <div class="<?php echo $sobreTotal > 0 ? 'col-6 col-md-3' : 'col-6'; ?>">
                  <div class="card border h-100"><div class="card-body py-3 text-center">
                     <?php if ($sobrePaises > 0): ?>
                     <div class="h2 mb-0"><?php echo (int) $sobrePaises; ?></div>
                     <div class="text-muted small text-uppercase"><?php echo __('países com ambientes NexTool', 'nextool'); ?></div>
                     <?php else: ?>
                     <div class="h2 mb-0"><i class="ti ti-world"></i></div>
                     <div class="text-muted small text-uppercase"><?php echo __('presença em dezenas de países', 'nextool'); ?></div>
                     <?php endif; ?>
                  </div></div>
               </div>
            </div>

            <!-- O que fazemos -->
            <h4 class="mb-3"><?php echo __('O que fazemos', 'nextool'); ?></h4>
            <div class="row g-3 mb-4">
               <?php
               $sobreCol = 'col-12 col-md-6 col-xl-4';
               echo $sobreCard($sobreCol, $sobreIcon('ti ti-puzzle'), 'bg-purple', __('Módulos para o GLPI', 'nextool'),
                  __('Um hub com módulos prontos para help desk e ITSM: aprovações, SLA, inventário, assinatura digital, IA, WhatsApp, Teams e muito mais, instalados daqui mesmo.', 'nextool'));
               echo $sobreCard($sobreCol, $sobreIcon('ti ti-plug-connected'), 'bg-blue', __('Integrações e automações', 'nextool'),
                  __('Conectamos SaaS, ERPs e diretórios por APIs REST, eventos e automações n8n, com chatbots e copilotos de IA integrados aos seus dados.', 'nextool'));
               echo $sobreCard($sobreCol, $sobreIcon('ti ti-cloud'), 'bg-teal', __('Cloud e infraestrutura', 'nextool'),
                  __('Ambientes em AWS e Azure, Docker, bancos MySQL/Percona e observabilidade com Zabbix e Grafana, com foco em custo e segurança.', 'nextool'));
               echo $sobreCard($sobreCol, $sobreIcon('ti ti-shield-check'), 'bg-orange', __('Governança e compliance', 'nextool'),
                  __('ISO 27001, ITIL 4 e COBIT para estruturar processos, SLAs e auditorias contínuas, com políticas, runbooks e planos de ação.', 'nextool'));
               echo $sobreCard($sobreCol, $sobreIcon('ti ti-headset'), 'bg-green', __('Implantação e suporte de GLPI', 'nextool'),
                  __('Instalação, configuração, LDAP/SSO, plugins e treinamento; suporte remoto por retainer ou sob demanda, com SLA definido.', 'nextool'));
               echo $sobreCard($sobreCol, $sobreIcon('ti ti-code'), 'bg-pink', __('Sob medida', 'nextool'),
                  __('Novas abas, workflows, relatórios e integrações específicas: de ajustes em plugins existentes a soluções criadas do zero.', 'nextool'));
               ?>
            </div>

            <!-- Como trabalhamos (3 passos, cartões numerados) -->
            <h4 class="mb-3"><?php echo __('Como trabalhamos', 'nextool'); ?></h4>
            <div class="row g-3 mb-4">
               <?php
               $sobreSteps = [
                  [__('Consultoria e proposta', 'nextool'), __('Uma rodada para entender a sua dor, mapear o cenário e formatar uma proposta de valor sob medida.', 'nextool')],
                  [__('Escopo e cronograma', 'nextool'), __('Kickoff com a sua equipe: escopo, entregáveis, marcos e prazos alinhados antes de começar.', 'nextool')],
                  [__('Entrega e garantia', 'nextool'), __('Execução no escopo e no prazo; depois, garantia e suporte para ajustes, com melhoria contínua.', 'nextool')],
               ];
               foreach ($sobreSteps as $i => [$stepTitle, $stepText]) {
                  echo $sobreCard('col-12 col-md-4', '<span class="fs-2 fw-bold">' . ($i + 1) . '</span>', 'bg-blue', $stepTitle, $stepText);
               }
               ?>
            </div>

            <!-- Como contratamos -->
            <h4 class="mb-3"><?php echo __('Como funciona o licenciamento dos módulos', 'nextool'); ?></h4>
            <div class="row g-3 mb-4">
               <div class="col-12 col-lg-6">
                  <div class="card h-100 border"><div class="card-body">
                     <div class="d-flex align-items-center gap-3 mb-2">
                        <span class="avatar bg-purple text-white rounded"><i class="ti ti-certificate fs-2"></i></span>
                        <h4 class="mb-0"><?php echo __('Módulos licenciados', 'nextool'); ?></h4>
                     </div>
                     <ul class="list-unstyled mb-0">
                        <?php
                        // xgettext:no-php-format ("50% do" não é diretiva; sem isto o msgfmt recusa as traduções)
                        echo $sobreCheck(__('Serviço contínuo: licença de 12 meses, renovada a cada período; a partir do segundo ano, 50% do valor da contratação.', 'nextool'));
                        echo $sobreCheck(__('Contratação no portal NexTool; o módulo é liberado aqui em minutos, com atualizações, suporte e acompanhamento técnico enquanto a licença estiver ativa.', 'nextool'));
                        echo $sobreCheck(__('Sem renovação, o módulo volta a aparecer como disponível para licenciar; os dados ficam preservados e a renovação devolve o acesso.', 'nextool'), true);
                        ?>
                     </ul>
                  </div></div>
               </div>
               <div class="col-12 col-lg-6">
                  <div class="card h-100 border"><div class="card-body">
                     <div class="d-flex align-items-center gap-3 mb-2">
                        <span class="avatar bg-teal text-white rounded"><i class="ti ti-gift fs-2"></i></span>
                        <h4 class="mb-0"><?php echo __('Módulos gratuitos', 'nextool'); ?></h4>
                     </div>
                     <ul class="list-unstyled mb-0">
                        <?php
                        echo $sobreCheck(__('Sem custo e sem prazo de validade; recebem atualizações como os demais.', 'nextool'));
                        echo $sobreCheck(__('Basta vincular este ambiente à sua conta NexTool para liberar o download.', 'nextool'), true);
                        ?>
                     </ul>
                  </div></div>
               </div>
            </div>
         </div>
      </div>
   </div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
