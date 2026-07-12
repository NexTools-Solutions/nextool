<?php
declare(strict_types=1);
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
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
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
    * Cache em memória da versão lida do module.json (evita I/O repetido).
    */
   private ?string $cachedVersion = null;

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
    * Versão do módulo, lida do module.json (fonte única de verdade).
    *
    * Módulos NÃO devem sobrescrever este método nem declarar versão hardcoded em
    * PHP - o module.json é o único lugar onde a versão vive. O pipeline de release
    * atualiza apenas o module.json e tudo flui daí. Sobrescrever só é justificável
    * para módulos com lógica de versionamento dinâmica (raro).
    *
    * @return string Versão semântica (X.Y.Z) ou string vazia se module.json ausente/inválido
    */
   public function getVersion() {
      if ($this->cachedVersion !== null) {
         return $this->cachedVersion;
      }
      $manifestPath = $this->getModulePath() . '/module.json';
      if (!is_file($manifestPath)) {
         $this->cachedVersion = '';
         return '';
      }
      $raw = @file_get_contents($manifestPath);
      if ($raw === false) {
         $this->cachedVersion = '';
         return '';
      }
      $data = json_decode($raw, true);
      if (!is_array($data) || !isset($data['version']) || !is_string($data['version'])) {
         $this->cachedVersion = '';
         return '';
      }
      $this->cachedVersion = $data['version'];
      return $this->cachedVersion;
   }

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
    *
    * Roda upgrade.sql (se existir) antes de delegar para install(). O upgrade.sql
    * é idempotente por convenção (ALTER TABLE MODIFY, ADD COLUMN IF NOT EXISTS, etc.)
    * e roda sempre que o módulo é atualizado, independente das versões $from/$to.
    * Para migrations destrutivas raras, sobrescreva este método no módulo.
    *
    * @param string|null $currentVersion
    * @param string|null $targetVersion
    * @return bool
    */
   public function upgrade(?string $currentVersion, ?string $targetVersion) {
      $upgradeSql = $this->getSqlPath('upgrade.sql');
      if ($upgradeSql !== null && file_exists($upgradeSql)) {
         if (!$this->executeSqlFile('upgrade.sql')) {
            return false;
         }
      }
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
      // Gate de EDITAR a config = bit CONFIGURE (usado no render p/ disabled dos campos).
      // NAO gateia toggle/gerenciar itens: essas acoes tem os proprios bits (TOGGLE,
      // MANAGE_ITEMS) checados nos handlers - nunca condicionar aquelas ao CONFIGURE.
      return PluginNextoolPermissionManager::canAdmin(
         $this->getModuleKey(),
         PluginNextoolPermissionManager::CONFIGURE
      );
   }

   /**
    * Verifica se o usuário pode ATIVAR/DESATIVAR o módulo (o toggle).
    * Gate = bit TOGGLE, distinto de CONFIGURE. Deve gatear o RENDER do botão
    * "Ativar/Desativar" nos config.tab -- o handler `toggle_enabled` já exige
    * TOGGLE via assertCanAdmin, então render e handler ficam alinhados.
    *
    * @return bool
    */
   public function canToggle() {
      if (!class_exists('PluginNextoolPermissionManager')) {
         return false;
      }
      return PluginNextoolPermissionManager::canAdmin(
         $this->getModuleKey(),
         PluginNextoolPermissionManager::TOGGLE
      );
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
    * Registra o domínio gettext do módulo (nextool_{moduleKey}).
    * Chamado automaticamente pelo ModuleManager antes de onInit().
    * Procura .mo em {modulePath}/locales/{lang}.mo
    *
    * @return void
    */
   public function loadModuleLang(): void {
      global $CFG_GLPI, $TRANSLATE;

      if (empty($TRANSLATE)) {
         return;
      }

      $localesDir = $this->getModulePath() . '/locales';
      if (!is_dir($localesDir)) {
         return;
      }

      $domain = 'nextool_' . $this->getModuleKey();
      $lang   = $_SESSION['glpilanguage'] ?? $CFG_GLPI['language'] ?? 'en_GB';

      // Resolver nome do arquivo .mo (mesmo padrão do Plugin::loadLang)
      $mofile = null;
      if (isset($CFG_GLPI['languages'][$lang])) {
         $candidate = $localesDir . '/' . $CFG_GLPI['languages'][$lang][1];
         if (file_exists($candidate)) {
            $mofile = $candidate;
         }
      }
      if ($mofile === null && file_exists($localesDir . '/' . $lang . '.mo')) {
         $mofile = $localesDir . '/' . $lang . '.mo';
      }
      if ($mofile === null && file_exists($localesDir . '/en_GB.mo')) {
         $mofile = $localesDir . '/en_GB.mo';
      }

      if ($mofile !== null) {
         $TRANSLATE->addTranslationFile('gettext', $mofile, $domain, $lang);
      }
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
    * Indica se o módulo tem catálogo próprio (CRUD de itens de configuração).
    * Quando true, a coluna "Gerenciar itens" aparece na matriz de administração.
    * Módulos com registros próprios (contratos, instâncias, sinônimos...) sobrescrevem.
    *
    * @return bool
    */
   public function hasCatalog(): bool {
      return false;
   }

   /**
    * Declara os bits de USABILIDADE deste módulo (colunas à esquerda na matriz, azuis).
    * Ver spec normativa: plugins/nextool/PERMISSIONS.md.
    *
    * Default = apenas ACCESS ("Acessar o recurso"). Cada módulo sobrescreve ESTE método
    * para adicionar suas ações de uso (faixa 1<<10 .. 1<<19) e/ou renomear o rótulo do
    * ACCESS, aplicando a regra do "Ver" (nunca genérico). A ordem declarada aqui é a
    * ordem das colunas de usabilidade na tela.
    *
    *   public const REGISTER = 1 << 10;
    *   public function getUsabilityRights(): array {
    *      return [
    *         PluginNextoolPermissionManager::ACCESS => __('Ver estoque', 'nextool_x'),
    *         self::REGISTER => __('Registrar saída', 'nextool_x'),
    *      ];
    *   }
    *
    * @return array<int,string|array{short:string,long:string}> Mapa bit => rótulo
    */
   public function getUsabilityRights(): array {
      return [PluginNextoolPermissionManager::ACCESS => __('Acessar o recurso', 'nextool')];
   }

   /**
    * Colunas (bits) do direito deste módulo na tela de perfil (formato P1):
    * USABILIDADE (esquerda, azul) + ADMINISTRAÇÃO fixa (direita, âmbar).
    * Ver spec normativa: plugins/nextool/PERMISSIONS.md.
    *
    * NÃO sobrescreva este método: declare a usabilidade em getUsabilityRights() e,
    * se o módulo tem catálogo próprio, hasCatalog(). A ordem uso|admin e o contrato
    * de administração (Configurar, [Gerenciar itens], Ativar/Desativar, Atualizar,
    * Desinstalar, Ver logs, Apagar dados) ficam garantidos aqui para os 36 módulos.
    *
    * @return array<int,string|array{short:string,long:string}> Mapa bit => rótulo
    */
   public function getProfileRights(): array {
      return $this->getUsabilityRights()
         + PluginNextoolPermissionManager::getAdminRightLabels($this->hasCatalog());
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

   /** @var array<string, array> Cache per-request de getConfig() por module_key (invalidado em saveConfig). */
   private static $configCache = [];

   /**
    * Obtém configuração atual do módulo
    *
    * Memoizado por request: getConfig() é chamado em múltiplos pontos do mesmo
    * request (onInit, assets .js.php, handlers AJAX) - sem cache, cada chamada
    * repete o mesmo SELECT em glpi_plugin_nextool_main_modules. Mesmo padrão do
    * getEnabledFeaturesCache do módulo fixes. Invalidado em saveConfig().
    *
    * @return array Configuração do módulo
    */
   public function getConfig() {
      global $DB;

      $moduleKey = $this->getModuleKey();
      if (array_key_exists($moduleKey, self::$configCache)) {
         return self::$configCache[$moduleKey];
      }

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => ['module_key' => $moduleKey],
         'LIMIT' => 1
      ]);

      $defaults = $this->getDefaultConfig();

      if (count($iterator)) {
         $data = $iterator->current();
         $config = json_decode($data['config'] ?? '{}', true);
         // Mescla os defaults POR BAIXO do config persistido: chaves novas do
         // getDefaultConfig() (ex.: poll_enabled/sync_status adicionadas depois do
         // primeiro save) passam a valer o default em vez de ficarem silenciosamente
         // OFF. O config salvo continua sobrescrevendo os defaults (inclusive um 0
         // explicito, que o usuario setou de proposito, prevalece).
         return self::$configCache[$moduleKey] = array_merge($defaults, is_array($config) ? $config : []);
      }

      return self::$configCache[$moduleKey] = $defaults;
   }

   /**
    * Salva configuração do módulo.
    *
    * Por padrão faz MERGE (PATCH): as chaves passadas em $config sobrescrevem as
    * persistidas e as chaves NÃO passadas permanecem intactas. Isso corrige a perda
    * de dados quando o módulo tem varias abas/handlers que salvam subconjuntos
    * disjuntos da config (antes, salvar uma aba zerava as chaves da outra porque o
    * json_encode substituía a coluna inteira). O merge é feito sobre o config
    * PERSISTIDO cru, nao sobre getConfig(), para nao congelar os defaults no banco -
    * a semântica de "defaults por baixo" do getConfig() e preservada.
    *
    * @param array $config  Configuração a salvar (parcial ou completa)
    * @param bool  $replace true substitui a config inteira (comportamento antigo).
    *                       Default false (merge). Nenhum handler atual precisa de
    *                       replace; o parâmetro existe como válvula de escape.
    * @return bool True se salvou com sucesso
    */
   public function saveConfig($config, $replace = false) {
      global $DB;

      if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         return false;
      }

      // Invalida o cache per-request de getConfig() - a próxima leitura
      // reflete imediatamente o que foi salvo neste mesmo request.
      unset(self::$configCache[$this->getModuleKey()]);

      $iterator = $DB->request([
         'FROM'  => 'glpi_plugin_nextool_main_modules',
         'WHERE' => ['module_key' => $this->getModuleKey()],
         'LIMIT' => 1
      ]);

      $now = date('Y-m-d H:i:s');
      if (count($iterator)) {
         if (!$replace) {
            // Merge sobre o persistido cru: preserva chaves de outras abas/handlers.
            $row       = $iterator->current();
            $persisted = json_decode($row['config'] ?? '{}', true);
            $config    = array_merge(is_array($persisted) ? $persisted : [], $config);
         }
         return $DB->update(
            'glpi_plugin_nextool_main_modules',
            [
               'config' => json_encode($config),
               'date_mod' => $now
            ],
            ['module_key' => $this->getModuleKey()]
         );
      }

      // Sem registro: cria linha para persistir a config (ex.: módulo acessado antes do install/catálogo)
      return $DB->insert(
         'glpi_plugin_nextool_main_modules',
         [
            'module_key'         => $this->getModuleKey(),
            'name'               => $this->getName(),
            'config'             => json_encode($config),
            'is_installed'       => 0,
            'is_enabled'         => 0,
            'is_available'       => 0,
            'billing_tier'        => $this->getBillingTier(),
            'date_creation'      => $now,
            'date_mod'           => $now,
         ]
      );
   }

   /**
    * Verifica se módulo está instalado
    * 
    * @return bool True se está instalado
    */
   public function isInstalled() {
      return PluginNextoolModuleManager::getInstance()->isInstalled($this->getModuleKey());
   }

   /**
    * Verifica se módulo está ativo.
    * Delega ao ModuleManager para reuso do moduleRowCache.
    *
    * @return bool True se está ativo
    */
   public function isEnabled() {
      return PluginNextoolModuleManager::getInstance()->isEnabled($this->getModuleKey());
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
   protected function getCssPath($filename, array $extraFactors = []) {
      $moduleKey = $this->getModuleKey();

      // Usa roteador genérico module_assets.php
      // Formato: front/module_assets.php?module=[key]&file=[filename]
      // O roteador serve o arquivo CSS do módulo sem passar pelo roteamento do Symfony
      return 'front/module_assets.php?module=' . urlencode($moduleKey) . '&file=' . urlencode($filename) . '&fv=' . $this->getAssetFv($filename, $extraFactors);
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
   protected function getJsPath($filename, array $extraFactors = []) {
      $moduleKey = $this->getModuleKey();

      // Usa roteador genérico module_assets.php
      // Formato: front/module_assets.php?module=[key]&file=[filename]
      // O roteador serve o arquivo JS do módulo sem passar pelo roteamento do Symfony
      return 'front/module_assets.php?module=' . urlencode($moduleKey) . '&file=' . urlencode($filename) . '&fv=' . $this->getAssetFv($filename, $extraFactors);
   }

   /**
    * Stamp de cache-busting (`fv`) por filemtime do asset (em front/). Necessário porque o `v` que o
    * GLPI anexa às URLs de JS/CSS é a versão do PLUGIN -- NÃO muda em edição de runtime (dev) nem em
    * hotfix sem bump, então o browser/proxy/CDN serve a versão velha. O `fv` (mtime do arquivo) muda a
    * CADA edição do asset, então um deploy chega ao usuário no próximo carregamento de página, SEM
    * precisar de hard reload. Vale p/ todos os módulos (este helper é do BaseModule).
    *
    * $extraFactors: fatores ADICIONAIS de variação do conteúdo gerado (idioma, interface,
    * flags de config). Assets .js.php/.css.php que embutem strings i18n ou config DEVEM
    * incluir esses fatores - a URL passa a mudar quando o conteúdo muda, o que permite
    * `Cache-Control: max-age` longo sem servir conteúdo stale (padrão fixes HI-01).
    *
    * @param string $filename     Nome do arquivo (ex: '[module_key].js.php')
    * @param array  $extraFactors Fatores extras de variação (strings/escalares)
    * @return string stamp curto (12 hex)
    */
   protected function getAssetFv($filename, array $extraFactors = []) {
      $path = $this->getModulePath() . '/front/' . $filename;
      $ver  = method_exists($this, 'getVersion') ? (string) $this->getVersion() : '';
      $extra = empty($extraFactors) ? '' : '|' . implode('|', array_map('strval', $extraFactors));
      return substr(md5($ver . '|' . (@filemtime($path) ?: '0') . $extra), 0, 12);
   }

   /**
    * Fatores de variação por SESSÃO para assets que embutem strings i18n ou
    * dependem da interface/permissões do usuário: [idioma, interface, perfil].
    * O perfil ativo entra porque assets com gate de permissão server-side
    * (ex.: timeline-button) geram conteúdo diferente por perfil - trocar de
    * perfil muda a URL e invalida o cache do browser na hora.
    * Combinar com getAssetFv()/getJsPath() - ex.: getJsPath('x.js.php',
    * array_merge($this->getSessionAssetFactors(), [$flagDeConfig])).
    *
    * @return array{0: string, 1: string, 2: string}
    */
   protected function getSessionAssetFactors(): array {
      return [
         (string) ($_SESSION['glpilanguage'] ?? ''),
         (string) ($_SESSION['glpiactiveprofile']['interface'] ?? ''),
         (string) ($_SESSION['glpiactiveprofile']['id'] ?? ''),
      ];
   }

   /**
    * Gate de registro de asset por página: true se a REQUEST_URI atual contém
    * algum dos fragmentos informados. Permite registrar add_javascript/add_css
    * SOMENTE nas páginas onde o asset é usado (ex.: timeline button só em
    * ticket.form.php), evitando o bootstrap do request de asset em todas as
    * outras páginas. Os early-returns client-side dos JS continuam valendo
    * como cinto de segurança. Em contexto sem REQUEST_URI (CLI/cron) retorna
    * false - assets de página não fazem sentido lá.
    *
    * @param array $needles Fragmentos de URI (ex.: ['ticket.form.php', '/Ticket/'])
    * @return bool
    */
   protected function isRequestForPage(array $needles): bool {
      $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
      if ($uri === '') {
         return false;
      }
      foreach ($needles as $needle) {
         if ($needle !== '' && strpos($uri, (string) $needle) !== false) {
            return true;
         }
      }
      return false;
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

      // GLPI 11: usar runFile do framework em vez de doQuery em SQL bruto.
      // doQuery() lança RuntimeException em erro SQL e runFile() não captura --
      // sem este try/catch, qualquer statement inválido vira HTTP 500 bruto no
      // endpoint AJAX em vez de "Falha ao executar instalação" na UI.
      try {
         return $DB->runFile($sqlPath);
      } catch (\Throwable $e) {
         Toolbox::logInFile(
            'plugin_nextool',
            sprintf(
               '[SQL] Falha ao executar %s do módulo %s: %s',
               $filename,
               $this->getModuleKey(),
               $e->getMessage()
            ) . "\n"
         );
         return false;
      }
   }

   /**
    * Adiciona coluna se não existir (portável MySQL/MariaDB).
    *
    * `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` é extensão exclusiva do MariaDB;
    * MySQL 8 rejeita com erro 1064. Este helper faz o check via $DB->fieldExists()
    * e roda um ADD COLUMN simples -- funciona em ambos os SGBDs.
    *
    * @param string $table      Nome da tabela
    * @param string $column     Nome da coluna
    * @param string $definition Definição SQL da coluna (ex: "tinyint NOT NULL DEFAULT 1 AFTER `foo`")
    * @return bool True se a coluna existe ao final (criada agora ou já existente)
    */
   protected function addColumnIfNotExists(string $table, string $column, string $definition): bool {
      global $DB;

      if (!$DB->tableExists($table)) {
         return false;
      }
      if ($DB->fieldExists($table, $column, false)) {
         return true;
      }

      try {
         $DB->doQuery(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s',
            $table,
            $column,
            $definition
         ));
         return true;
      } catch (\Throwable $e) {
         Toolbox::logInFile(
            'plugin_nextool',
            sprintf('[SQL] addColumnIfNotExists(%s.%s) falhou: %s', $table, $column, $e->getMessage()) . "\n"
         );
         return false;
      }
   }

   /**
    * Remove coluna se existir (portável MySQL/MariaDB).
    *
    * @param string $table  Nome da tabela
    * @param string $column Nome da coluna
    * @return bool True se a coluna não existe ao final
    */
   protected function dropColumnIfExists(string $table, string $column): bool {
      global $DB;

      if (!$DB->tableExists($table) || !$DB->fieldExists($table, $column, false)) {
         return true;
      }

      try {
         $DB->doQuery(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
         return true;
      } catch (\Throwable $e) {
         Toolbox::logInFile(
            'plugin_nextool',
            sprintf('[SQL] dropColumnIfExists(%s.%s) falhou: %s', $table, $column, $e->getMessage()) . "\n"
         );
         return false;
      }
   }

   /**
    * Verifica se um índice existe na tabela (portável MySQL/MariaDB).
    *
    * O DBmysql do GLPI não expõe indexExists(); SHOW INDEX funciona em ambos.
    *
    * @param string $table   Nome da tabela
    * @param string $keyName Nome do índice
    * @return bool
    */
   protected function indexExists(string $table, string $keyName): bool {
      global $DB;

      try {
         $result = $DB->doQuery(sprintf(
            "SHOW INDEX FROM `%s` WHERE Key_name = '%s'",
            $table,
            $DB->escape($keyName)
         ));
         return $result && $DB->numrows($result) > 0;
      } catch (\Throwable $e) {
         return false;
      }
   }

   /**
    * Adiciona índice se não existir (portável MySQL/MariaDB).
    *
    * `ADD INDEX IF NOT EXISTS` é extensão exclusiva do MariaDB.
    *
    * @param string $table         Nome da tabela
    * @param string $keyName       Nome do índice
    * @param string $keyDefinition Definição completa (ex: "INDEX `idx_x` (`a`,`b`)" ou "UNIQUE KEY `uniq_y` (`c`)")
    * @return bool True se o índice existe ao final
    */
   protected function addKeyIfNotExists(string $table, string $keyName, string $keyDefinition): bool {
      global $DB;

      if (!$DB->tableExists($table)) {
         return false;
      }
      if ($this->indexExists($table, $keyName)) {
         return true;
      }

      try {
         $DB->doQuery(sprintf('ALTER TABLE `%s` ADD %s', $table, $keyDefinition));
         return true;
      } catch (\Throwable $e) {
         Toolbox::logInFile(
            'plugin_nextool',
            sprintf('[SQL] addKeyIfNotExists(%s.%s) falhou: %s', $table, $keyName, $e->getMessage()) . "\n"
         );
         return false;
      }
   }

   /**
    * Remove índice se existir (portável MySQL/MariaDB).
    *
    * `DROP INDEX IF EXISTS` em ALTER TABLE é extensão exclusiva do MariaDB.
    *
    * @param string $table   Nome da tabela
    * @param string $keyName Nome do índice
    * @return bool True se o índice não existe ao final
    */
   protected function dropKeyIfExists(string $table, string $keyName): bool {
      global $DB;

      if (!$DB->tableExists($table) || !$this->indexExists($table, $keyName)) {
         return true;
      }

      try {
         $DB->doQuery(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $keyName));
         return true;
      } catch (\Throwable $e) {
         Toolbox::logInFile(
            'plugin_nextool',
            sprintf('[SQL] dropKeyIfExists(%s.%s) falhou: %s', $table, $keyName, $e->getMessage()) . "\n"
         );
         return false;
      }
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
    * Executa arquivo uninstall.sql do módulo (se existir).
    *
    * NUNCA chame este método em uninstall(). É exclusivo de purgeData().
    * O botão "Apagar dados" chama purgeData(), que chama este método e faz o
    * ModuleManager dropar as tabelas. Em uninstall() os dados devem permanecer.
    *
    * @return bool True se executou com sucesso ou arquivo não existe
    */
   protected function executeUninstallSql() {
      return $this->executeSqlFile('uninstall.sql');
   }
}
