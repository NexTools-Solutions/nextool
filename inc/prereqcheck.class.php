<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Pré-requisitos do ambiente que exigem humano (nextool-dev#260)
 * -------------------------------------------------------------------------
 * Três checks viram ALERTA LOCAL (aba Alertas + sino) em vez de linha de log:
 *  1. base desatualizada bloqueando download/atualização de módulos
 *     (`nextool_upgrade_required` do servidor, ou derivado do catálogo local);
 *  2. extensões PHP ausentes (curl, phar; verificador Ed25519 só com update do
 *     core pendente);
 *  3. diretórios sem escrita (módulos; core-update e plugin só com update do
 *     core pendente).
 *
 * Sem aba, tabela ou cron novos: dedup por condição via `local_key` do
 * AlertManager e expiração quando a condição some. Regra de ruído (#227): só
 * vira alerta o que o plugin NÃO resolve sozinho e que bloqueia algo AGORA.
 *
 * Frequência: 1x/h real por file-flag versionada em GLPI_CACHE_DIR (custo em
 * regime: 1 is_file + 1 filemtime, zero query); forçado no Sincronizar, no
 * aceite de políticas e no cron de catálogo. O check 1 também é reativo: o
 * DistributionClient lança código 426 e o ModuleManager chama raiseBaseOutdated().
 *
 * Famílias (prefixo até o PRIMEIRO ':' -- por isso underscore, nunca `prereq:x:y`):
 *   prereq_base:<min_version>            | prereq_base:unknown:<versão que recebeu o 426>
 *   prereq_ext:<itens ordenados por +>:<Ymd>
 *   prereq_dir:<slugs ordenados por +>:<Ymd>
 * `raiseLocal` é no-op para chave já existente (mesmo expirada): a data no
 * sufixo permite re-emitir num dia seguinte quando a condição volta.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

final class PluginNextoolPrereqCheck {

   public const FAMILY_BASE = 'prereq_base:';
   public const FAMILY_EXT  = 'prereq_ext:';
   public const FAMILY_DIR  = 'prereq_dir:';

   /** Intervalo mínimo entre execuções do boot (segundos). */
   public const CHECK_INTERVAL = 3600;
   public const FLAG_PREFIX    = 'nextool_prereq_checked_v';

   private const REQUIRED_EXTENSIONS = ['curl', 'phar'];

   /** Diretórios que só importam ao auto-update do core. */
   private const CORE_ONLY_DIRS = ['core_update', 'plugin'];

   // ------------------------------------------------------------------ ciclo

   /**
    * Executa o ciclo completo (gate de frequência -> fatos -> condições -> alertas).
    *
    * @param string $source boot | manual_sync | policies_acceptance | cron_sync | ...
    * @param bool   $force  ignora o gate de frequência (Sincronizar, cron)
    * @return array{ran: bool, source: string, conditions?: string[]}
    */
   public static function run(string $source, bool $force = false): array {
      self::loadDependencies();
      if (!$force && !self::isDue()) {
         return ['ran' => false, 'source' => $source];
      }
      $facts      = self::gatherFacts();
      $conditions = self::evaluate($facts);
      self::apply($conditions);
      self::expireSatisfiedBase($facts);
      self::markChecked();
      return ['ran' => true, 'source' => $source, 'conditions' => array_keys($conditions)];
   }

   /**
    * Entrada REATIVA do check 1: o servidor recusou um download/update com
    * `nextool_upgrade_required` (código 426 no DistributionClient).
    *
    * @param string|null $minVersion versão mínima informada pelo servidor (null/'' = desconhecida)
    */
   public static function raiseBaseOutdated(?string $minVersion, string $origin = 'download'): bool {
      self::loadDependencies();
      $min     = trim((string)$minVersion);
      $variant = $min !== '' ? $min : 'unknown:' . self::pluginVersion();
      return self::raiseIfNotActive(
         self::FAMILY_BASE,
         $variant,
         false,
         self::baseTitle($min),
         self::baseBody($min, [], $origin)
      );
   }

   /** Remove as flags de frequência (chamado pelo CoreUpdater após aplicar update). */
   public static function clearFlags(): void {
      if (!defined('GLPI_CACHE_DIR') || !is_dir(GLPI_CACHE_DIR)) {
         return;
      }
      foreach (glob(GLPI_CACHE_DIR . '/' . self::FLAG_PREFIX . '*') ?: [] as $flag) {
         @unlink($flag);
      }
   }

   // ------------------------------------------------------------------ fatos

   /**
    * Coleta os fatos do ambiente. Sem rede, sem proc_open: extension_loaded,
    * is_writable, estado do core update (config) e catálogo local.
    *
    * @return array{
    *   plugin_version: string, missing_ext: string[], has_verifier: bool,
    *   core_update_pending: bool, unwritable_dirs: array<string,string>,
    *   web_user: string, blocked_modules: array<string,array{name:string,installed:string,available:string,min_version:string}>
    * }
    */
   public static function gatherFacts(): array {
      self::loadDependencies();
      $pluginVersion = self::pluginVersion();
      $hasCore       = class_exists('PluginNextoolCoreUpdater');

      $missingExt = $hasCore && method_exists('PluginNextoolCoreUpdater', 'missingRequiredExtensions')
         ? PluginNextoolCoreUpdater::missingRequiredExtensions()
         : array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn(string $e): bool => !extension_loaded($e)));
      $hasVerifier = $hasCore && method_exists('PluginNextoolCoreUpdater', 'hasCheapSignatureVerifier')
         ? PluginNextoolCoreUpdater::hasCheapSignatureVerifier()
         : extension_loaded('sodium');
      $webUser = $hasCore && method_exists('PluginNextoolCoreUpdater', 'webProcessUser')
         ? PluginNextoolCoreUpdater::webProcessUser()
         : 'www-data';

      $corePending = false;
      if ($hasCore) {
         try {
            $state = PluginNextoolCoreUpdater::getState();
            // Mesmo OR da UI (front/config.form.php): botão "Atualização Disponível".
            $corePending = !empty($state['update_available'])
               || trim((string)($state['staged_target_version'] ?? '')) !== ''
               || trim((string)($state['latest_available_version'] ?? '')) !== '';
         } catch (Throwable $e) {
            $corePending = false;
         }
      }

      $dirs = [
         'modules'     => defined('NEXTOOL_MODULES_BASE') ? NEXTOOL_MODULES_BASE : '',
         'core_update' => defined('NEXTOOL_DOC_DIR') ? rtrim(NEXTOOL_DOC_DIR, '/') . '/core-update' : '',
         'plugin'      => defined('NEXTOOL_PHP_DIR') ? NEXTOOL_PHP_DIR : '',
      ];
      $unwritable = [];
      foreach ($dirs as $slug => $path) {
         if ($path !== '' && !self::isWritableDir($path)) {
            $unwritable[$slug] = $path;
         }
      }

      $blocked = [];
      if (class_exists('PluginNextoolModuleCatalog') && method_exists('PluginNextoolModuleCatalog', 'getPendingUpdates')) {
         try {
            foreach (PluginNextoolModuleCatalog::getPendingUpdates() as $key => $info) {
               $min = trim((string)($info['min_version_nextools'] ?? ''));
               if ($min !== '' && ($pluginVersion === '' || version_compare($pluginVersion, $min, '<'))) {
                  $blocked[(string)$key] = [
                     'name'        => (string)($info['name'] ?? $key),
                     'installed'   => (string)($info['installed'] ?? ''),
                     'available'   => (string)($info['available'] ?? ''),
                     'min_version' => $min,
                  ];
               }
            }
         } catch (Throwable $e) {
            $blocked = [];
         }
      }

      return [
         'plugin_version'      => $pluginVersion,
         'missing_ext'         => array_values($missingExt),
         'has_verifier'        => (bool)$hasVerifier,
         'core_update_pending' => $corePending,
         'unwritable_dirs'     => $unwritable,
         'web_user'            => $webUser,
         'blocked_modules'     => $blocked,
      ];
   }

   // -------------------------------------------------------------- avaliação

   /**
    * PURA: fatos -> condições por família. Testável injetando fatos.
    *
    * @return array<string, array{variant: string, dated: bool, title: string, body: string, type: string}>
    */
   public static function evaluate(array $facts): array {
      $out         = [];
      $corePending = !empty($facts['core_update_pending']);

      // (2) extensões: curl/phar sempre; ed25519 só com update do core pendente.
      $ext = array_map('strval', (array)($facts['missing_ext'] ?? []));
      if ($corePending && empty($facts['has_verifier'])) {
         $ext[] = 'ed25519';
      }
      $ext = array_values(array_unique(array_filter($ext, static fn(string $e): bool => $e !== '')));
      sort($ext, SORT_STRING);
      if ($ext !== []) {
         $out[self::FAMILY_EXT] = [
            'variant' => implode('+', $ext),
            'dated'   => true,
            'title'   => sprintf(__('Extensões PHP necessárias ausentes: %s', 'nextool'), implode(', ', $ext)),
            'body'    => self::extBody($ext, $corePending),
            'type'    => 'warning',
         ];
      }

      // (3) diretórios: modules sempre; core_update/plugin só com update do core pendente.
      $dirs = (array)($facts['unwritable_dirs'] ?? []);
      if (!$corePending) {
         foreach (self::CORE_ONLY_DIRS as $slug) {
            unset($dirs[$slug]);
         }
      }
      ksort($dirs, SORT_STRING);
      if ($dirs !== []) {
         $out[self::FAMILY_DIR] = [
            'variant' => implode('+', array_keys($dirs)),
            'dated'   => true,
            'title'   => _n(
               'Diretório sem permissão de escrita bloqueia a instalação e a atualização de módulos',
               'Diretórios sem permissão de escrita bloqueiam a instalação e a atualização de módulos',
               count($dirs),
               'nextool'
            ),
            'body'    => self::dirBody($dirs, (string)($facts['web_user'] ?? 'www-data')),
            'type'    => 'warning',
         ];
      }

      // (1) base: derivado do catálogo -- módulo instalado com update que exige base maior.
      $blocked = (array)($facts['blocked_modules'] ?? []);
      if ($blocked !== []) {
         $max = '';
         foreach ($blocked as $info) {
            $min = (string)($info['min_version'] ?? '');
            if ($min !== '' && ($max === '' || version_compare($min, $max, '>'))) {
               $max = $min;
            }
         }
         if ($max !== '') {
            $out[self::FAMILY_BASE] = [
               'variant' => $max,
               'dated'   => false,
               'title'   => self::baseTitle($max),
               'body'    => self::baseBody($max, $blocked, 'catalog'),
               'type'    => 'warning',
            ];
         }
      }

      return $out;
   }

   /**
    * Aplica as condições: presente -> emite se ainda não há alerta ativo com a
    * mesma variante; ausente -> expira a família (ext/dir). A família base NÃO é
    * expirada pela ausência (o reativo pode saber de módulo não instalado) --
    * é expireSatisfiedBase() quem a fecha, pela versão.
    */
   public static function apply(array $conditions): void {
      self::loadDependencies();
      if (!class_exists('PluginNextoolAlertManager')) {
         return;
      }
      foreach ([self::FAMILY_EXT, self::FAMILY_DIR] as $family) {
         if (isset($conditions[$family])) {
            $c = $conditions[$family];
            self::raiseIfNotActive($family, $c['variant'], !empty($c['dated']), $c['title'], $c['body'], $c['type'] ?? 'warning');
         } else {
            PluginNextoolAlertManager::expireLocalFamily($family);
         }
      }
      if (isset($conditions[self::FAMILY_BASE])) {
         $c = $conditions[self::FAMILY_BASE];
         // Alerta reativo com versão MAIOR que a derivada (módulo ainda não instalado)
         // prevalece: não rebaixar a exigência a cada hora.
         $active = PluginNextoolAlertManager::findActiveLocalKey(self::FAMILY_BASE);
         if ($active !== null) {
            $activeVariant = substr($active, strlen(self::FAMILY_BASE));
            if (!str_starts_with($activeVariant, 'unknown') && version_compare($activeVariant, $c['variant'], '>')) {
               return;
            }
         }
         self::raiseIfNotActive(self::FAMILY_BASE, $c['variant'], false, $c['title'], $c['body'], $c['type'] ?? 'warning');
      }
   }

   /**
    * Fecha o alerta de base quando a versão instalada já atende (ou, para a
    * variante `unknown`, quando a base mudou desde o 426 que a emitiu).
    */
   public static function expireSatisfiedBase(array $facts): void {
      self::loadDependencies();
      if (!class_exists('PluginNextoolAlertManager')) {
         return;
      }
      $active = PluginNextoolAlertManager::findActiveLocalKey(self::FAMILY_BASE);
      if ($active === null) {
         return;
      }
      $variant = substr($active, strlen(self::FAMILY_BASE));
      $current = (string)($facts['plugin_version'] ?? self::pluginVersion());
      if (str_starts_with($variant, 'unknown')) {
         $raisedAt = substr($variant, strlen('unknown:'));
         if ($raisedAt !== $current) {
            PluginNextoolAlertManager::expireLocalFamily(self::FAMILY_BASE);
         }
         return;
      }
      if ($current !== '' && version_compare($current, $variant, '>=')) {
         PluginNextoolAlertManager::expireLocalFamily(self::FAMILY_BASE);
      }
   }

   // --------------------------------------------------------------- emissão

   /**
    * Emite só se não há alerta ATIVO da família com a mesma variante. Variante
    * diferente -> chave nova (raiseLocal expira o irmão). Para famílias datadas
    * a data do sufixo não entra na comparação.
    */
   public static function raiseIfNotActive(string $family, string $variant, bool $dated, string $title, string $body, string $type = 'warning'): bool {
      if (!class_exists('PluginNextoolAlertManager')) {
         return false;
      }
      $active = PluginNextoolAlertManager::findActiveLocalKey($family);
      if ($active !== null) {
         $activeVariant = substr($active, strlen($family));
         if ($dated) {
            $activeVariant = (string)preg_replace('/:\d{8}$/', '', $activeVariant);
         }
         if ($activeVariant === $variant) {
            return false;
         }
      }
      $key = $family . $variant . ($dated ? ':' . date('Ymd') : '');
      return PluginNextoolAlertManager::raiseLocal($key, $title, $body, $type);
   }

   // ---------------------------------------------------------------- textos

   private static function baseTitle(string $min): string {
      return $min !== ''
         ? sprintf(__('Atualize o NexTool para a versão %s ou superior: há módulos que não podem ser baixados ou atualizados', 'nextool'), $min)
         : __('Atualize o NexTool: a versão instalada não consegue mais baixar nem atualizar módulos', 'nextool');
   }

   private static function baseBody(string $min, array $blocked, string $origin): string {
      $current = self::pluginVersion();
      $html = '<p>' . htmlspecialchars(
         $min !== ''
            ? sprintf(__('O servidor NexTool só entrega módulos a partir da versão %1$s do plugin; a instalada é a %2$s.', 'nextool'), $min, $current !== '' ? $current : '?')
            : sprintf(__('O servidor NexTool recusou o download ou a atualização de um módulo porque a versão instalada do plugin (%s) está desatualizada.', 'nextool'), $current !== '' ? $current : '?')
      ) . '</p>';
      if ($blocked !== []) {
         $html .= '<p>' . htmlspecialchars(__('Módulos instalados com atualização disponível que exigem a base nova:', 'nextool')) . '</p><ul>';
         foreach ($blocked as $info) {
            $html .= sprintf(
               '<li>%s (%s → %s; %s)</li>',
               htmlspecialchars((string)($info['name'] ?? '')),
               htmlspecialchars((string)($info['installed'] ?? '')),
               htmlspecialchars((string)($info['available'] ?? '')),
               htmlspecialchars(sprintf(__('exige NexTool %s', 'nextool'), (string)($info['min_version'] ?? '')))
            );
         }
         $html .= '</ul>';
      }
      $html .= '<p>' . sprintf(
         htmlspecialchars(__('Abra a %s e use o botão "Atualização Disponível" (ou "Sincronizar" para verificar) para atualizar o plugin. Este aviso some sozinho após a atualização.', 'nextool')),
         '<a href="' . htmlspecialchars(self::modulesTabUrl()) . '">' . htmlspecialchars(__('tela de Módulos', 'nextool')) . '</a>'
      ) . '</p>';
      return $html;
   }

   private static function extBody(array $ext, bool $corePending): string {
      $purpose = [
         'curl'    => __('download dos pacotes de módulo e da atualização do NexTool', 'nextool'),
         'phar'    => __('extração dos pacotes .tar.gz baixados', 'nextool'),
         'ed25519' => __('verificação da assinatura da atualização do NexTool pendente: habilite a extensão sodium (recomendada) ou o openssl 3.0 ou superior do sistema com proc_open', 'nextool'),
      ];
      $html = '<p>' . htmlspecialchars(__('O PHP deste servidor não tem o que o NexTool precisa para baixar e instalar pacotes:', 'nextool')) . '</p><ul>';
      foreach ($ext as $e) {
         $html .= '<li><strong>' . htmlspecialchars($e) . '</strong> -- ' . htmlspecialchars($purpose[$e] ?? '') . '</li>';
      }
      $html .= '</ul><p>' . htmlspecialchars(__('Peça ao administrador do servidor para habilitar a extensão e reiniciar o PHP. Este aviso some sozinho quando resolvido.', 'nextool')) . '</p>';
      return $html;
   }

   private static function dirBody(array $dirs, string $webUser): string {
      $purpose = [
         'modules'     => __('instalação e atualização de módulos', 'nextool'),
         'core_update' => __('staging e backup da atualização do NexTool pendente', 'nextool'),
         'plugin'      => __('aplicação da atualização do NexTool pendente', 'nextool'),
      ];
      $html = '<p>' . htmlspecialchars(sprintf(__('O usuário do PHP (%s) não consegue gravar em:', 'nextool'), $webUser)) . '</p><ul>';
      foreach ($dirs as $slug => $path) {
         $html .= sprintf(
            '<li><strong>%s</strong> -- %s. %s <strong>chown -R %s:%s %s</strong></li>',
            htmlspecialchars((string)$path),
            htmlspecialchars($purpose[$slug] ?? (string)$slug),
            htmlspecialchars(__('No servidor, como root:', 'nextool')),
            htmlspecialchars($webUser),
            htmlspecialchars($webUser),
            htmlspecialchars((string)$path)
         );
      }
      $html .= '</ul><p>' . htmlspecialchars(__('Este aviso some sozinho quando a permissão for corrigida.', 'nextool')) . '</p>';
      return $html;
   }

   // --------------------------------------------------------------- suporte

   private static function modulesTabUrl(): string {
      $web = class_exists('Plugin') ? (string)Plugin::getWebDir('nextool') : '/plugins/nextool';
      return $web . '/front/nextoolconfig.form.php?id=1&forcetab=' . rawurlencode('PluginNextoolMainConfig$1');
   }

   private static function pluginVersion(): string {
      return defined('PLUGIN_NEXTOOL_VERSION') ? (string)PLUGIN_NEXTOOL_VERSION : '';
   }

   private static function isWritableDir(string $path): bool {
      if (is_dir($path)) {
         return is_writable($path);
      }
      // Ainda não existe (criado sob demanda): o pai precisa aceitar a criação.
      return is_writable(dirname($path));
   }

   private static function flagPath(): ?string {
      if (!defined('GLPI_CACHE_DIR') || !is_dir(GLPI_CACHE_DIR)) {
         return null;
      }
      return GLPI_CACHE_DIR . '/' . self::FLAG_PREFIX . self::pluginVersion();
   }

   /** Sem cache dir gravável o boot NÃO roda o check (Sincronizar e cron cobrem). */
   private static function isDue(): bool {
      $flag = self::flagPath();
      if ($flag === null) {
         return false;
      }
      if (!is_file($flag)) {
         return true;
      }
      $mtime = @filemtime($flag);
      return $mtime === false || $mtime < (time() - self::CHECK_INTERVAL);
   }

   private static function markChecked(): void {
      $flag = self::flagPath();
      if ($flag === null) {
         return;
      }
      foreach (glob(GLPI_CACHE_DIR . '/' . self::FLAG_PREFIX . '*') ?: [] as $old) {
         if ($old !== $flag) {
            @unlink($old);
         }
      }
      @touch($flag);
   }

   private static function loadDependencies(): void {
      static $loaded = false;
      if ($loaded) {
         return;
      }
      $loaded = true;
      $base = defined('NEXTOOL_PHP_DIR') ? NEXTOOL_PHP_DIR : dirname(__DIR__);
      foreach (['modulespath.inc.php', 'alertmanager.class.php', 'coreupdater.class.php', 'modulecatalog.class.php'] as $inc) {
         $f = $base . '/inc/' . $inc;
         if (is_file($f)) {
            require_once $f;
         }
      }
   }
}
