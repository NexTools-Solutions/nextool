<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - BaseModule
 * -------------------------------------------------------------------------
 * Classe abstrata base para todos os módulos do NexTool Solutions.
 * Todos os módulos devem estender esta classe e implementar seus métodos
 * abstratos. Esta classe define a interface padrão que todos os módulos
 * devem seguir.
 * -------------------------------------------------------------------------
 * @abstract
 * @author    Richard Loureiro
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

abstract class PluginNextoolBaseModule {

   /**
    * Nome único do módulo (chave de identificação)
    * Deve ser único, sem espaços, lowercase
    * Exemplo: 'emailtools', 'reporttools', 'customfields'
    * 
    * @return string Nome único do módulo
    */
   abstract public function getModuleKey();

   /**
    * Nome amigável do módulo (exibido na interface)
    * Exemplo: 'Email Tools', 'Report Tools', 'Custom Fields'
    * 
    * @return string Nome amigável
    */
   abstract public function getName();

   /**
    * Descrição do módulo (exibida na interface)
    * Breve descrição do que o módulo faz
    * 
    * @return string Descrição
    */
   abstract public function getDescription();

   /**
    * Versão do módulo
    * Usar semantic versioning (X.Y.Z)
    * 
    * @return string Versão
    */
   abstract public function getVersion();

   /**
    * Ícone do módulo (classe Tabler Icons)
    * Exemplo: 'ti ti-mail', 'ti ti-report', 'ti ti-tool'
    * Lista completa: https://tabler-icons.io/
    * 
    * @return string Classe do ícone
    */
   abstract public function getIcon();

   /**
    * Autor do módulo
    * 
    * @return string Nome do autor
    */
   abstract public function getAuthor();

   /**
    * Retorna o billing tier para fins de licenciamento (FREE/PAID/...).
    *
    * @return string
    */
   public function getBillingTier() {
      return 'FREE';
   }

   /**
    * Instalação do módulo
    * Cria tabelas, insere dados iniciais, etc.
    * 
    * @return bool True se instalou com sucesso
    */
   abstract public function install();

   /**
    * Desinstalação do módulo.
    *
    * A desinstalação não remove dados persistidos: o objetivo é apenas
    * desregistrar hooks/configurações e deixar as tabelas intactas para
    * reinstalações futuras. Use o botão "Apagar dados" (purgeData) quando
    * for necessário dropar as tabelas.
    *
    * REGRA CRÍTICA: NUNCA acionar uninstall.sql neste método. Executar
    * uninstall.sql é ação exclusiva do botão "Apagar dados" (purgeData).
    *
    * @return bool True se desinstalou com sucesso
    */
   public function uninstall() {
      return true;
   }

   /**
    * Executa processos de upgrade entre versões.
    * Por padrão, reutiliza install() para garantir idempotência, mas módulos
    * podem sobrescrever para aplicar migrations específicas.
    *
    * @param string|null $currentVersion
    * @param string|null $targetVersion
    * @return bool
    */
   public function upgrade(?string $currentVersion, ?string $targetVersion) {
      return $this->install();
   }

   /**
    * Remove dados persistidos do módulo (DROP TABLE, limpeza de registros, etc.).
    * Usado pelo botão "Apagar dados" após o módulo ser desinstalado.
    * 
    * @return bool
    */
   public function purgeData() {
      return $this->executeUninstallSql();
   }

   /**
    * Verifica se o módulo tem página de configuração
    * 
    * @return bool True se tem página de configuração
    */
   public function hasConfig() {
      return false;
   }

   /**
    * Indica se o módulo usa página de configuração standalone (própria)
    * em vez de aparecer como aba dentro do painel do NexTool.
    *
    * Módulos que retornam true:
    * - NÃO aparecem como aba vertical no nextoolconfig.form.php
    * - O submenu em "Nextools" aponta diretamente para getConfigPage()
    * - O botão "Configurações" no card aponta para getConfigPage()
    *
    * @return bool True se usa página standalone, false para aba embutida (padrão)
    */
   public function usesStandaloneConfig() {
      return false;
   }

   /**
    * Retorna o caminho para a página de configuração do módulo
    * Só é chamado se hasConfig() retornar true
    * 
    * @return string|null Caminho relativo para página de config
    */
   public function getConfigPage() {
      return null;
   }

   /**
    * Verifica se o usuário pode editar as configurações do módulo
    * Usado nas páginas de configuração para habilitar/desabilitar campos
    * 
    * @return bool True se pode editar (UPDATE), False se apenas visualizar (READ)
    */
   public function canEditConfig() {
      if (!class_exists('PluginNextoolPermissionManager')) {
         return false;
      }
      return PluginNextoolPermissionManager::canManageModule($this->getModuleKey());
   }

   /**
    * Inicialização do módulo (chamado quando módulo está ativo)
    * Use este método para registrar hooks, adicionar itens ao menu, etc.
    * 
    * @return void
    */
   public function onInit() {
      // Implementação opcional nos módulos filhos
   }

   /**
    * Providers de hooks globais do GLPI (Search/MassiveActions/etc.).
    *
    * Por padrão, módulos não expõem providers.
    * Para implementar, sobrescreva e retorne uma lista de FQCNs (classes)
    * que implementam PluginNextoolHookProviderInterface.
    *
    * @return array<int,string>
    */
   public function getHookProviders(): array {
      return [];
   }

   /**
    * Retorna as tabelas de dados do módulo (usadas para purge/auditoria).
    *
    * O ModuleManager usa este método para descobrir quais tabelas pertencem
    * ao módulo, eliminando a necessidade de hardcoding no core.
    * Sobrescreva no módulo retornando a lista de tabelas criadas pelo install.sql.
    *
    * @return string[] Lista de nomes de tabelas (ex: ['glpi_plugin_nextool_[modulo]_config'])
    */
   public function getDataTables(): array {
      return [];
   }

   /**
    * Retorna a lista de arquivos AJAX stateless (sem sessão/login) do módulo.
    *
    * Módulos com endpoints públicos (webhooks, aprovações por e-mail, etc.)
    * devem sobrescrever e retornar os nomes dos arquivos em ajax/ que não
    * requerem autenticação. O core usa esta informação para:
    * 1. Registrar rotas stateless no SessionManager do GLPI (boot)
    * 2. Decidir se inclui includes.php no roteador AJAX
    *
    * @return string[] Lista de arquivos (ex: ['webhook.php', 'approve.php'])
    */
   public function getStatelessFiles(): array {
      return [];
   }

   /**
    * Retorna registro de menu para o plugin base registrar no GLPI (hook menu_toadd).
    * Módulos que desejam adicionar um menu na barra principal devem sobrescrever e
    * retornar ['key' => string, 'class' => string]. A classe deve implementar
    * getMenuName() e getMenuContent().
    *
    * @return array{key: string, class: string}|null null se o módulo não possui menu
    */
   public function getMenuRegistration(): ?array {
      return null;
   }

   /**
    * Retorna itens de menu adicionais para o hook redefine_menus.
    *
    * Módulos que precisam de menu de primeiro nível na sidebar (fora do
    * submenu "Nextools") devem sobrescrever e retornar um array com a
    * estrutura do menu GLPI. O core itera sobre módulos ativos e injeta
    * os menus retornados.
    *
    * @return array|null null se não possui menu adicional, ou array com estrutura:
    *   ['menu_key' => string, 'menu' => [...estrutura GLPI...]]
    */
   public function getRedefineMenuItems(): ?array {
      return null;
   }

   /**
    * Hook executado após ativação do módulo.
    *
    * @return void
    */
   public function onEnable() {
      // Implementação opcional nos módulos filhos
   }

   /**
    * Hook executado após desativação do módulo.
    *
    * @return void
    */
   public function onDisable() {
      // Implementação opcional nos módulos filhos
   }

   /**
    * Verifica se o módulo tem dependências
    * 
    * @return array Lista de módulos necessários (module_key)
    */
   public function getDependencies() {
      return [];
   }

   /**
    * Verifica pré-requisitos do módulo
    * Pode verificar extensões PHP, outras configurações, etc.
    * 
    * @return array ['success' => bool, 'message' => string]
    */
   public function checkPrerequisites() {
      return [
         'success' => true,
         'message' => ''
      ];
   }

   /**
    * Retorna configuração padrão do módulo
    * Útil para inicializar configurações na instalação
    * 
    * @return array Configuração padrão
    */
   public function getDefaultConfig() {
      return [];
   }

   /**
    * Obtém configuração atual do módulo
    * 
    * @return array Configuração do módulo
    */
   public function getConfig() {
      global $DB;

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => ['module_key' => $this->getModuleKey()],
         'LIMIT' => 1
      ]);

      if (count($iterator)) {
         $data = $iterator->current();
         $config = json_decode($data['config'] ?? '{}', true);
         return $config ?: [];
      }

      return $this->getDefaultConfig();
   }

   /**
    * Salva configuração do módulo
    * 
    * @param array $config Configuração a salvar
    * @return bool True se salvou com sucesso
    */
   public function saveConfig($config) {
      global $DB;

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => ['module_key' => $this->getModuleKey()],
         'LIMIT' => 1
      ]);

      if (count($iterator)) {
         return $DB->update(
            'glpi_plugin_nextool_main_modules',
            [
               'config' => json_encode($config),
               'date_mod' => date('Y-m-d H:i:s')
            ],
            ['module_key' => $this->getModuleKey()]
         );
      }

      return false;
   }

   /**
    * Verifica se módulo está instalado
    * 
    * @return bool True se está instalado
    */
   public function isInstalled() {
      global $DB;

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => [
            'module_key'   => $this->getModuleKey(),
            'is_installed' => 1
         ],
         'LIMIT' => 1
      ]);

      return count($iterator) > 0;
   }

   /**
    * Verifica se módulo está ativo
    * 
    * @return bool True se está ativo
    */
   public function isEnabled() {
      global $DB;

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => [
            'module_key' => $this->getModuleKey(),
            'is_enabled' => 1
         ],
         'LIMIT' => 1
      ]);

      return count($iterator) > 0;
   }

   /**
    * Retorna caminho físico base do módulo
    * Detecta automaticamente se está na nova estrutura (modules/[nome]/) ou antiga (inc/modules/[nome]/)
    * 
    * @return string Caminho físico completo do diretório do módulo
    */
   protected function getModulePath() {
      $reflection = new ReflectionClass($this);
      $classFile = $reflection->getFileName();
      $classDir = dirname($classFile);
      
      // Se arquivo está em [modulo]/inc/[modulo].class.php (nova estrutura)
      // Volta 2 níveis: inc/ -> [modulo]/
      if (basename($classDir) === 'inc') {
         return dirname($classDir);
      }
      
      // Se arquivo está em inc/modules/[modulo]/[modulo].class.php (estrutura antiga)
      // O diretório atual já é o módulo
      return $classDir;
   }

   /**
    * Retorna caminho web base do módulo
    * Usa a nova estrutura se disponível, senão usa estrutura antiga
    * 
    * @return string Caminho web relativo ao plugin
    */
   protected function getModuleWebPath() {
      // Path lógico para URL (módulos podem estar em files/_plugins/nextool/modules/)
      return Plugin::getWebDir('nextool') . '/modules/' . $this->getModuleKey();
   }

   /**
    * Retorna caminho web para arquivo front-end do módulo
    * 
    * Usa o roteador central em front/modules.php para evitar problemas
    * com o roteamento do Symfony no GLPI 11.
    * 
    * @param string $filename Nome do arquivo (ex: 'helloworld.php')
    * @return string Caminho web completo através do roteador
    */
   protected function getFrontPath($filename) {
      $moduleKey = $this->getModuleKey();
      
      // Usa roteador central para evitar problemas com Symfony
      // Formato: /plugins/nextool/front/modules.php?module=[key]&file=[filename]
      return Plugin::getWebDir('nextool') . '/front/modules.php?module=' . urlencode($moduleKey) . '&file=' . urlencode($filename);
   }

   /**
    * Retorna caminho web para arquivo AJAX do módulo
    * 
    * @param string $filename Nome do arquivo (ex: 'endpoint.php')
    * @return string Caminho web completo
    */
   protected function getAjaxPath($filename) {
      $modulePath = $this->getModulePath();
      // Roteador central para AJAX (funciona com módulos em files/_plugins/nextool/modules/)
      if (is_dir($modulePath . '/ajax')) {
         return Plugin::getWebDir('nextool') . '/ajax/module_ajax.php?module=' . urlencode($this->getModuleKey()) . '&file=' . urlencode($filename);
      }
      return Plugin::getWebDir('nextool') . '/ajax/modules/' . $filename;
   }

   /**
    * Retorna caminho web para arquivo CSS do módulo (através do roteador genérico)
    * 
    * Usa o roteador genérico em front/module_assets.php para evitar problemas
    * com o roteamento do Symfony no GLPI 11.
    * 
    * O roteador é genérico e funciona com qualquer módulo, não requer arquivos
    * específicos fora da pasta do módulo.
    * 
    * @param string $filename Nome do arquivo CSS.php (ex: '[module_key].css.php')
    * @return string Caminho web relativo ao plugin para uso em hooks do GLPI
    */
   protected function getCssPath($filename) {
      $moduleKey = $this->getModuleKey();
      
      // Usa roteador genérico module_assets.php
      // Formato: front/module_assets.php?module=[key]&file=[filename]
      // O roteador serve o arquivo CSS do módulo sem passar pelo roteamento do Symfony
      return 'front/module_assets.php?module=' . urlencode($moduleKey) . '&file=' . urlencode($filename);
   }

   /**
    * Retorna caminho web para arquivo JS do módulo (através do roteador genérico)
    * 
    * Usa o roteador genérico em front/module_assets.php para evitar problemas
    * com o roteamento do Symfony no GLPI 11.
    * 
    * O roteador é genérico e funciona com qualquer módulo, não requer arquivos
    * específicos fora da pasta do módulo.
    * 
    * @param string $filename Nome do arquivo JS.php (ex: '[module_key].js.php')
    * @return string Caminho web relativo ao plugin para uso em hooks do GLPI
    */
   protected function getJsPath($filename) {
      $moduleKey = $this->getModuleKey();
      
      // Usa roteador genérico module_assets.php
      // Formato: front/module_assets.php?module=[key]&file=[filename]
      // O roteador serve o arquivo JS do módulo sem passar pelo roteamento do Symfony
      return 'front/module_assets.php?module=' . urlencode($moduleKey) . '&file=' . urlencode($filename);
   }

   /**
    * Retorna caminho físico para arquivo CSS do módulo
    * 
    * @param string $filename Nome do arquivo (ex: 'style.css')
    * @return string Caminho físico completo
    */
   protected function getCssFilePath($filename) {
      $modulePath = $this->getModulePath();
      
      // Detecta estrutura
      if (is_dir($modulePath . '/css')) {
         // Nova estrutura: modules/[nome]/css/[arquivo]
         return $modulePath . '/css/' . $filename;
      } else {
         // Estrutura antiga: css/[arquivo]
         return GLPI_ROOT . '/plugins/nextool/css/' . $filename;
      }
   }

   /**
    * Retorna caminho físico para arquivo JS do módulo
    * 
    * @param string $filename Nome do arquivo (ex: 'script.js')
    * @return string Caminho físico completo
    */
   protected function getJsFilePath($filename) {
      $modulePath = $this->getModulePath();
      
      // Detecta estrutura
      if (is_dir($modulePath . '/js')) {
         // Nova estrutura: modules/[nome]/js/[arquivo]
         return $modulePath . '/js/' . $filename;
      } else {
         // Estrutura antiga: js/[arquivo]
         return GLPI_ROOT . '/plugins/nextool/js/' . $filename;
      }
   }

   /**
    * Retorna caminho físico para arquivo dentro do diretório inc/ do módulo
    * 
    * @param string $filename Nome do arquivo (ex: 'class.config.php', 'helper.php')
    * @return string Caminho físico completo
    */
   protected function getIncPath($filename) {
      $modulePath = $this->getModulePath();
      
      // Nova estrutura: modules/[nome]/inc/[arquivo]
      return $modulePath . '/inc/' . $filename;
   }

   /**
    * Retorna caminho físico para arquivo SQL do módulo
    * 
    * @param string $filename Nome do arquivo SQL (ex: 'install.sql', 'uninstall.sql')
    * @return string Caminho físico completo
    */
   protected function getSqlPath($filename) {
      $modulePath = $this->getModulePath();
      
      // Nova estrutura: modules/[nome]/sql/[arquivo]
      $sqlDir = $modulePath . '/sql';
      
      if (is_dir($sqlDir)) {
         return $sqlDir . '/' . $filename;
      }
      
      // Se não existe diretório sql/, retorna null
      return null;
   }

   /**
    * Executa um arquivo SQL do módulo
    * 
    * Lê o arquivo SQL, remove comentários de linha única (--),
    * divide em comandos por ponto-e-vírgula e executa cada um.
    * 
    * @param string $filename Nome do arquivo SQL (ex: 'install.sql')
    * @return bool True se executou com sucesso, False em caso de erro
    */
   protected function executeSqlFile($filename) {
      global $DB;

      $sqlPath = $this->getSqlPath($filename);
      
      if (!$sqlPath || !file_exists($sqlPath)) {
         // Arquivo não existe, não é erro (módulo pode não ter SQL)
         return true;
      }

      // GLPI 11: usar runFile do framework em vez de doQuery em SQL bruto
      return $DB->runFile($sqlPath);
   }

   /**
    * Executa arquivo install.sql do módulo (se existir)
    * 
    * Método helper para facilitar uso nos métodos install()
    * 
    * @return bool True se executou com sucesso ou arquivo não existe
    */
   protected function executeInstallSql() {
      return $this->executeSqlFile('install.sql');
   }

   /**
    * Executa arquivo uninstall.sql do módulo (se existir)
    * 
    * Método helper para facilitar uso nos métodos uninstall()
    * 
    * @return bool True se executou com sucesso ou arquivo não existe
    */
   protected function executeUninstallSql() {
      return $this->executeSqlFile('uninstall.sql');
   }
}

// Compatibilidade legado: alguns módulos antigos ainda estendem PluginRitectoolsBaseModule.
if (!class_exists('PluginRitectoolsBaseModule')) {
   abstract class PluginRitectoolsBaseModule extends PluginNextoolBaseModule {
   }
}


