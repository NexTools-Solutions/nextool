<?php
declare(strict_types=1);
/**
 * Rodapé da configuração do NexTool: ficha técnica deste ambiente e links fixos. Desenhado por
 * PluginNextoolMainConfig::displayTabContentForItem() no fim de TODA aba da página (as da base e as
 * de configuração dos módulos); nasceu na aba Sobre e foi para todas em 2026-10-13.
 * Autossuficiente: não depende das variáveis do config.form.php (as abas de módulo não passam por ele).
 * Quem não tem acesso às abas administrativas (perfil só de módulo) não vê o código do ambiente nem
 * o link "Minhas licenças", que leva a uma aba que ele não abre.
 * mt-3: a linha separadora precisa de distância do que vem antes. Nas abas da base o conteúdo já termina com
 * margem (colapsa, nada muda); nas abas de módulo ele termina no card, e sem isso a linha some colada nele.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once NEXTOOL_PHP_DIR . '/inc/compat.class.php';
require_once NEXTOOL_PHP_DIR . '/inc/config.class.php';

$nextoolFooterEsc   = static fn($v): string => (string) PluginNextoolCompat::escape((string) $v);
$nextoolFooterAdmin = PluginNextoolPermissionManager::canAccessAdminTabs();
$nextoolFooterWeb   = PluginNextoolCompat::webDir() . '/front/nextoolconfig.form.php?id=1&forcetab=';
$nextoolFooterGlpi  = defined('GLPI_VERSION') ? (string) GLPI_VERSION : '-';
?>
<div class="nextool-config-footer d-flex flex-wrap align-items-center gap-3 text-muted small border-top pt-3 mt-3 mx-3 mb-3">
   <span><i class="ti ti-tool me-1"></i>NexTool <strong>v<?php echo $nextoolFooterEsc(PLUGIN_NEXTOOL_VERSION); ?></strong></span>
   <span><i class="ti ti-server me-1"></i>GLPI <strong><?php echo $nextoolFooterEsc($nextoolFooterGlpi); ?></strong> · PHP <?php echo $nextoolFooterEsc(PHP_VERSION); ?></span>
   <?php if ($nextoolFooterAdmin):
      $nextoolFooterId = trim((string) (PluginNextoolConfig::getDistributionSettings()['client_identifier'] ?? ''));
   ?>
   <span><i class="ti ti-fingerprint me-1"></i><?php echo __('Ambiente', 'nextool'); ?>
      <?php echo $nextoolFooterId !== '' ? '<code>' . $nextoolFooterEsc($nextoolFooterId) . '</code>' : '<em>' . $nextoolFooterEsc(__('não identificado', 'nextool')) . '</em>'; ?>
   </span>
   <?php endif; ?>
   <span class="ms-auto">
      <?php if ($nextoolFooterAdmin): ?>
      <a href="<?php echo $nextoolFooterEsc($nextoolFooterWeb . rawurlencode('PluginNextoolMainConfig$3')); ?>" class="text-decoration-underline"><?php echo __('Minhas licenças', 'nextool'); ?></a> ·
      <?php endif; ?>
      <a href="<?php echo $nextoolFooterEsc($nextoolFooterWeb . rawurlencode('PluginNextoolMainConfig$1')); ?>" class="text-decoration-underline"><?php echo __('Módulos', 'nextool'); ?></a>
      · <a href="<?php echo $nextoolFooterEsc(NEXTOOL_RELEASES_URL); ?>" target="_blank" rel="noopener" class="text-decoration-underline">Releases</a>
      · <a href="<?php echo $nextoolFooterEsc(NEXTOOL_TERMS_URL); ?>" target="_blank" rel="noopener" class="text-decoration-underline"><?php echo __('Termos de uso', 'nextool'); ?></a>
      · <?php echo sprintf(__('Desenvolvido por %s', 'nextool'),
            '<a href="' . $nextoolFooterEsc(NEXTOOL_AUTHOR_URL) . '" target="_blank" rel="noopener" class="text-decoration-underline">' . $nextoolFooterEsc(NEXTOOL_AUTHOR_NAME) . '</a>'
            . ' · <a href="' . $nextoolFooterEsc(NEXTOOL_SITE_URL) . '" target="_blank" rel="noopener" class="text-decoration-underline">NexTool Solutions</a>'); ?>
   </span>
</div>
