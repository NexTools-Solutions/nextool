<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Prontidão dos módulos para uma major do GLPI
 * -------------------------------------------------------------------------
 * Responde, para um major-alvo do GLPI, quais módulos INSTALADOS não o declaram
 * em `compat_glpi_majors` (ou `glpi_major`) do seu module.json e, para cada um,
 * se o catálogo já oferece uma versão compatível. Alimenta:
 *   - o bloco "Compatibilidade com o GLPI N" da guia Módulos (antes de migrar);
 *   - o aviso com confirmação no preflight do self-update, quando a base nova
 *     já suporta o próximo major;
 *   - o alerta de pré-requisito no próprio GLPI N, listando os bloqueados.
 *
 * Alvo: no GLPI < NEXT_MAJOR é o próximo major (preparação); no GLPI >= NEXT_MAJOR
 * é o major em execução (módulos bloqueados AGORA pelo ModuleManager). Módulo sem
 * declaração de major (legado) não entra: o ModuleManager o carrega.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

require_once __DIR__ . '/compat.class.php';

final class PluginNextoolMajorReadiness {

   /** Próximo major do GLPI para o qual a base está sendo preparada. */
   public const NEXT_MAJOR = 12;

   /** @var array<int, array<string,mixed>> memo por request, por alvo */
   private static array $reports = [];

   private function __construct() {
   }

   public static function currentMajor(): int {
      return PluginNextoolCompat::glpiMajor();
   }

   /** Major-alvo da verificação (ver cabeçalho). */
   public static function targetMajor(): int {
      $current = self::currentMajor();
      return $current >= self::NEXT_MAJOR ? $current : self::NEXT_MAJOR;
   }

   /**
    * Majors declarados no module.json do módulo instalado em disco, na mesma regra do
    * ModuleManager: `compat_glpi_majors` (CSV) tem precedência; senão `glpi_major`;
    * sem nenhum dos dois (ou sem module.json legível) devolve null = legado.
    *
    * @return int[]|null
    */
   public static function declaredMajors(string $moduleKey): ?array {
      if ($moduleKey === '' || !defined('NEXTOOL_MODULES_BASE')) {
         return null;
      }
      $file = NEXTOOL_MODULES_BASE . '/' . $moduleKey . '/module.json';
      $json = @file_get_contents($file);
      if (!is_string($json) || $json === '') {
         return null;
      }
      $data = json_decode($json, true);
      if (!is_array($data)) {
         return null;
      }
      return self::parseMajors($data['compat_glpi_majors'] ?? null, $data['glpi_major'] ?? null);
   }

   /**
    * @param mixed $csv   valor de compat_glpi_majors ("10,11")
    * @param mixed $single valor de glpi_major (11)
    * @return int[]|null
    */
   public static function parseMajors($csv, $single = null): ?array {
      if (is_string($csv) && trim($csv) !== '') {
         $out = [];
         foreach (explode(',', $csv) as $m) {
            $m = (int) trim($m);
            if ($m > 0) {
               $out[] = $m;
            }
         }
         return $out === [] ? null : array_values(array_unique($out));
      }
      if (is_numeric($single) && (int) $single > 0) {
         return [(int) $single];
      }
      return null;
   }

   /**
    * PURA (testável com dados injetados): dado o conjunto de módulos instalados, quais não
    * declaram $target e o que o catálogo oferece para cada um.
    *
    * @param array<string, array{name?:string, version?:string, declared?:int[]|null, catalog_majors?:int[]|null, catalog_version?:string|null, discontinued?:bool}> $installed
    * @return array{target:int, count:int, modules:array<string,array{name:string,version:string,has_release:bool,catalog_version:?string}>, ready:string[], waiting:string[]}
    */
   public static function classify(array $installed, int $target): array {
      $modules = [];
      foreach ($installed as $key => $info) {
         $key      = (string) $key;
         $declared = $info['declared'] ?? null;
         if ($declared === null || in_array($target, $declared, true)) {
            continue; // legado (não listado) ou já compatível
         }
         if (!empty($info['discontinued'])) {
            continue; // descontinuado no catálogo: não terá versão para o major, não é pendência
         }
         $catalogMajors  = $info['catalog_majors'] ?? null;
         $catalogVersion = isset($info['catalog_version']) && trim((string) $info['catalog_version']) !== '' ? trim((string) $info['catalog_version']) : null;
         $hasRelease     = is_array($catalogMajors) && in_array($target, $catalogMajors, true) && $catalogVersion !== null;
         $modules[$key] = [
            'name'            => trim((string) ($info['name'] ?? '')) !== '' ? (string) $info['name'] : $key,
            'version'         => (string) ($info['version'] ?? ''),
            'has_release'     => $hasRelease,
            'catalog_version' => $hasRelease ? $catalogVersion : null,
         ];
      }
      uasort($modules, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
      $ready   = array_keys(array_filter($modules, static fn(array $m): bool => $m['has_release']));
      $waiting = array_keys(array_filter($modules, static fn(array $m): bool => !$m['has_release']));
      return [
         'target'  => $target,
         'count'   => count($modules),
         'modules' => $modules,
         'ready'   => $ready,
         'waiting' => $waiting,
      ];
   }

   /**
    * Relatório para o major-alvo (padrão: targetMajor()), a partir do catálogo local
    * (módulos instalados, nome localizado, compat do catálogo) e do module.json em disco.
    * Memoizado por request. Nunca lança: sem catálogo devolve lista vazia.
    *
    * @return array{target:int, current:int, count:int, modules:array<string,array<string,mixed>>, ready:string[], waiting:string[]}
    */
   public static function report(?int $target = null): array {
      $target = $target ?? self::targetMajor();
      if (isset(self::$reports[$target])) {
         return self::$reports[$target];
      }
      $installed = [];
      try {
         if (!class_exists('PluginNextoolModuleCatalog')) {
            require_once __DIR__ . '/modulecatalog.class.php';
         }
         foreach (PluginNextoolModuleCatalog::all() as $key => $meta) {
            if (empty($meta['is_installed'])) {
               continue;
            }
            $installed[(string) $key] = [
               'name'            => (string) ($meta['name'] ?? $key),
               'version'         => (string) ($meta['version'] ?? ''),
               'declared'        => self::declaredMajors((string) $key),
               'catalog_majors'  => self::parseMajors($meta['compat_glpi_majors'] ?? null),
               'catalog_version' => isset($meta['version']) ? (string) $meta['version'] : null,
               'discontinued'    => !empty($meta['is_discontinued']),
            ];
         }
      } catch (\Throwable $e) {
         $installed = [];
      }
      $report            = self::classify($installed, $target);
      $report['current'] = self::currentMajor();
      return self::$reports[$target] = $report;
   }

   /**
    * Versão do relatório para a PREPARAÇÃO (GLPI abaixo do major-alvo): só os módulos ainda sem
    * versão para o major. Módulo que já tem versão compatível no catálogo não é pendência de
    * migração (o botão Atualizar do card já sinaliza), então sai da lista e da contagem. No próprio
    * major-alvo o relatório volta inteiro: lá esses módulos estão bloqueados e a atualização os destrava.
    */
   public static function withoutReady(array $report): array {
      if ((int) ($report['current'] ?? self::currentMajor()) >= (int) ($report['target'] ?? self::NEXT_MAJOR)) {
         return $report;
      }
      $waiting           = (array) ($report['waiting'] ?? []);
      $report['modules'] = array_intersect_key((array) ($report['modules'] ?? []), array_flip($waiting));
      $report['ready']   = [];
      $report['count']   = count($waiting);
      return $report;
   }

   /** Descarta o memo (testes e ações que mudam módulos no mesmo request). */
   public static function resetCache(): void {
      self::$reports = [];
   }

   /** Rótulo curto com a contagem, para badge e título. */
   public static function summaryLabel(array $report): string {
      $n = (int) ($report['count'] ?? 0);
      $t = (int) ($report['target'] ?? self::NEXT_MAJOR);
      return sprintf(
         _n('%1$d módulo sem versão para o GLPI %2$d', '%1$d módulos sem versão para o GLPI %2$d', $n, 'nextool'),
         $n,
         $t
      );
   }

   /**
    * Linhas "com versão compatível" / "aguardando" em texto simples (sem HTML),
    * para o preflight e para os logs. O HTML das telas é montado por quem exibe.
    *
    * @return array{ready:string, waiting:string}
    */
   public static function lines(array $report): array {
      $ready = [];
      foreach ((array) ($report['ready'] ?? []) as $key) {
         $m = $report['modules'][$key];
         $ready[] = $m['name'] . ' ' . $m['catalog_version'];
      }
      $waiting = [];
      foreach ((array) ($report['waiting'] ?? []) as $key) {
         $waiting[] = $report['modules'][$key]['name'];
      }
      return ['ready' => implode(', ', $ready), 'waiting' => implode(', ', $waiting)];
   }
}
