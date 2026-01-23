<?php
/**
 * Página de configuração do módulo AI Assist
 */

if (!defined('GLPI_ROOT')) {
   include ('../../../../../inc/includes.php');
}

global $CFG_GLPI;

Session::checkRight('config', READ);

require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/permissionmanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/modules/aiassist/inc/aiassist.class.php';

// Verifica permissão de visualização do módulo (READ mínimo)
PluginNextoolPermissionManager::assertCanViewModule('aiassist');

$module = new PluginNextoolAiassist();
$settings = $module->getSettings();
$quota = $module->getQuotaData();
$isProxyMode = ($settings['provider_mode'] ?? PluginNextoolAiassist::PROVIDER_MODE_DIRECT) === PluginNextoolAiassist::PROVIDER_MODE_PROXY;
$provider = $settings['provider'] ?? PluginNextoolAiassist::PROVIDER_OPENAI;

$providers = [
   PluginNextoolAiassist::PROVIDER_OPENAI => [
      'label' => __('OpenAI', 'nextool'),
      'api_key_label' => __('Chave API OpenAI', 'nextool'),
      'api_key_placeholder' => 'sk-...',
      'description' => __('Requisições diretas na OpenAI com sua própria chave.', 'nextool'),
      'models' => [
         'gpt-4o-mini' => 'gpt-4o-mini (recomendado)',
         'gpt-4o'      => 'gpt-4o',
         'o4-mini'     => 'o4-mini',
         'gpt-4.1-mini'=> 'gpt-4.1-mini'
      ],
      'default_model' => 'gpt-4o-mini',
   ],
   PluginNextoolAiassist::PROVIDER_GEMINI => [
      'label' => __('Gemini', 'nextool'),
      'api_key_label' => __('Chave API Gemini', 'nextool'),
      'api_key_placeholder' => 'AIza...',
      'description' => __('Requisições diretas na API Gemini com sua própria chave.', 'nextool'),
      'models' => [
         'gemini-3-flash-preview' => 'gemini-3-flash-preview (gratuito)',
         'gemini-2.0-flash'       => 'gemini-2.0-flash'
      ],
      'default_model' => 'gemini-3-flash-preview',
   ],
];

// Verifica se pode editar configurações (UPDATE necessário)
$canEdit = $module->canEditConfig();

if (isset($_POST['save_aiassist_config']) || isset($_POST['reset_quota']) || isset($_POST['notify_test_result'])) {
   // Valida permissão de UPDATE antes de processar
   PluginNextoolPermissionManager::assertCanManageModule('aiassist');

   if (isset($_POST['reset_quota'])) {
      $module->resetQuota();
      Session::addMessageAfterRedirect(__('Saldo de tokens resetado com sucesso.', 'nextool'), false, INFO);
      Html::back();
   }

   // Solicitação de exibir notificação padrão do GLPI (após teste de conexão via AJAX)
   if (isset($_POST['notify_test_result'])) {
      $type = ($_POST['notify_type'] ?? 'info') === 'error' ? ERROR : INFO;
      $msg  = trim((string)($_POST['notify_message'] ?? ''));
      if ($msg === '') {
         $msg = __('Operação concluída.', 'nextool');
      }
      // Limita mensagem para evitar poluição
      if (strlen($msg) > 800) {
         $msg = substr($msg, 0, 800) . '...';
      }
      Session::addMessageAfterRedirect($msg, false, $type);
      Html::back();
   }

   $input = [
      'client_identifier'  => $_POST['client_identifier'] ?? '',
      'provider_mode'      => $_POST['provider_mode'] ?? PluginNextoolAiassist::PROVIDER_MODE_DIRECT,
      'provider'           => $_POST['provider'] ?? PluginNextoolAiassist::PROVIDER_OPENAI,
      'proxy_identifier'   => $_POST['proxy_identifier'] ?? '',
      'model'              => trim($_POST['model'] ?? ''),
      'api_key'            => trim($_POST['api_key'] ?? ''),
      'allow_sensitive'    => isset($_POST['allow_sensitive']) ? 1 : 0,
      'payload_max_chars'  => (int)($_POST['payload_max_chars'] ?? 6000),
      'timeout_seconds'    => (int)($_POST['timeout_seconds'] ?? 25),
      'rate_limit_minutes' => (int)($_POST['rate_limit_minutes'] ?? 5),
      'tokens_limit_month' => (int)($_POST['tokens_limit_month'] ?? 100000),
      'feature_summary_enabled'  => isset($_POST['feature_summary_enabled']) ? 1 : 0,
      'feature_reply_enabled'    => isset($_POST['feature_reply_enabled']) ? 1 : 0,
      'feature_sentiment_enabled' => isset($_POST['feature_sentiment_enabled']) ? 1 : 0,
      'feature_summary_model'    => trim($_POST['feature_summary_model'] ?? ''),
      'feature_reply_model'      => trim($_POST['feature_reply_model'] ?? ''),
      'feature_sentiment_model'  => trim($_POST['feature_sentiment_model'] ?? ''),
   ];

   if ($module->saveSettings($input)) {
      Session::addMessageAfterRedirect(__('Configurações do AI Assist salvas com sucesso.', 'nextool'), false, INFO);
   } else {
      Session::addMessageAfterRedirect(__('Erro ao salvar configurações do AI Assist.', 'nextool'), false, ERROR);
   }

   Html::back();
}

$requestedSection = $_GET['section'] ?? 'features';
$allowedSections = ['features', 'api', 'consumption', 'logs'];
$activeSection = in_array($requestedSection, $allowedSections, true) ? $requestedSection : 'features';

Html::header('NexTool Solutions - AI Assist', $_SERVER['PHP_SELF'], 'config', 'plugins');

$csrf = Session::getNewCSRFToken();
$ajax_token = Session::getNewCSRFToken();
$hasApiKey = !empty($settings['has_api_key']);
$quotaLimit = (int)($quota['tokens_limit'] ?? $settings['tokens_limit_month']);
$quotaUsed = (int)($quota['tokens_used'] ?? 0);
$quotaPercent = $quotaLimit > 0 ? min(100, round(($quotaUsed / max(1, $quotaLimit)) * 100, 2)) : 0;
$periodStart = $quota['period_start'] ?? date('Y-m-01');
$periodEnd = $quota['period_end'] ?? date('Y-m-t');
$lastReset = $quota['last_reset_at'] ?? __('Nunca', 'nextool');

$tabs = [
   'features' => [
      'label' => __('Funcionalidades', 'nextool'),
      'icon'  => 'ti ti-toggle-left'
   ],
   'api' => [
      'label' => __('API IA', 'nextool'),
      'icon'  => 'ti ti-robot'
   ],
   'consumption' => [
      'label' => __('Consumo & Limites', 'nextool'),
      'icon'  => 'ti ti-meter-spark'
   ],
   'logs' => [
      'label' => __('Logs & Histórico', 'nextool'),
      'icon'  => 'ti ti-file-text'
   ],
];

?>

<div class="container-fluid mt-3 mb-4">
   <form method="post" action="<?php echo Plugin::getWebDir('nextool') . '/front/modules.php?module=aiassist&file=aiassist.config.php&section=' . urlencode($activeSection); ?>" id="aiassistConfigForm">
      <?php
         echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
         echo Html::hidden('forcetab', ['value' => 'PluginNextoolSetup$1']);
      ?>

      <ul class="nav nav-tabs mb-3" id="aiassist-nav" role="tablist">
         <?php foreach ($tabs as $key => $tab): ?>
            <li class="nav-item" role="presentation">
               <button type="button"
                       class="nav-link <?php echo $activeSection === $key ? 'active' : ''; ?>"
                       data-section="<?php echo $key; ?>"
                       onclick="aiassistSwitchSection('<?php echo $key; ?>')">
                  <i class="<?php echo $tab['icon']; ?> me-1"></i>
                  <?php echo $tab['label']; ?>
               </button>
            </li>
         <?php endforeach; ?>
      </ul>

      <div data-section="features" class="aiassist-tab-section" style="<?php echo $activeSection === 'features' ? '' : 'display:none;'; ?>">
         <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent border-0 pb-0">
               <div class="d-flex align-items-center gap-2">
                  <div class="rounded-circle bg-purple text-white d-flex align-items-center justify-content-center" style="width:48px;height:48px;background: linear-gradient(135deg, #7c3aed, #c084fc);">
                     <i class="ti ti-toggle-left fs-3"></i>
                  </div>
                  <div>
                     <h3 class="mb-0"><?php echo __('Controle de Funcionalidades', 'nextool'); ?></h3>
                     <small class="text-muted"><?php echo __('Ative ou desative as funcionalidades do módulo AI Assist conforme sua necessidade.', 'nextool'); ?></small>
                  </div>
               </div>
            </div>
            <div class="card-body">
               <div class="alert alert-info mb-4">
                  <i class="ti ti-info-circle me-2"></i>
                  <?php echo __('Ao desativar uma funcionalidade, ela não estará disponível na aba do chamado e não consumirá tokens da API.', 'nextool'); ?>
               </div>

               <div class="row g-4">
                  <!-- Resumo do Chamado -->
                  <div class="col-md-6">
                     <div class="card border">
                        <div class="card-body">
                           <div class="d-flex align-items-start justify-content-between mb-3">
                              <div class="flex-grow-1">
                                 <h5 class="mb-1">
                                    <i class="ti ti-file-text me-2 text-primary"></i>
                                    <?php echo __('Resumo do Chamado', 'nextool'); ?>
                                 </h5>
                                 <p class="text-muted mb-0 small">
                                    <?php echo __('Gera um resumo inteligente do chamado com base no histórico de interações, facilitando a compreensão rápida do contexto.', 'nextool'); ?>
                                 </p>
                              </div>
                              <div class="ms-3 d-flex flex-column align-items-end">
                                 <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           name="feature_summary_enabled" 
                                           id="feature_summary_enabled" 
                                           value="1" 
                                           <?php echo !empty($settings['feature_summary_enabled']) ? 'checked' : ''; ?>
                                           style="width: 3rem; height: 1.5rem;">
                                    <label class="form-check-label" for="feature_summary_enabled"></label>
                                 </div>
                                 <span class="badge <?php echo !empty($settings['feature_summary_enabled']) ? 'bg-success' : 'bg-secondary'; ?>" id="feature_summary_status">
                                    <?php echo !empty($settings['feature_summary_enabled']) ? __('Ativo', 'nextool') : __('Inativo', 'nextool'); ?>
                                 </span>
                              </div>
                           </div>
                           <div class="mt-2">
                              <small class="text-muted">
                                 <i class="ti ti-bolt me-1"></i>
                                 <?php echo __('Consome tokens da API para processar o histórico e gerar o resumo.', 'nextool'); ?>
                              </small>
                           </div>
                           <div class="mt-3">
                              <label class="form-label small fw-semibold"><?php echo __('Modelo específico (opcional)', 'nextool'); ?></label>
                              <select name="feature_summary_model" class="form-select form-select-sm aiassist-model-select" data-model-scope="summary">
                                 <option value=""><?php echo __('Usar modelo padrão', 'nextool'); ?></option>
                                 <?php
                                    $currentModel = $settings['feature_summary_model'] ?? '';
                                    foreach ($providers as $providerKey => $providerInfo) {
                                       foreach ($providerInfo['models'] as $value => $label) {
                                          $selected = ($currentModel === $value) ? 'selected' : '';
                                          echo "<option value=\"{$value}\" data-provider=\"{$providerKey}\" {$selected}>{$label}</option>";
                                       }
                                    }
                                 ?>
                              </select>
                              <small class="text-muted">
                                 <?php echo __('Se especificado, este modelo será usado apenas para resumos. Caso contrário, usa o modelo padrão configurado na aba "API IA".', 'nextool'); ?>
                              </small>
                           </div>
                        </div>
                     </div>
                  </div>

                  <!-- Sugestão de Resposta -->
                  <div class="col-md-6">
                     <div class="card border">
                        <div class="card-body">
                           <div class="d-flex align-items-start justify-content-between mb-3">
                              <div class="flex-grow-1">
                                 <h5 class="mb-1">
                                    <i class="ti ti-message-circle me-2 text-success"></i>
                                    <?php echo __('Sugestão de Resposta', 'nextool'); ?>
                                 </h5>
                                 <p class="text-muted mb-0 small">
                                    <?php echo __('Sugere uma resposta pronta em português brasileiro baseada no contexto do chamado, economizando tempo na elaboração de respostas.', 'nextool'); ?>
                                 </p>
                              </div>
                              <div class="ms-3 d-flex flex-column align-items-end">
                                 <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           name="feature_reply_enabled" 
                                           id="feature_reply_enabled" 
                                           value="1" 
                                           <?php echo !empty($settings['feature_reply_enabled']) ? 'checked' : ''; ?>
                                           style="width: 3rem; height: 1.5rem;">
                                    <label class="form-check-label" for="feature_reply_enabled"></label>
                                 </div>
                                 <span class="badge <?php echo !empty($settings['feature_reply_enabled']) ? 'bg-success' : 'bg-secondary'; ?>" id="feature_reply_status">
                                    <?php echo !empty($settings['feature_reply_enabled']) ? __('Ativo', 'nextool') : __('Inativo', 'nextool'); ?>
                                 </span>
                              </div>
                           </div>
                           <div class="mt-2">
                              <small class="text-muted">
                                 <i class="ti ti-bolt me-1"></i>
                                 <?php echo __('Consome tokens da API para gerar sugestões contextualizadas.', 'nextool'); ?>
                              </small>
                           </div>
                           <div class="mt-3">
                              <label class="form-label small fw-semibold"><?php echo __('Modelo específico (opcional)', 'nextool'); ?></label>
                              <select name="feature_reply_model" class="form-select form-select-sm aiassist-model-select" data-model-scope="reply">
                                 <option value=""><?php echo __('Usar modelo padrão', 'nextool'); ?></option>
                                 <?php
                                    $currentModel = $settings['feature_reply_model'] ?? '';
                                    foreach ($providers as $providerKey => $providerInfo) {
                                       foreach ($providerInfo['models'] as $value => $label) {
                                          $selected = ($currentModel === $value) ? 'selected' : '';
                                          echo "<option value=\"{$value}\" data-provider=\"{$providerKey}\" {$selected}>{$label}</option>";
                                       }
                                    }
                                 ?>
                              </select>
                              <small class="text-muted">
                                 <?php echo __('Se especificado, este modelo será usado apenas para sugestões de resposta.', 'nextool'); ?>
                              </small>
                           </div>
                        </div>
                     </div>
                  </div>

                  <!-- Análise de Sentimento e Urgência -->
                  <div class="col-md-6">
                     <div class="card border">
                        <div class="card-body">
                           <div class="d-flex align-items-start justify-content-between mb-3">
                              <div class="flex-grow-1">
                                 <h5 class="mb-1">
                                    <i class="ti ti-mood-smile me-2 text-warning"></i>
                                    <?php echo __('Análise de Sentimento e Urgência', 'nextool'); ?>
                                 </h5>
                                 <p class="text-muted mb-0 small">
                                    <?php echo __('Analisa o sentimento do chamado (positivo, neutro, negativo) e identifica o nível de urgência, ajudando na priorização.', 'nextool'); ?>
                                 </p>
                              </div>
                              <div class="ms-3 d-flex flex-column align-items-end">
                                 <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           name="feature_sentiment_enabled" 
                                           id="feature_sentiment_enabled" 
                                           value="1" 
                                           <?php echo !empty($settings['feature_sentiment_enabled']) ? 'checked' : ''; ?>
                                           style="width: 3rem; height: 1.5rem;">
                                    <label class="form-check-label" for="feature_sentiment_enabled"></label>
                                 </div>
                                 <span class="badge <?php echo !empty($settings['feature_sentiment_enabled']) ? 'bg-success' : 'bg-secondary'; ?>" id="feature_sentiment_status">
                                    <?php echo !empty($settings['feature_sentiment_enabled']) ? __('Ativo', 'nextool') : __('Inativo', 'nextool'); ?>
                                 </span>
                              </div>
                           </div>
                           <div class="mt-2">
                              <small class="text-muted">
                                 <i class="ti ti-bolt me-1"></i>
                                 <?php echo __('Consome tokens da API para análise de sentimento e classificação de urgência.', 'nextool'); ?>
                              </small>
                           </div>
                           <div class="mt-3">
                              <label class="form-label small fw-semibold"><?php echo __('Modelo específico (opcional)', 'nextool'); ?></label>
                              <select name="feature_sentiment_model" class="form-select form-select-sm aiassist-model-select" data-model-scope="sentiment">
                                 <option value=""><?php echo __('Usar modelo padrão', 'nextool'); ?></option>
                                 <?php
                                    $currentModel = $settings['feature_sentiment_model'] ?? '';
                                    foreach ($providers as $providerKey => $providerInfo) {
                                       foreach ($providerInfo['models'] as $value => $label) {
                                          $selected = ($currentModel === $value) ? 'selected' : '';
                                          echo "<option value=\"{$value}\" data-provider=\"{$providerKey}\" {$selected}>{$label}</option>";
                                       }
                                    }
                                 ?>
                              </select>
                              <small class="text-muted">
                                 <?php echo __('Se especificado, este modelo será usado apenas para análise de sentimento.', 'nextool'); ?>
                              </small>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- Estatísticas de Uso por Funcionalidade -->
               <hr class="my-4">
               <div class="mt-4">
                  <h5 class="mb-3">
                     <i class="ti ti-chart-bar me-2 text-purple"></i>
                     <?php echo __('Estatísticas de Uso', 'nextool'); ?>
                  </h5>
                  <?php
                     // Obtém estatísticas dos últimos 30 dias
                     $statsStart = date('Y-m-01');
                     $statsEnd = date('Y-m-t');
                     $featureStats = $module->getFeatureUsageStats($statsStart, $statsEnd);
                     $totalTokens = 0;
                     $totalCalls = 0;
                     foreach ($featureStats as $stat) {
                        $totalTokens += $stat['tokens_total'];
                        $totalCalls += $stat['total_calls'];
                     }
                  ?>
                  <?php if (!empty($featureStats)): ?>
                     <div class="row g-3">
                        <?php
                           $featureLabels = [
                              'summary' => ['label' => __('Resumo do Chamado', 'nextool'), 'icon' => 'ti-file-text', 'color' => 'primary'],
                              'reply' => ['label' => __('Sugestão de Resposta', 'nextool'), 'icon' => 'ti-message-circle', 'color' => 'success'],
                              'sentiment' => ['label' => __('Análise de Sentimento', 'nextool'), 'icon' => 'ti-mood-smile', 'color' => 'warning'],
                           ];
                           foreach ($featureLabels as $featureKey => $featureInfo):
                              $stat = $featureStats[$featureKey] ?? ['tokens_total' => 0, 'total_calls' => 0];
                              $tokenPercent = $totalTokens > 0 ? round(($stat['tokens_total'] / $totalTokens) * 100, 1) : 0;
                        ?>
                           <div class="col-md-4">
                              <div class="card border">
                                 <div class="card-body">
                                    <div class="d-flex align-items-center mb-2">
                                       <i class="ti <?php echo $featureInfo['icon']; ?> me-2 text-<?php echo $featureInfo['color']; ?> fs-4"></i>
                                       <h6 class="mb-0"><?php echo $featureInfo['label']; ?></h6>
                                    </div>
                                    <div class="mb-2">
                                       <small class="text-muted d-block"><?php echo __('Tokens utilizados', 'nextool'); ?></small>
                                       <strong class="fs-5"><?php echo number_format($stat['tokens_total']); ?></strong>
                                       <?php if ($tokenPercent > 0): ?>
                                          <span class="badge bg-<?php echo $featureInfo['color']; ?> bg-opacity-25 text-<?php echo $featureInfo['color']; ?> ms-2">
                                             <?php echo $tokenPercent; ?>%
                                          </span>
                                       <?php endif; ?>
                                    </div>
                                    <div>
                                       <small class="text-muted d-block"><?php echo __('Chamadas realizadas', 'nextool'); ?></small>
                                       <strong><?php echo number_format($stat['total_calls']); ?></strong>
                                    </div>
                                 </div>
                              </div>
                           </div>
                        <?php endforeach; ?>
                     </div>
                     <div class="mt-3">
                        <div class="alert alert-secondary mb-0">
                           <div class="d-flex justify-content-between align-items-center">
                              <span>
                                 <i class="ti ti-info-circle me-2"></i>
                                 <strong><?php echo __('Período:', 'nextool'); ?></strong>
                                 <?php echo Html::convDate($statsStart) . ' → ' . Html::convDate($statsEnd); ?>
                              </span>
                              <span>
                                 <strong><?php echo __('Total:', 'nextool'); ?></strong>
                                 <?php echo number_format($totalTokens); ?> <?php echo __('tokens', 'nextool'); ?> / 
                                 <?php echo number_format($totalCalls); ?> <?php echo __('chamadas', 'nextool'); ?>
                              </span>
                           </div>
                        </div>
                     </div>
                  <?php else: ?>
                     <div class="alert alert-secondary mb-0">
                        <i class="ti ti-info-circle me-2"></i>
                        <?php echo __('Nenhuma estatística disponível para o período atual.', 'nextool'); ?>
                     </div>
                  <?php endif; ?>
               </div>
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
               <button type="submit" name="save_aiassist_config" class="btn btn-primary" <?php echo $canEdit ? '' : ' disabled'; ?>>
                  <i class="ti ti-device-floppy me-1"></i>
                  <?php echo __('Salvar configurações', 'nextool'); ?>
               </button>
               <?php if (!$canEdit): ?>
                  <div class="alert alert-info mb-0 ms-2 py-2">
                     <i class="ti ti-info-circle me-2"></i>
                     <?php echo __('Permissão de visualização: não é possível editar', 'nextool'); ?>
                  </div>
               <?php endif; ?>
               <a class="btn btn-secondary" href="<?php echo $CFG_GLPI['root_doc']; ?>/front/config.form.php?forcetab=PluginNextoolSetup$1">
                  <i class="ti ti-arrow-left me-1"></i>
                  <?php echo __('Voltar', 'nextool'); ?>
               </a>
            </div>
         </div>
      </div>

      <div data-section="api" class="aiassist-tab-section" style="<?php echo $activeSection === 'api' ? '' : 'display:none;'; ?>">
         <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent border-0 pb-0">
               <div class="d-flex align-items-center gap-2">
                  <div class="rounded-circle bg-purple text-white d-flex align-items-center justify-content-center" style="width:48px;height:48px;background: linear-gradient(135deg, #7c3aed, #c084fc);">
                     <i class="ti ti-plug-connected fs-3"></i>
                  </div>
                  <div>
                     <h3 class="mb-0"><?php echo __('Configuração da API IA', 'nextool'); ?></h3>
                     <small class="text-muted"><?php echo __('Escolha entre chave própria ou proxy NexTool Solutions e ajuste os parâmetros técnicos.', 'nextool'); ?></small>
                  </div>
               </div>
            </div>
            <div class="card-body">

               <div class="alert alert-info mb-4">
                  <i class="ti ti-bulb me-2"></i>
                  <?php echo __('Alterne entre “Chave própria” (API direta) ou “Proxy NexTool Solutions” para centralizar consumo e quotas.', 'nextool'); ?>
               </div>

               <!-- Campos globais removidos da tela do módulo por solicitação -->

               <div class="row g-3 mt-1">
                  <div class="col-md-6">
                     <label class="form-label fw-semibold"><?php echo __('Provedor de IA', 'nextool'); ?></label>
                     <select name="provider" class="form-select aiassist-provider-select">
                        <?php foreach ($providers as $providerKey => $providerInfo): ?>
                           <option value="<?php echo $providerKey; ?>" <?php echo $provider === $providerKey ? 'selected' : ''; ?>>
                              <?php echo $providerInfo['label']; ?>
                           </option>
                        <?php endforeach; ?>
                     </select>
                     <small class="text-muted">
                        <?php echo __('Escolha o provedor usado nas chamadas de IA. Outros provedores serão adicionados futuramente.', 'nextool'); ?>
                     </small>
                  </div>
               </div>

               <hr class="my-4">

               <label class="form-label fw-semibold"><?php echo __('Modo de integração', 'nextool'); ?></label>
               <div class="btn-group mb-3" role="group">
                  <input class="btn-check" type="radio" name="provider_mode" id="aiassist-mode-direct" value="direct" <?php echo !$isProxyMode ? 'checked' : ''; ?>>
                  <label class="btn btn-outline-primary" for="aiassist-mode-direct">
                     <?php echo __('Chave própria (API direta)', 'nextool'); ?>
                  </label>
                  <input class="btn-check" type="radio" name="provider_mode" id="aiassist-mode-proxy" value="proxy" <?php echo $isProxyMode ? 'checked' : ''; ?>>
                  <label class="btn btn-outline-primary" for="aiassist-mode-proxy">
                     <?php echo __('Proxy NexTool Solutions', 'nextool'); ?>
                  </label>
               </div>

               <div class="alert alert-secondary aiassist-direct-only" style="<?php echo $isProxyMode ? 'display:none;' : ''; ?>">
                  <i class="ti ti-key me-2"></i>
                  <?php echo __('As requisições usam diretamente sua conta no provedor selecionado. Quotas e resets seguem as regras do provedor.', 'nextool'); ?>
               </div>

               <div class="alert alert-secondary aiassist-proxy-only" style="<?php echo $isProxyMode ? '' : 'display:none;'; ?>">
                  <i class="ti ti-network me-2"></i>
                  <?php echo __('Com o proxy NexTool Solutions, o consumo passa por uma camada intermediária com quotas centralizadas e métricas compartilhadas.', 'nextool'); ?>
               </div>

               <div class="row g-3 mt-1 aiassist-direct-only" style="<?php echo $isProxyMode ? 'display:none;' : ''; ?>">
                  <div class="col-12">
                     <label class="form-label fw-semibold">
                        <?php foreach ($providers as $providerKey => $providerInfo): ?>
                           <span class="aiassist-provider-label" data-provider="<?php echo $providerKey; ?>" style="<?php echo $provider === $providerKey ? '' : 'display:none;'; ?>">
                              <?php echo $providerInfo['api_key_label']; ?>
                           </span>
                        <?php endforeach; ?>
                     </label>
                     <input type="password"
                            name="api_key"
                            class="form-control aiassist-api-key"
                            placeholder="<?php echo $providers[$provider]['api_key_placeholder'] ?? ''; ?>"
                            data-openai-placeholder="<?php echo $providers[PluginNextoolAiassist::PROVIDER_OPENAI]['api_key_placeholder']; ?>"
                            data-gemini-placeholder="<?php echo $providers[PluginNextoolAiassist::PROVIDER_GEMINI]['api_key_placeholder']; ?>"
                            autocomplete="off">
                     <?php if ($hasApiKey): ?>
                        <small class="text-muted">
                           <i class="ti ti-lock me-1 text-success"></i>
                           <?php echo __('Chave armazenada com segurança. Deixe em branco para manter.', 'nextool'); ?>
                        </small>
                     <?php else: ?>
                        <?php foreach ($providers as $providerKey => $providerInfo): ?>
                           <small class="text-muted aiassist-provider-hint" data-provider="<?php echo $providerKey; ?>" style="<?php echo $provider === $providerKey ? '' : 'display:none;'; ?>">
                              <?php echo $providerInfo['description']; ?>
                           </small>
                        <?php endforeach; ?>
                     <?php endif; ?>
                  </div>
               </div>

               <div class="row g-3 mt-1">
                  <div class="col-md-6">
                     <label class="form-label fw-semibold"><?php echo __('Modelo preferencial', 'nextool'); ?></label>
                     <select name="model" class="form-select aiassist-model-select" data-model-scope="default">
                        <?php
                           $currentModel = $settings['model'] ?? $providers[$provider]['default_model'];
                           foreach ($providers as $providerKey => $providerInfo) {
                              foreach ($providerInfo['models'] as $value => $label) {
                                 $selected = ($currentModel === $value) ? 'selected' : '';
                                 echo "<option value=\"{$value}\" data-provider=\"{$providerKey}\" {$selected}>{$label}</option>";
                              }
                           }
                        ?>
                     </select>
                     <small class="text-muted"><?php echo __('Selecione o modelo conforme a disponibilidade do provedor escolhido.', 'nextool'); ?></small>
                  </div>
                  <div class="col-md-6 aiassist-proxy-only" style="<?php echo $isProxyMode ? '' : 'display:none;'; ?>">
                     <label class="form-label fw-semibold"><?php echo __('Limite mensal de tokens (proxy)', 'nextool'); ?></label>
                     <input type="number"
                            class="form-control"
                            value="<?php echo (int)max(1000, $quotaLimit ?: 100000); ?>"
                            readonly>
                     <small class="text-muted">
                        <?php echo __('Valor definido e sincronizado pelo proxy NexTool Solutions. Não editável localmente.', 'nextool'); ?>
                     </small>
                     <input type="hidden" name="tokens_limit_month" value="<?php echo (int)max(1000, $quotaLimit ?: 100000); ?>">
                  </div>
               </div>

               <div class="row g-3 mt-1">
                  <div class="col-md-4">
                     <label class="form-label fw-semibold"><?php echo __('Payload máximo (caracteres)', 'nextool'); ?></label>
                     <input type="number" name="payload_max_chars" class="form-control" min="1000" max="20000" value="<?php echo (int)$settings['payload_max_chars']; ?>">
                     <small class="text-muted"><?php echo __('Controla o quanto de histórico é enviado a cada requisição.', 'nextool'); ?></small>
                  </div>
                  <div class="col-md-4">
                     <label class="form-label fw-semibold"><?php echo __('Timeout (segundos)', 'nextool'); ?></label>
                     <input type="number" name="timeout_seconds" class="form-control" min="5" max="120" value="<?php echo (int)$settings['timeout_seconds']; ?>">
                  </div>
                  <div class="col-md-4">
                     <label class="form-label fw-semibold"><?php echo __('Rate limit (minutos)', 'nextool'); ?></label>
                     <input type="number" name="rate_limit_minutes" class="form-control" min="0" max="60" value="<?php echo (int)$settings['rate_limit_minutes']; ?>">
                     <small class="text-muted"><?php echo __('Intervalo mínimo entre solicitações por usuário (0 = sem limite).', 'nextool'); ?></small>
                  </div>
               </div>

               <div class="mt-3">
                  <div class="form-check form-switch">
                     <input class="form-check-input" type="checkbox" name="allow_sensitive" id="allow_sensitive" value="1" <?php echo !empty($settings['allow_sensitive']) ? 'checked' : ''; ?>>
                     <label class="form-check-label fw-semibold" for="allow_sensitive">
                        <?php echo __('Permitir dados sensíveis no prompt', 'nextool'); ?>
                     </label>
                  </div>
                  <small class="text-muted">
                     <?php echo __('Quando desligado, campos marcados como confidenciais são removidos antes do envio.', 'nextool'); ?>
                  </small>
               </div>

            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
               <button type="submit" name="save_aiassist_config" class="btn btn-primary" <?php echo $canEdit ? '' : ' disabled'; ?>>
                  <i class="ti ti-device-floppy me-1"></i>
                  <?php echo __('Salvar configurações', 'nextool'); ?>
               </button>
               <a href="<?php echo Plugin::getWebDir('nextool'); ?>/front/modules.php?module=aiassist&file=aiassist.test.php" class="btn btn-outline-success">
                  <i class="ti ti-plug-connected me-1"></i>
                  <?php echo __('Testar conexão', 'nextool'); ?>
               </a>
               <a class="btn btn-secondary" href="<?php echo $CFG_GLPI['root_doc']; ?>/front/config.form.php?forcetab=PluginNextoolSetup$1">
                  <i class="ti ti-arrow-left me-1"></i>
                  <?php echo __('Voltar', 'nextool'); ?>
               </a>
            </div>
         </div>
      </div>

      <div data-section="consumption" class="aiassist-tab-section" style="<?php echo $activeSection === 'consumption' ? '' : 'display:none;'; ?>">
         <?php if ($isProxyMode): ?>
            <div class="row g-3">
               <div class="col-xl-6">
                  <div class="card shadow-sm border-0 h-100">
                     <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <div>
                           <h4 class="mb-0">
                              <i class="ti ti-meter-spark me-2 text-purple"></i>
                              <?php echo __('Período atual', 'nextool'); ?>
                           </h4>
                           <small class="text-muted"><?php echo sprintf('%s → %s', Html::convDate($periodStart), Html::convDate($periodEnd)); ?></small>
                        </div>
                        <span class="badge bg-purple bg-opacity-75"><?php echo sprintf('%d%%', $quotaPercent); ?></span>
                     </div>
                     <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                           <span class="text-muted"><?php echo __('Saldo utilizado', 'nextool'); ?></span>
                           <span class="fw-bold"><?php echo sprintf('%d / %d', $quotaUsed, max(1, $quotaLimit)); ?></span>
                        </div>
                        <div class="progress mb-3" style="height: 10px;">
                           <div class="progress-bar bg-gradient"
                                role="progressbar"
                                style="width: <?php echo $quotaPercent; ?>%; background: linear-gradient(90deg,#7c3aed,#c084fc);"
                                aria-valuenow="<?php echo $quotaPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <div class="mb-2">
                           <small class="text-muted"><?php echo __('Último reset', 'nextool'); ?></small><br>
                           <small><?php echo $lastReset ? Html::convDateTime($lastReset) : __('Nunca', 'nextool'); ?></small>
                        </div>
                        <div class="d-flex gap-2">
                           <button type="submit"
                                   name="reset_quota"
                                   class="btn btn-warning"
                                   <?php echo $canEdit ? '' : ' disabled'; ?>
                                   onclick="return confirm('<?php echo __('Deseja realmente resetar o saldo de tokens?', 'nextool'); ?>');">
                              <i class="ti ti-rotate-clockwise-2 me-1"></i>
                              <?php echo __('Resetar saldo de tokens', 'nextool'); ?>
                           </button>
                           <span class="text-muted small align-self-center"><?php echo __('Use apenas em contingência.', 'nextool'); ?></span>
                        </div>
                     </div>
                  </div>
               </div>

               <div class="col-xl-6">
                  <?php
                     $monthlyStats = $module->getMonthlyUsageStats(3);
                     $featureStats = [];
                     if (!empty($monthlyStats)) {
                        $lastPeriod = end($monthlyStats);
                        $featureStats = $module->getFeatureUsageStats($lastPeriod['period_start'], $lastPeriod['period_end']);
                     }
                  ?>
                  <div class="card shadow-sm border-0 h-100">
                     <div class="card-header bg-transparent border-0">
                        <h4 class="mb-0">
                           <i class="ti ti-calendar-stats me-2 text-purple"></i>
                           <?php echo __('Últimos 3 meses', 'nextool'); ?>
                        </h4>
                     </div>
                     <div class="card-body">
                        <?php if (!empty($monthlyStats)): ?>
                           <div class="table-responsive mb-3">
                              <table class="table table-striped table-sm align-middle mb-0">
                                 <thead>
                                    <tr>
                                       <th><?php echo __('Período', 'nextool'); ?></th>
                                       <th class="text-end"><?php echo __('Tokens', 'nextool'); ?></th>
                                       <th class="text-end"><?php echo __('% do limite', 'nextool'); ?></th>
                                       <th class="text-end"><?php echo __('Chamadas (OK/Erro)', 'nextool'); ?></th>
                                    </tr>
                                 </thead>
                                 <tbody>
                                    <?php foreach ($monthlyStats as $stat): ?>
                                       <tr>
                                          <td><?php echo Html::entities_deep($stat['label']); ?></td>
                                          <td class="text-end"><?php echo number_format($stat['tokens_total']); ?></td>
                                          <td class="text-end">
                                             <?php echo $stat['percentage'] !== null ? $stat['percentage'] . '%' : '—'; ?>
                                          </td>
                                          <td class="text-end">
                                             <span class="text-success"><?php echo $stat['success_calls']; ?></span>
                                             /
                                             <span class="text-danger"><?php echo $stat['error_calls']; ?></span>
                                          </td>
                                       </tr>
                                    <?php endforeach; ?>
                                 </tbody>
                              </table>
                           </div>
                           <div>
                              <h6 class="fw-semibold mb-2"><?php echo __('Distribuição por feature (período mais recente)', 'nextool'); ?></h6>
                              <?php if (!empty($featureStats)): ?>
                                 <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                       <thead>
                                          <tr>
                                             <th><?php echo __('Feature', 'nextool'); ?></th>
                                             <th class="text-end"><?php echo __('Tokens', 'nextool'); ?></th>
                                             <th class="text-end"><?php echo __('Chamadas', 'nextool'); ?></th>
                                          </tr>
                                       </thead>
                                       <tbody>
                                          <?php foreach ($featureStats as $feature => $info): ?>
                                             <tr>
                                                <td><?php echo ucfirst(Html::entities_deep($feature)); ?></td>
                                                <td class="text-end"><?php echo number_format($info['tokens_total']); ?></td>
                                                <td class="text-end"><?php echo $info['total_calls']; ?></td>
                                             </tr>
                                          <?php endforeach; ?>
                                       </tbody>
                                    </table>
                                 </div>
                              <?php else: ?>
                                 <div class="alert alert-secondary mb-0">
                                    <?php echo __('Sem dados de consumo no período selecionado.', 'nextool'); ?>
                                 </div>
                              <?php endif; ?>
                           </div>
                        <?php else: ?>
                           <div class="alert alert-secondary mb-0">
                              <?php echo __('Sem dados registrados para calcular histórico.', 'nextool'); ?>
                           </div>
                        <?php endif; ?>
                     </div>
                  </div>
               </div>
            </div>
         <?php else: ?>
            <div class="alert alert-info">
               <i class="ti ti-info-circle me-2"></i>
               <?php echo __('Você está usando chave própria. O consumo é controlado diretamente pela OpenAI e não há quotas locais para monitorar.', 'nextool'); ?>
            </div>
         <?php endif; ?>
      </div>

      <div data-section="logs" class="aiassist-tab-section" style="<?php echo $activeSection === 'logs' ? '' : 'display:none;'; ?>">
         <div class="row g-3">
            <!-- Seção 1: Logs do Sistema -->
            <div class="col-lg-6">
               <div class="card shadow-sm border-0 h-100">
                  <div class="card-header bg-transparent border-0">
                     <h4 class="mb-0">
                        <i class="ti ti-file-text me-2 text-purple"></i>
                        <?php echo __('Logs do Sistema', 'nextool'); ?>
                     </h4>
                  </div>
                  <div class="card-body">
                     <div class="mb-3">
                        <label class="form-label small fw-semibold"><?php echo __('Arquivo de log', 'nextool'); ?></label>
                        <code class="d-block bg-light p-2 rounded"><?php echo GLPI_ROOT . '/files/_log/plugin_nextool_aiassist.log'; ?></code>
                     </div>
                     
                     <div class="mb-3">
                        <label class="form-label small fw-semibold"><?php echo __('Últimas linhas do log', 'nextool'); ?></label>
                        <?php
                           $logFile = GLPI_ROOT . '/files/_log/plugin_nextool_aiassist.log';
                           $logLines = [];
                           if (file_exists($logFile) && is_readable($logFile)) {
                              $logLines = array_slice(file($logFile), -50); // Últimas 50 linhas
                           }
                        ?>
                        <div class="bg-dark text-light p-3 rounded" style="max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 0.85rem;">
                           <?php if (!empty($logLines)): ?>
                              <?php foreach ($logLines as $line): ?>
                                 <div><?php echo Html::entities_deep(rtrim($line)); ?></div>
                              <?php endforeach; ?>
                           <?php else: ?>
                              <div class="text-muted"><?php echo __('Nenhum log disponível ainda.', 'nextool'); ?></div>
                           <?php endif; ?>
                        </div>
                     </div>

                     <div class="mb-3">
                        <label class="form-label small fw-semibold"><?php echo __('Comandos úteis', 'nextool'); ?></label>
                        <div class="bg-light p-2 rounded">
                           <code class="d-block small">tail -f <?php echo GLPI_ROOT . '/files/_log/plugin_nextool_aiassist.log'; ?></code>
                           <code class="d-block small mt-1">tail -n 100 <?php echo GLPI_ROOT . '/files/_log/plugin_nextool_aiassist.log'; ?></code>
                        </div>
                     </div>

                     <div class="alert alert-info mb-0">
                        <i class="ti ti-info-circle me-2"></i>
                        <small>
                           <?php echo __('Os logs registram todas as operações do módulo: requisições à API, erros, resets de quota e mudanças de configuração.', 'nextool'); ?>
                        </small>
                     </div>
                  </div>
               </div>
            </div>

            <!-- Seção 2: Histórico de Mudanças -->
            <div class="col-lg-6">
               <div class="card shadow-sm border-0 h-100">
                  <div class="card-header bg-transparent border-0">
                     <h4 class="mb-0">
                        <i class="ti ti-history me-2 text-purple"></i>
                        <?php echo __('Histórico de Mudanças', 'nextool'); ?>
                     </h4>
                  </div>
                  <div class="card-body">
                     <?php
                        // Filtros do histórico
                        $historyFilters = [
                           'field_name' => $_GET['history_field'] ?? '',
                           'users_id'   => !empty($_GET['history_user']) ? (int)$_GET['history_user'] : null,
                           'date_from'  => $_GET['history_date_from'] ?? '',
                           'date_to'    => $_GET['history_date_to'] ?? '',
                           'limit'      => 50,
                        ];

                        $history = $module->getConfigHistory($historyFilters);
                        $fieldLabels = [
                           'provider_mode' => __('Modo de integração', 'nextool'),
                           'provider' => __('Provedor', 'nextool'),
                           'model' => __('Modelo padrão', 'nextool'),
                           'allow_sensitive' => __('Permitir dados sensíveis', 'nextool'),
                           'payload_max_chars' => __('Payload máximo', 'nextool'),
                           'timeout_seconds' => __('Timeout', 'nextool'),
                           'rate_limit_minutes' => __('Rate limit', 'nextool'),
                           'tokens_limit_month' => __('Limite mensal de tokens', 'nextool'),
                           'feature_summary_enabled' => __('Resumo do Chamado (ativo)', 'nextool'),
                           'feature_reply_enabled' => __('Sugestão de Resposta (ativo)', 'nextool'),
                           'feature_sentiment_enabled' => __('Análise de Sentimento (ativo)', 'nextool'),
                           'feature_summary_model' => __('Modelo - Resumo', 'nextool'),
                           'feature_reply_model' => __('Modelo - Resposta', 'nextool'),
                           'feature_sentiment_model' => __('Modelo - Sentimento', 'nextool'),
                        ];
                     ?>

                     <!-- Filtros -->
                     <form method="get" action="" class="mb-3">
                        <?php
                           $currentParams = $_GET;
                           unset($currentParams['history_field'], $currentParams['history_user'], $currentParams['history_date_from'], $currentParams['history_date_to']);
                           foreach ($currentParams as $key => $value) {
                              echo Html::hidden($key, ['value' => $value]);
                           }
                        ?>
                        <div class="row g-2 mb-2">
                           <div class="col-12">
                              <label class="form-label small"><?php echo __('Campo', 'nextool'); ?></label>
                              <select name="history_field" class="form-select form-select-sm">
                                 <option value=""><?php echo __('Todos', 'nextool'); ?></option>
                                 <?php foreach ($fieldLabels as $field => $label): ?>
                                    <option value="<?php echo $field; ?>" <?php echo $historyFilters['field_name'] === $field ? 'selected' : ''; ?>>
                                       <?php echo $label; ?>
                                    </option>
                                 <?php endforeach; ?>
                              </select>
                           </div>
                           <div class="col-6">
                              <label class="form-label small"><?php echo __('Data inicial', 'nextool'); ?></label>
                              <input type="date" name="history_date_from" class="form-control form-control-sm" value="<?php echo Html::entities_deep($historyFilters['date_from']); ?>">
                           </div>
                           <div class="col-6">
                              <label class="form-label small"><?php echo __('Data final', 'nextool'); ?></label>
                              <input type="date" name="history_date_to" class="form-control form-control-sm" value="<?php echo Html::entities_deep($historyFilters['date_to']); ?>">
                           </div>
                        </div>
                        <div class="d-flex gap-2">
                           <button type="submit" class="btn btn-sm btn-primary">
                              <i class="ti ti-filter me-1"></i>
                              <?php echo __('Filtrar', 'nextool'); ?>
                           </button>
                           <a href="?" class="btn btn-sm btn-outline-secondary">
                              <i class="ti ti-x me-1"></i>
                              <?php echo __('Limpar', 'nextool'); ?>
                           </a>
                        </div>
                     </form>

                     <!-- Tabela de histórico -->
                     <?php if (!empty($history)): ?>
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                           <table class="table table-sm table-hover mb-0">
                              <thead class="table-light sticky-top">
                                 <tr>
                                    <th><?php echo __('Data/Hora', 'nextool'); ?></th>
                                    <th><?php echo __('Campo', 'nextool'); ?></th>
                                    <th><?php echo __('Valor Antigo', 'nextool'); ?></th>
                                    <th><?php echo __('Valor Novo', 'nextool'); ?></th>
                                    <th><?php echo __('Usuário', 'nextool'); ?></th>
                                 </tr>
                              </thead>
                              <tbody>
                                 <?php foreach ($history as $entry): ?>
                                    <tr>
                                       <td class="small"><?php echo Html::convDateTime($entry['date_creation']); ?></td>
                                       <td class="small">
                                          <strong><?php echo Html::entities_deep($fieldLabels[$entry['field_name']] ?? $entry['field_name']); ?></strong>
                                       </td>
                                       <td class="small">
                                          <span class="text-muted"><?php echo Html::entities_deep($entry['old_value'] ?? '—'); ?></span>
                                       </td>
                                       <td class="small">
                                          <span class="text-success"><?php echo Html::entities_deep($entry['new_value'] ?? '—'); ?></span>
                                       </td>
                                       <td class="small">
                                          <?php
                                             if ($entry['users_id']) {
                                                $user = new User();
                                                if ($user->getFromDB($entry['users_id'])) {
                                                   echo Html::entities_deep($user->getName());
                                                } else {
                                                   echo sprintf(__('Usuário #%d', 'nextool'), $entry['users_id']);
                                                }
                                             } else {
                                                echo __('Sistema', 'nextool');
                                             }
                                          ?>
                                       </td>
                                    </tr>
                                 <?php endforeach; ?>
                              </tbody>
                           </table>
                        </div>
                     <?php else: ?>
                        <div class="alert alert-secondary mb-0">
                           <i class="ti ti-info-circle me-2"></i>
                           <?php echo __('Nenhuma mudança registrada ainda.', 'nextool'); ?>
                        </div>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
         </div>

         <!-- Status de Segurança (mantido, mas em posição secundária) -->
         <div class="row g-3 mt-2">
            <div class="col-12">
               <div class="card shadow-sm border-0">
                  <div class="card-header bg-transparent border-0">
                     <h5 class="mb-0">
                        <i class="ti ti-shield-lock me-2"></i>
                        <?php echo __('Status de Segurança', 'nextool'); ?>
                     </h5>
                  </div>
                  <div class="card-body">
                     <div class="row g-3">
                        <div class="col-md-4">
                           <div class="d-flex align-items-center">
                              <i class="ti ti-circle-check text-success me-2 fs-5"></i>
                              <div>
                                 <strong><?php echo __('Chave API', 'nextool'); ?></strong>
                                 <p class="text-muted mb-0 small">
                                    <?php echo $hasApiKey
                                       ? __('Armazenada e criptografada', 'nextool')
                                       : __('Não configurada', 'nextool'); ?>
                                 </p>
                              </div>
                           </div>
                        </div>
                        <div class="col-md-4">
                           <div class="d-flex align-items-center">
                              <i class="ti ti-<?php echo !empty($settings['allow_sensitive']) ? 'alert-triangle text-warning' : 'circle-check text-success'; ?> me-2 fs-5"></i>
                              <div>
                                 <strong><?php echo __('Dados Sensíveis', 'nextool'); ?></strong>
                                 <p class="text-muted mb-0 small">
                                    <?php echo !empty($settings['allow_sensitive'])
                                       ? __('Podem ser enviados à IA', 'nextool')
                                       : __('São filtrados antes do envio', 'nextool'); ?>
                                 </p>
                              </div>
                           </div>
                        </div>
                        <div class="col-md-4">
                           <div class="d-flex align-items-center">
                              <i class="ti ti-circle-check text-success me-2 fs-5"></i>
                              <div>
                                 <strong><?php echo __('Monitoramento', 'nextool'); ?></strong>
                                 <p class="text-muted mb-0 small">
                                    <?php echo __('Logs dedicados ativos', 'nextool'); ?>
                                 </p>
                              </div>
                           </div>
                        </div>
                     </div>
                  </div>
               </div>
            </div>
         </div>
      </div>

   </form>
</div>

<?php
Html::footer();
?>
<script>
function aiassistSwitchSection(section) {
   const navButtons = document.querySelectorAll('#aiassist-nav button[data-section]');
   const sections = document.querySelectorAll('.aiassist-tab-section');
   navButtons.forEach((btn) => {
      const isActive = btn.getAttribute('data-section') === section;
      btn.classList.toggle('active', isActive);
   });
   sections.forEach((wrapper) => {
      const isMatch = wrapper.getAttribute('data-section') === section;
      wrapper.style.display = isMatch ? '' : 'none';
   });
}

function aiassistUpdateProviderModeUI() {
   const mode = document.querySelector('input[name="provider_mode"]:checked')?.value || 'direct';
   document.querySelectorAll('.aiassist-direct-only').forEach((el) => {
      el.style.display = mode === 'direct' ? '' : 'none';
   });
   document.querySelectorAll('.aiassist-proxy-only').forEach((el) => {
      el.style.display = mode === 'proxy' ? '' : 'none';
   });
}

function aiassistUpdateProviderUI() {
   const providerSelect = document.querySelector('.aiassist-provider-select');
   const provider = providerSelect ? providerSelect.value : '<?php echo PluginNextoolAiassist::PROVIDER_OPENAI; ?>';

   document.querySelectorAll('.aiassist-provider-label').forEach((el) => {
      el.style.display = el.dataset.provider === provider ? '' : 'none';
   });

   document.querySelectorAll('.aiassist-provider-hint').forEach((el) => {
      el.style.display = el.dataset.provider === provider ? '' : 'none';
   });

   const apiKeyInput = document.querySelector('.aiassist-api-key');
   if (apiKeyInput) {
      const placeholder = provider === '<?php echo PluginNextoolAiassist::PROVIDER_GEMINI; ?>'
         ? apiKeyInput.dataset.geminiPlaceholder
         : apiKeyInput.dataset.openaiPlaceholder;
      if (placeholder) {
         apiKeyInput.placeholder = placeholder;
      }
   }

   const providerDefaults = {
      '<?php echo PluginNextoolAiassist::PROVIDER_OPENAI; ?>': '<?php echo $providers[PluginNextoolAiassist::PROVIDER_OPENAI]['default_model']; ?>',
      '<?php echo PluginNextoolAiassist::PROVIDER_GEMINI; ?>': '<?php echo $providers[PluginNextoolAiassist::PROVIDER_GEMINI]['default_model']; ?>'
   };

   document.querySelectorAll('.aiassist-model-select').forEach((select) => {
      let hasValidSelection = false;
      Array.from(select.options).forEach((option) => {
         const optionProvider = option.getAttribute('data-provider');
         if (!optionProvider) {
            option.hidden = false;
            return;
         }
         const isMatch = optionProvider === provider;
         option.hidden = !isMatch;
         if (isMatch && option.selected) {
            hasValidSelection = true;
         }
      });

      if (!hasValidSelection) {
         if (select.dataset.modelScope === 'default') {
            const defaultValue = providerDefaults[provider] || '';
            if (defaultValue) {
               select.value = defaultValue;
            }
         } else {
            select.value = '';
         }
      }
   });
}

document.addEventListener('DOMContentLoaded', function () {
   const providerRadios = document.querySelectorAll('input[name="provider_mode"]');
   providerRadios.forEach((radio) => {
      radio.addEventListener('change', aiassistUpdateProviderModeUI);
   });
   aiassistUpdateProviderModeUI();

   const providerSelect = document.querySelector('.aiassist-provider-select');
   if (providerSelect) {
      providerSelect.addEventListener('change', aiassistUpdateProviderUI);
   }
   aiassistUpdateProviderUI();

   // Atualiza badges de status das funcionalidades
   function updateFeatureStatus(feature, enabled) {
      const statusEl = document.getElementById('feature_' + feature + '_status');
      if (statusEl) {
         statusEl.textContent = enabled ? '<?php echo addslashes(__('Ativo', 'nextool')); ?>' : '<?php echo addslashes(__('Inativo', 'nextool')); ?>';
         statusEl.className = 'badge ' + (enabled ? 'bg-success' : 'bg-secondary');
      }
   }

   const featureSwitches = {
      'summary': document.getElementById('feature_summary_enabled'),
      'reply': document.getElementById('feature_reply_enabled'),
      'sentiment': document.getElementById('feature_sentiment_enabled')
   };

   Object.keys(featureSwitches).forEach(function(feature) {
      const switchEl = featureSwitches[feature];
      if (switchEl) {
         switchEl.addEventListener('change', function() {
            updateFeatureStatus(feature, this.checked);
         });
      }
   });
});
</script>
