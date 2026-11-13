<?php
declare(strict_types=1);
/**
 * Guia de divulgação "Serviços" (nextool-dev#277): a vitrine dos serviços da NexTool Solutions dentro do GLPI.
 * Nasceu servindo também a guia "Novidades", que saiu em 2026-10-13 (as novidades aparecem na aba 4).
 * Contexto/variáveis esperadas: $nextool_is_standalone, $nextool_standalone_output_tab, $canViewAdminTabs,
 * $firstTabKey, $nextool_hero_standalone.
 *
 * Visual da "variação C" do PluginNextoolOfferBanner (noite + dourado + Fraunces embutida), em quatro blocos:
 * hero com provas, três pontos de entrada (Projeto / Contrato / Projeto), catálogo dos 9 serviços do site e uma
 * faixa final de conversa. O que vem do servidor pelo canal de comunicados (tipo `servicos`, ver
 * PluginNextoolAlertManager::VITRINE_TYPES) entra como faixa "Em destaque" no topo, então a campanha muda sem
 * lançar versão. Sem pop-up e sem coleta nova de dados: só links que o usuário escolhe abrir (site no idioma do
 * usuário, com UTM; WhatsApp comercial com mensagem pronta).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

$nextoolVitrineTab = 'servicos';
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
   $nxsE = static fn ($v): string => (string) PluginNextoolCompat::escape((string) $v);
   // *trecho* vira destaque dourado (mesma convenção do OfferBanner::rich).
   $nxsRich = static fn (string $t): string => (string) preg_replace('/\*([^*]+)\*/u', '<em>$1</em>', $nxsE($t));

   $nextoolVitrineItems = PluginNextoolAlertManager::getVitrineItems($nextoolVitrineTab);

   // Prova "N módulos" sai do catálogo local (sem os descontinuados), nunca de número fixo.
   $nextoolModuleCount = count(array_filter(
      PluginNextoolModuleCatalog::all(),
      static fn (array $m): bool => empty($m['is_discontinued'])
   ));

   $nextoolTalkUrl     = $nextoolSiteUrl('/agenda', 'conversa');
   $nextoolWhatsappUrl = PluginNextoolOfferBanner::whatsappUrl(__('Olá! Vim pelo NexTool no meu GLPI e quero conversar sobre os serviços da NexTool Solutions.', 'nextool'));

   $nextoolProofs = [
      [__('GLPI 10, 11 e 12', 'nextool'), __('um só artefato, sempre em dia', 'nextool')],
      $nextoolModuleCount > 0
         ? [sprintf(_n('%d módulo', '%d módulos', $nextoolModuleCount, 'nextool'), $nextoolModuleCount), __('feitos e mantidos por nós', 'nextool')]
         : [__('Módulos próprios', 'nextool'), __('feitos e mantidos por nós', 'nextool')],
      [__('Do zero ao go-live', 'nextool'), __('implantação, migração e treino', 'nextool')],
      [__('Suporte com SLA', 'nextool'), __('corretivo e evolutivo', 'nextool')],
   ];

   // [tipo, título, pergunta, itens, CTA, url, destaque]
   $nextoolPlans = [
      [__('Projeto', 'nextool'), __('Implantação e migração', 'nextool'), __('Vai começar ou trocar de ferramenta?', 'nextool'),
         [__('GLPI no ar com perfis, regras e SLAs', 'nextool'), __('Dados vindos de OTRS, Zendesk, ServiceNow', 'nextool'), __('Go-live assistido e equipe treinada', 'nextool')],
         __('Pedir orçamento', 'nextool'), $nextoolSiteUrl('/servicos/implantacao-glpi', 'plano-implantacao'), false],
      [__('Contrato', 'nextool'), __('Suporte GLPI contínuo', 'nextool'), __('Seu GLPI já roda e precisa de alguém por perto.', 'nextool'),
         [__('Corretivo e evolutivo com SLA', 'nextool'), __('Atualização de versão assistida', 'nextool'), __('Horas para evoluir processos e telas', 'nextool')],
         __('Quero suporte contínuo', 'nextool'), $nextoolSiteUrl('/servicos/suporte-glpi', 'plano-suporte'), true],
      [__('Projeto', 'nextool'), __('Integrações e IA', 'nextool'), __('Quer o GLPI conversando com o resto da empresa?', 'nextool'),
         [__('ERP, LDAP/SSO, n8n e APIs', 'nextool'), __('Chatbot no Teams e no WhatsApp', 'nextool'), __('IA aplicada aos chamados', 'nextool')],
         __('Falar com um especialista', 'nextool'), $nextoolSiteUrl('/servicos/integracoes-glpi', 'plano-integracoes'), false],
   ];

   // Catálogo em ordem de grade (3 colunas: Começar / Evoluir / Sustentar). [ícone, título, texto, slug, IA?]
   $nextoolGroups = [__('Começar', 'nextool'), __('Evoluir', 'nextool'), __('Sustentar', 'nextool')];
   $nextoolTiles  = [
      ['ti-rocket', __('Implantação GLPI', 'nextool'), __('Instalação, configuração e go-live assistido.', 'nextool'), 'implantacao-glpi', false],
      ['ti-plug-connected', __('Integrações GLPI', 'nextool'), __('APIs, n8n, ERP e LDAP/SSO sem tarefa manual.', 'nextool'), 'integracoes-glpi', false],
      ['ti-lifebuoy', __('Suporte GLPI', 'nextool'), __('Corretivo e evolutivo, com SLA e backlog.', 'nextool'), 'suporte-glpi', false],
      ['ti-database-export', __('Migração de dados', 'nextool'), __('Chamados, base e ativos sem perder histórico.', 'nextool'), 'migracao-de-dados', false],
      ['ti-code', __('Plugins sob medida', 'nextool'), __('O que nenhum módulo faz, feito para você.', 'nextool'), 'desenvolvimento-plugins-glpi', false],
      ['ti-cloud', __('Infraestrutura e cloud', 'nextool'), __('AWS e Azure, monitoramento e FinOps.', 'nextool'), 'infraestrutura-cloud', false],
      ['ti-shield-check', __('Consultoria e compliance', 'nextool'), __('ITIL 4, ISO 27001 e COBIT na prática.', 'nextool'), 'consultoria', false],
      ['ti-messages', __('Chatbot Teams e WhatsApp', 'nextool'), __('Abrir, consultar e aprovar chamados no chat.', 'nextool'), 'chatbot-glpi', false],
      ['ti-sparkles', __('Inteligência artificial', 'nextool'), __('RAG, automações e MCPs para o service desk.', 'nextool'), 'inteligencia-artificial', true],
   ];
   $nextoolFont = PluginNextoolCompat::webDir() . '/front/offerfont.php?f=';
?>
<?php if (!$nextool_is_standalone): ?><div class="tab-pane fade<?php echo $firstTabKey === $nextoolVitrineTab ? ' show active' : ''; ?>" id="rt-tab-<?php echo $nextoolVitrineTab; ?>" role="tabpanel" aria-labelledby="rt-tab-<?php echo $nextoolVitrineTab; ?>-link"><?php endif; ?>
   <?php echo $nextool_hero_standalone; ?>

   <style>
   @font-face{font-family:"NexTool Fraunces";font-style:normal;font-weight:400 600;font-display:swap;src:url("<?php echo $nxsE($nextoolFont . 'regular'); ?>") format("woff2")}
   @font-face{font-family:"NexTool Fraunces";font-style:italic;font-weight:400 600;font-display:swap;src:url("<?php echo $nxsE($nextoolFont . 'italic'); ?>") format("woff2")}
   .nxs{--n1:#1f2a4a;--n2:#0f1629;--n3:#0a0f1e;--cream:#fbf8ef;--mist:#b9bccb;--gold:#c8a24a;--goldink:#121829;--goldtxt:#9a7a2c;--goldsoft:rgba(200,162,74,.16);
     --serif:"NexTool Fraunces",Georgia,serif;--line:var(--tblr-border-color,#d9dde5);--ink:var(--tblr-body-color,#1d2433);--muted:#667085;color:var(--ink)}
   .nxs-night{position:relative;overflow:hidden;color:var(--cream);background:radial-gradient(120% 90% at 100% 0,var(--n1) 0,var(--n2) 55%,var(--n3) 100%)}
   .nxs-night>canvas{position:absolute;inset:0;width:100%;height:100%;opacity:.5;pointer-events:none}
   .nxs-night>*:not(canvas){position:relative}
   .nxs-eyebrow{font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;font-weight:700;color:var(--goldtxt)}
   .nxs-night .nxs-eyebrow{color:var(--gold)}
   .nxs-hero{display:grid;grid-template-columns:1.35fr 1fr;gap:28px;align-items:center;padding:34px 36px;border-radius:18px;box-shadow:0 22px 48px rgba(15,22,41,.28)}
   .nxs-hero h2{font-family:var(--serif);font-weight:400;font-size:2.35rem;line-height:1.1;margin:10px 0 12px;color:var(--cream)}
   .nxs em{font-style:italic;color:var(--gold)}
   .nxs-hero h2 em{display:block}
   .nxs-hero p{color:var(--mist);font-size:1rem;margin:0 0 20px;max-width:46ch}
   .nxs-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;font-weight:700;font-size:.92rem;text-decoration:none;border:1px solid transparent;transition:.15s}
   .nxs-btn:hover{text-decoration:none;filter:brightness(1.06);transform:translateY(-1px)}
   .nxs-gold,.nxs-gold:hover{background:var(--gold);color:var(--goldink)}
   .nxs-ghost,.nxs-ghost:hover{border-color:rgba(251,248,239,.35);color:var(--cream)}
   .nxs-soft,.nxs-soft:hover{background:var(--tblr-bg-surface-secondary,#f1f2f6);color:var(--ink);border-color:var(--line);justify-content:center}
   .nxs-proof{display:grid;grid-template-columns:1fr 1fr;gap:12px}
   .nxs-proof div{padding:14px 16px;border-radius:12px;background:rgba(255,255,255,.05);border:1px solid rgba(200,162,74,.28);transition:transform .18s ease,border-color .18s ease,background .18s ease,box-shadow .18s ease}
   .nxs-proof div:hover{transform:translateY(-3px);border-color:var(--gold);background:rgba(200,162,74,.1);box-shadow:0 10px 26px rgba(0,0,0,.35),0 0 0 1px rgba(200,162,74,.25)}
   .nxs-proof b{transition:color .18s ease}
   .nxs-proof div:hover b{color:var(--gold)}
   @media (prefers-reduced-motion:reduce){.nxs-proof div,.nxs-proof div:hover{transition:none;transform:none}}
   .nxs-proof b{display:block;font-family:var(--serif);font-weight:400;font-size:1.15rem;line-height:1.2;margin-bottom:2px;color:var(--cream)}
   .nxs-proof span{font-size:.8rem;color:var(--mist)}
   .nxs-sec{margin:34px 0 14px;display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap}
   .nxs-sec h3{font-family:var(--serif);font-weight:600;font-size:1.45rem;margin:4px 0 0}
   .nxs-sec p{margin:0;color:var(--muted);font-size:.9rem}
   .nxs-plans{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;align-items:stretch}
   .nxs-plan{display:flex;flex-direction:column;gap:10px;padding:22px 24px;border-radius:16px;border:1px solid var(--line);background:var(--tblr-bg-surface,#fff)}
   .nxs-plan .nxs-eyebrow{color:var(--muted)}
   .nxs-plan h4{margin:0;font-size:1.12rem;font-weight:700}
   .nxs-plan .why{font-size:.86rem;color:var(--muted);margin:0}
   .nxs-plan ul{list-style:none;padding:0;margin:4px 0 8px;display:flex;flex-direction:column;gap:7px;font-size:.9rem;flex:1}
   .nxs-plan li{display:flex;gap:9px;align-items:flex-start}
   .nxs-plan li:before{content:"";flex:0 0 18px;height:18px;border-radius:50%;margin-top:1px;background:center/10px no-repeat url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12'%3E%3Cpath d='M2.5 6.3l2.2 2.2L9.5 3.7' fill='none' stroke='%23c8a24a' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"),var(--goldsoft)}
   .nxs-plan.hot{border:0;background:radial-gradient(120% 90% at 100% 0,var(--n1) 0,var(--n2) 55%,var(--n3) 100%);color:var(--cream);transform:translateY(-6px);box-shadow:0 22px 48px rgba(15,22,41,.35)}
   .nxs-plan.hot .nxs-eyebrow{color:var(--gold)}
   .nxs-plan.hot h4{font-family:var(--serif);font-weight:400;font-size:1.45rem;color:var(--cream)}
   .nxs-plan.hot .why,.nxs-plan.hot ul{color:var(--mist)}
   .nxs-flag{font-size:.64rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:4px 10px;border-radius:999px;background:var(--gold);color:var(--goldink);white-space:nowrap}
   .nxs-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
   .nxs-tile{display:flex;gap:14px;align-items:flex-start;padding:16px 18px;border-radius:14px;border:1px solid var(--line);background:var(--tblr-bg-surface-secondary,#f8f9fb);text-decoration:none;color:var(--ink);transition:.15s}
   .nxs-tile:hover{border-color:var(--gold);transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,22,41,.08);text-decoration:none;color:var(--ink)}
   .nxs-ico{flex:0 0 42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.35rem;background:var(--goldsoft);color:var(--goldtxt);border:1px solid rgba(200,162,74,.4)}
   .nxs-ico.ai{background:#ede9fe;color:#6d28d9;border-color:#c4b5fd}
   .nxs-tile h5{margin:0 0 3px;font-size:.98rem;font-weight:700}
   .nxs-tile p{margin:0;font-size:.82rem;color:var(--muted)}
   .nxs-tile .go{margin-left:auto;align-self:center;color:var(--goldtxt);font-size:1.1rem}
   .nxs-group{font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:var(--muted);margin:0 0 -4px}
   .nxs-strip{display:flex;align-items:center;gap:20px;margin-top:30px;padding:22px 26px;border-radius:16px}
   .nxs-strip .nxs-ico{color:var(--gold)}
   .nxs-strip h4{font-family:var(--serif);font-weight:400;font-size:1.4rem;margin:0;color:var(--cream)}
   .nxs-strip p{margin:2px 0 0;color:var(--mist);font-size:.9rem}
   .nxs-strip .nxs-btn{margin-left:auto}
   .nxs-feature{display:flex;align-items:center;gap:14px;padding:12px 16px;border-radius:12px;border:1px dashed var(--gold);background:var(--goldsoft);margin:0 0 18px;font-size:.9rem}
   .nxs-feature b,.nxs-feature strong{color:var(--goldtxt)}
   .nxs-feature .body>*:last-child{margin-bottom:0}
   @media (max-width:1199px){.nxs-hero{grid-template-columns:1fr}.nxs-plans{grid-template-columns:1fr}.nxs-plan.hot{transform:none}}
   @media (max-width:991px){.nxs-grid{grid-template-columns:1fr 1fr}.nxs-group{display:none}.nxs-strip{flex-wrap:wrap}.nxs-strip .nxs-btn{margin-left:0}}
   @media (max-width:575px){.nxs-grid{grid-template-columns:1fr}.nxs-hero{padding:24px 20px}.nxs-hero h2{font-size:1.8rem}.nxs-proof{grid-template-columns:1fr}}
   </style>

   <?php echo PluginNextoolOfferBanner::assets(); ?>
   <div class="nxs" id="nextool-vitrine-<?php echo $nextoolVitrineTab; ?>">
      <?php foreach ($nextoolVitrineItems as $item): ?>
         <div class="nxs-feature" data-nextool-vitrine="remoto">
            <span class="nxs-flag"><?php echo $nxsE(__('Em destaque', 'nextool')); ?></span>
            <div class="body"><b><?php echo $nxsE((string) $item['title']); ?></b>
               <?php echo PluginNextoolAlertManager::sanitizeBody((string) $item['body']); ?></div>
         </div>
      <?php endforeach; ?>

      <section class="nxs-hero nxs-night" data-nxo-dots>
         <canvas aria-hidden="true"></canvas>
         <div>
            <span class="nxs-eyebrow"><?php echo $nxsE(__('Serviços NexTool Solutions', 'nextool')); ?></span>
            <h2><?php echo $nxsRich(__('Seu GLPI no ponto, *sem você carregar sozinho.*', 'nextool')); ?></h2>
            <p><?php echo $nxsE(__('Os módulos resolvem uma parte. Implantação, integrações, suporte e IA a gente faz junto com o seu time, do diagnóstico ao go-live.', 'nextool')); ?></p>
            <div class="d-flex gap-2 flex-wrap">
               <a class="nxs-btn nxs-gold" target="_blank" rel="noopener" href="<?php echo $nxsE($nextoolTalkUrl); ?>"><i class="ti ti-calendar-event"></i><?php echo $nxsE(__('Agendar uma conversa', 'nextool')); ?></a>
               <a class="nxs-btn nxs-ghost" target="_blank" rel="noopener" href="<?php echo $nxsE($nextoolWhatsappUrl); ?>"><i class="ti ti-brand-whatsapp"></i><?php echo $nxsE(__('Chamar no WhatsApp', 'nextool')); ?></a>
            </div>
         </div>
         <div class="nxs-proof">
            <?php foreach ($nextoolProofs as [$big, $small]): ?>
               <div><b><?php echo $nxsE($big); ?></b><span><?php echo $nxsE($small); ?></span></div>
            <?php endforeach; ?>
         </div>
      </section>

      <div class="nxs-sec"><div><span class="nxs-eyebrow"><?php echo $nxsE(__('Para o seu momento', 'nextool')); ?></span><h3><?php echo $nxsE(__('Por onde a maioria começa', 'nextool')); ?></h3></div></div>
      <div class="nxs-plans" data-nextool-vitrine="padrao">
         <?php foreach ($nextoolPlans as [$kind, $title, $why, $items, $cta, $url, $hot]): ?>
            <div class="nxs-plan<?php echo $hot ? ' hot' : ''; ?>">
               <div class="d-flex justify-content-between align-items-center">
                  <span class="nxs-eyebrow"><?php echo $nxsE($kind); ?></span>
                  <?php if ($hot): ?><span class="nxs-flag"><?php echo $nxsE(__('Mais escolhido', 'nextool')); ?></span><?php endif; ?>
               </div>
               <h4><?php echo $nxsE($title); ?></h4>
               <p class="why"><?php echo $nxsE($why); ?></p>
               <ul><?php foreach ($items as $li): ?><li><?php echo $nxsE($li); ?></li><?php endforeach; ?></ul>
               <a class="nxs-btn <?php echo $hot ? 'nxs-gold' : 'nxs-soft'; ?>" style="justify-content:center" target="_blank" rel="noopener" href="<?php echo $nxsE($url); ?>"><?php echo $nxsE($cta); ?></a>
            </div>
         <?php endforeach; ?>
      </div>

      <div class="nxs-sec">
         <div><span class="nxs-eyebrow"><?php echo $nxsE(__('Catálogo', 'nextool')); ?></span><h3><?php echo $nxsE(__('Todos os serviços', 'nextool')); ?></h3></div>
         <p><?php echo $nxsE(__('Cada um abre a página do serviço no site, com casos e escopo.', 'nextool')); ?></p>
      </div>
      <div class="nxs-grid">
         <?php foreach ($nextoolGroups as $group): ?><p class="nxs-group"><?php echo $nxsE($group); ?></p><?php endforeach; ?>
         <?php foreach ($nextoolTiles as [$icon, $title, $text, $slug, $ai]): ?>
            <a class="nxs-tile" target="_blank" rel="noopener" href="<?php echo $nxsE($nextoolSiteUrl('/servicos/' . $slug, $slug)); ?>">
               <span class="nxs-ico<?php echo $ai ? ' ai' : ''; ?>"><i class="ti <?php echo $icon; ?>"></i></span>
               <div><h5><?php echo $nxsE($title); ?></h5><p><?php echo $nxsE($text); ?></p></div>
               <i class="ti ti-arrow-right go"></i>
            </a>
         <?php endforeach; ?>
      </div>

      <section class="nxs-strip nxs-night">
         <span class="nxs-ico"><i class="ti ti-compass"></i></span>
         <div><h4><?php echo $nxsE(__('Não sabe por onde começar?', 'nextool')); ?></h4><p><?php echo $nxsE(__('Conte o cenário em 30 minutos. A gente diz o que vale fazer primeiro, sem compromisso.', 'nextool')); ?></p></div>
         <a class="nxs-btn nxs-gold" target="_blank" rel="noopener" href="<?php echo $nxsE($nextoolSiteUrl('/agenda', 'faixa-final')); ?>"><i class="ti ti-calendar-event"></i><?php echo $nxsE(__('Agendar uma conversa', 'nextool')); ?></a>
      </section>
   </div>
<?php if (!$nextool_is_standalone): ?></div><?php endif; ?>
<?php endif; ?>
