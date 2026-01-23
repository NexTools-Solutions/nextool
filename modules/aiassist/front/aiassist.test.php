<?php
/**
 * Página de teste do AI Assist
 * Teste simples de comunicação com a IA
 */

// Define GLPI_ROOT se não estiver definido
if (!defined('GLPI_ROOT')) {
   define('GLPI_ROOT', dirname(__FILE__, 5));
   include_once(GLPI_ROOT . '/inc/includes.php');
}

Session::checkLoginUser();

// Carrega classes necessárias
require_once GLPI_ROOT . '/plugins/nextool/inc/modulemanager.class.php';
require_once GLPI_ROOT . '/plugins/nextool/inc/basemodule.class.php';

$manager = PluginNextoolModuleManager::getInstance();
$module = $manager->getModule('aiassist');

$response = '';
$error = '';

// Define variáveis que serão usadas tanto no processamento quanto na exibição
$settings = $module->getSettings();
$useProxy = ($settings['provider_mode'] ?? PluginNextoolAiassist::PROVIDER_MODE_DIRECT) === PluginNextoolAiassist::PROVIDER_MODE_PROXY;
$providerKey = $settings['provider'] ?? PluginNextoolAiassist::PROVIDER_OPENAI;

// Processa o formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_message'])) {
   // Debug de tokens CSRF
   $debugInfo = [
      'POST token' => $_POST['_glpi_csrf_token'] ?? 'NÃO ENVIADO',
      'SESSION token' => $_SESSION['_glpi_csrf_token'] ?? 'NÃO EXISTE',
      'Tokens iguais?' => (($_POST['_glpi_csrf_token'] ?? '') === ($_SESSION['_glpi_csrf_token'] ?? '')) ? 'SIM' : 'NÃO'
   ];
   
   // CSRF não validado por enquanto - aguardando correção da sessão
   // Session::checkCSRF($_POST);
   
   $testMessage = trim($_POST['test_message']);
   
   if (!empty($testMessage)) {
      try {
         // Testa a comunicação básica com a IA
         $provider = $module->getProviderInstance();
         
         if ($provider) {
            // Primeiro testa a conexão
            $connectionTest = $module->testProviderConnection();
            
            if ($connectionTest['success']) {
               // Tenta enviar mensagem de teste usando o método correto
               try {
                  $testResult = $provider->chat([
                     ['role' => 'user', 'content' => $testMessage]
                  ]);
                  
                  // Debug completo do resultado
                  $debugInfo['chat_result'] = $testResult;
                  
                  if (!empty($testResult['success']) && !empty($testResult['content'])) {
                     $response = $testResult['content'];
                  } elseif (!empty($testResult['error'])) {
                     $error = 'Erro do Provider: ' . $testResult['error'];
                  } else {
                     $error = 'Resposta vazia ou inválida da IA. Ver Debug abaixo.';
                  }
               } catch (Throwable $e) {
                  $error = 'Exception no chat(): ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine();
                  $debugInfo['chat_exception'] = [
                     'class' => get_class($e),
                     'message' => $e->getMessage(),
                     'file' => $e->getFile(),
                     'line' => $e->getLine()
                  ];
               }
            } else {
               $error = $connectionTest['message'] ?? 'Falha na conexão com provedor de IA';
            }
         } else {
            $error = 'Nenhum provedor de IA configurado ou ativado';
         }
      } catch (Exception $e) {
         $error = 'Erro: ' . $e->getMessage();
      } catch (Throwable $e) {
         $error = 'Erro fatal: ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine();
      }
   } else {
      $error = 'Digite uma mensagem para testar';
   }
}

Html::header(__('AI Assist - Teste', 'nextool'), $_SERVER['PHP_SELF'], 'config', 'plugins');

echo '<div class="container-fluid">';
echo '<div class="row">';
echo '<div class="col-12">';

// Card de Teste
echo '<div class="card mt-3">';
echo '<div class="card-header">';
echo '<h3>' . __('Teste de Comunicação com IA', 'nextool') . '</h3>';
echo '</div>';
echo '<div class="card-body">';

// Debug CSRF
if (isset($debugInfo)) {
   echo '<div class="alert alert-info"><strong>Debug CSRF:</strong><pre>';
   print_r($debugInfo);
   echo '</pre></div>';
}

// Mensagens
if (!empty($error)) {
   echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

if (!empty($response)) {
   echo '<div class="alert alert-success">';
   echo '<strong>Resposta da IA:</strong><br>';
   echo '<pre style="white-space: pre-wrap; background: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 10px;">';
   echo htmlspecialchars($response);
   echo '</pre>';
   echo '</div>';
}

// Formulário de teste - action corrigida para o roteador
$formAction = Plugin::getWebDir('nextool') . '/front/modules.php?module=aiassist&file=aiassist.test.php';
echo '<form method="post" action="' . $formAction . '">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';

echo '<div class="mb-3">';
echo '<label class="form-label">' . __('Mensagem de Teste', 'nextool') . '</label>';
echo '<textarea name="test_message" class="form-control" rows="5" placeholder="Digite uma mensagem para testar a comunicação com a IA...">';
echo htmlspecialchars($_POST['test_message'] ?? '');
echo '</textarea>';
echo '<small class="text-muted">Exemplo: "Olá, você pode me ajudar com um teste?"</small>';
echo '</div>';

echo '<button type="submit" class="btn btn-primary">';
echo '<i class="ti ti-send me-1"></i>' . __('Enviar Teste', 'nextool');
echo '</button>';

echo '<a href="' . Plugin::getWebDir('nextool') . '/front/modules.php?module=aiassist&file=aiassist.config.php" class="btn btn-secondary ms-2">';
echo '<i class="ti ti-arrow-left me-1"></i>' . __('Voltar', 'nextool');
echo '</a>';

echo '</form>';

echo '</div>'; // card-body
echo '</div>'; // card

// Card de Informações
echo '<div class="card mt-3">';
echo '<div class="card-header">';
echo '<h4>' . __('Informações do Sistema', 'nextool') . '</h4>';
echo '</div>';
echo '<div class="card-body">';

$provider = $module->getProviderInstance();

// Testa conexão e coleta informações de debug
$connectionTest = ['success' => false, 'message' => 'Não testado'];
$connectionDebug = [];

if ($provider) {
   try {
      $testSettings = $module->getSettings(['include_secret' => true]);
      $connectionDebug['api_key_length'] = isset($testSettings['api_key']) ? strlen($testSettings['api_key']) : 0;
      $connectionDebug['api_key_prefix'] = isset($testSettings['api_key']) ? substr($testSettings['api_key'], 0, 10) : 'vazio';
      $connectionDebug['model'] = $testSettings['model'] ?? 'não definido';
      $connectionDebug['endpoint'] = $module->getProviderEndpointForDisplay(
         $providerKey,
         $testSettings['model'] ?? $module->getDefaultModelForProvider($providerKey)
      );
      $connectionDebug['provider_mode'] = $testSettings['provider_mode'] ?? 'não definido';
      
      $connectionTest = $module->testProviderConnection();
      $connectionDebug['test_result'] = $connectionTest;
   } catch (Throwable $e) {
      $connectionTest = [
         'success' => false,
         'message' => 'Exception: ' . $e->getMessage()
      ];
      $connectionDebug['exception'] = get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine();
   }
} else {
   $connectionTest['message'] = 'Provider não existe';
}

$settingsWithSecret = $module->getSettings(['include_secret' => true]);
$apiKeyConfigured = !empty($settingsWithSecret['api_key']);
$apiKeySuffix = $apiKeyConfigured ? substr($settingsWithSecret['api_key'], -4) : '';

echo '<table class="table table-sm">';
echo '<tr><td><strong>Módulo Ativo:</strong></td><td>' . ($module->isEnabled() ? '✅ Sim' : '❌ Não') . '</td></tr>';
echo '<tr><td><strong>Provedor Configurado:</strong></td><td>' . ($provider ? '✅ Sim' : '❌ Não') . '</td></tr>';
echo '<tr><td><strong>Tipo de Provedor:</strong></td><td>' . Html::entities_deep($module->getProviderLabel($providerKey)) . '</td></tr>';
echo '<tr><td><strong>Modo:</strong></td><td>' . ($useProxy ? 'Proxy NexTool Solutions' : 'API Direta') . '</td></tr>';
echo '<tr><td><strong>API Key configurada:</strong></td><td>' . ($apiKeyConfigured ? '✅ Sim (***' . $apiKeySuffix . ')' : '❌ Não') . '</td></tr>';
echo '<tr><td><strong>Model:</strong></td><td>' . Html::entities_deep($settingsWithSecret['model'] ?? $module->getDefaultModelForProvider($providerKey)) . '</td></tr>';
echo '<tr><td><strong>Endpoint:</strong></td><td>' . Html::entities_deep($module->getProviderEndpointForDisplay($providerKey, $settingsWithSecret['model'] ?? $module->getDefaultModelForProvider($providerKey))) . '</td></tr>';
echo '<tr><td><strong>Teste de Conexão:</strong></td><td>' . ($connectionTest['success'] ? '✅ OK' : '❌ ' . ($connectionTest['message'] ?? 'Falhou')) . '</td></tr>';
echo '</table>';

// Debug detalhado da conexão
if (!empty($connectionDebug)) {
   echo '<div class="alert alert-info mt-3">';
   echo '<strong>Debug de Conexão:</strong>';
   echo '<pre style="font-size: 0.85rem;">' . print_r($connectionDebug, true) . '</pre>';
   echo '</div>';
}

echo '</div>';
echo '</div>';

echo '</div>'; // col
echo '</div>'; // row
echo '</div>'; // container

Html::footer();

