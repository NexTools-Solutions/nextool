<?php
/**
 * NexTool -- vigia das rotinas e ações automáticas (incidente glpisync na JMBA, 28/08 a 14/10/2026).
 *
 * Uma CronTask cujo processo morre no meio (erro fatal, OOM, kill) fica em "em execução" para sempre: o
 * GLPI só dispara tarefa que não esteja rodando e não destrava sozinho (o `cronWatcher` do core só manda
 * a notificação nativa, que pode estar desligada). Na JMBA o poll do glpisync ficou 47 dias parado sem
 * ninguém saber, porque toda a saúde do módulo roda DENTRO do próprio cron.
 *
 * O vigia roda FORA do que ele vigia:
 *  - CronTask própria `nextoolWatchdog` (5 min): um cron preso não impede os outros de rodar;
 *  - nas requisições web de usuário logado, no máximo a cada WEB_INTERVAL (por arquivo no cache, sem
 *    consulta ao banco nas outras requisições): cobre o cron do servidor inteiro parado.
 *
 * O que ele faz:
 *  - `nextoolJobs` em execução há mais de DEAD_AFTER com a trava da rodada LIVRE = o processo morreu
 *    (o MariaDB solta o GET_LOCK quando a conexão cai): destrava e avisa. Com a trava segurada há mais de
 *    HUNG_AFTER: está viva mas presa, só avisa;
 *  - `nextoolJobs` aguardando sem rodar há mais de STOPPED_AFTER: cron do servidor parado;
 *  - rotina com MAX_FAILS falhas seguidas (pausada): avisa até voltar a funcionar;
 *  - outra ação automática do NexTool (base ou módulo) presa em execução além do critério do core (2x a
 *    frequência ou 2 h): só avisa, porque sem trava não há prova de que o processo morreu.
 *
 * Os avisos vão para o sino (canal publish/sink, Smart Notify) dos administradores do NexTool, em fontes
 * OBRIGATÓRIAS (o administrador não desliga). Enquanto o problema persiste, cada checagem republica e o
 * sino agrupa; depois de REPEAT_SECONDS sai um aviso novo. Quando normaliza, sai um aviso verde que
 * encerra o vermelho (`resolves`).
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolJobWatchdog {

   public const TASK_NAME      = 'nextoolWatchdog';
   public const FREQUENCY      = 300;
   /** `nextoolJobs` em execução há mais que isto, sem a trava da rodada: o processo morreu. */
   public const DEAD_AFTER     = 180;
   /** `nextoolJobs` com a trava da rodada segurada há mais que isto: viva, mas presa. */
   public const HUNG_AFTER     = 3600;
   /** `nextoolJobs` aguardando sem rodar há mais que isto: o cron do servidor parou. */
   public const STOPPED_AFTER  = 900;
   /** Intervalo mínimo da checagem pelas requisições web. */
   public const WEB_INTERVAL   = 600;
   /** Enquanto o problema persiste, um aviso novo a cada 6 h (decisão do owner, 14/10/2026). */
   public const REPEAT_SECONDS = 21600;

   public const SRC_UNLOCKED    = 'nextool.jobs_unlocked';
   public const SRC_CRON_STOPPED = 'nextool.cron_stopped';
   public const SRC_JOB_PAUSED  = 'nextool.job_paused';
   public const SRC_TASK_STUCK  = 'nextool.crontask_stuck';

   private const OPEN_KEY  = 'watchdog_open_alerts';
   private const WEB_FLAG  = 'nextool_watchdog_web';

   public static function getTypeName($nb = 0) {
      return __('Vigia das rotinas do NexTool', 'nextool');
   }

   public static function cronInfo($name) {
      if ($name === self::TASK_NAME) {
         return [
            'description' => __('Destrava as rotinas do NexTool quando o processo morreu no meio e avisa quando uma rotina ou ação automática para de rodar', 'nextool'),
         ];
      }
      return [];
   }

   /** Fontes do sino. Chamado no init da base, ao lado de `nextool.server_alert`. */
   public static function registerSources(): void {
      if (!class_exists('PluginNextoolHookDispatcher')
          || !method_exists('PluginNextoolHookDispatcher', 'registerNotificationSource')) {
         return;
      }
      $comum = [
         'dedup_window' => self::REPEAT_SECONDS,
         'mandatory'    => true,
      ];
      PluginNextoolHookDispatcher::registerNotificationSource(self::SRC_CRON_STOPPED, $comum + [
         'label'       => ['Rotinas do NexTool paradas', 'nextool'],
         'description' => ['A ação automática que executa as rotinas dos módulos deixou de rodar (cron do servidor parado).', 'nextool'],
         'icon'        => 'ti ti-clock-off',
         'color'       => 'danger',
         'severity'    => 'critical',
      ]);
      PluginNextoolHookDispatcher::registerNotificationSource(self::SRC_UNLOCKED, $comum + [
         'label'       => ['Rotinas do NexTool destravadas', 'nextool'],
         'description' => ['Uma execução das rotinas terminou sem concluir e o NexTool liberou a fila sozinho.', 'nextool'],
         'icon'        => 'ti ti-lock-open',
         'color'       => 'warning',
         'severity'    => 'warning',
      ]);
      PluginNextoolHookDispatcher::registerNotificationSource(self::SRC_JOB_PAUSED, $comum + [
         'label'       => ['Rotina de módulo pausada', 'nextool'],
         'description' => ['Uma rotina de módulo falhou várias vezes seguidas e está pausada.', 'nextool'],
         'icon'        => 'ti ti-player-pause',
         'color'       => 'danger',
         'severity'    => 'critical',
      ]);
      PluginNextoolHookDispatcher::registerNotificationSource(self::SRC_TASK_STUCK, $comum + [
         'label'       => ['Ação automática presa', 'nextool'],
         'description' => ['Uma ação automática do NexTool está em execução há muito mais tempo que o normal e não roda de novo enquanto isso.', 'nextool'],
         'icon'        => 'ti ti-hourglass-off',
         'color'       => 'danger',
         'severity'    => 'critical',
      ]);
   }

   /**
    * Nunca chamado: só marca para o xgettext os rótulos lazy (`['msgid', 'nextool']`) das fontes do sino,
    * que o consumidor traduz na leitura. Sem isto eles não entram no POT e saem em português em todo idioma.
    * Inclui as da fonte `nextool.server_alert` (setup.php), que tinham o mesmo buraco.
    */
   private static function i18nMarkers(): void {
      __('Rotinas do NexTool paradas', 'nextool');
      __('A ação automática que executa as rotinas dos módulos deixou de rodar (cron do servidor parado).', 'nextool');
      __('Rotinas do NexTool destravadas', 'nextool');
      __('Uma execução das rotinas terminou sem concluir e o NexTool liberou a fila sozinho.', 'nextool');
      __('Rotina de módulo pausada', 'nextool');
      __('Uma rotina de módulo falhou várias vezes seguidas e está pausada.', 'nextool');
      __('Ação automática presa', 'nextool');
      __('Uma ação automática do NexTool está em execução há muito mais tempo que o normal e não roda de novo enquanto isso.', 'nextool');
      __('Avisos da NexTool', 'nextool');
      __('Comunicados oficiais recebidos do servidor NexTool (os mesmos da aba Novidades).', 'nextool');
   }

   /** CronTask `nextoolWatchdog` (MODE_EXTERNAL, a cada 5 min). */
   public static function cronNextoolWatchdog(CronTask $task): int {
      $trava = self::lockName();
      $status = PluginNextoolJobDispatcher::tryLock($trava);
      if ($status === false) {
         return 0;
      }
      try {
         $res = self::check('cron', static function (string $msg) use ($task): void {
            $task->log($msg);
         });
      } finally {
         if ($status === true) {
            PluginNextoolJobDispatcher::unlock($trava);
         }
      }
      $task->addVolume(count($res['open']));
      return ($res['unlocked'] || $res['open'] !== []) ? 1 : 0;
   }

   /**
    * Checagem pelas requisições web: só usuário logado, nunca AJAX, asset ou CLI, no máximo a cada
    * WEB_INTERVAL. O intervalo é controlado pelo horário de um arquivo no cache (um stat por request).
    */
   public static function maybeRunFromWeb(): void {
      if (PHP_SAPI === 'cli' || !defined('GLPI_CACHE_DIR') || !is_dir(GLPI_CACHE_DIR)) {
         return;
      }
      if (!class_exists('Session') || !Session::getLoginUserID()) {
         return;
      }
      $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
      $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
      if ($xhr || str_contains($path, '/ajax/') || (function_exists('plugin_nextool_is_asset_request') && plugin_nextool_is_asset_request())) {
         return;
      }
      $flag = GLPI_CACHE_DIR . '/' . self::WEB_FLAG;
      $ultima = @filemtime($flag);
      if ($ultima !== false && (time() - $ultima) < self::WEB_INTERVAL) {
         return;
      }
      @touch($flag);
      try {
         self::check('web');
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'jobs: vigia (web) falhou: ' . $e->getMessage() . "\n");
      }
   }

   /**
    * Uma checagem completa. Idempotente: destravar usa UPDATE condicionado a state=2; os avisos
    * deduplicam no sino.
    *
    * @param string        $origem 'cron' | 'web' | 'test'
    * @param callable|null $log    recebe cada ação (string)
    * @param int|null      $now    relógio (teste)
    * @return array{unlocked:bool, open:string[]}
    */
   public static function check(string $origem, ?callable $log = null, ?int $now = null): array {
      global $DB;
      $now = $now ?? time();
      $out = ['unlocked' => false, 'open' => []];
      if (!isset($DB) || !$DB->tableExists('glpi_crontasks')) {
         return $out;
      }
      require_once __DIR__ . '/jobdispatcher.class.php';
      $registra = static function (string $msg) use ($log): void {
         Toolbox::logInFile('plugin_nextool', 'jobs: vigia: ' . $msg . "\n");
         if ($log !== null) {
            $log($msg);
         }
      };

      $abertos = [];

      // 1) A ação automática das rotinas.
      $jobsTask = self::loadTask('PluginNextoolJobDispatcher', PluginNextoolJobDispatcher::TASK_NAME);
      if ($jobsTask !== null && PluginNextoolJobDispatcher::isEnabled()) {
         $estado = (int) $jobsTask['state'];
         $lastrun = empty($jobsTask['lastrun']) ? 0 : (int) strtotime((string) $jobsTask['lastrun']);
         $idade = $lastrun > 0 ? $now - $lastrun : 0;
         if ($estado === CronTask::STATE_RUNNING && $lastrun > 0) {
            $livre = PluginNextoolJobDispatcher::isLockFree(PluginNextoolJobDispatcher::roundLockName());
            if ($livre === true && $idade > self::DEAD_AFTER) {
               $suspeitas = array_map(
                  static fn (array $r): string => $r['module_key'] . '/' . $r['job_key'],
                  PluginNextoolJobDispatcher::getCrashedRows()
               );
               if (self::unlockTask((int) $jobsTask['id'])) {
                  $out['unlocked'] = true;
                  $registra(sprintf('nextoolJobs destravada (em execução desde %s, processo inexistente; rotina em curso: %s)',
                     (string) $jobsTask['lastrun'], $suspeitas === [] ? '-' : implode(', ', $suspeitas)));
                  self::publish([
                     'source_key' => self::SRC_UNLOCKED,
                     'title'      => __('Rotinas do NexTool destravadas automaticamente', 'nextool'),
                     'message'    => sprintf(
                        __('A execução das rotinas iniciada em %1$s terminou sem concluir (erro fatal ou processo encerrado) e ficou travada. O NexTool liberou a fila. Rotina em execução no momento: %2$s. Se isso se repetir, a rotina é pausada e você recebe outro aviso.', 'nextool'),
                        Html::convDateTime((string) $jobsTask['lastrun']),
                        $suspeitas === [] ? __('nenhuma identificada', 'nextool') : implode(', ', $suspeitas)
                     ),
                     'url'        => self::routinesUrl(),
                     'severity'   => 'warning',
                     'dedup_key'  => self::SRC_UNLOCKED,
                  ]);
               }
            } elseif ($livre === false && $idade > self::HUNG_AFTER) {
               $chave = self::SRC_TASK_STUCK . ':' . PluginNextoolJobDispatcher::TASK_NAME;
               $abertos[$chave] = self::stuckPayload($chave, $jobsTask);
            }
         } elseif ($estado === CronTask::STATE_WAITING && $lastrun > 0 && $idade > self::STOPPED_AFTER) {
            $abertos[self::SRC_CRON_STOPPED] = [
               'source_key' => self::SRC_CRON_STOPPED,
               'title'      => sprintf(__('As rotinas do NexTool não rodam há %d minutos', 'nextool'), (int) floor($idade / 60)),
               'message'    => sprintf(
                  __('A ação automática que executa as rotinas dos módulos não roda desde %s. Sincronizações, filas e avisos que dependem dela estão parados. Confira se o cron do servidor do GLPI está ativo.', 'nextool'),
                  Html::convDateTime((string) $jobsTask['lastrun'])
               ),
               'url'        => self::cronTaskUrl((int) $jobsTask['id']),
               'severity'   => 'critical',
               'dedup_key'  => self::SRC_CRON_STOPPED,
            ];
         }
      }

      // 2) O próprio vigia (só pela web: na CronTask ele está rodando agora).
      if ($origem === 'web') {
         $vigia = self::loadTask(self::class, self::TASK_NAME);
         if ($vigia !== null && (int) $vigia['state'] === CronTask::STATE_RUNNING && !empty($vigia['lastrun'])
             && $now - (int) strtotime((string) $vigia['lastrun']) > 2 * self::FREQUENCY
             && PluginNextoolJobDispatcher::isLockFree(self::lockName()) === true
             && self::unlockTask((int) $vigia['id'])) {
            $registra('nextoolWatchdog destravada (processo inexistente)');
         }
      }

      // 3) Rotinas pausadas por falhas seguidas.
      if (PluginNextoolJobDispatcher::tableReady()) {
         foreach ($DB->request([
            'FROM'  => PluginNextoolJobDispatcher::TABLE,
            'WHERE' => ['fails' => ['>=', PluginNextoolJobDispatcher::MAX_FAILS]],
         ]) as $row) {
            $rotina = $row['module_key'] . '/' . $row['job_key'];
            $chave = self::SRC_JOB_PAUSED . ':' . $rotina;
            $abertos[$chave] = [
               'source_key' => self::SRC_JOB_PAUSED,
               'title'      => sprintf(__('Rotina %s pausada por falhas seguidas', 'nextool'), $rotina),
               'message'    => sprintf(
                  __('%1$d falhas seguidas. Nova tentativa em %2$s. Último erro: %3$s', 'nextool'),
                  (int) $row['fails'],
                  empty($row['paused_until']) ? '-' : Html::convDateTime((string) $row['paused_until']),
                  (string) $row['last_message'] !== '' ? (string) $row['last_message'] : '-'
               ),
               'url'        => self::routinesUrl(),
               'severity'   => 'critical',
               'dedup_key'  => $chave,
            ];
         }
      }

      // 4) Outras ações automáticas do NexTool presas em execução (critério do core: 2x a frequência
      //    ou 2 h). Sem trava, não há prova de morte: só avisa.
      foreach ($DB->request([
         'FROM'  => 'glpi_crontasks',
         'WHERE' => ['state' => CronTask::STATE_RUNNING, 'itemtype' => ['LIKE', 'PluginNextool%']],
      ]) as $task) {
         if (in_array((string) $task['itemtype'], ['PluginNextoolJobDispatcher', self::class], true) || empty($task['lastrun'])) {
            continue;
         }
         $idade = $now - (int) strtotime((string) $task['lastrun']);
         if ($idade <= 2 * (int) $task['frequency'] && $idade <= 2 * HOUR_TIMESTAMP) {
            continue;
         }
         $chave = self::SRC_TASK_STUCK . ':' . $task['name'];
         $abertos[$chave] = self::stuckPayload($chave, $task);
      }

      foreach ($abertos as $payload) {
         self::publish($payload);
      }

      // 5) O que estava aberto e normalizou: aviso verde que encerra o vermelho.
      $antes = self::loadOpen();
      foreach (array_diff($antes, array_keys($abertos)) as $chave) {
         self::publishResolved((string) $chave);
         $registra('normalizado: ' . $chave);
      }
      self::saveOpen(array_keys($abertos));

      $out['open'] = array_keys($abertos);
      return $out;
   }

   private static function stuckPayload(string $chave, array $task): array {
      return [
         'source_key' => self::SRC_TASK_STUCK,
         'title'      => sprintf(__('Ação automática %s presa em execução', 'nextool'), (string) $task['name']),
         'message'    => sprintf(
            __('Está em execução desde %s, muito além do tempo normal, e não roda de novo enquanto isso. Se o processo não existe mais, libere-a em Configurar > Ações automáticas.', 'nextool'),
            Html::convDateTime((string) $task['lastrun'])
         ),
         'url'        => self::cronTaskUrl((int) $task['id']),
         'severity'   => 'critical',
         'dedup_key'  => $chave,
      ];
   }

   private static function publishResolved(string $chave): void {
      $partes = explode(':', $chave, 2);
      $fonte = $partes[0];
      $alvo = $partes[1] ?? '';
      $titulo = match ($fonte) {
         self::SRC_CRON_STOPPED => __('As rotinas do NexTool voltaram a rodar', 'nextool'),
         self::SRC_JOB_PAUSED   => sprintf(__('Rotina %s voltou a funcionar', 'nextool'), $alvo),
         self::SRC_TASK_STUCK   => sprintf(__('Ação automática %s voltou a rodar', 'nextool'), $alvo),
         default                => '',
      };
      if ($titulo === '') {
         return;
      }
      self::publish([
         'source_key'   => $fonte,
         'title'        => $titulo,
         'message'      => '',
         'url'          => self::routinesUrl(),
         'severity'     => 'success',
         'dedup_key'    => 'resolved:' . $chave,
         'dedup_window' => 0,
         'resolves'     => [$chave],
      ]);
   }

   private static function publish(array $payload): void {
      if (!class_exists('PluginNextoolHookDispatcher') || !method_exists('PluginNextoolHookDispatcher', 'publishNotification')) {
         return;
      }
      $payload += [
         'audience'     => ['type' => 'base_admins'],
         'dedup_window' => self::REPEAT_SECONDS,
         // Dentro das 6 h o sino agrupa; 'refresh' faz o texto acompanhar o estado atual (minutos parado,
         // rotina que caiu agora), em vez de congelar o da primeira ocorrência.
         'on_duplicate' => 'refresh',
      ];
      PluginNextoolHookDispatcher::publishNotification($payload);
   }

   private static function loadTask(string $itemtype, string $name): ?array {
      global $DB;
      $row = $DB->request(['FROM' => 'glpi_crontasks', 'WHERE' => ['itemtype' => $itemtype, 'name' => $name], 'LIMIT' => 1])->current();
      return $row ?: null;
   }

   /** Volta para "aguardando" só se ainda estiver "em execução" (duas checagens ao mesmo tempo não brigam). */
   private static function unlockTask(int $id): bool {
      global $DB;
      $DB->update('glpi_crontasks', ['state' => CronTask::STATE_WAITING], ['id' => $id, 'state' => CronTask::STATE_RUNNING]);
      return PluginNextoolCompat::affectedRows($DB) > 0;
   }

   /** @return string[] */
   private static function loadOpen(): array {
      $cfg = Config::getConfigurationValues('plugin:nextool', [self::OPEN_KEY]);
      $lista = json_decode((string) ($cfg[self::OPEN_KEY] ?? '[]'), true);
      return is_array($lista) ? array_values(array_filter($lista, 'is_string')) : [];
   }

   /** @param string[] $chaves */
   private static function saveOpen(array $chaves): void {
      sort($chaves);
      if ($chaves === self::loadOpen()) {
         return;
      }
      Config::setConfigurationValues('plugin:nextool', [self::OPEN_KEY => json_encode(array_values($chaves))]);
   }

   private static function lockName(): string {
      global $DB;
      return 'nxwatchdog_' . substr(md5((string) ($DB->dbdefault ?? '')), 0, 24);
   }

   private static function routinesUrl(): string {
      $aba = class_exists('PluginNextoolMainConfig') && defined('PluginNextoolMainConfig::TAB_MANUTENCAO')
         ? (int) PluginNextoolMainConfig::TAB_MANUTENCAO
         : 93;
      return '/plugins/nextool/front/nextoolconfig.form.php?id=1&forcetab=' . rawurlencode('PluginNextoolMainConfig$' . $aba);
   }

   private static function cronTaskUrl(int $id): string {
      return '/front/crontask.form.php?id=' . $id;
   }
}
