<?php
declare(strict_types=1);
/**
 * Nextools - Anúncio antes do download de módulo gratuito (nextool-dev#283).
 *
 * O /validate entrega os anúncios vigentes (bloco `download_ads`); aqui eles são sanitizados e guardados em config, a
 * imagem é baixada da ContainerAPI uma vez por sha256 e servida pelo próprio plugin (o navegador nunca fala com a
 * NexTool), e as métricas (exibição, clique, contador concluído) são somadas localmente e vão agregadas no próximo
 * /validate, que devolve `download_ad_metrics_ack` para descontar o que foi enviado.
 *
 * Desenho: /data/relatorios/download-ads-2026-10-12/desenho.md (repo infra).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolDownloadAds {

   public const ADS_CONTEXT     = 'plugin:nextool_download_ads';
   public const METRICS_CONTEXT = 'plugin:nextool_download_ad_metrics';
   public const EVENTS          = ['impression' => 'impressions', 'click' => 'clicks', 'complete' => 'completions'];

   private const MAX_ADS          = 30;
   private const MAX_METRIC_ITEMS = 50;
   private const MAX_METRIC_VALUE = 1000;
   private const MAX_IMAGE_BYTES  = 524288;
   private const MIME_EXT         = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

   /**
    * Guarda os anúncios recebidos e sincroniza o cache de imagens. `null` (bloco ausente numa validação 200) = nenhum
    * anúncio vigente: limpa. Quem chama garante que é uma validação real (HTTP 200 com `valid`), nunca um 401/429/5xx.
    */
   public static function persist(?array $block, string $baseUrl = '', string $identifier = '', string $secret = ''): void {
      $ads = [];
      foreach (array_slice((array) ($block['ads'] ?? []), 0, self::MAX_ADS) as $raw) {
         if (is_array($raw) && ($ad = self::sanitize($raw)) !== null) {
            $ads[] = $ad;
         }
      }
      try {
         Config::setConfigurationValues(self::ADS_CONTEXT, PluginNextoolDbCompat::row([
            'ads'       => (string) json_encode($ads, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'synced_at' => (string) time(),
         ]));
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'DownloadAds: falha ao persistir os anúncios - ' . $e->getMessage());
         return;
      }
      self::syncImages($ads, $baseUrl, $identifier, $secret);
   }

   /** @return list<array<string, mixed>> anúncios guardados (já sanitizados) */
   public static function getAds(): array {
      $raw = (string) (Config::getConfigurationValues(self::ADS_CONTEXT, ['ads'])['ads'] ?? '');
      $ads = $raw !== '' ? json_decode($raw, true) : null;
      return is_array($ads) ? array_values(array_filter($ads, 'is_array')) : [];
   }

   /** Anúncio que vale para o módulo: o próprio do módulo, senão o geral, senão nenhum. */
   public static function resolveForModule(string $moduleKey): ?array {
      $default = null;
      foreach (self::getAds() as $ad) {
         if (in_array($moduleKey, (array) ($ad['modules'] ?? []), true)) {
            return $ad;
         }
         if ($default === null && !empty($ad['default'])) {
            $default = $ad;
         }
      }
      return $default;
   }

   /**
    * Dados para o popup da guia Módulos: só o que o JS precisa, com a URL local da imagem quando ela está em cache.
    *
    * @return list<array<string, mixed>>
    */
   public static function payloadForPage(): array {
      $out = [];
      foreach (self::getAds() as $ad) {
         $sha = (string) ($ad['image_sha256'] ?? '');
         $out[] = [
            'id'        => (int) $ad['id'],
            'title'     => (string) $ad['title'],
            'body'      => (string) $ad['body'],
            'cta_label' => $ad['cta_label'] ?? null,
            'cta_url'   => $ad['cta_url'] ?? null,
            'video_url' => !empty($ad['video_id']) ? 'https://www.youtube.com/watch?v=' . $ad['video_id'] : null,
            // Player embutido (youtube-nocookie) só depois do clique no play: até lá o navegador não fala com o Google.
            'video_id'  => !empty($ad['video_id']) ? (string) $ad['video_id'] : null,
            // Card do vídeo (formato do portfólio do site da NexSuite): título e visualizações embaixo da capa.
            'video_title' => !empty($ad['video_id']) ? ($ad['video_title'] ?? null) : null,
            'video_views' => !empty($ad['video_id']) ? ($ad['video_views'] ?? null) : null,
            'countdown' => (int) $ad['countdown'],
            'image_url' => ($sha !== '' && self::imagePath($sha) !== null)
               ? PluginNextoolCompat::webDir() . '/front/download_ad_image.php?sha=' . $sha
               : null,
            'default'   => !empty($ad['default']),
            'modules'   => array_values((array) ($ad['modules'] ?? [])),
         ];
      }
      return $out;
   }

   /** Caminho da imagem em cache (só sha de anúncio guardado e arquivo existente), ou null. */
   public static function imagePath(string $sha): ?string {
      if (!preg_match('/^[0-9a-f]{64}$/', $sha)) {
         return null;
      }
      foreach (self::getAds() as $ad) {
         if (($ad['image_sha256'] ?? '') === $sha && isset(self::MIME_EXT[$ad['image_mime'] ?? ''])) {
            $path = self::imageDir() . '/' . $sha . '.' . self::MIME_EXT[$ad['image_mime']];
            return is_file($path) ? $path : null;
         }
      }
      return null;
   }

   /** Mime da imagem em cache (para o Content-Type). */
   public static function imageMime(string $sha): ?string {
      foreach (self::getAds() as $ad) {
         if (($ad['image_sha256'] ?? '') === $sha) {
            return (string) $ad['image_mime'];
         }
      }
      return null;
   }

   /** Soma um evento do popup. Anúncio que não está guardado, módulo ou evento inválido: ignora (false). */
   public static function recordEvent(int $adId, string $moduleKey, string $event): bool {
      if (!isset(self::EVENTS[$event]) || !preg_match('/^[a-z0-9_-]{1,100}$/', $moduleKey)) {
         return false;
      }
      $known = false;
      foreach (self::getAds() as $ad) {
         if ((int) $ad['id'] === $adId) {
            $known = true;
            break;
         }
      }
      if (!$known) {
         return false;
      }
      $metrics = self::loadMetrics();
      $key = $adId . '|' . $moduleKey;
      $field = self::EVENTS[$event];
      $metrics[$key][$field] = min(self::MAX_METRIC_VALUE * 100, (int) ($metrics[$key][$field] ?? 0) + 1);
      return self::saveMetrics($metrics);
   }

   /**
    * Bloco `download_ad_metrics` do próximo /validate (no máximo 50 itens, cada contador até 1000; o resto fica para
    * a validação seguinte).
    *
    * @return list<array{ad_id:int, module_key:string, impressions:int, clicks:int, completions:int}>
    */
   public static function pendingMetrics(): array {
      $out = [];
      foreach (self::loadMetrics() as $key => $counts) {
         [$adId, $module] = array_pad(explode('|', (string) $key, 2), 2, '');
         $item = ['ad_id' => (int) $adId, 'module_key' => $module];
         foreach (self::EVENTS as $field) {
            $item[$field] = min(self::MAX_METRIC_VALUE, max(0, (int) ($counts[$field] ?? 0)));
         }
         if ($item['ad_id'] > 0 && $module !== '' && ($item['impressions'] + $item['clicks'] + $item['completions']) > 0) {
            $out[] = $item;
         }
         if (count($out) >= self::MAX_METRIC_ITEMS) {
            break;
         }
      }
      return $out;
   }

   /**
    * O servidor gravou: desconta o que foi enviado (subtrai, não zera, para não perder evento somado nesse meio-tempo).
    *
    * @param list<array<string, mixed>> $sent
    */
   public static function acknowledgeMetrics(array $sent): void {
      if ($sent === []) {
         return;
      }
      $metrics = self::loadMetrics();
      foreach ($sent as $item) {
         $key = (int) ($item['ad_id'] ?? 0) . '|' . (string) ($item['module_key'] ?? '');
         if (!isset($metrics[$key])) {
            continue;
         }
         foreach (self::EVENTS as $field) {
            $metrics[$key][$field] = max(0, (int) ($metrics[$key][$field] ?? 0) - (int) ($item[$field] ?? 0));
         }
         if (array_sum(array_map('intval', $metrics[$key])) === 0) {
            unset($metrics[$key]);
         }
      }
      self::saveMetrics($metrics);
   }

   /**
    * Só campos conhecidos, texto puro e cortado, link só https, vídeo só YouTube (reduzido ao id), contador 3..30,
    * imagem por sha256 com mime em lista. Anúncio sem título ou sem onde aparecer é descartado.
    */
   private static function sanitize(array $raw): ?array {
      $id = filter_var($raw['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      $title = self::text($raw['title'] ?? '', 120);
      if ($id === false || $title === '') {
         return null;
      }
      $modules = [];
      foreach ((array) ($raw['modules'] ?? []) as $m) {
         $m = strtolower(trim((string) $m));
         if (preg_match('/^[a-z0-9_-]{1,100}$/', $m) && !in_array($m, $modules, true)) {
            $modules[] = $m;
         }
      }
      $isDefault = !empty($raw['default']);
      if (!$isDefault && $modules === []) {
         return null;
      }
      $ctaUrl = self::httpsUrl($raw['cta_url'] ?? null);
      $sha = strtolower((string) ($raw['image']['sha256'] ?? ''));
      $mime = (string) ($raw['image']['mime'] ?? '');
      $hasImage = preg_match('/^[0-9a-f]{64}$/', $sha) === 1 && isset(self::MIME_EXT[$mime]);
      return [
         'id'           => $id,
         'title'        => $title,
         'body'         => self::text($raw['body'] ?? '', 600),
         'cta_label'    => $ctaUrl !== null ? (self::text($raw['cta_label'] ?? '', 40) ?: null) : null,
         'cta_url'      => $ctaUrl,
         'video_id'     => self::youtubeId($raw['video_url'] ?? null),
         'video_title'  => self::text($raw['video_title'] ?? '', 200) ?: null,
         'video_views'  => isset($raw['video_views']) && is_numeric($raw['video_views']) && (int) $raw['video_views'] >= 0
            ? (int) $raw['video_views'] : null,
         'countdown'    => max(3, min(30, (int) ($raw['countdown'] ?? 10))),
         'image_sha256' => $hasImage ? $sha : null,
         'image_mime'   => $hasImage ? $mime : null,
         'default'      => $isDefault,
         'modules'      => $isDefault ? [] : $modules,
      ];
   }

   private static function text(mixed $value, int $max): string {
      $value = is_string($value) ? $value : '';
      $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
      return mb_substr($value, 0, $max);
   }

   private static function httpsUrl(mixed $value): ?string {
      $value = trim((string) ($value ?? ''));
      if ($value === '' || strlen($value) > 500 || !preg_match('#^https://[^\s<>"\']+$#i', $value)) {
         return null;
      }
      return filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
   }

   private static function youtubeId(mixed $value): ?string {
      $value = trim((string) ($value ?? ''));
      if (preg_match('#^https://(?:www\.|m\.)?youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)([A-Za-z0-9_-]{11})(?:[&?\#].*)?$#', $value, $m)
          || preg_match('#^https://youtu\.be/([A-Za-z0-9_-]{11})(?:[?\#].*)?$#', $value, $m)) {
         return $m[1];
      }
      return null;
   }

   /**
    * Baixa as imagens que faltam (conferidas pelo sha256) e apaga as que nenhum anúncio usa mais. Falha numa imagem
    * não derruba nada: o anúncio aparece sem ela.
    *
    * @param list<array<string, mixed>> $ads
    */
   private static function syncImages(array $ads, string $baseUrl, string $identifier, string $secret): void {
      $dir = self::imageDir();
      $wanted = [];
      foreach ($ads as $ad) {
         if (!empty($ad['image_sha256'])) {
            $wanted[$ad['image_sha256'] . '.' . self::MIME_EXT[$ad['image_mime']]] = $ad;
         }
      }
      if ($wanted !== [] && !is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
         Toolbox::logInFile('plugin_nextool', 'DownloadAds: não foi possível criar ' . $dir);
         return;
      }
      foreach ($wanted as $file => $ad) {
         if (is_file($dir . '/' . $file) || $baseUrl === '' || $identifier === '' || $secret === '') {
            continue;
         }
         try {
            require_once NEXTOOL_PHP_DIR . '/inc/distributionclient.class.php';
            $image = (new PluginNextoolDistributionClient($baseUrl, $identifier, $secret))->downloadAdImage($ad['image_sha256']);
            if (strlen($image['data']) > self::MAX_IMAGE_BYTES || hash('sha256', $image['data']) !== $ad['image_sha256']) {
               throw new RuntimeException('imagem com tamanho ou sha256 divergente');
            }
            $tmp = $dir . '/.' . $file . '.' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $image['data']) === false || !rename($tmp, $dir . '/' . $file)) {
               @unlink($tmp);
               throw new RuntimeException('falha ao gravar');
            }
         } catch (Throwable $e) {
            Toolbox::logInFile('plugin_nextool', sprintf('DownloadAds: imagem do anúncio %d não baixada - %s', (int) $ad['id'], $e->getMessage()));
         }
      }
      // Sem GLOB_BRACE: não existe na musl (imagens Alpine).
      foreach (self::MIME_EXT as $ext) {
         foreach (glob($dir . '/*.' . $ext) ?: [] as $path) {
            if (!isset($wanted[basename($path)])) {
               @unlink($path);
            }
         }
      }
   }

   private static function imageDir(): string {
      return NEXTOOL_DOC_DIR . '/download_ads';
   }

   /** @return array<string, array<string, int>> */
   private static function loadMetrics(): array {
      $raw = (string) (Config::getConfigurationValues(self::METRICS_CONTEXT, ['metrics'])['metrics'] ?? '');
      $metrics = $raw !== '' ? json_decode($raw, true) : null;
      return is_array($metrics) ? $metrics : [];
   }

   private static function saveMetrics(array $metrics): bool {
      try {
         Config::setConfigurationValues(self::METRICS_CONTEXT, PluginNextoolDbCompat::row([
            'metrics' => (string) json_encode($metrics, JSON_UNESCAPED_SLASHES),
         ]));
         return true;
      } catch (Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'DownloadAds: falha ao gravar métricas - ' . $e->getMessage());
         return false;
      }
   }
}
