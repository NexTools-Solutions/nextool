<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Novidades da NexTool na página inicial do GLPI (#292)
 * -------------------------------------------------------------------------
 * Opção opt-in (desligada por padrão) da aba Novidades: ao ligar, cadastra pelo
 * caminho NATIVO do GLPI (Ferramentas > Feeds RSS) um feed público com o blog da
 * NexTool no idioma padrão do GLPI, visível a todos os perfis da interface central
 * em todas as entidades. O GLPI mostra feeds públicos na aba "Feeds RSS" da página
 * inicial central; o self-service não recebe o alvo e não vê.
 *
 * Só gerencia o que criou: guarda o id do feed em glpi_configs e nunca toca em
 * feeds cadastrados à mão. Ajustes que o admin fizer no feed (perfis, nome) não
 * são desfeitos. Desligar ou desinstalar o plugin apaga o feed criado.
 *
 * Rede: o GLPI baixa o feed pelo servidor (proxy do GLPI). Sem acesso, o feed é
 * cadastrado mesmo assim (decisão do owner, 2026-10-29) e a tela avisa; quando a
 * rede for liberada ele passa a funcionar sozinho.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolNewsFeed {

   public const CONFIG_CONTEXT = 'plugin:nextool_news_feed';

   private const SITE = 'https://nextoolsolutions.com';

   /** Caminho do feed por prefixo de idioma do GLPI; o resto cai no inglês. */
   private const FEED_PATHS = [
      'pt_BR' => '/feed.xml',
      'pt_PT' => '/pt-pt/feed.xml',
      'en'    => '/en/feed.xml',
      'es'    => '/es/feed.xml',
      'fr'    => '/fr/feed.xml',
      'it'    => '/it/feed.xml',
   ];

   /** URL do feed para um idioma do GLPI (ex.: `es_MX` -> `/es/feed.xml`; desconhecido -> inglês). */
   public static function feedUrlForLanguage(string $language): string {
      if (isset(self::FEED_PATHS[$language])) {
         return self::SITE . self::FEED_PATHS[$language];
      }
      $prefix = strtolower(substr($language, 0, 2));
      if ($prefix !== 'pt' && isset(self::FEED_PATHS[$prefix])) {
         return self::SITE . self::FEED_PATHS[$prefix];
      }
      if ($prefix === 'pt') {
         return self::SITE . self::FEED_PATHS['pt_PT'];
      }
      return self::SITE . self::FEED_PATHS['en'];
   }

   /** Id do feed criado pelo plugin, se ele ainda existir no GLPI (o admin pode tê-lo apagado à mão). */
   public static function getFeedId(): int {
      $id = (int) (Config::getConfigurationValues(self::CONFIG_CONTEXT, ['rssfeeds_id'])['rssfeeds_id'] ?? 0);
      if ($id <= 0) {
         return 0;
      }
      $feed = new RSSFeed();
      return $feed->getFromDB($id) ? $id : 0;
   }

   /**
    * Estado para a tela: ligado?, id, URL e se o GLPI conseguiu baixar o feed.
    *
    * @return array{enabled:bool, id:int, url:string, have_error:bool}
    */
   public static function getState(): array {
      $id = self::getFeedId();
      if ($id === 0) {
         return ['enabled' => false, 'id' => 0, 'url' => '', 'have_error' => false];
      }
      $feed = new RSSFeed();
      $feed->getFromDB($id);
      return [
         'enabled'    => true,
         'id'         => $id,
         'url'        => (string) ($feed->fields['url'] ?? ''),
         'have_error' => !empty($feed->fields['have_error']),
      ];
   }

   /**
    * Liga: cadastra o feed (se ainda não houver um criado pelo plugin) e o torna público
    * para os perfis centrais. Idempotente: ligar de novo não duplica.
    *
    * @return array{ok:bool, created:bool, have_error:bool, id:int}
    */
   public static function enable(): array {
      global $CFG_GLPI;

      $existing = self::getFeedId();
      if ($existing > 0) {
         $state = self::getState();
         return ['ok' => true, 'created' => false, 'have_error' => $state['have_error'], 'id' => $existing];
      }

      $feed = new RSSFeed();
      // O add nativo baixa o feed pelo servidor (proxy do GLPI) para preencher nome e descrição e
      // marca `have_error` se não conseguir; o dono é o usuário da sessão.
      $id = (int) $feed->add([
         'url'          => self::feedUrlForLanguage((string) ($CFG_GLPI['language'] ?? 'en_GB')),
         'is_active'    => 1,
         'refresh_rate' => DAY_TIMESTAMP,
         'max_items'    => 10,
      ]);
      if ($id <= 0) {
         return ['ok' => false, 'created' => false, 'have_error' => false, 'id' => 0];
      }
      Config::setConfigurationValues(self::CONFIG_CONTEXT, ['rssfeeds_id' => $id]);

      self::addCentralProfiles($id);

      $feed->getFromDB($id);
      return ['ok' => true, 'created' => true, 'have_error' => !empty($feed->fields['have_error']), 'id' => $id];
   }

   /** Desliga: apaga o feed que o plugin criou (os alvos de visibilidade saem junto, pelo purge do core). */
   public static function disable(): bool {
      $id = self::getFeedId();
      $ok = true;
      if ($id > 0) {
         $ok = (bool) (new RSSFeed())->delete(['id' => $id], true);
      }
      Config::deleteConfigurationValues(self::CONFIG_CONTEXT, ['rssfeeds_id']);
      return $ok;
   }

   /** Visível a todos os perfis da interface central, em todas as entidades (raiz + recursivo). */
   private static function addCentralProfiles(int $feedId): void {
      global $DB;

      $rows = $DB->request([
         'SELECT' => ['id'],
         'FROM'   => Profile::getTable(),
         'WHERE'  => ['interface' => 'central'],
      ]);
      foreach ($rows as $row) {
         (new Profile_RSSFeed())->add([
            'rssfeeds_id'  => $feedId,
            'profiles_id'  => (int) $row['id'],
            'entities_id'  => 0,
            'is_recursive' => 1,
         ]);
      }
   }
}
