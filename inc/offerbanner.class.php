<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Banner de oferta (padrão dos módulos)
 * -------------------------------------------------------------------------
 * Vitrine de serviço da NexTool dentro das telas dos módulos (ex.: "Ainda não tem um cofre?" na aba
 * Conexão do Vault Send). O módulo passa só DADOS; o visual, o CSS, a animação, o escape e os links
 * ficam aqui, para todos os módulos parecerem da mesma família e mudarem juntos.
 *
 * Formatos:
 *  - 'hero' (vitrine fixa do módulo, decisão do owner em 2026-10-21): bloco compacto no TOPO da página de
 *    configuração do módulo, acima das abas (aparece em todas), como o hero da base. Texto à esquerda,
 *    até 3 planos em cartões curtos à direita ('summary' de uma linha); o 'highlight' é noturno e dourado.
 *  - 'plans': versão grande do mesmo desenho (título, texto, planos com lista de itens), para dentro de aba.
 *  - 'strip': faixa noturna de uma linha.
 *
 * Texto: escape sempre. Destaque no título com *asteriscos* ("Senha não é *texto de chamado*."),
 * que o tradutor mantém; nada de HTML vindo do módulo.
 * Botão (cta): ['label' => ..., 'whatsapp' => 'mensagem pronta'] abre o WhatsApp comercial
 * (número do NEXTOOL_WHATSAPP_URL); ou ['label' => ..., 'url' => 'https://...'] (no site da NexTool
 * ganha UTM do módulo e da tela). Rodapé: link com 'href' e atributos de uma lista fechada
 * (data-bs-toggle, data-bs-target, aria-controls); 'href' aceita '#âncora', caminho do próprio GLPI ('/...') ou https.
 *
 * Regras de uso (ui-standards.md, "Banner de oferta"): só onde a oferta resolve o problema daquele
 * momento, nunca em tela de uso diário. Título na Fraunces EMBUTIDA no plugin (front/offerfont.php,
 * licença OFL), nunca do Google Fonts: carregar fonte externa no GLPI do cliente vazaria o IP de cada usuário.
 *
 * Piso PHP 8.0 (GLPI 10); sem dependência de outra classe do plugin além do PluginNextoolCompat.
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

class PluginNextoolOfferBanner {

   /** CSS e JS saem uma vez por resposta (várias ofertas na mesma tela não repetem). */
   private static bool $assetsPrinted = false;

   private const WHATSAPP_PHONE = '5532984692962';
   private const SITE_HOST      = 'nextoolsolutions.com';
   private const LINK_ATTRS     = ['data-bs-toggle', 'data-bs-target', 'aria-controls'];

   /**
    * @param array<string,mixed> $offer ver o cabeçalho do arquivo
    */
   public static function render(array $offer): string {
      $format = (string) ($offer['format'] ?? 'plans');
      $html   = $format === 'strip' ? self::strip($offer) : ($format === 'hero' ? self::hero($offer) : self::plans($offer));
      return self::assets() . $html;
   }

   // ------------------------------------------------------------------ formatos

   private static function plans(array $o): string {
      $plans = array_slice(array_values(array_filter((array) ($o['plans'] ?? []), 'is_array')), 0, 3);
      $out   = '<div class="nxo nxo-plans' . self::extraClass($o) . '">';
      // Cabeçalho opcional: sem eyebrow, título e texto, só os cartões.
      if (!empty($o['eyebrow']) || !empty($o['title']) || !empty($o['lead'])) {
         $out .= '<div class="nxo-head">';
         if (!empty($o['eyebrow'])) {
            $out .= '<span class="nxo-eyebrow">' . self::e($o['eyebrow']) . '</span>';
         }
         if (!empty($o['title'])) {
            $out .= '<h3 class="nxo-title">' . self::rich((string) $o['title']) . '</h3>';
         }
         if (!empty($o['lead'])) {
            $out .= '<p class="nxo-lead">' . self::e($o['lead']) . '</p>';
         }
         $out .= '</div>';
      }
      $out .= '<div class="nxo-grid nxo-grid-' . count($plans) . '">';
      foreach ($plans as $p) {
         $hot  = !empty($p['highlight']);
         $out .= '<div class="nxo-plan' . ($hot ? ' nxo-hot nxo-night' : '') . '"' . ($hot ? ' data-nxo-dots' : '') . '>';
         if ($hot) {
            $out .= '<canvas aria-hidden="true"></canvas>';
         }
         // Tipo e selo na mesma linha: o cartão em destaque fica da altura dos outros (owner, 2026-10-21).
         $out .= '<div class="nxo-plan-top">';
         if (!empty($p['kind'])) {
            $out .= '<span class="nxo-kind">' . self::e($p['kind']) . '</span>';
         }
         if ($hot) {
            $out .= '<span class="nxo-flag">' . self::e($p['flag'] ?? __('Mais escolhido', 'nextool')) . '</span>';
         }
         $out .= '</div><h4>' . self::e($p['title'] ?? '') . '</h4>';
         $items = array_filter((array) ($p['items'] ?? []), 'is_string');
         if ($items !== []) {
            $out .= '<ul class="nxo-checks">';
            foreach ($items as $it) {
               $out .= '<li>' . self::e($it) . '</li>';
            }
            $out .= '</ul>';
         }
         $out .= self::cta((array) ($p['cta'] ?? []), $hot ? 'nxo-btn-gold' : 'nxo-btn-soft', $o);
         $out .= '</div>';
      }
      $out .= '</div>';
      $out .= self::footer($o);
      return $out . '</div>';
   }

   private static function hero(array $o): string {
      $plans = array_slice(array_values(array_filter((array) ($o['plans'] ?? []), 'is_array')), 0, 3);
      $out   = '<div class="nxo nxo-hero' . self::extraClass($o) . '"><div class="nxo-hero-text">';
      if (!empty($o['eyebrow'])) {
         $out .= '<span class="nxo-eyebrow">' . self::e($o['eyebrow']) . '</span>';
      }
      $out .= '<h3 class="nxo-title">' . self::rich((string) ($o['title'] ?? '')) . '</h3>';
      if (!empty($o['lead'])) {
         $out .= '<p class="nxo-lead">' . self::e($o['lead']) . '</p>';
      }
      $out .= self::footer($o) . '</div><div class="nxo-hero-plans nxo-hero-plans-' . count($plans) . '">';
      foreach ($plans as $p) {
         $hot  = !empty($p['highlight']);
         $out .= '<div class="nxo-tile' . ($hot ? ' nxo-hot nxo-night' : '') . '"' . ($hot ? ' data-nxo-dots' : '') . '>';
         if ($hot) {
            $out .= '<canvas aria-hidden="true"></canvas>';
         }
         $out .= '<div class="nxo-tile-top">';
         if (!empty($p['kind'])) {
            $out .= '<span class="nxo-kind">' . self::e($p['kind']) . '</span>';
         }
         if ($hot) {
            $out .= '<span class="nxo-flag">' . self::e($p['flag'] ?? __('Mais escolhido', 'nextool')) . '</span>';
         }
         $out .= '</div><h4>' . self::e($p['title'] ?? '') . '</h4>';
         $summary = (string) ($p['summary'] ?? (((array) ($p['items'] ?? []))[0] ?? ''));
         if ($summary !== '') {
            $out .= '<p>' . self::e($summary) . '</p>';
         }
         $out .= self::cta((array) ($p['cta'] ?? []), ($hot ? 'nxo-btn-gold' : 'nxo-btn-soft') . ' nxo-btn-sm', $o);
         $out .= '</div>';
      }
      return $out . '</div></div>';
   }

   private static function strip(array $o): string {
      $tone = (string) ($o['tone'] ?? 'brand') === 'warning' ? 'warning' : 'brand';
      $icon = preg_match('/^ti-[a-z0-9-]+$/', (string) ($o['icon'] ?? '')) ? (string) $o['icon'] : 'ti-sparkles';
      $out  = '<div class="nxo nxo-strip nxo-night nxo-tone-' . $tone . self::extraClass($o) . '" data-nxo-dots>';
      $out .= '<canvas aria-hidden="true"></canvas>';
      $out .= '<div class="nxo-strip-icon"><i class="ti ' . $icon . '"></i></div>';
      $out .= '<div class="nxo-strip-body">';
      if (!empty($o['eyebrow'])) {
         $out .= '<span class="nxo-eyebrow">' . self::e($o['eyebrow']) . '</span>';
      }
      $out .= '<div class="nxo-strip-title">' . self::rich((string) ($o['title'] ?? '')) . '</div>';
      if (!empty($o['text'])) {
         $out .= '<div class="nxo-strip-text">' . self::e($o['text']) . '</div>';
      }
      $out .= '</div>';
      $out .= self::cta((array) ($o['cta'] ?? []), 'nxo-btn-gold', $o, true);
      return $out . '</div>';
   }

   // ------------------------------------------------------------------ peças

   private static function cta(array $cta, string $class, array $offer, bool $withIcon = false): string {
      $label = trim((string) ($cta['label'] ?? ''));
      if ($label === '') {
         return '';
      }
      $isWa = isset($cta['whatsapp']);
      $href = $isWa ? self::whatsappUrl((string) $cta['whatsapp']) : self::safeUrl((string) ($cta['url'] ?? ''), $offer);
      if ($href === '') {
         return '';
      }
      $icon = ($isWa && $withIcon) ? '<i class="ti ti-brand-whatsapp"></i>' : '';
      return '<a class="nxo-btn ' . $class . '" href="' . self::e($href) . '" target="_blank" rel="noopener">' . $icon . self::e($label) . '</a>';
   }

   private static function footer(array $o): string {
      $f = (array) ($o['footer'] ?? []);
      if (empty($f['text']) && empty($f['link'])) {
         return '';
      }
      $out = '<p class="nxo-foot">' . self::e($f['text'] ?? '');
      $l   = (array) ($f['link'] ?? []);
      if (!empty($l['label'])) {
         $href  = (string) ($l['href'] ?? '#');
         // '#âncora' e caminho do próprio GLPI ('/...', nunca '//outro-site') passam; o resto só https.
         $local = $href === '' || $href[0] === '#' || ($href[0] === '/' && !str_starts_with($href, '//') && !str_contains($href, '\\'));
         $href  = $local ? $href : self::safeUrl($href, $o);
         $attrs = '';
         foreach ((array) ($l['attrs'] ?? []) as $k => $v) {
            if (in_array($k, self::LINK_ATTRS, true)) {
               $attrs .= ' ' . $k . '="' . self::e($v) . '"';
            }
         }
         $out .= ' <a href="' . self::e($href !== '' ? $href : '#') . '"' . $attrs . '>' . self::e($l['label']) . '</a>';
      }
      return $out . '</p>';
   }

   /** WhatsApp comercial da NexTool com mensagem pronta (número do NEXTOOL_WHATSAPP_URL da base). */
   public static function whatsappUrl(string $text): string {
      $phone = self::WHATSAPP_PHONE;
      if (defined('NEXTOOL_WHATSAPP_URL')) {
         parse_str((string) parse_url((string) NEXTOOL_WHATSAPP_URL, PHP_URL_QUERY), $q);
         if (!empty($q['phone']) && ctype_digit((string) $q['phone'])) {
            $phone = (string) $q['phone'];
         }
      }
      return 'https://api.whatsapp.com/send?' . http_build_query(['phone' => $phone, 'text' => $text]);
   }

   /** Só https; no site da NexTool acrescenta UTM (módulo = campanha, tela = conteúdo). */
   private static function safeUrl(string $url, array $offer): string {
      if (!preg_match('#^https://#i', $url)) {
         return '';
      }
      $host = strtolower((string) parse_url($url, PHP_URL_HOST));
      if ($host === self::SITE_HOST || str_ends_with($host, '.' . self::SITE_HOST)) {
         $utm = http_build_query(array_filter([
            'utm_source'   => 'glpi',
            'utm_medium'   => 'nextool-modulo',
            'utm_campaign' => (string) ($offer['module'] ?? ''),
            'utm_content'  => (string) ($offer['origin'] ?? ''),
         ]));
         $url .= (strpos($url, '?') === false ? '?' : '&') . $utm;
      }
      return $url;
   }

   private static function extraClass(array $o): string {
      $c = trim(preg_replace('/[^A-Za-z0-9_ -]/', '', (string) ($o['class'] ?? '')) ?? '');
      return $c !== '' ? ' ' . $c : '';
   }

   private static function e($v): string {
      return (string) PluginNextoolCompat::escape((string) $v);
   }

   /** Escapa e transforma *trecho* em destaque (<em>). Asterisco solto fica como texto. */
   private static function rich(string $text): string {
      $safe = self::e($text);
      return (string) preg_replace('/\*([^*]+)\*/u', '<em>$1</em>', $safe);
   }

   // ------------------------------------------------------------------ CSS + JS

   private static function assets(): string {
      if (self::$assetsPrinted) {
         return '';
      }
      self::$assetsPrinted = true;
      $font = PluginNextoolCompat::webDir() . '/front/offerfont.php?f=';
      $face = '<style>'
         . '@font-face{font-family:"NexTool Fraunces";font-style:normal;font-weight:400 600;font-display:swap;src:url("' . self::e($font . 'regular') . '") format("woff2")}'
         . '@font-face{font-family:"NexTool Fraunces";font-style:italic;font-weight:400 600;font-display:swap;src:url("' . self::e($font . 'italic') . '") format("woff2")}'
         . '</style>';
      return $face . <<<'HTML'
<style>
.nxo{--nxo-night-1:#1f2a4a;--nxo-night-2:#0f1629;--nxo-night-3:#0a0f1e;--nxo-cream:#fbf8ef;--nxo-mist:#b9bccb;--nxo-dim:#8c91a6;
  --nxo-gold:#c8a24a;--nxo-gold-ink:#121829;--nxo-gold-text:#9a7a2c;--nxo-gold-soft:rgba(200,162,74,.16);
  --nxo-serif:"NexTool Fraunces",Georgia,"Times New Roman",serif;
  --nxo-surface:var(--tblr-bg-surface,#ffffff);--nxo-line:var(--tblr-border-color,#d9dde5);--nxo-ink:var(--tblr-body-color,#1d2433);--nxo-muted:var(--tblr-secondary,#5d6678);
  color:var(--nxo-ink)}
.nxo *{box-sizing:border-box}
.nxo-night,.nxo-plan.nxo-night{position:relative;overflow:hidden;color:var(--nxo-cream);background:radial-gradient(120% 90% at 100% 0,var(--nxo-night-1) 0,var(--nxo-night-2) 55%,var(--nxo-night-3) 100%)}
.nxo-night>canvas{position:absolute;inset:0;width:100%;height:100%;opacity:.5;pointer-events:none}
.nxo-night>*:not(canvas){position:relative}
.nxo-eyebrow{display:block;font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;font-weight:600;color:var(--nxo-gold-text)}
.nxo-night .nxo-eyebrow{color:var(--nxo-gold)}
.nxo-plans{display:flex;flex-direction:column;gap:20px;margin:0 0 20px}
.nxo-head{display:flex;flex-direction:column;gap:6px;max-width:760px}
.nxo-title{font-family:var(--nxo-serif);font-weight:600;font-size:1.9rem;line-height:1.15;margin:0;color:var(--nxo-ink);text-wrap:balance}
.nxo-title em{font-style:italic;color:var(--nxo-gold-text)}
.nxo-lead{margin:0;color:var(--nxo-muted);font-size:1rem}
.nxo-grid{display:grid;gap:14px;align-items:stretch}
.nxo-grid-3{grid-template-columns:minmax(0,1fr) minmax(0,1.15fr) minmax(0,1fr)}
.nxo-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
@media (max-width:991.98px){.nxo-grid-3,.nxo-grid-2{grid-template-columns:minmax(0,1fr)}}
.nxo-plan{display:flex;flex-direction:column;gap:10px;height:100%;padding:20px 22px;border-radius:14px;background:var(--nxo-surface);border:1px solid var(--nxo-line)}
.nxo-plan-top{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;min-height:22px}
.nxo-plan .nxo-btn{margin-top:6px}
.nxo-plan h4{margin:0;font-size:1.05rem;font-weight:700;color:var(--nxo-ink)}
.nxo-kind{font-size:.7rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--nxo-muted)}
.nxo-plan.nxo-hot{padding:20px 22px;border:0;border-radius:16px;box-shadow:0 22px 48px rgba(15,22,41,.35);background:radial-gradient(120% 90% at 100% 0,var(--nxo-night-1) 0,var(--nxo-night-2) 55%,var(--nxo-night-3) 100%)}
.nxo-hot h4{font-family:var(--nxo-serif);font-weight:400;font-size:1.35rem;line-height:1.15;color:var(--nxo-cream)}
.nxo-hot .nxo-kind{color:var(--nxo-gold)}
.nxo-hot .nxo-checks{color:var(--nxo-mist)}
.nxo-flag{align-self:flex-start;font-size:.66rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:4px 10px;border-radius:999px;background:var(--nxo-gold);color:var(--nxo-gold-ink)}
.nxo-checks{margin:0;padding:0;list-style:none;display:grid;gap:7px;font-size:.9rem}
.nxo-checks li{display:flex;gap:9px;align-items:flex-start}
.nxo-checks li::before{content:"";width:16px;height:16px;flex:0 0 16px;margin-top:3px;border-radius:50%;
  background:center/10px no-repeat url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath d='M2.5 6.3l2.2 2.2L9.5 3.7' fill='none' stroke='%23c8a24a' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"),var(--nxo-gold-soft)}
.nxo-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;margin-top:auto;padding:12px 18px;border-radius:9px;font-weight:600;font-size:.9rem;line-height:1;text-decoration:none !important;white-space:normal;text-align:center;border:1px solid transparent}
.nxo-btn .ti{font-size:1.1rem}
.nxo-btn:focus-visible{outline:3px solid rgba(200,162,74,.5);outline-offset:2px}
.nxo-btn-gold{background:var(--nxo-gold) !important;color:var(--nxo-gold-ink) !important}
.nxo-btn-gold:hover{background:#d6b25c !important}
.nxo-btn-soft{background:var(--tblr-bg-surface-secondary,#f1f2f6) !important;color:var(--nxo-ink) !important;border-color:var(--nxo-line)}
.nxo-btn-soft:hover{border-color:var(--nxo-gold)}
.nxo-foot{margin:0;font-size:.85rem;color:var(--nxo-muted)}
.nxo-foot a{font-weight:600}
.nxo-strip{display:flex;flex-wrap:wrap;align-items:center;gap:16px 20px;padding:20px 24px;border-radius:14px}
.nxo-strip-icon{width:48px;height:48px;flex:0 0 48px;display:flex;align-items:center;justify-content:center;border-radius:12px;font-size:1.6rem;background:var(--nxo-gold-soft);color:var(--nxo-gold);border:1px solid rgba(200,162,74,.45)}
.nxo-tone-warning .nxo-strip-icon{background:rgba(245,158,11,.16);color:#f59e0b;border-color:rgba(245,158,11,.5)}
.nxo-strip-body{flex:1 1 320px;min-width:0;display:flex;flex-direction:column;gap:4px}
.nxo-strip-title{font-family:var(--nxo-serif);font-size:1.3rem;line-height:1.25;color:var(--nxo-cream)}
.nxo-strip-title em{font-style:normal;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:1rem;padding:2px 9px;border-radius:999px;background:var(--nxo-gold-soft);color:var(--nxo-gold);white-space:nowrap}
.nxo-strip-text{color:var(--nxo-mist);font-size:.9rem}
.nxo-strip .nxo-btn{margin-top:0}
.nxo-hero{display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.6fr);gap:18px 24px;align-items:center;margin:0 0 16px;padding:20px 22px;border-radius:14px;background:var(--nxo-surface);border:1px solid var(--nxo-line);box-shadow:0 1px 2px rgba(20,30,50,.05)}
.nxo-hero-text{display:flex;flex-direction:column;gap:6px;min-width:0}
.nxo-hero .nxo-title{font-size:1.45rem}
.nxo-hero .nxo-lead{font-size:.9rem}
.nxo-hero .nxo-foot{margin-top:4px}
.nxo-hero-plans{display:grid;gap:10px;align-items:stretch;min-width:0}
.nxo-hero-plans-3{grid-template-columns:repeat(3,minmax(0,1fr))}
.nxo-hero-plans-2{grid-template-columns:repeat(2,minmax(0,1fr))}
.nxo-tile{display:flex;flex-direction:column;gap:6px;min-width:0;padding:14px 14px 12px;border-radius:12px;border:1px solid var(--nxo-line);background:var(--tblr-bg-surface-secondary,#f8f9fb)}
.nxo-tile-top{display:flex;align-items:center;justify-content:space-between;gap:6px;flex-wrap:wrap}
.nxo-tile h4{margin:0;font-size:.95rem;font-weight:700;color:var(--nxo-ink)}
.nxo-tile p{margin:0;font-size:.8rem;color:var(--nxo-muted)}
.nxo-tile.nxo-hot{border:0;box-shadow:0 12px 28px rgba(15,22,41,.3);background:radial-gradient(120% 90% at 100% 0,var(--nxo-night-1) 0,var(--nxo-night-2) 55%,var(--nxo-night-3) 100%)}
.nxo-tile.nxo-hot h4{font-family:var(--nxo-serif);font-weight:400;font-size:1.15rem;color:var(--nxo-cream)}
.nxo-tile.nxo-hot p{color:var(--nxo-mist)}
.nxo-tile.nxo-hot .nxo-kind{color:var(--nxo-gold)}
.nxo-tile .nxo-flag{font-size:.58rem;padding:3px 8px}
.nxo-btn-sm{padding:8px 12px;font-size:.8rem;margin-top:auto}
@media (max-width:1199.98px){.nxo-hero{grid-template-columns:minmax(0,1fr)}}
@media (max-width:767.98px){.nxo-hero-plans-3,.nxo-hero-plans-2{grid-template-columns:minmax(0,1fr)}}
@media (max-width:575.98px){.nxo-title{font-size:1.5rem}.nxo-hero .nxo-title{font-size:1.25rem}.nxo-strip{padding:18px}.nxo-hero{padding:16px}}
</style>
<script>
(function () {
   // Constelação discreta nos cartões noturnos: parada com "reduzir movimento" e fora da tela.
   if (!window.HTMLCanvasElement) { return; }
   var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
   function boot(box) {
      var cv = box.querySelector(':scope > canvas'); if (!cv || cv.__nxo) { return; } cv.__nxo = true;
      var ctx = cv.getContext('2d'); if (!ctx) { return; }
      var dots = [], w = 0, h = 0, on = true;
      function size() {
         var r = cv.getBoundingClientRect(), d = window.devicePixelRatio || 1;
         w = r.width; h = r.height; if (!w || !h) { return; }
         cv.width = w * d; cv.height = h * d; ctx.setTransform(d, 0, 0, d, 0, 0);
         var n = Math.max(14, Math.min(70, Math.round(w * h / 9000))); dots = [];
         for (var i = 0; i < n; i++) { dots.push({ x: Math.random() * w, y: Math.random() * h, vx: (Math.random() - .5) * .22, vy: (Math.random() - .5) * .22 }); }
      }
      function frame() {
         if (!cv.isConnected) { return; }
         if (!dots.length) { size(); }
         ctx.clearRect(0, 0, w, h);
         for (var i = 0; i < dots.length; i++) {
            var a = dots[i]; if (!still) { a.x += a.vx; a.y += a.vy; }
            if (a.x < 0 || a.x > w) { a.vx *= -1; } if (a.y < 0 || a.y > h) { a.vy *= -1; }
            for (var j = i + 1; j < dots.length; j++) {
               var b = dots[j], dx = a.x - b.x, dy = a.y - b.y, dd = dx * dx + dy * dy;
               if (dd < 8000) { ctx.strokeStyle = 'rgba(200,162,74,' + (0.2 * (1 - dd / 8000)) + ')'; ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke(); }
            }
            ctx.fillStyle = 'rgba(233,230,220,.45)'; ctx.beginPath(); ctx.arc(a.x, a.y, 1.3, 0, 6.3); ctx.fill();
         }
         if (!still && on) { requestAnimationFrame(frame); }
      }
      if (window.IntersectionObserver) {
         new IntersectionObserver(function (es) { var v = es[0].isIntersecting; if (v && !on) { on = true; frame(); } else if (!v) { on = false; } }).observe(box);
      }
      window.addEventListener('resize', function () { size(); if (still) { frame(); } });
      size(); frame();
   }
   function scan() { document.querySelectorAll('[data-nxo-dots]').forEach(boot); }
   // O script vem junto de cada tela renderizada (abas do GLPI chegam por AJAX), então basta varrer agora.
   if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', scan); } else { setTimeout(scan, 0); }
})();
</script>
HTML;
   }
}
