<?php
/**
 * Escolha do modo de IA de um módulo: "Gerenciado pela NexTool" (créditos de IA) x "Sua chave" (provedor do cliente).
 * Componente da plataforma multi-módulo dos créditos (2026-10-29), no desenho da aba "Modo de IA" do AI Assist: seção
 * recolhível, dois cartões de rádio, saldo e "Comprar créditos" no gerenciado, e o bloco de conexão do provedor some
 * quando o gerenciado está escolhido.
 *
 * Uso (dentro do <form> da aba do módulo):
 *   $GLOBALS['nextool_ai_mode_cards'] = [
 *      'prefix'   => 'translate',                 // prefixo dos ids/classes ([a-z0-9_-])
 *      'field'    => 'translate_mode',            // name do rádio
 *      'value'    => 'managed' | 'own',           // modo salvo
 *      'disabled' => !$canEdit,
 *      'hide'     => '#translate-provider-card',  // seletor do que some no gerenciado (opcional)
 *      'title'    => __('Modo de tradução', ...), // título da seção
 *      'managed'  => ['subtitle' => '...', 'pros' => ['...', ...]],
 *      'own'      => ['title' => '...', 'subtitle' => '...', 'cons' => ['...', ...], 'help' => '...'],
 *   ];
 *   include NEXTOOL_PHP_DIR . '/front/ai_mode_cards.inc.php';
 * O CSS é o `.nextool-ai-mode-*` do front/nextool-tabs.css.php. Textos dos cartões são do módulo (domínio dele); os
 * genéricos (Saldo, Comprar créditos, Recomendado) são da base.
 *
 * @since pós-6.49.0 (a versão sai no /release)
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once NEXTOOL_PHP_DIR . '/inc/aicredits.class.php';

$nxAm = is_array($GLOBALS['nextool_ai_mode_cards'] ?? null) ? $GLOBALS['nextool_ai_mode_cards'] : [];
unset($GLOBALS['nextool_ai_mode_cards']);
$nxAmPrefix = preg_replace('/[^a-z0-9_-]/', '', (string) ($nxAm['prefix'] ?? 'nx')) ?: 'nx';
$nxAmField  = preg_replace('/[^a-z0-9_]/', '', (string) ($nxAm['field'] ?? 'ai_mode')) ?: 'ai_mode';
$nxAmValue  = ($nxAm['value'] ?? 'managed') === 'own' ? 'own' : 'managed';
$nxAmDis    = !empty($nxAm['disabled']) ? 'disabled' : '';
$nxAmHide   = (string) ($nxAm['hide'] ?? '');
$nxAmMan    = is_array($nxAm['managed'] ?? null) ? $nxAm['managed'] : [];
$nxAmOwn    = is_array($nxAm['own'] ?? null) ? $nxAm['own'] : [];
$nxAmCred   = PluginNextoolAiCredits::balanceInfo();
$nxAmBuy    = PluginNextoolAiCredits::purchaseUrl();
$nxAmEsc    = static fn($s) => PluginNextoolCompat::escape((string) $s);
$nxAmId     = $nxAmPrefix . '-ai-mode';
?>
<div class="card shadow-sm nextool-ai-mode" id="<?php echo $nxAmId; ?>" data-hide="<?php echo $nxAmEsc($nxAmHide); ?>">
   <div class="card-header mb-0 pt-2 border-top rounded-0 d-flex justify-content-between align-items-center"
        data-bs-toggle="collapse" data-bs-target="#<?php echo $nxAmId; ?>-collapse" aria-expanded="true" aria-controls="<?php echo $nxAmId; ?>-collapse" role="button" style="cursor: pointer;">
      <h4 class="card-title ms-5 mb-0">
         <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-indigo s-1"><i class="fs-2x ti ti-route"></i></div>
         <span><?php echo $nxAmEsc($nxAm['title'] ?? __('Modo de IA', 'nextool')); ?></span>
      </h4>
      <i class="ti ti-chevron-down nextool-collapse-indicator"></i>
   </div>
   <div class="card-body collapse show" id="<?php echo $nxAmId; ?>-collapse">
      <div class="row g-3">
         <div class="col-lg-6">
            <div class="card h-100 nextool-ai-mode-card<?php echo $nxAmValue === 'managed' ? ' is-selected' : ''; ?>" data-mode="managed"><div class="card-body">
               <div class="d-flex align-items-start gap-3">
                  <span class="avatar avatar-md bg-primary text-white flex-shrink-0"><i class="ti ti-sparkles fs-2"></i></span>
                  <div class="flex-fill">
                     <div class="d-flex align-items-center flex-wrap gap-2">
                        <input type="radio" class="form-check-input nextool-ai-mode-radio m-0" id="<?php echo $nxAmId; ?>-managed" name="<?php echo $nxAmField; ?>" value="managed" <?php echo $nxAmValue === 'managed' ? 'checked' : ''; ?> <?php echo $nxAmDis; ?>>
                        <label class="fw-bold fs-3 mb-0" for="<?php echo $nxAmId; ?>-managed"><?php echo __('Gerenciado pela NexTool', 'nextool'); ?></label>
                        <span class="badge bg-primary text-white"><?php echo __('Recomendado', 'nextool'); ?></span>
                     </div>
                     <?php if (!empty($nxAmMan['subtitle'])): ?>
                        <div class="text-secondary mt-1"><?php echo $nxAmEsc($nxAmMan['subtitle']); ?></div>
                     <?php endif; ?>
                     <ul class="list-unstyled mt-2 mb-0">
                        <?php foreach ((array) ($nxAmMan['pros'] ?? []) as $nxAmItem): ?>
                           <li class="d-flex gap-2 mb-1"><i class="ti ti-check mt-1 flex-shrink-0"></i><span><?php echo $nxAmEsc($nxAmItem); ?></span></li>
                        <?php endforeach; ?>
                     </ul>
                     <div class="d-flex align-items-center flex-wrap gap-3 mt-3 pt-3 border-top">
                        <?php if ($nxAmCred !== null): ?>
                           <div>
                              <div class="small text-secondary"><?php echo __('Saldo', 'nextool'); ?></div>
                              <div class="fs-2 fw-bold lh-1 nextool-ai-mode-balance"><?php echo $nxAmEsc($nxAmCred['balance_brl']); ?></div>
                           </div>
                        <?php endif; ?>
                        <a class="btn btn-primary ms-auto" href="<?php echo $nxAmEsc($nxAmBuy); ?>" target="_blank" rel="noopener"><i class="ti ti-shopping-cart me-1"></i><?php echo __('Comprar créditos', 'nextool'); ?></a>
                     </div>
                  </div>
               </div>
            </div></div>
         </div>
         <div class="col-lg-6">
            <div class="card h-100 nextool-ai-mode-card<?php echo $nxAmValue === 'own' ? ' is-selected' : ''; ?>" data-mode="own"><div class="card-body">
               <div class="d-flex align-items-start gap-3">
                  <span class="avatar avatar-md bg-secondary text-white flex-shrink-0"><i class="ti ti-key fs-2"></i></span>
                  <div class="flex-fill">
                     <div class="d-flex align-items-center flex-wrap gap-2">
                        <input type="radio" class="form-check-input nextool-ai-mode-radio m-0" id="<?php echo $nxAmId; ?>-own" name="<?php echo $nxAmField; ?>" value="own" <?php echo $nxAmValue === 'own' ? 'checked' : ''; ?> <?php echo $nxAmDis; ?>>
                        <label class="fw-bold fs-3 mb-0" for="<?php echo $nxAmId; ?>-own"><?php echo $nxAmEsc($nxAmOwn['title'] ?? __('Sua chave', 'nextool')); ?></label>
                        <?php if (!empty($nxAmOwn['help'])): ?>
                           <span class="form-help" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="<?php echo $nxAmEsc($nxAmOwn['help']); ?>">?</span>
                        <?php endif; ?>
                     </div>
                     <?php if (!empty($nxAmOwn['subtitle'])): ?>
                        <div class="text-secondary mt-1"><?php echo $nxAmEsc($nxAmOwn['subtitle']); ?></div>
                     <?php endif; ?>
                     <ul class="list-unstyled mt-2 mb-0">
                        <?php foreach ((array) ($nxAmOwn['cons'] ?? []) as $nxAmItem): ?>
                           <li class="d-flex gap-2 mb-1"><i class="ti ti-x mt-1 flex-shrink-0"></i><span><?php echo $nxAmEsc($nxAmItem); ?></span></li>
                        <?php endforeach; ?>
                     </ul>
                  </div>
               </div>
            </div></div>
         </div>
      </div>
   </div>
</div>
<script>
// setTimeout: a aba chega por AJAX; adiar um tick garante o DOM pronto. O cartão inteiro escolhe o modo (menos link,
// "?" e o próprio rádio); no gerenciado, o bloco de conexão do provedor (data-hide) some.
setTimeout(function () {
   var root = document.getElementById(<?php echo json_encode($nxAmId); ?>);
   if (!root || root.dataset.nxBound) { return; }
   root.dataset.nxBound = '1';
   function apply() {
      var checked = root.querySelector('.nextool-ai-mode-radio:checked');
      var mode = checked ? checked.value : 'managed';
      root.querySelectorAll('.nextool-ai-mode-card').forEach(function (c) { c.classList.toggle('is-selected', c.dataset.mode === mode); });
      if (root.dataset.hide) {
         document.querySelectorAll(root.dataset.hide).forEach(function (el) { el.style.display = mode === 'managed' ? 'none' : ''; });
      }
      root.dispatchEvent(new CustomEvent('nextool:ai-mode', { bubbles: true, detail: { mode: mode } }));
   }
   root.querySelectorAll('.nextool-ai-mode-radio').forEach(function (r) { r.addEventListener('change', apply); });
   root.querySelectorAll('.nextool-ai-mode-card').forEach(function (card) {
      card.addEventListener('click', function (ev) {
         if (ev.target.closest('a, .form-help, input')) { return; }
         var radio = card.querySelector('.nextool-ai-mode-radio');
         if (radio && !radio.disabled && !radio.checked) {
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
         }
      });
   });
   apply();
});
</script>
