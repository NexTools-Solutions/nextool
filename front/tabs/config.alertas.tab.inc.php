<?php
declare(strict_types=1);
/**
 * Aba Novidades do Nextool (até 2026-10-13 "Alertas"): avisos recebidos do servidor, alertas locais
 * de pré-requisito e as novidades publicadas pelo portal (tipo `novidades`, que não vão para o sino).
 */

require_once NEXTOOL_PHP_DIR . '/inc/alertmanager.class.php';

$alertHistory = PluginNextoolAlertManager::getAlertHistory();
$alertsEndpoint = PluginNextoolCompat::webDir() . '/ajax/alerts.php';
?>

<?php $show_alertas = (!$nextool_is_standalone || $nextool_standalone_output_tab === 'alertas') && $canViewAdminTabs; if ($show_alertas): ?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade" id="rt-tab-alertas" role="tabpanel"><?php endif; ?>
<div class="d-flex flex-column gap-3">

   <?php echo $nextool_hero_standalone ?? ''; ?>

   <div class="card shadow-sm nextool-tab-card">
      <div class="card-header d-flex justify-content-between align-items-center">
         <h4 class="card-title mb-0">
            <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-purple s-1">
               <i class="fs-2x ti ti-bell"></i>
            </div>
            <span class="ms-5"><?php echo __('Novidades', 'nextool'); ?></span>
         </h4>
         <?php if (!empty($alertHistory)): ?>
         <button type="button" class="btn btn-sm btn-outline-secondary" id="nextool-alerts-mark-all-read"
                 data-endpoint="<?php echo PluginNextoolCompat::escape($alertsEndpoint); ?>">
            <i class="ti ti-checks me-1"></i><?php echo __('Marcar todos como lidos', 'nextool'); ?>
         </button>
         <?php endif; ?>
      </div>
      <div class="card-body">
         <?php if (empty($alertHistory)): ?>
            <div class="alert alert-info mb-0">
               <i class="ti ti-info-circle me-2"></i><?php echo __('Nenhuma novidade ou aviso recebido. Clique em Sincronizar para verificar.', 'nextool'); ?>
            </div>
         <?php else: ?>
            <?php
               // Filtros no estilo da aba Módulos (busca + chips com contagem, zeros escondidos). Dentro do mesmo grupo
               // soma (Crítico OU Alerta); entre grupos restringe (Não lidos E Crítico). Tudo no navegador.
               $nxFc = ['unread' => 0, 'expired' => 0, 'critical' => 0, 'warning' => 0, 'promo' => 0, 'info' => 0];
               foreach ($alertHistory as $a) {
                  if (empty($a['is_read_user'])) { $nxFc['unread']++; }
                  if (!empty($a['is_expired'])) { $nxFc['expired']++; }
                  $nxFc[PluginNextoolAlertManager::getTypeGroup((string) $a['alert_type'])]++;
               }
               $nxStateChips = [
                  ['group' => 'state', 'key' => 'unread',  'label' => __('Não lidos', 'nextool'), 'icon' => 'ti ti-mail',  'btn' => 'btn-outline-warning',   'badge' => 'bg-warning text-white'],
                  ['group' => 'state', 'key' => 'expired', 'label' => __('Expirados', 'nextool'), 'icon' => 'ti ti-clock-x', 'btn' => 'btn-outline-secondary', 'badge' => 'bg-secondary text-white'],
               ];
               $nxTypeChips = [];
               foreach (['critical', 'warning', 'promo', 'info'] as $t) {
                  $cor = preg_match('/\bbg-([a-z]+)\b/', PluginNextoolAlertManager::getTypeBadgeClass($t), $m) ? $m[1] : 'info';
                  $nxTypeChips[] = ['group' => 'type', 'key' => $t, 'label' => PluginNextoolAlertManager::getTypeLabel($t),
                     'icon' => preg_replace('/\s*text-[a-z]+/', '', PluginNextoolAlertManager::getTypeIcon($t)),
                     'btn' => 'btn-outline-' . $cor, 'badge' => 'bg-' . $cor . ' text-white'];
               }
               usort($nxTypeChips, static fn (array $a, array $b): int => $nxFc[$b['key']] <=> $nxFc[$a['key']]);
            ?>
            <div class="mb-3" id="nextool-alerts-filter-bar">
               <div class="input-group mb-2">
                  <span class="input-group-text bg-white border-end-0"><i class="ti ti-search text-muted"></i></span>
                  <input type="text" class="form-control border-start-0" id="nextool-alerts-search" autocomplete="off"
                         placeholder="<?php echo PluginNextoolCompat::escape(__('Buscar novidade por título ou texto...', 'nextool')); ?>">
               </div>
               <div class="d-flex gap-2 flex-wrap">
                  <?php foreach ([$nxStateChips, $nxTypeChips] as $i => $grupo): ?>
                     <?php if ($i > 0): ?><span class="border-start mx-1 d-none d-md-block" style="height:24px"></span><?php endif; ?>
                     <?php foreach ($grupo as $chip): if ($nxFc[$chip['key']] <= 0) { continue; } ?>
                     <button type="button" class="btn btn-sm <?php echo $chip['btn']; ?> rounded-pill nextool-news-chip"
                             data-group="<?php echo $chip['group']; ?>" data-key="<?php echo $chip['key']; ?>" aria-pressed="false">
                        <i class="<?php echo $chip['icon']; ?> me-1"></i><?php echo PluginNextoolCompat::escape($chip['label']); ?>
                        <span class="badge <?php echo $chip['badge']; ?> ms-1" data-count-for="<?php echo $chip['key']; ?>"><?php echo $nxFc[$chip['key']]; ?></span>
                     </button>
                     <?php endforeach; ?>
                  <?php endforeach; ?>
               </div>
            </div>
            <div class="text-muted text-center py-4 d-none" id="nextool-alerts-empty">
               <?php echo __('Nenhuma novidade encontrada com os filtros selecionados.', 'nextool'); ?>
            </div>
            <?php // Paginação no navegador: 5 por página, as mais recentes primeiro (getAlertHistory ordena). ?>
            <?php // Um cartão por anúncio, com faixa na cor do tipo: na lista única os anúncios longos se emendavam. ?>
            <div class="d-flex flex-column gap-3" id="nextool-alerts-list" data-per-page="5">
               <?php foreach ($alertHistory as $alert):
                  // Leitura POR USUÁRIO (2026-08-06): "Novo" e o botão de marcar lido
                  // seguem o estado DESTE usuário, não mais o global.
                  $isUnread = empty($alert['is_read_user']);
                  $isExpired = !empty($alert['is_expired']);
                  $typeIcon = PluginNextoolAlertManager::getTypeIcon($alert['alert_type']);
                  $typeBadge = PluginNextoolAlertManager::getTypeBadgeClass($alert['alert_type']);
                  // Faixa à esquerda na mesma cor do selo do tipo (bg-warning -> border-warning).
                  $typeBorder = preg_match('/\bbg-([a-z]+)\b/', $typeBadge, $cor) ? $cor[1] : 'info';
               ?>
               <div class="card nextool-alert-item<?php echo $isUnread ? ' nextool-alert-unread' : ''; ?><?php echo $isExpired ? ' nextool-alert-expired' : ''; ?>" style="border-left: 4px solid var(--tblr-<?php echo $typeBorder; ?>);" data-alert-id="<?php echo (int)$alert['id']; ?>"
                    data-type="<?php echo PluginNextoolAlertManager::getTypeGroup((string) $alert['alert_type']); ?>"
                    data-unread="<?php echo $isUnread ? '1' : '0'; ?>" data-expired="<?php echo $isExpired ? '1' : '0'; ?>">
                  <div class="card-body p-3">
                  <div class="d-flex justify-content-between align-items-start">
                     <div class="d-flex gap-2 align-items-start flex-grow-1">
                        <i class="<?php echo $typeIcon; ?> fs-3 mt-1"></i>
                        <div>
                           <div class="d-flex align-items-center gap-2 mb-1">
                              <strong><?php echo PluginNextoolCompat::escape($alert['title']); ?></strong>
                              <span class="badge <?php echo $typeBadge; ?>" style="font-size: 0.65rem;">
                                 <?php echo PluginNextoolCompat::escape(PluginNextoolAlertManager::getTypeLabel((string) $alert['alert_type'])); ?>
                              </span>
                              <?php if ($isExpired): ?>
                              <span class="badge bg-secondary text-white" style="font-size: 0.6rem;"><?php echo __('Expirado', 'nextool'); ?></span>
                              <?php elseif ($isUnread): ?>
                              <span class="badge bg-warning text-white" style="font-size: 0.6rem;"><?php echo __('Novo', 'nextool'); ?></span>
                              <?php endif; ?>
                           </div>
                           <div class="small text-muted mb-1">
                              <?php echo PluginNextoolCompat::escape($alert['date_received']); ?>
                              <?php if (!$isUnread && !empty($alert['date_read_user'])): ?>
                                 &middot; <?php echo __('Lido em', 'nextool'); ?> <?php echo PluginNextoolCompat::escape($alert['date_read_user']); ?>
                              <?php endif; ?>
                           </div>
                           <div class="small nextool-alert-body"><?php echo PluginNextoolAlertManager::sanitizeBody($alert['body']); ?></div>
                        </div>
                     </div>
                     <?php if ($isUnread): ?>
                     <button type="button" class="btn btn-sm btn-outline-success nextool-alert-mark-read ms-2"
                             data-alert-id="<?php echo (int)$alert['id']; ?>"
                             data-endpoint="<?php echo PluginNextoolCompat::escape($alertsEndpoint); ?>">
                        <i class="ti ti-check"></i>
                     </button>
                     <?php endif; ?>
                  </div>
                  </div>
               </div>
               <?php endforeach; ?>
            </div>
            <nav id="nextool-alerts-nav" class="d-flex justify-content-between align-items-center mt-3" aria-label="<?php echo PluginNextoolCompat::escape(__('Páginas das novidades', 'nextool')); ?>">
               <span class="text-muted small" id="nextool-alerts-range"></span>
               <ul class="pagination m-0" id="nextool-alerts-pages"></ul>
            </nav>
         <?php endif; ?>
      </div>
   </div>

   <?php
   // #292: novidades da NexTool na página inicial do GLPI (opt-in, desligado por padrão).
   require_once NEXTOOL_PHP_DIR . '/inc/newsfeed.class.php';
   $nextoolNewsFeed = PluginNextoolNewsFeed::getState();
   ?>
   <div class="card shadow-sm nextool-tab-card">
      <div class="card-header mb-3 pt-2 border-top rounded-0">
         <h4 class="card-title ms-5 mb-0">
            <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-purple s-1">
               <i class="fs-2x ti ti-rss"></i>
            </div>
            <span><?php echo __('Novidades na página inicial do GLPI', 'nextool'); ?></span>
         </h4>
      </div>
      <div class="card-body pt-0">
         <form method="post" action="<?php echo PluginNextoolCompat::webDir() . '/front/config.save.php'; ?>">
            <?php if (!PluginNextoolCompat::csrfTokenless()) { echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); } ?>
            <?php echo Html::hidden('forcetab', ['value' => $nextool_is_standalone ? 'PluginNextoolMainConfig$4' : 'PluginNextoolSetup$1']); ?>
            <?php echo Html::hidden('action', ['value' => 'save_news_feed']); ?>
            <p class="text-muted mb-3">
               <?php echo __('Mostra o blog da NexTool (guias, novidades do GLPI e alertas) na aba "Feeds RSS" da página inicial, para os perfis da interface padrão.', 'nextool'); ?>
               <span class="form-help ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true"
                     data-bs-title="<?php echo PluginNextoolCompat::escape(__('O feed é cadastrado em Ferramentas > Feeds RSS, no idioma padrão do GLPI. O autoatendimento não vê. Desligar remove o feed.', 'nextool')); ?>">?</span>
            </p>
            <label class="form-check form-switch mb-2">
               <input class="form-check-input" type="checkbox" name="news_feed_enabled" value="1"
                      <?php echo $nextoolNewsFeed['enabled'] ? 'checked' : ''; ?> <?php echo $canManageAdminTabs ? '' : 'disabled'; ?>>
               <span class="form-check-label"><?php echo __('Mostrar as novidades da NexTool na página inicial do GLPI', 'nextool'); ?></span>
            </label>
            <?php if ($nextoolNewsFeed['enabled'] && $nextoolNewsFeed['have_error']): ?>
               <div class="alert alert-warning mb-2">
                  <span><i class="ti ti-alert-triangle me-1"></i><?php echo __('Este servidor não conseguiu baixar o feed. Confira o acesso à internet e o proxy do GLPI (Configurar > Geral > Proxy); quando o acesso for liberado, as novidades passam a aparecer sozinhas.', 'nextool'); ?></span>
               </div>
            <?php endif; ?>
            <?php if ($nextoolNewsFeed['enabled']): ?>
               <p class="small text-muted mb-0">
                  <i class="ti ti-rss me-1"></i>
                  <a href="<?php echo PluginNextoolCompat::escape(RSSFeed::getFormURLWithID($nextoolNewsFeed['id'])); ?>"><?php echo __('Ver o feed em Ferramentas > Feeds RSS', 'nextool'); ?></a>
                  <span class="ms-1">(<?php echo PluginNextoolCompat::escape($nextoolNewsFeed['url']); ?>)</span>
               </p>
            <?php endif; ?>
            <?php if ($canManageAdminTabs): ?>
            <div class="text-end mt-3">
               <button type="submit" class="btn btn-primary">
                  <i class="ti ti-device-floppy me-1"></i><?php echo __('Salvar', 'nextool'); ?>
               </button>
            </div>
            <?php endif; ?>
         </form>
      </div>
   </div>
</div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>

<style>
   /* Não lido: fundo levemente amarelado (sem bg-*-lt, que tinge o texto). Expirado: esmaecido. */
   #nextool-alerts-list .nextool-alert-unread { background: rgba(var(--tblr-warning-rgb, 247, 103, 7), .06); }
   #nextool-alerts-list .nextool-alert-expired { opacity: .75; }
</style>
<script>
(function() {
   // Filtros + paginação das novidades (5 por página, sobre o que o filtro deixa visível).
   var nxNews = (function() {
      var lista = document.getElementById('nextool-alerts-list');
      var pags = document.getElementById('nextool-alerts-pages');
      if (!lista || !pags) { return null; }
      var itens = Array.prototype.slice.call(lista.querySelectorAll(':scope > .nextool-alert-item'));
      var porPagina = parseInt(lista.getAttribute('data-per-page'), 10) || 5;
      var nav = document.getElementById('nextool-alerts-nav');
      var faixa = document.getElementById('nextool-alerts-range');
      var vazio = document.getElementById('nextool-alerts-empty');
      var busca = document.getElementById('nextool-alerts-search');
      var chips = Array.prototype.slice.call(document.querySelectorAll('.nextool-news-chip'));
      var rotuloFaixa = <?php echo json_encode(__('%1$d a %2$d de %3$d', 'nextool')); ?>;
      var anterior = <?php echo json_encode(__('Anterior', 'nextool')); ?>;
      var proxima = <?php echo json_encode(__('Próxima', 'nextool')); ?>;
      var pagina = 1;
      itens.forEach(function(el) { el._nxTexto = (el.textContent || '').toLowerCase(); });

      function passa(el) {
         var termo = busca ? busca.value.trim().toLowerCase() : '';
         if (termo !== '' && el._nxTexto.indexOf(termo) === -1) { return false; }
         var estados = chips.filter(function(c) { return c.dataset.group === 'state' && c.classList.contains('active'); });
         if (estados.length && !estados.some(function(c) { return el.getAttribute('data-' + c.dataset.key) === '1'; })) { return false; }
         var tipos = chips.filter(function(c) { return c.dataset.group === 'type' && c.classList.contains('active'); });
         if (tipos.length && !tipos.some(function(c) { return el.getAttribute('data-type') === c.dataset.key; })) { return false; }
         return true;
      }
      function item(rotulo, p, desabilitado, ativo, html) {
         var li = document.createElement('li');
         li.className = 'page-item' + (desabilitado ? ' disabled' : '') + (ativo ? ' active' : '');
         var a = document.createElement('a');
         a.className = 'page-link';
         a.href = '#';
         a.setAttribute('aria-label', rotulo);
         if (html) { a.innerHTML = html; } else { a.textContent = rotulo; }
         if (ativo) { a.setAttribute('aria-current', 'page'); }
         a.addEventListener('click', function(ev) { ev.preventDefault(); if (!desabilitado && !ativo) { pagina = p; render(); } });
         li.appendChild(a);
         return li;
      }
      function render() {
         var visiveis = itens.filter(passa);
         var total = Math.max(1, Math.ceil(visiveis.length / porPagina));
         if (pagina > total) { pagina = total; }
         var ini = (pagina - 1) * porPagina;
         itens.forEach(function(el) { el.classList.add('d-none'); });
         visiveis.slice(ini, ini + porPagina).forEach(function(el) { el.classList.remove('d-none'); });
         if (vazio) { vazio.classList.toggle('d-none', visiveis.length > 0); }
         if (nav) { nav.classList.toggle('d-none', visiveis.length <= porPagina); nav.classList.toggle('d-flex', visiveis.length > porPagina); }
         if (faixa) {
            faixa.textContent = rotuloFaixa.replace('%1$d', ini + 1).replace('%2$d', Math.min(ini + porPagina, visiveis.length)).replace('%3$d', visiveis.length);
         }
         pags.innerHTML = '';
         pags.appendChild(item(anterior, pagina - 1, pagina === 1, false, '<i class="ti ti-chevron-left"></i>'));
         for (var n = 1; n <= total; n++) { pags.appendChild(item(String(n), n, false, n === pagina)); }
         pags.appendChild(item(proxima, pagina + 1, pagina === total, false, '<i class="ti ti-chevron-right"></i>'));
      }
      chips.forEach(function(c) {
         c.addEventListener('click', function() {
            var on = !c.classList.contains('active');
            c.classList.toggle('active', on);
            c.setAttribute('aria-pressed', on ? 'true' : 'false');
            pagina = 1;
            render();
         });
      });
      if (busca) { busca.addEventListener('input', function() { pagina = 1; render(); }); }
      render();
      return {
         // Depois de marcar como lido: tira do contador "Não lidos" e refaz a lista.
         marcarLido: function(el) {
            if (!el || el.getAttribute('data-unread') !== '1') { return; }
            el.setAttribute('data-unread', '0');
            var cont = document.querySelector('[data-count-for="unread"]');
            if (cont) {
               var n = Math.max(0, (parseInt(cont.textContent, 10) || 0) - 1);
               cont.textContent = String(n);
               if (n === 0) { var chip = cont.closest('.nextool-news-chip'); if (chip) { chip.classList.add('d-none'); chip.classList.remove('active'); } }
            }
            render();
         }
      };
   })();

   document.addEventListener('click', function(e) {
      var btn = e.target.closest('.nextool-alert-mark-read');
      if (btn) {
         var id = btn.dataset.alertId;
         var endpoint = btn.dataset.endpoint;
         var xhr = new XMLHttpRequest();
         xhr.open('POST', endpoint);
         xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
         xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
         var csrfToken = typeof nextoolGetAjaxCsrfToken === 'function' ? nextoolGetAjaxCsrfToken() : '';
         if (csrfToken) { xhr.setRequestHeader('X-Glpi-Csrf-Token', csrfToken); }
         xhr.onload = function() {
            var row = btn.closest('.nextool-alert-item');
            if (row) { row.classList.remove('nextool-alert-unread'); }
            if (nxNews) { nxNews.marcarLido(row); }
            btn.remove();
            var newBadge = row && row.querySelector('.badge.bg-warning');
            if (newBadge) { newBadge.remove(); }
         };
         xhr.send('action=mark_read&alert_id=' + id);
         return;
      }

      var markAll = e.target.closest('#nextool-alerts-mark-all-read');
      if (markAll) {
         var endpoint = markAll.dataset.endpoint;
         var xhr = new XMLHttpRequest();
         xhr.open('POST', endpoint);
         xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
         xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
         var csrfToken = typeof nextoolGetAjaxCsrfToken === 'function' ? nextoolGetAjaxCsrfToken() : '';
         if (csrfToken) { xhr.setRequestHeader('X-Glpi-Csrf-Token', csrfToken); }
         xhr.onload = function() {
            var items = document.querySelectorAll('#nextool-alerts-list .nextool-alert-unread');
            items.forEach(function(el) { el.classList.remove('nextool-alert-unread'); if (nxNews) { nxNews.marcarLido(el); } });
            var btns = document.querySelectorAll('.nextool-alert-mark-read');
            btns.forEach(function(b) { b.remove(); });
            var badges = document.querySelectorAll('#nextool-alerts-list .badge.bg-warning');
            badges.forEach(function(b) { b.remove(); });
            markAll.remove();
         };
         xhr.send('action=mark_all_read');
      }
   });
})();
</script>
<?php endif; ?>
