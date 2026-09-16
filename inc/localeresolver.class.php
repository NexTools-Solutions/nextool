<?php
declare(strict_types=1);

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Resolve o catálogo gettext mais adequado sem duplicar arquivos por variante regional.
 *
 * Ordem de precedência:
 *  1. idioma exato da sessão;
 *  2. catálogo da mesma família linguística;
 *  3. idioma padrão da instância;
 *  4. en_GB.
 */
final class PluginNextoolLocaleResolver {

   /**
    * @param array<string, mixed> $languages Mapa de idiomas do GLPI (`$CFG_GLPI['languages']`).
    * @return array{path: string, locale: string}|null
    */
   public static function resolveMoFile(
      string $localesDir,
      string $requestedLocale,
      string $defaultLocale = 'en_GB',
      array $languages = []
   ): ?array {
      $localesDir = rtrim($localesDir, '/\\');
      if (!is_dir($localesDir)) {
         return null;
      }

      $requestedLocale = self::normalizeLocale($requestedLocale);
      $defaultLocale = self::normalizeLocale($defaultLocale);
      if ($requestedLocale === '') {
         $requestedLocale = $defaultLocale !== '' ? $defaultLocale : 'en_GB';
      }

      $seenPaths = [];

      $exact = self::resolveExact($localesDir, $requestedLocale, $languages, $seenPaths);
      if ($exact !== null) {
         return $exact;
      }

      $family = self::resolveFamily($localesDir, $requestedLocale, $seenPaths);
      if ($family !== null) {
         return $family;
      }

      if ($defaultLocale !== '') {
         $default = self::resolveExact($localesDir, $defaultLocale, $languages, $seenPaths);
         if ($default !== null) {
            return $default;
         }
      }

      return self::resolveExact($localesDir, 'en_GB', $languages, $seenPaths);
   }

   /**
    * Normaliza `es-CO`, `es_CO.UTF-8` e `es_CO@variant` para `es_CO`.
    */
   public static function normalizeLocale(string $locale): string {
      $locale = trim($locale);
      if ($locale === '') {
         return '';
      }

      $locale = preg_split('/[.@]/', $locale, 2)[0] ?? '';
      $locale = str_replace('-', '_', $locale);
      if (!preg_match('/^([A-Za-z]{2,3})(?:_([A-Za-z0-9]+))?$/', $locale, $matches)) {
         return '';
      }

      $language = strtolower($matches[1]);
      if (!isset($matches[2]) || $matches[2] === '') {
         return $language;
      }

      return $language . '_' . strtoupper($matches[2]);
   }

   /**
    * @param array<string, mixed> $languages
    * @param array<string, bool>  $seenPaths
    * @return array{path: string, locale: string}|null
    */
   private static function resolveExact(
      string $localesDir,
      string $locale,
      array $languages,
      array &$seenPaths
   ): ?array {
      if ($locale === '') {
         return null;
      }

      $filenames = [];
      if (isset($languages[$locale])
          && is_array($languages[$locale])
          && isset($languages[$locale][1])
          && is_string($languages[$locale][1])) {
         $mappedFilename = basename($languages[$locale][1]);
         if ($mappedFilename !== '') {
            $filenames[] = $mappedFilename;
         }
      }
      $filenames[] = $locale . '.mo';

      foreach (array_unique($filenames) as $filename) {
         $path = $localesDir . DIRECTORY_SEPARATOR . $filename;
         if (isset($seenPaths[$path])) {
            continue;
         }
         $seenPaths[$path] = true;
         if (is_file($path)) {
            return ['path' => $path, 'locale' => $locale];
         }
      }

      return null;
   }

   /**
    * Escolhe um catálogo da mesma família. Prefere `xx.mo`, depois `xx_XX.mo`
    * (quando existir) e, por fim, a primeira variante em ordem lexical.
    *
    * @param array<string, bool> $seenPaths
    * @return array{path: string, locale: string}|null
    */
   private static function resolveFamily(
      string $localesDir,
      string $requestedLocale,
      array &$seenPaths
   ): ?array {
      if (!preg_match('/^([a-z]{2,3})(?:_|$)/', $requestedLocale, $matches)) {
         return null;
      }
      $family = $matches[1];
      $candidates = [];

      $entries = scandir($localesDir);
      if ($entries === false) {
         return null;
      }

      foreach ($entries as $filename) {
         if (!preg_match('/^([A-Za-z]{2,3}(?:_[A-Za-z0-9]+)?)\.mo$/', $filename, $fileMatch)) {
            continue;
         }
         $locale = self::normalizeLocale($fileMatch[1]);
         if ($locale === ''
             || $locale === $requestedLocale
             || (!str_starts_with($locale, $family . '_') && $locale !== $family)) {
            continue;
         }

         $path = $localesDir . DIRECTORY_SEPARATOR . $filename;
         if (isset($seenPaths[$path]) || !is_file($path)) {
            continue;
         }
         $candidates[] = ['path' => $path, 'locale' => $locale];
      }

      usort($candidates, static function (array $a, array $b) use ($family): int {
         $rank = static function (string $locale) use ($family): int {
            if ($locale === $family) {
               return 0;
            }
            if ($locale === $family . '_' . strtoupper($family)) {
               return 1;
            }
            return 2;
         };
         return [$rank($a['locale']), $a['locale']] <=> [$rank($b['locale']), $b['locale']];
      });

      if ($candidates === []) {
         return null;
      }

      $seenPaths[$candidates[0]['path']] = true;
      return $candidates[0];
   }
}
