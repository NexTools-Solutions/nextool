<?php
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Module Catalog
 * -------------------------------------------------------------------------
 * Manager de catálogo que usa o banco como fonte única da verdade.
 * As constantes PHP servem apenas para bootstrap inicial.
 * 
 * ARQUITETURA:
 * - ritecadmin → ContainerAPI → nextool (banco)
 * - Este manager lê de glpi_plugin_nextool_main_modules
 * - Fallback para BOOTSTRAP_MODULES apenas na primeira instalação
 * -------------------------------------------------------------------------
 * @author    Richard Loureiro
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolModuleCatalog {

   /**
    * Módulos de bootstrap (usados apenas na primeira instalação)
    * IMPORTANTE: Esta constante NÃO é a fonte da verdade!
    * A fonte oficial é glpi_plugin_nextool_main_modules (sincronizado via ContainerAPI)
    */
   private const BOOTSTRAP_MODULES = [
      'aiassist' => [
         'name'        => 'AI Assist',
         'description' => 'Utiliza IA para analisar o sentimento do solicitante, sugerir automaticamente a urgência mais adequada e gerar resumos claros dos chamados, agilizando a triagem e priorização de cada atendimento pela equipe de suporte.',
         'version'     => '1.5.0',
         'icon'        => 'ti ti-robot',
         'billing_tier'=> 'FREE',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'Richard Loureiro',
            'url'  => 'https://linkedin.com/in/richard-ti/',
         ],
      ],
      'autentique' => [
         'name'        => 'Autentique',
         'description' => 'Integra assinatura digital aos chamados, permitindo enviar documentos diretamente pelo sistema, controlar quem deve assinar e acompanhar em tempo real o status de cada assinatura até a conclusão do processo.',
         'version'     => '1.9.0',
         'icon'        => 'ti ti-signature',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'Richard Loureiro',
            'url'  => 'https://linkedin.com/in/richard-ti/',
         ],
      ],
      'mailinteractions' => [
         'name'        => 'Mail Interactions',
         'description' => 'Permite interações completas por e-mail, possibilitando que usuários aprovem solicitações, validem entregas e respondam pesquisas de satisfação diretamente da caixa de entrada, sem necessidade de acessar o sistema.',
         'version'     => '2.0.1',
         'icon'        => 'ti ti-mail',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'Richard Loureiro',
            'url'  => 'https://linkedin.com/in/richard-ti/',
         ],
      ],
      'mailanalyzer' => [
         'name'        => 'Mail Analyzer',
         'description' => 'Analisa conversas por e-mail, combina respostas relacionadas em um único ticket e evita duplicidades causadas por CC.',
         'version'     => '3.3.0',
         'icon'        => 'ti ti-mail',
         'billing_tier'=> 'FREE',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'Richard Loureiro',
            'url'  => 'https://linkedin.com/in/richard-ti/',
         ],
      ],
      'orderservice' => [
         'name'        => 'Ordem de Serviço',
         'description' => 'Gera a Ordem de Serviço em PDF a partir do chamado, com cabeçalho configurável e dados do prestador.',
         'version'     => '2.3.2',
         'icon'        => 'ti ti-file-type-pdf',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'Richard Loureiro',
            'url'  => 'https://linkedin.com/in/richard-ti/',
         ],
      ],
      'pendingsurvey' => [
         'name'        => 'Pending Survey',
         'description' => 'Exibe pop-ups alertando o usuário sobre pesquisas de satisfação pendentes e, opcionalmente, bloqueia a abertura de novos chamados quando a quantidade de pesquisas não respondidas ultrapassar o limite configurado (X).',
         'version'     => '1.0.1',
         'icon'        => 'ti ti-message-question',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'Richard Loureiro',
            'url'  => 'https://linkedin.com/in/richard-ti/',
         ],
      ],
      'smartassign' => [
         'name'        => 'Smart Assign',
         'description' => 'Distribui novos chamados automaticamente entre os técnicos, aplicando regras de balanceamento de carga ou rodízio configurável, para evitar sobrecarga em alguns atendentes e garantir um fluxo de trabalho mais equilibrado.',
         'version'     => '1.2.0',
         'icon'        => 'ti ti-user-check',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'Richard Loureiro',
            'url'  => 'https://linkedin.com/in/richard-ti/',
         ],
      ],
      'helloworld' => [
         'name'        => 'Hello World (PoC)',
         'description' => 'Demonstração da distribuição remota de módulos via ContainerAPI.',
         'version'     => '1.0.1',
         'icon'        => 'ti ti-code',
         'billing_tier'=> 'FREE',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'Richard Loureiro',
            'url'  => 'https://linkedin.com/in/richard-ti/',
         ],
      ],
      'geolocation' => [
         'name'        => 'Geolocalização',
         'description' => 'Captura e registra a localização geográfica do usuário ao adicionar acompanhamentos ou soluções em tickets.',
         'version'     => '1.0.2',
         'icon'        => 'ti ti-map-pin',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'NexTool Solutions',
            'url'  => 'https://nextoolsolutions.ai',
         ],
      ],
      'template' => [
         'name'        => 'Template Module',
         'description' => 'Módulo template base para criação de novos módulos do NexTool.',
         'version'     => '1.1.0',
         'icon'        => 'ti ti-template',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'NexTool Solutions',
            'url'  => 'https://nextoolsolutions.ai',
         ],
      ],
      'signaturepad' => [
         'name'        => 'Assinatura Manual (PDF)',
         'description' => 'Carrega um PDF padrão, gera link interno de assinatura (mouse/touch) e salva um novo PDF assinado como documento no GLPI.',
         'version'     => '0.1.0',
         'icon'        => 'ti ti-signature',
         'billing_tier'=> 'PAID',
         'has_config'  => true,
         'downloadable'=> true,
         'author'      => [
            'name' => 'NexTool Solutions',
            'url'  => 'https://nextoolsolutions.ai',
         ],
      ],
   ];

   /**
    * Retorna todos os módulos do banco (fonte única da verdade)
    * Fallback para BOOTSTRAP_MODULES apenas se banco estiver vazio
    * 
    * @return array
    */
   public static function all(): array {
      global $DB;
      
      $table = 'glpi_plugin_nextool_main_modules';
      if (!$DB->tableExists($table)) {
         // Bootstrap: banco ainda não foi criado
         return self::BOOTSTRAP_MODULES;
      }

      $modules = [];
      $iterator = $DB->request([
         'FROM'  => $table,
         'ORDER' => 'name'
      ]);

      if (count($iterator) === 0) {
         // Bootstrap: banco vazio, usar constantes para primeiro acesso
         return self::BOOTSTRAP_MODULES;
      }

      foreach ($iterator as $row) {
         $moduleKey = $row['module_key'];
         
         // Pega metadados extras de BOOTSTRAP_MODULES se existir (ícone, descrição, author)
         $bootstrap = self::BOOTSTRAP_MODULES[$moduleKey] ?? [];
         
         $modules[$moduleKey] = [
            'name'         => $row['name'],
            'description'  => $row['description'] ?? ($bootstrap['description'] ?? ''),
            'version'      => $row['available_version'] ?? $row['version'], // Prioriza available_version
            'icon'         => $bootstrap['icon'] ?? 'ti ti-puzzle',
            'billing_tier' => $row['billing_tier'] ?? 'FREE',
            'has_config'   => $bootstrap['has_config'] ?? true,
            'downloadable' => $bootstrap['downloadable'] ?? true,
            'author'       => $bootstrap['author'] ?? [
               'name' => 'NexTool Solutions',
               'url'  => 'https://nextoolsolutions.ai',
            ],
            // Dados do banco (runtime)
            'is_installed'  => (bool)($row['is_installed'] ?? 0),
            'is_enabled'    => (bool)($row['is_enabled'] ?? 0),
            'is_available'  => (bool)($row['is_available'] ?? 0),
         ];
      }

      return $modules;
   }

   /**
    * Busca um módulo específico pelo module_key
    * 
    * @param string $moduleKey
    * @return array|null
    */
   public static function find(string $moduleKey): ?array {
      $all = self::all();
      return $all[$moduleKey] ?? null;
   }

   /**
    * Retorna módulos de bootstrap (apenas para uso interno)
    * 
    * @return array
    */
   public static function getBootstrapModules(): array {
      return self::BOOTSTRAP_MODULES;
   }
}


