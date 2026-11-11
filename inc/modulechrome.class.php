<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Moldura padrão das telas de módulo
 * -------------------------------------------------------------------------
 * Peças que toda página de configuração de módulo mostra e que mudam juntas para todos os módulos
 * quando a base muda (decisão do owner, 2026-10-21):
 *  - tabFooter(): o mesmo rodapé das abas da configuração do NexTool (versões, ambiente, links), no fim
 *    de CADA aba do módulo. O módulo chama no displayTabContentForItem, depois do conteúdo da aba.
 *  - diagnosticButtons($moduleKey): os botões "Modo diagnóstico" e "Suporte", para a linha do Salvar da
 *    aba de configuração (decisão do owner, 2026-11-10; substitui o bloco "Modo diagnóstico do módulo").
 * A vitrine de serviço do topo (hero) é o PluginNextoolOfferBanner, formato 'hero'.
 *
 * Módulo em base antiga: testar class_exists('PluginNextoolModuleChrome') antes de chamar.
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

class PluginNextoolModuleChrome {

   /** Rodapé padrão (front/tabs/config.footer.inc.php, o mesmo das abas da base). Nunca derruba a aba. */
   public static function tabFooter(): string {
      try {
         ob_start();
         include NEXTOOL_PHP_DIR . '/front/tabs/config.footer.inc.php';
         return (string) ob_get_clean();
      } catch (\Throwable $e) {
         if (ob_get_level() > 0) {
            ob_end_clean();
         }
         return '';
      }
   }

   /**
    * Botões "Modo diagnóstico" e "Suporte" para a linha do Salvar da aba de configuração do módulo.
    *
    *  - "Ativar modo diagnóstico": liga por 24 horas e desliga sozinho (PluginNextoolModuleDiagnostic).
    *    Ligado, vira "Desativar modo diagnóstico", com o horário em que desliga ao lado. Só para quem tem
    *    Configurar no módulo. Atualiza no lugar, sem recarregar a página (o formulário pode ter alteração
    *    não salva).
    *  - "Suporte": SÓ aparece com o modo diagnóstico ligado (decisão do owner, 2026-11-10: evitar chamado
    *    aberto sem o diagnóstico). Abre o popup de suporte da base com o módulo já escolhido e o tipo Bug;
    *    o relatório técnico leva o que o diagnóstico capturou. Só para quem pode abrir atendimento.
    *
    * Devolve '' para quem não tem nenhum dos dois direitos. Nunca derruba a aba.
    * Os atributos `data-nextool-diag-*` são os mesmos do painel antigo (os roteiros E2E os conferem).
    */
   public static function diagnosticButtons(string $moduleKey): string {
      try {
         require_once NEXTOOL_PHP_DIR . '/inc/modulediagnostic.class.php';
         require_once NEXTOOL_PHP_DIR . '/inc/permissionmanager.class.php';
         $key = PluginNextoolModuleDiagnostic::cleanKey($moduleKey);
         if ($key === '') {
            return '';
         }
         $status = PluginNextoolModuleDiagnostic::status($key);
         $podeDiag = $status['can_enable'];
         $podeSuporte = PluginNextoolPermissionManager::canBase(PluginNextoolPermissionManager::BASE_SEND_CONTACT);
         if (!$podeDiag && !$podeSuporte) {
            return '';
         }
         $esc = static fn (string $v): string => PluginNextoolCompat::escape($v);
         $id = 'nxdiagbtn-' . $key;
         $txt = [
            'off'     => __('Ativar modo diagnóstico', 'nextool'),
            'on'      => __('Desativar modo diagnóstico', 'nextool'),
            'until'   => __('Ativo até %s', 'nextool'),
            'tipOff'  => __('Registra mais detalhes deste módulo por até 24 horas e depois desliga sozinho. Ative, repita o que deu problema e abra o suporte: o que foi registrado vai junto no relatório técnico.', 'nextool'),
            'tipOn'   => __('Clique para desligar o modo diagnóstico. Ele também desliga sozinho no horário indicado.', 'nextool'),
            'tipSupport' => __('Abre o pedido de suporte deste módulo. O que o modo diagnóstico registrou vai junto no relatório técnico.', 'nextool'),
            'fail'    => __('Não foi possível alterar o modo diagnóstico.', 'nextool'),
            'support' => __('Não foi possível abrir o suporte. Use o botão Suporte na página do NexTool.', 'nextool'),
         ];
         $ativo = !empty($status['active']);
         $html = '<span class="d-inline-flex flex-wrap gap-2 align-items-center" id="' . $esc($id) . '" data-nextool-diag-panel="' . $esc($key) . '">';
         if ($podeDiag) {
            $rotulo = $ativo ? $txt['on'] : $txt['off'];
            $ate = ($ativo && $status['until'] !== '') ? sprintf($txt['until'], $status['until']) : '';
            $html .= '<span class="small text-muted' . ($ate === '' ? ' d-none' : '') . '" data-nextool-diag-until>' . $esc($ate) . '</span>';
            $html .= '<button type="button" class="btn ' . ($ativo ? 'btn-orange text-white' : 'btn-outline-secondary') . '"'
               . ' data-nextool-diag-action="' . ($ativo ? 'disable' : 'enable') . '"'
               . ' data-nextool-diag-state="' . ($ativo ? 'on' : 'off') . '"'
               . ' title="' . $esc($ativo ? $txt['tipOn'] : $txt['tipOff']) . '">'
               . '<i class="ti ti-stethoscope me-1"></i><span data-nextool-diag-label>' . $esc($rotulo) . '</span></button>';
         }
         if ($podeSuporte) {
            // Só com o diagnóstico ligado: o script mostra e esconde junto com o botão acima.
            $html .= '<button type="button" class="btn btn-outline-secondary' . ($ativo ? '' : ' d-none') . '" data-nextool-diag-support'
               . ' title="' . $esc($txt['tipSupport']) . '">'
               . '<i class="ti ti-lifebuoy me-1"></i>' . $esc(__('Suporte', 'nextool')) . '</button>';
         }
         $html .= '</span>';

         $cfg = [
            'id'       => $id,
            'module'   => $key,
            'diag'     => PluginNextoolCompat::webDir() . '/ajax/module_diagnostic.php',
            'support'  => PluginNextoolCompat::webDir() . '/ajax/support.php',
            'txt'      => $txt,
         ];
         $html .= '<script>(function(){'
            . 'var c=' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
            . <<<'JS'
var root=document.getElementById(c.id);if(!root||!window.jQuery){return;}
var $=window.jQuery;
var toast=function(m){if(typeof glpi_toast_error==='function'){glpi_toast_error(m);}else{alert(m);}};
var btn=root.querySelector('[data-nextool-diag-action]');
if(btn){btn.addEventListener('click',function(){
  btn.disabled=true;
  $.ajax({url:c.diag,method:'POST',dataType:'json',data:{action:btn.getAttribute('data-nextool-diag-action'),module:c.module}})
   .done(function(r){
     btn.disabled=false;
     if(!r||!r.success){toast((r&&r.message)||c.txt.fail);return;}
     var on=!!r.active;
     btn.setAttribute('data-nextool-diag-action',on?'disable':'enable');
     btn.setAttribute('data-nextool-diag-state',on?'on':'off');
     btn.className='btn '+(on?'btn-orange text-white':'btn-outline-secondary');
     btn.setAttribute('title',on?c.txt.tipOn:c.txt.tipOff);
     btn.querySelector('[data-nextool-diag-label]').textContent=on?c.txt.on:c.txt.off;
     var ate=root.querySelector('[data-nextool-diag-until]');
     if(ate){ate.textContent=(on&&r.until)?c.txt.until.replace('%s',r.until):'';ate.classList.toggle('d-none',!(on&&r.until));}
     var s=root.querySelector('[data-nextool-diag-support]');
     if(s){s.classList.toggle('d-none',!on);}
   })
   .fail(function(x){btn.disabled=false;var r=x&&x.responseJSON;toast((r&&r.message)||c.txt.fail);});
});}
var sup=root.querySelector('[data-nextool-diag-support]');
if(sup){sup.addEventListener('click',function(){
  var open=function(){if(typeof window.nextoolSupportOpen==='function'){window.nextoolSupportOpen({item:c.module,type:'bug'});}else{toast(c.txt.support);}};
  if(document.getElementById('nextool-support-modal')){open();return;}
  sup.disabled=true;
  $.ajax({url:c.support,method:'POST',dataType:'json',data:{action:'modal'}})
   .done(function(r){
     sup.disabled=false;
     if(!r||!r.success||!r.html){toast((r&&r.message)||c.txt.support);return;}
     $('body').append(r.html);
     open();
   })
   .fail(function(x){sup.disabled=false;var r=x&&x.responseJSON;toast((r&&r.message)||c.txt.support);});
});}
JS
            . '})();</script>';
         return $html;
      } catch (\Throwable $e) {
         return '';
      }
   }
}
