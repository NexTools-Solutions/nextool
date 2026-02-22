<?php
/**
 * Nextools - Hook Provider Interface
 *
 * Contrato para módulos fornecerem integrações com hooks globais do GLPI
 * (Search/giveItem, MassiveActions, MassiveActionsFieldsDisplay). O plugin base
 * mantém as funções plugin_nextool_* e delega para providers dos módulos ativos.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

interface PluginNextoolHookProviderInterface {

   /**
    * Permite registrar classes no contexto do plugin (Plugin::registerClass),
    * quando necessário para Search/itemtypes de plugin.
    *
    * Deve ser seguro chamar mais de uma vez.
    *
    * @return void
    */
   public function registerClasses(): void;

   /**
    * Retorna ações em massa adicionais para um itemtype específico.
    *
    * @param string $type Itemtype alvo (ex.: PluginNextool[Module]Item)
    * @return array<string,string> action => label
    */
   public function getMassiveActions(string $type): array;

   /**
    * Renderiza campos específicos para MassiveAction "Atualizar" quando o core
    * não consegue renderizar corretamente para itemtypes de plugin.
    *
    * @param array $options
    * @return bool True se tratou, false para deixar o core tratar
    */
   public function massiveActionsFieldsDisplay(array $options = []): bool;

   /**
    * Hook giveItem para Search: permite tratar renderização de células e
    * evitar erros do core com itemtypes de plugin.
    *
    * @param string|null $itemtype
    * @param int $ID
    * @param array $data
    * @param int $num
    * @return string|false
    */
   public function giveItem($itemtype, $ID, $data, $num);
}

