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
 *  - `nextoolJobs` parada (fonte única: `health()`): aguardando sem rodar há mais de STOPPED_AFTER, nunca
 *    rodou, desativada ou inexistente (nextool-dev#300). O mesmo veredito alimenta o sino, a faixa no topo
 *    das páginas do NexTool e dos módulos com rotina e o aviso da página inicial (`renderAlert()`);
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

   /** Carência depois de criar a ação ou de instalar/atualizar o plugin: sem alarme falso. */
   public const GRACE_SECONDS  = 900;
   /** Config com o horário (epoch) da última instalação/atualização do plugin. */
   public const GRACE_KEY      = 'jobs_health_grace_from';

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
         $lastrun = self::epoch($jobsTask, 'lastrun');
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
         }
      }

      // 1b) Rotinas paradas: aguardando sem rodar, nunca rodou, ação desativada ou inexistente (#300).
      //     "Presa em execução" segue no ramo acima (fonte nextool.crontask_stuck).
      $saude = self::health($now);
      if (!$saude['ok'] && $saude['reason'] !== 'task_stuck') {
         $texto = self::healthText($saude, $now);
         $url = $saude['task_id'] > 0 && $saude['reason'] !== 'never_ran' ? self::cronTaskUrl($saude['task_id']) : self::routinesUrl();
         if ($saude['reason'] === 'cron_busy') {
            $url = !empty($saude['busy_task_id']) ? self::cronTaskUrl((int) $saude['busy_task_id']) : self::routinesUrl();
         }
         $abertos[self::SRC_CRON_STOPPED] = [
            'source_key' => self::SRC_CRON_STOPPED,
            'title'      => $texto['title'],
            'message'    => $saude['reason'] === 'cron_stopped' ? $texto['message'] : trim($texto['message'] . ' ' . $texto['action']),
            'url'        => $url,
            // Cron ativo com a fila presa é atenção (amarelo), não parada (vermelho).
            'severity'   => self::isWarningReason((string) $saude['reason']) ? 'warning' : 'critical',
            'dedup_key'  => self::SRC_CRON_STOPPED,
         ];
      }

      // 2) O próprio vigia (só pela web: na CronTask ele está rodando agora).
      if ($origem === 'web') {
         $vigia = self::loadTask(self::class, self::TASK_NAME);
         if ($vigia !== null && (int) $vigia['state'] === CronTask::STATE_RUNNING && !empty($vigia['lastrun'])
             && $now - self::epoch($vigia, 'lastrun') > 2 * self::FREQUENCY
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
         'SELECT' => self::taskColumns(),
         'FROM'   => 'glpi_crontasks',
         'WHERE'  => ['state' => CronTask::STATE_RUNNING, 'itemtype' => ['LIKE', 'PluginNextool%']],
      ]) as $task) {
         if (in_array((string) $task['itemtype'], ['PluginNextoolJobDispatcher', self::class], true) || empty($task['lastrun'])) {
            continue;
         }
         $idade = $now - self::epoch($task, 'lastrun');
         if ($idade <= 2 * (int) $task['frequency'] && $idade <= 2 * HOUR_TIMESTAMP) {
            continue;
         }
         $chave = self::SRC_TASK_STUCK . ':' . $task['name'];
         $abertos[$chave] = self::stuckPayload($chave, $task);
      }

      foreach ($abertos as $payload) {
         self::publish($payload);
      }

      // 5) O que estava aberto e normalizou: aviso verde que encerra o vermelho. A lista nova é gravada
      //    ANTES de publicar: se a gravação falhar, nada sai (e a próxima checagem tenta de novo); se a
      //    publicação falhar, o verde não se repete a cada checagem.
      $antes = self::loadOpen();
      $normalizados = array_diff($antes, array_keys($abertos));
      self::saveOpen(array_keys($abertos));
      foreach ($normalizados as $chave) {
         self::publishResolved((string) $chave);
         $registra('normalizado: ' . $chave);
      }

      $out['open'] = array_keys($abertos);
      return $out;
   }

   /**
    * Fonte única da saúde das rotinas (nextool-dev#300): a ação automática `nextoolJobs` está rodando?
    * Uma consulta a `glpi_crontasks`. Sino, tela de Rotinas, abas dos módulos e página inicial leem daqui.
    *
    * Motivos: `cron_busy` (não roda há mais de STOPPED_AFTER, mas o cron do GLPI está ativo: fila presa; vem com
    * busy_task/busy_task_id/busy_since_ts), `task_missing` (a ação não existe), `task_disabled` (desativada), `never_ran` (criada há mais
    * de GRACE_SECONDS e nunca rodou), `cron_stopped` (não roda há mais de STOPPED_AFTER), `task_stuck`
    * (em execução, com a trava segurada, há mais de HUNG_AFTER).
    *
    * Responde ok com a chave das rotinas desligada de propósito e dentro da carência de GRACE_SECONDS
    * depois de instalar/atualizar o plugin.
    *
    * Idades sempre pelo epoch convertido no banco (`taskColumns()`), nunca `strtotime()` da string: ver lá.
    *
    * @return array{ok:bool, reason:string, since:?string, task_id:int, since_ts?:int}
    */
   public static function health(?int $now = null): array {
      global $DB;
      $now = $now ?? time();
      $ok = ['ok' => true, 'reason' => '', 'since' => null, 'task_id' => 0];
      if (!isset($DB) || !$DB->tableExists('glpi_crontasks')) {
         return $ok;
      }
      require_once __DIR__ . '/jobdispatcher.class.php';
      if (!PluginNextoolJobDispatcher::isEnabled()) {
         return $ok;
      }
      $cfg = Config::getConfigurationValues('plugin:nextool', [self::GRACE_KEY]);
      $carencia = (int) ($cfg[self::GRACE_KEY] ?? 0);
      if ($carencia > 0 && $now >= $carencia && ($now - $carencia) < self::GRACE_SECONDS) {
         return $ok;
      }

      $task = self::loadTask('PluginNextoolJobDispatcher', PluginNextoolJobDispatcher::TASK_NAME);
      if ($task === null) {
         return ['ok' => false, 'reason' => 'task_missing', 'since' => null, 'task_id' => 0];
      }
      $id = (int) $task['id'];
      $estado = (int) $task['state'];
      if ($estado === CronTask::STATE_DISABLE) {
         return ['ok' => false, 'reason' => 'task_disabled', 'since' => empty($task['date_mod']) ? null : (string) $task['date_mod'], 'task_id' => $id];
      }
      $lastrun = self::epoch($task, 'lastrun');
      if ($lastrun <= 0) {
         $criada = self::epoch($task, 'date_creation');
         if ($criada > 0 && ($now - $criada) <= self::GRACE_SECONDS) {
            return ['ok' => true, 'reason' => '', 'since' => null, 'task_id' => $id];
         }
         return ['ok' => false, 'reason' => 'never_ran', 'since' => $criada > 0 ? (string) $task['date_creation'] : null, 'task_id' => $id];
      }
      $idade = $now - $lastrun;
      if ($estado === CronTask::STATE_RUNNING) {
         $livre = PluginNextoolJobDispatcher::isLockFree(PluginNextoolJobDispatcher::roundLockName());
         if ($livre === false) {
            return $idade > self::HUNG_AFTER
               ? ['ok' => false, 'reason' => 'task_stuck', 'since' => (string) $task['lastrun'], 'task_id' => $id]
               : ['ok' => true, 'reason' => '', 'since' => null, 'task_id' => $id];
         }
         // Sem a trava da rodada o processo morreu: o vigia destrava; se o cron não voltar, é cron parado.
      }
      if ($idade > self::STOPPED_AFTER) {
         $parado = ['ok' => false, 'reason' => 'cron_stopped', 'since' => (string) $task['lastrun'], 'task_id' => $id, 'since_ts' => $lastrun];
         // Outras ações do GLPI rodaram há pouco: o cron está ativo, mas a fila não chega às ações dos plugins
         // (o GLPI roda poucas por vez, as dele primeiro, e uma ação lenta segura a rodada). Ex.: DEV em 12/11/2026,
         // queuednotification levando 8 min por rodada com o Office 365 lento, nextoolJobs 2 h sem vez.
         $fila = self::cronActivity($now);
         if ($fila !== null) {
            return ['reason' => 'cron_busy'] + $fila + $parado;
         }
         return $parado;
      }
      return ['ok' => true, 'reason' => '', 'since' => null, 'task_id' => $id];
   }

   /**
    * Atividade recente do cron do GLPI fora das ações do NexTool: null se nada rodou nos últimos STOPPED_AFTER
    * (cron do servidor parado de verdade). Senão, a ação em execução há mais tempo, se houver (a que segura a
    * rodada). Epoch convertido pelo banco, como em taskColumns().
    *
    * @return array{busy_task:string, busy_task_id:int, busy_since_ts:int}|null
    */
   private static function cronActivity(int $now): ?array {
      global $DB;
      $proprias = ['PluginNextoolJobDispatcher', self::class];
      $ultima = $DB->request([
         'SELECT' => [PluginNextoolCompat::queryExpression('MAX(UNIX_TIMESTAMP(`lastrun`)) AS `ts`')],
         'FROM'   => 'glpi_crontasks',
         'WHERE'  => ['NOT' => ['itemtype' => $proprias], 'state' => ['>', CronTask::STATE_DISABLE]],
      ])->current();
      $ts = (int) ($ultima['ts'] ?? 0);
      if ($ts <= 0 || $ts > $now + 60 || ($now - $ts) > self::STOPPED_AFTER) {
         return null;
      }
      $presa = $DB->request([
         'SELECT' => self::taskColumns(),
         'FROM'   => 'glpi_crontasks',
         'WHERE'  => ['NOT' => ['itemtype' => $proprias], 'state' => CronTask::STATE_RUNNING, ['NOT' => ['lastrun' => null]]],
         'ORDER'  => ['lastrun ASC'],
         'LIMIT'  => 1,
      ])->current();
      return [
         'busy_task'     => $presa ? (string) $presa['name'] : '',
         'busy_task_id'  => $presa ? (int) $presa['id'] : 0,
         'busy_since_ts' => $presa ? self::epoch($presa, 'lastrun') : 0,
      ];
   }

   /** Limite de ações por rodada (`cron_limit`) a partir do qual não sugerimos aumentar: o padrão do GLPI é 5. */
   public const CRON_LIMIT_OK = 6;

   /**
    * Sugestão de aumentar o "Número máximo de ações automáticas (executadas através da CLI)" do GLPI.
    *
    * O GLPI roda no máximo `cron_limit` ações por rodada, as dele primeiro; com o padrão (5) e várias ações de
    * 1 minuto, a `nextoolJobs` espera a vez e as rotinas atrasam. Sugere quando o limite está no padrão ou
    * abaixo, ou quando é menor que o número de ações ativas de 1 minuto (o mesmo critério do aviso do GLPI).
    *
    * @return array{limit:int, per_minute:int, suggested:int}|null null quando o limite está folgado.
    */
   public static function cronLimitAdvice(): ?array {
      global $CFG_GLPI, $DB;
      $limite = (int) ($CFG_GLPI['cron_limit'] ?? 0);
      if ($limite <= 0) {
         return null;
      }
      $porMinuto = 0;
      try {
         $porMinuto = (int) countElementsInTable('glpi_crontasks', [
            'frequency' => ['<=', MINUTE_TIMESTAMP],
            'state'     => ['>', CronTask::STATE_DISABLE],
         ]);
      } catch (Throwable $e) {
         $porMinuto = 0;
      }
      if ($limite >= self::CRON_LIMIT_OK && $limite >= $porMinuto) {
         return null;
      }
      return [
         'limit'      => $limite,
         'per_minute' => $porMinuto,
         'suggested'  => min(30, max(10, $porMinuto + 3)),
      ];
   }

   /** Problema que pede atenção, mas não é parada: cron ativo com a fila presa (aviso amarelo). */
   public static function isWarningReason(string $reason): bool {
      return $reason === 'cron_busy';
   }

   /**
    * Título, explicação e o que fazer, por motivo de `health()`. Texto puro (o sino e as faixas escapam).
    *
    * @param array{ok:bool, reason:string, since:?string, task_id:int} $saude
    * @return array{title:string, message:string, action:string}
    */
   public static function healthText(array $saude, ?int $now = null): array {
      $now = $now ?? time();
      $desde = !empty($saude['since']) ? Html::convDateTime((string) $saude['since']) : '';
      $efeito = __('Sincronizações, filas e avisos dos módulos que dependem dela estão parados.', 'nextool');
      switch ((string) $saude['reason']) {
         case 'task_missing':
            return [
               'title'   => __('A ação automática das rotinas do NexTool não existe', 'nextool'),
               'message' => __('A ação automática "nextoolJobs", que executa as rotinas dos módulos, não foi encontrada neste GLPI.', 'nextool') . ' ' . $efeito,
               'action'  => __('Recrie a ação na tela de Rotinas do NexTool (Manutenção).', 'nextool'),
            ];
         case 'task_disabled':
            return [
               'title'   => __('A ação automática das rotinas do NexTool está desativada', 'nextool'),
               'message' => __('A ação automática "nextoolJobs", que executa as rotinas dos módulos, está desativada.', 'nextool') . ' ' . $efeito,
               'action'  => __('Ative a ação em Configurar > Ações automáticas.', 'nextool'),
            ];
         case 'never_ran':
            return [
               'title'   => __('As rotinas do NexTool nunca rodaram', 'nextool'),
               'message' => ($desde !== ''
                  ? sprintf(__('A ação automática "nextoolJobs", que executa as rotinas dos módulos, existe desde %s e ainda não rodou.', 'nextool'), $desde)
                  : __('A ação automática "nextoolJobs", que executa as rotinas dos módulos, ainda não rodou.', 'nextool')) . ' ' . $efeito,
               'action'  => __('Confira se o cron do servidor do GLPI está configurado.', 'nextool'),
            ];
         case 'task_stuck':
            return [
               'title'   => __('As rotinas do NexTool estão presas em execução', 'nextool'),
               'message' => sprintf(__('A ação automática "nextoolJobs" está em execução desde %s, muito além do tempo normal, e não roda de novo enquanto isso.', 'nextool'), $desde) . ' ' . $efeito,
               'action'  => __('Se o processo não existe mais, libere a ação em Configurar > Ações automáticas.', 'nextool'),
            ];
         case 'cron_busy':
            $desdeTs = isset($saude['since_ts']) ? (int) $saude['since_ts'] : 0;
            $min = $desdeTs > 0 ? (int) floor(max(0, $now - $desdeTs) / 60) : 0;
            $presa = (string) ($saude['busy_task'] ?? '');
            $presaMin = !empty($saude['busy_since_ts']) ? (int) floor(max(0, $now - (int) $saude['busy_since_ts']) / 60) : 0;
            return [
               'title'   => sprintf(__('As rotinas do NexTool estão atrasadas há %d minutos', 'nextool'), $min),
               'message' => $presa !== ''
                  ? sprintf(__('O cron do GLPI está ativo, mas a fila de ações automáticas está presa: a ação "%1$s" está em execução há %2$d minutos e as ações dos plugins esperam a vez. As rotinas do NexTool não rodam desde %3$s.', 'nextool'), $presa, $presaMin, $desde)
                  : sprintf(__('O cron do GLPI está ativo, mas as ações dos plugins não estão tendo vez: o GLPI roda poucas ações automáticas por vez e as dele vêm primeiro. As rotinas do NexTool não rodam desde %s.', 'nextool'), $desde),
               'action'  => $presa !== ''
                  ? sprintf(__('Abra a ação "%s" para ver o que a deixa lenta (na fila de e-mails, costuma ser o servidor de e-mail).', 'nextool'), $presa)
                  : __('Confira as ações automáticas do GLPI que rodam com mais frequência em Configurar > Ações automáticas.', 'nextool'),
            ];
         case 'cron_stopped':
            // `since_ts` vem do banco (imune ao fuso do PHP); a string só para quem chama sem ele.
            $desdeTs = isset($saude['since_ts']) ? (int) $saude['since_ts']
               : (!empty($saude['since']) ? (int) strtotime((string) $saude['since']) : 0);
            $min = $desdeTs > 0 ? (int) floor(max(0, $now - $desdeTs) / 60) : 0;
            return [
               'title'   => sprintf(__('As rotinas do NexTool não rodam há %d minutos', 'nextool'), $min),
               'message' => sprintf(
                  __('A ação automática que executa as rotinas dos módulos não roda desde %s. Sincronizações, filas e avisos que dependem dela estão parados. Confira se o cron do servidor do GLPI está ativo.', 'nextool'),
                  $desde
               ),
               'action'  => __('Confira se o cron do servidor do GLPI está ativo.', 'nextool'),
            ];
      }
      return ['title' => '', 'message' => '', 'action' => ''];
   }

   /**
    * Chave do módulo dono de uma classe `PluginNextool<Chave>...` (a mais longa que casa, entre os módulos
    * habilitados). Null para classe da base.
    */
   public static function moduleKeyFromClass(string $class): ?string {
      global $DB;
      $resto = strtolower($class);
      if (strpos($resto, 'pluginnextool') !== 0 || !$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         return null;
      }
      $resto = substr($resto, strlen('pluginnextool'));
      $achada = null;
      foreach ($DB->request([
         'SELECT' => ['module_key'],
         'FROM'   => 'glpi_plugin_nextool_main_modules',
         'WHERE'  => ['is_enabled' => 1, 'is_installed' => 1],
      ]) as $row) {
         $chave = strtolower((string) $row['module_key']);
         if ($chave !== '' && strpos($resto, $chave) === 0 && ($achada === null || strlen($chave) > strlen($achada))) {
            $achada = $chave;
         }
      }
      return $achada;
   }

   /** O módulo declara alguma rotina (`getScheduledJobs()`)? Nunca lança. */
   public static function moduleHasJobs(string $moduleKey): bool {
      try {
         require_once NEXTOOL_PHP_DIR . '/inc/modulemanager.class.php';
         $module = PluginNextoolModuleManager::getInstance()->getModule($moduleKey);
         if ($module === null || !method_exists($module, 'getScheduledJobs')) {
            return false;
         }
         $jobs = $module->getScheduledJobs();
         return is_array($jobs) && $jobs !== [];
      } catch (\Throwable $e) {
         return false;
      }
   }

   /** Depois de fechada no X, a faixa do topo volta a aparecer passado este tempo (segundos). */
   public const STRIP_SNOOZE_SECONDS = 14400;

   /**
    * Aviso "rotinas paradas" (#300). Só para quem enxerga as abas administrativas do NexTool e só enquanto
    * `health()` acusa problema: some sozinho quando a `nextoolJobs` volta a rodar.
    *
    * @param string      $contexto  'strip' = faixa no TOPO da página (acima das abas e do hero), com X para fechar
    *                               (volta depois de STRIP_SNOOZE_SECONDS). Na página do NexTool traz o que fazer
    *                               (linha do crontab, link da ação automática ou "Recriar ação"); na página de um
    *                               módulo (passar `$moduleKey`), só quando o módulo declara rotina, com o link para
    *                               a tela de Rotinas.
    *                               'central' = página inicial do GLPI, no MESMO formato dos avisos nativos dela
    *                               (`central/messages.html.twig`).
    * @param string|null $moduleKey página de módulo (só no 'strip')
    */
   public static function renderAlert(string $contexto, ?string $moduleKey = null): string {
      global $CFG_GLPI;
      try {
         if (!class_exists('Session') || !Session::getLoginUserID()
             || !class_exists('PluginNextoolPermissionManager')
             || !PluginNextoolPermissionManager::canAccessAdminTabs()) {
            return '';
         }
         $saude = self::health();
         if ($saude['ok']) {
            return '';
         }
         if ($moduleKey !== null && !self::moduleHasJobs($moduleKey)) {
            return '';
         }
         if ($contexto === 'central' && PluginNextoolJobDispatcher::collectJobs() === []) {
            // Nenhum módulo ativo usa rotinas: não há o que estar parado.
            return '';
         }
         $texto = self::healthText($saude);
         $esc = static fn (string $v): string => PluginNextoolCompat::escape($v);
         $raiz = (string) ($CFG_GLPI['root_doc'] ?? '');
         $urlRotinas = $raiz . self::routinesUrl();
         $motivo = (string) $saude['reason'];
         // GLPI 10 usa Font Awesome nos avisos da página inicial; 11 e 12, Tabler.
         $icone = PluginNextoolCompat::glpiMajor() < 11 ? 'fas fa-3x fa-exclamation-triangle' : 'ti ti-alert-triangle fs-3x';

         $cor = self::isWarningReason($motivo) ? 'alert-warning' : 'alert-danger';
         if ($contexto === 'central') {
            return '<div class="message-area nextool-jobs-health-alert" data-reason="' . $esc($motivo) . '">'
               . '<div class="alert alert-important ' . $cor . ' d-flex" role="alert">'
               . '<i class="' . $icone . '"></i><ul>'
               . '<li>' . $esc($texto['title'] . '. ' . $texto['message']) . ' '
               . '<a class="text-white fw-bold text-decoration-underline" href="' . $esc($urlRotinas) . '">' . $esc(__('Ver as rotinas do NexTool', 'nextool')) . '</a></li>'
               . '</ul></div></div>';
         }

         // Faixa do topo. O script a leva para fora da aba (primeiro elemento da área da página) e respeita o X.
         $noNextool = $moduleKey === null;
         $acao = '';
         $extra = '';
         if ($noNextool) {
            if ($motivo === 'never_ran' || $motivo === 'cron_stopped') {
               $extra = '<div class="small mt-1">' . $esc(__('Linha do crontab do usuário do servidor web:', 'nextool'))
                  . ' <code class="text-white bg-transparent border border-white px-1">'
                  . $esc('* * * * * php ' . rtrim((string) GLPI_ROOT, '/') . '/front/cron.php') . '</code></div>';
            }
            if ($motivo === 'cron_busy') {
               $acao = !empty($saude['busy_task_id'])
                  ? '<a class="btn btn-sm btn-light text-nowrap" href="' . $esc($raiz . self::cronTaskUrl((int) $saude['busy_task_id'])) . '"><i class="ti ti-external-link me-1"></i>'
                     . $esc(__('Abrir a ação presa', 'nextool')) . '</a>'
                  : '<a class="btn btn-sm btn-light text-nowrap" href="' . $esc($raiz . '/front/crontask.php') . '"><i class="ti ti-external-link me-1"></i>'
                     . $esc(__('Abrir as ações automáticas', 'nextool')) . '</a>';
            } elseif ($motivo === 'task_missing') {
               if (PluginNextoolPermissionManager::canManageAdminTabs()) {
                  $acao = '<form method="post" action="' . $esc(PluginNextoolCompat::webDir() . '/front/config.save.php') . '" class="m-0">'
                     . '<input type="hidden" name="action" value="job_task_recreate">'
                     . '<input type="hidden" name="forcetab" value="PluginNextoolMainConfig$93">'
                     . '<button type="submit" class="btn btn-sm btn-light text-nowrap"><i class="ti ti-refresh me-1"></i>'
                     . $esc(__('Recriar ação', 'nextool')) . '</button>'
                     . Html::closeForm(false);
               }
            } elseif ($saude['task_id'] > 0 && $motivo !== 'never_ran' && $motivo !== 'cron_stopped') {
               $acao = '<a class="btn btn-sm btn-light text-nowrap" href="' . $esc($raiz . self::cronTaskUrl($saude['task_id'])) . '"><i class="ti ti-external-link me-1"></i>'
                  . $esc(__('Abrir a ação automática', 'nextool')) . '</a>';
            } else {
               $acao = '<a class="btn btn-sm btn-light text-nowrap" href="' . $esc($urlRotinas) . '"><i class="ti ti-clock-play me-1"></i>'
                  . $esc(__('Abrir Rotinas do NexTool', 'nextool')) . '</a>';
            }
         } else {
            $acao = '<a class="btn btn-sm btn-light text-nowrap" href="' . $esc($urlRotinas) . '"><i class="ti ti-clock-play me-1"></i>'
               . $esc(__('Abrir Rotinas do NexTool', 'nextool')) . '</a>';
         }

         $html = '<div class="alert alert-important ' . $cor . ' d-flex align-items-center gap-3 mb-3 nextool-jobs-health-alert nextool-jobs-health-strip" role="alert"'
            . ' data-reason="' . $esc($motivo) . '" data-snooze="' . (int) self::STRIP_SNOOZE_SECONDS . '" style="display:none !important;">'
            . '<i class="ti ti-clock-off fs-1"></i>'
            . '<div class="flex-fill"><div class="fw-bold">' . $esc($texto['title']) . '</div>'
            . '<div>' . $esc($texto['message']) . ($noNextool ? ' ' . $esc($texto['action']) : '') . '</div>' . $extra . '</div>'
            . $acao
            . '<button type="button" class="btn-close btn-close-white ms-1" data-nextool-jobs-health-close aria-label="'
            . $esc(__('Fechar por enquanto', 'nextool')) . '" title="' . $esc(__('Fechar por enquanto', 'nextool')) . '"></button>'
            . '</div>';
         $html .= <<<'JS'
<script>(function(){
var novo=document.currentScript?document.currentScript.previousElementSibling:null;
if(!novo||!novo.classList||!novo.classList.contains('nextool-jobs-health-strip')){var t=document.querySelectorAll('.nextool-jobs-health-strip');novo=t[t.length-1];}
if(!novo){return;}
var chave='nextool_jobs_health_closed_'+novo.getAttribute('data-reason');
var adiado=0;try{adiado=parseInt(window.localStorage.getItem(chave)||'0',10)||0;}catch(e){}
var soneca=(parseInt(novo.getAttribute('data-snooze'),10)||14400)*1000;
// Uma faixa só por página: a aba recarregada traz outra cópia; fica a mais nova, fora da aba.
document.querySelectorAll('.nextool-jobs-health-strip').forEach(function(el){if(el!==novo){el.remove();}});
if(Date.now()-adiado<soneca){novo.remove();return;}
var alvo=document.querySelector('#page .page-body')||document.querySelector('main#page')||document.getElementById('page');
if(alvo){alvo.insertBefore(novo,alvo.firstChild);}
novo.style.removeProperty('display');
var x=novo.querySelector('[data-nextool-jobs-health-close]');
if(x){x.addEventListener('click',function(){try{window.localStorage.setItem(chave,String(Date.now()));}catch(e){}novo.remove();});}
})();</script>
JS;
         return $html;
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool', 'jobs: aviso de rotinas paradas falhou: ' . $e->getMessage() . "\n");
         return '';
      }
   }

   /** Gancho `display_central`: faixa curta na página inicial do GLPI (só administradores do NexTool). */
   public static function displayCentral(): void {
      echo self::renderAlert('central');
   }

   /** Carimba o início da carência (instalação/atualização do plugin). */
   public static function markGrace(): void {
      try {
         Config::setConfigurationValues('plugin:nextool', [self::GRACE_KEY => (string) time()]);
      } catch (\Throwable $e) {
         // carência é conveniência: nunca atrapalha a instalação.
      }
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
      $row = $DB->request([
         'SELECT' => self::taskColumns(),
         'FROM'   => 'glpi_crontasks',
         'WHERE'  => ['itemtype' => $itemtype, 'name' => $name],
         'LIMIT'  => 1,
      ])->current();
      return $row ?: null;
   }

   /**
    * Colunas da CronTask + `lastrun` e `date_creation` em epoch, convertidos PELO BANCO.
    *
    * O core grava `lastrun` com o NOW() do banco, no fuso da sessão do banco. Converter a string com
    * `strtotime()` usa o fuso do processo PHP: quando o php.ini do CLI e o da web têm `date.timezone`
    * diferentes (ou o PHP difere do banco, sem `use_timezones`), a idade ganha a diferença inteira. Cliente
    * em 12/11/2026: o cron via "não rodam há 240 minutos" com a rotina acabando de rodar e a web via "ok",
    * e o sino alternava vermelho e "voltaram a rodar" a cada checagem. O core compara no SQL pelo mesmo
    * motivo (`CronTask::getNeedToRun`).
    *
    * @return array<int, mixed>
    */
   private static function taskColumns(): array {
      return [
         'glpi_crontasks.*',
         PluginNextoolCompat::queryExpression('UNIX_TIMESTAMP(`glpi_crontasks`.`lastrun`) AS `lastrun_ts`'),
         PluginNextoolCompat::queryExpression('UNIX_TIMESTAMP(`glpi_crontasks`.`date_creation`) AS `date_creation_ts`'),
      ];
   }

   /** Epoch de `lastrun`/`date_creation` de uma linha de `taskColumns()`; 0 quando vazio. */
   private static function epoch(array $task, string $coluna): int {
      if (empty($task[$coluna])) {
         return 0;
      }
      if (isset($task[$coluna . '_ts']) && is_numeric($task[$coluna . '_ts'])) {
         return (int) $task[$coluna . '_ts'];
      }
      return (int) strtotime((string) $task[$coluna]);
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
