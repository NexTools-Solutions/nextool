<?php
/**
 * Nextools - Assistente de primeira configuração dos módulos.
 *
 * Cada módulo declara os SEUS passos (BaseModule::getSetupWizardSteps()); a base monta o assistente com os passos comuns
 * (Boas-vindas no início; "Quem pode usar" e Resumo no fim), guarda o estado por módulo e serve o endpoint
 * (ajax/module_wizard.php) e o modal (front/nextool-wizard.js.php).
 *
 * Regras:
 *  - arma SÓ na instalação nova (ModuleManager::installModule sem linha anterior); nunca em atualização, sincronização
 *    ou reinstalação. Sem linha de estado = concluído: cliente que já tinha o módulo nunca vê o assistente;
 *  - fechar o modal só pausa; o card do catálogo vira "Continuar configuração" e a página do módulo reabre sozinha;
 *  - cada passo grava pelo próprio save (a mesma lógica da tela de configuração), então parar no meio não deixa nada
 *    pela metade; o assistente pode ser reaberto pelo menu do card.
 *
 * Contrato do passo (array):
 *   name      string  identificador estável (o progresso é guardado por nome, não por posição)
 *   title     string  título já traduzido
 *   render    callable(): string   HTML do corpo (campos com name=..., sem <form>)
 *   save      callable(array $input): array{ok:bool, message?:string}   grava; ok=false mantém no passo
 *   skip      callable(): bool     (opcional) passo pulado agora (ex.: provedor quando o modo é o gerenciado)
 *   optional  bool                 (opcional) mostra "Pular"
 *   test      callable(array $input): array{ok:bool, message:string}  (opcional) botão "Testar" sem gravar
 *
 * HTML por echo (sem Twig) e Bootstrap 5: funciona no GLPI 10, 11 e 12.
 *
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolSetupWizard {

   public const TABLE = 'glpi_plugin_nextool_main_setup_wizards';

   public const STATUS_PENDING   = 'pending';
   public const STATUS_PAUSED    = 'paused';
   public const STATUS_DONE      = 'done';
   public const STATUS_DISMISSED = 'dismissed';

   public static function ready(): bool {
      global $DB;
      return isset($DB) && $DB->tableExists(self::TABLE);
   }

   private static function module(string $key): ?PluginNextoolBaseModule {
      if (!class_exists('PluginNextoolModuleManager')) {
         return null;
      }
      $m = PluginNextoolModuleManager::getInstance()->getModule($key);
      return $m instanceof PluginNextoolBaseModule ? $m : null;
   }

   /** O módulo tem assistente? (algum passo próprio declarado) */
   public static function hasWizard(string $key): bool {
      $m = self::module($key);
      return $m !== null && method_exists($m, 'getSetupWizardSteps') && $m->getSetupWizardSteps() !== [];
   }

   public static function state(string $key): ?array {
      global $DB;
      if (!self::ready()) {
         return null;
      }
      $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['module_key' => $key], 'LIMIT' => 1])->current();
      return $row ?: null;
   }

   public static function isPending(string $key): bool {
      $s = self::state($key);
      return $s !== null && in_array((string) $s['status'], [self::STATUS_PENDING, self::STATUS_PAUSED], true) && self::hasWizard($key);
   }

   /** Instalação nova: arma o assistente (se o módulo tiver). Nunca lança: o assistente não pode derrubar a instalação. */
   public static function arm(string $key): void {
      try {
         if (!self::ready() || !self::hasWizard($key)) {
            return;
         }
         $m = self::module($key);
         self::write($key, [
            'status' => self::STATUS_PENDING, 'current_step' => '', 'completed_steps' => '[]',
            'wizard_version' => method_exists($m, 'getSetupWizardVersion') ? (int) $m->getSetupWizardVersion() : 1,
         ]);
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool', '[wizard] arm ' . $key . ': ' . $e->getMessage() . "\n");
      }
   }

   /** Apagar dados do módulo: o estado do assistente sai junto. */
   public static function forget(string $key): void {
      global $DB;
      if (self::ready()) {
         $DB->delete(self::TABLE, ['module_key' => $key]);
      }
   }

   private static function write(string $key, array $fields): void {
      global $DB;
      $fields['users_id'] = (int) (Session::getLoginUserID() ?: 0);
      $fields['date_mod'] = date('Y-m-d H:i:s');
      if (self::state($key) !== null) {
         $DB->update(self::TABLE, $fields, ['module_key' => $key]);
      } else {
         $DB->insert(self::TABLE, $fields + ['module_key' => $key, 'date_creation' => date('Y-m-d H:i:s')]);
      }
   }

   // ── Passos ─────────────────────────────────────────────────────────────────────────────────────────────

   /** @return array<int, array> passos efetivos (comuns + do módulo), com `skip` já avaliado */
   public static function steps(string $key): array {
      $m = self::module($key);
      if ($m === null) {
         return [];
      }
      $own = method_exists($m, 'getSetupWizardSteps') ? (array) $m->getSetupWizardSteps() : [];
      $all = array_merge([self::welcomeStep($m, $own)], $own, [self::whoStep($m), self::summaryStep($m)]);
      $out = [];
      foreach ($all as $st) {
         if (!is_array($st) || empty($st['name']) || !isset($st['render'])) {
            continue;
         }
         if (isset($st['skip']) && is_callable($st['skip']) && call_user_func($st['skip'])) {
            continue;
         }
         $out[] = $st;
      }
      return $out;
   }

   public static function findStep(array $steps, string $name): ?array {
      foreach ($steps as $i => $st) {
         if ($st['name'] === $name) {
            return $st + ['_index' => $i];
         }
      }
      return null;
   }

   /** Estrutura para o JS (sem os callables). */
   public static function outline(string $key): array {
      $steps = self::steps($key);
      $state = self::state($key) ?? [];
      $done = json_decode((string) ($state['completed_steps'] ?? '[]'), true) ?: [];
      $list = [];
      foreach ($steps as $st) {
         $list[] = ['name' => $st['name'], 'title' => (string) $st['title'], 'optional' => !empty($st['optional']),
                    'testable' => isset($st['test']), 'done' => in_array($st['name'], $done, true)];
      }
      $current = (string) ($state['current_step'] ?? '');
      if ($current === '' || self::findStep($steps, $current) === null) {
         $current = $list[0]['name'] ?? '';
      }
      $m = self::module($key);
      return ['module' => $key, 'module_name' => $m ? (string) $m->getName() : $key, 'steps' => $list, 'current' => $current];
   }

   public static function markStep(string $key, string $step, bool $completed): void {
      $state = self::state($key) ?? [];
      $done = json_decode((string) ($state['completed_steps'] ?? '[]'), true) ?: [];
      if ($completed && !in_array($step, $done, true)) {
         $done[] = $step;
      }
      self::write($key, [
         'status' => (string) ($state['status'] ?? self::STATUS_PENDING) === self::STATUS_DONE ? self::STATUS_DONE : self::STATUS_PENDING,
         'current_step' => $step, 'completed_steps' => json_encode(array_values($done)),
      ]);
   }

   public static function setStatus(string $key, string $status, ?string $current = null): void {
      $f = ['status' => $status];
      if ($current !== null) {
         $f['current_step'] = $current;
      }
      self::write($key, $f);
   }

   // ── Passos comuns ──────────────────────────────────────────────────────────────────────────────────────

   private static function welcomeStep(PluginNextoolBaseModule $m, array $own): array {
      return [
         'name'   => 'welcome',
         'title'  => __('Boas-vindas', 'nextool'),
         'render' => static function () use ($m, $own): string {
            $h = '<p class="fs-4 mb-2">' . sprintf(__('Vamos deixar o %s pronto para usar.', 'nextool'), '<strong>' . PluginNextoolCompat::escape((string) $m->getName()) . '</strong>') . '</p>';
            $desc = method_exists($m, 'getDescription') ? trim((string) $m->getDescription()) : '';
            if ($desc !== '') {
               $h .= '<p class="text-muted">' . PluginNextoolCompat::escape($desc) . '</p>';
            }
            $h .= '<p class="mb-1">' . __('Em poucos passos:', 'nextool') . '</p><ol class="mb-3">';
            foreach ($own as $st) {
               if (isset($st['skip']) && is_callable($st['skip']) && call_user_func($st['skip'])) {
                  continue;
               }
               if (!empty($st['title'])) {
                  $h .= '<li>' . PluginNextoolCompat::escape((string) $st['title']) . '</li>';
               }
            }
            $h .= '<li>' . __('Quem pode usar', 'nextool') . '</li></ol>';
            $h .= '<p class="text-muted small mb-0">' . __('Cada passo grava na hora. Fechar só pausa: o assistente continua de onde parou, e tudo pode ser mudado depois na configuração do módulo.', 'nextool') . '</p>';
            return $h;
         },
         'save'   => static fn (array $in): array => ['ok' => true],
      ];
   }

   /** Perfis × direito de uso do módulo. Só soma acesso (nunca tira) e exige o direito de editar perfis. */
   private static function whoStep(PluginNextoolBaseModule $m): array {
      $key = (string) $m->getModuleKey();
      return [
         'name'     => 'who',
         'title'    => __('Quem pode usar', 'nextool'),
         'optional' => true,
         'render'   => static function () use ($m, $key): string {
            if (!Session::haveRight('profile', UPDATE)) {
               return '<div class="alert alert-info mb-0"><span><i class="ti ti-info-circle me-2"></i>'
                  . __('Seu perfil não pode editar perfis. Peça a um administrador para liberar o módulo em Administração > Perfis, ou pule este passo.', 'nextool') . '</span></div>';
            }
            global $DB;
            $right = PluginNextoolPermissionManager::getModuleRightName($key);
            $has = [];
            foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $right]]) as $r) {
               $has[(int) $r['profiles_id']] = (int) $r['rights'];
            }
            $uses = method_exists($m, 'getUsabilityRights') ? (array) $m->getUsabilityRights() : [];
            $h = '<p>' . __('Marque os perfis que podem usar o módulo. Os que já usam seguem como estão: este passo só acrescenta acesso.', 'nextool') . '</p>';
            $h .= '<div class="list-group">';
            foreach ($DB->request(['SELECT' => ['id', 'name', 'interface'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name']) as $p) {
               $pid = (int) $p['id'];
               $on = (($has[$pid] ?? 0) & PluginNextoolPermissionManager::ACCESS) === PluginNextoolPermissionManager::ACCESS;
               $h .= '<label class="list-group-item d-flex align-items-center gap-2">'
                  . '<input class="form-check-input m-0" type="checkbox" name="profiles[]" value="' . $pid . '"' . ($on ? ' checked disabled' : '') . '>'
                  . '<span>' . PluginNextoolCompat::escape((string) $p['name']) . '</span>'
                  . ($p['interface'] === 'helpdesk' ? '<span class="badge bg-secondary text-white ms-auto">' . __('portal', 'nextool') . '</span>' : '')
                  . ($on ? '<span class="badge bg-success text-white ms-auto">' . __('já usa', 'nextool') . '</span>' : '')
                  . '</label>';
            }
            $h .= '</div>';
            if (count($uses) > 1) {
               $h .= '<p class="text-muted small mt-2 mb-0">' . __('Os perfis marcados recebem todas as funções de uso do módulo (no portal, só as permitidas a ele). O ajuste fino, função por função, fica em Administração > Perfis.', 'nextool') . '</p>';
            }
            return $h;
         },
         'save'     => static function (array $in) use ($m, $key): array {
            $ids = array_values(array_filter(array_map('intval', (array) ($in['profiles'] ?? []))));
            if ($ids === []) {
               return ['ok' => true];
            }
            if (!Session::haveRight('profile', UPDATE)) {
               return ['ok' => false, 'message' => __('Você não tem permissão para editar perfis.', 'nextool')];
            }
            PluginNextoolPermissionManager::syncModuleRights([$key]);
            $mask = PluginNextoolPermissionManager::ACCESS;
            foreach (array_keys(method_exists($m, 'getUsabilityRights') ? (array) $m->getUsabilityRights() : []) as $bit) {
               $mask |= (int) $bit;
            }
            foreach ($ids as $pid) {
               PluginNextoolPermissionManager::grantModuleUse($pid, $key, $mask);
            }
            PluginNextoolPermissionManager::reloadActiveProfileRights();
            return ['ok' => true];
         },
      ];
   }

   private static function summaryStep(PluginNextoolBaseModule $m): array {
      return [
         'name'   => 'summary',
         'title'  => __('Resumo', 'nextool'),
         'render' => static function () use ($m): string {
            $lines = method_exists($m, 'getSetupWizardSummary') ? (array) $m->getSetupWizardSummary() : [];
            $h = '<p>' . __('Configuração atual:', 'nextool') . '</p><ul class="list-unstyled mb-3">';
            foreach ($lines as $label => $value) {
               $h .= '<li class="mb-1"><i class="ti ti-check text-success me-1"></i><strong>' . PluginNextoolCompat::escape((string) $label) . ':</strong> '
                  . PluginNextoolCompat::escape((string) $value) . '</li>';
            }
            $h .= '</ul><p class="text-muted small mb-0">' . __('Tudo isso pode ser mudado a qualquer momento na configuração do módulo. Para rever este assistente, use o menu do card no catálogo.', 'nextool') . '</p>';
            return $h;
         },
         'save'   => static fn (array $in): array => ['ok' => true],
      ];
   }
}
