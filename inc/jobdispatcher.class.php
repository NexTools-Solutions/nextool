<?php
/**
 * NexTool -- dispatcher de rotinas dos módulos (nextool-dev#281, S1 da auditoria das crons de 02/10).
 *
 * O GLPI executa no máximo `cron_limit` ações automáticas por minuto, core antes de plugin. Com cada módulo
 * registrando as próprias CronTasks frequentes, a fila satura e as de 1 min rodam a cada 3-4 min, quase
 * sempre sem trabalho. Aqui a base ocupa UMA vaga (`nextoolJobs`, a cada minuto) e reparte o tempo entre
 * as rotinas que os módulos declaram em `getScheduledJobs()`.
 *
 * Fica como CronTask própria o que precisa: envio com espaçamento deliberado entre mensagens
 * (`whatsappbot_queue_process`, anti-banimento) e tarefas diárias ou pesadas.
 *
 * Rodada (orçamento total BUDGET_SECONDS, cooperativo):
 *  - chave `jobs_dispatcher_enabled` (plugin:nextool, padrão 1) desligada: não roda nada. É interruptor de
 *    emergência, não fallback: as CronTasks que os módulos migraram não existem mais;
 *  - coleta os jobs dos módulos HABILITADOS; desliga (state=0) a CronTask que um job declara em `replaces`
 *    e que ainda exista (cliente que atualizou o módulo antes da base);
 *  - roda os vencidos, do mais atrasado ao menos, e quem estourou o orçamento na rodada anterior por último;
 *  - por job: `housekeeping` (retenção) SEMPRE, `run` só com `active()` verdadeiro; trava por job
 *    (a mesma do botão "Executar agora"); exceção isolada;
 *  - 5 falhas seguidas pausam o job por 2x o intervalo dele, dobrando a cada falha até 1 h; um sucesso
 *    tira a pausa.
 *    Estourar o tempo NÃO pausa: só registra e manda o job para o fim da próxima rodada.
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolJobDispatcher {

   public const TASK_NAME      = 'nextoolJobs';
   public const TABLE          = 'glpi_plugin_nextool_main_jobs';
   public const CONFIG_KEY     = 'jobs_dispatcher_enabled';
   public const BUDGET_SECONDS = 45;
   /** Margem: não começa job novo com menos que isso de orçamento. */
   public const START_MARGIN   = 2.0;
   /** Orçamento do "Executar agora" (fora da CronTask). */
   public const MANUAL_SECONDS = 30;
   public const MIN_EVERY      = 60;
   public const MAX_EVERY      = 3600;
   public const MAX_FAILS      = 5;
   public const MAX_PAUSE      = 3600;

   public static function getTypeName($nb = 0) {
      return __('Rotinas do NexTool', 'nextool');
   }

   public static function cronInfo($name) {
      if ($name === self::TASK_NAME) {
         return [
            'description' => __('Executa as rotinas frequentes dos módulos NexTool numa única ação automática', 'nextool'),
         ];
      }
      return [];
   }

   /**
    * CronTask `nextoolJobs` (MODE_EXTERNAL, a cada minuto).
    *
    * @return int 1 = algum job trabalhou; 0 = nada a fazer; -1 = algum job falhou e nenhum trabalhou.
    */
   public static function cronNextoolJobs(CronTask $task): int {
      require_once __DIR__ . '/jobcontext.class.php';
      if (!self::isEnabled()) {
         $task->log(__('Rotinas do NexTool desligadas (jobs_dispatcher_enabled = 0)', 'nextool'));
         return 0;
      }
      return self::runRound(PluginNextoolJobContext::forTask($task, self::BUDGET_SECONDS), time());
   }

   /** Chave de emergência. Padrão ligado. */
   public static function isEnabled(): bool {
      if (!class_exists('Config')) {
         return true;
      }
      $cfg = Config::getConfigurationValues('plugin:nextool', [self::CONFIG_KEY]);
      return (string) ($cfg[self::CONFIG_KEY] ?? '1') !== '0';
   }

   /**
    * Uma rodada. Pública para o teste injetar o relógio (`$now`) e um contexto curto.
    *
    * @param array<int, array>|null $jobs jobs já normalizados (teste); null = coleta dos módulos
    */
   public static function runRound(PluginNextoolJobContext $ctx, int $now, ?array $jobs = null): int {
      if (!self::tableReady()) {
         $ctx->log('tabela ' . self::TABLE . ' ausente: rode a atualização do plugin');
         return 0;
      }
      $jobs = $jobs ?? self::collectJobs($ctx);
      if ($jobs === []) {
         return 0;
      }
      foreach ($jobs as $job) {
         self::disableReplaced($job, $ctx);
      }

      $state = self::loadState();
      $due = [];
      foreach ($jobs as $job) {
         $row = $state[self::stateKey($job)] ?? null;
         if (!self::isDue($job, $row, $now)) {
            continue;
         }
         $lastRun = ($row !== null && !empty($row['last_run'])) ? strtotime((string) $row['last_run']) : false;
         $due[] = [
            'job'     => $job,
            'overrun' => (int) ($row['last_overrun'] ?? 0),
            'atraso'  => $lastRun === false ? PHP_INT_MAX : $now - ($lastRun + (int) $job['every']),
         ];
      }
      // Quem estourou o orçamento vai por último; entre iguais, o mais atrasado primeiro.
      usort($due, static function (array $a, array $b): int {
         return [$a['overrun'], -$a['atraso']] <=> [$b['overrun'], -$b['atraso']];
      });

      $trabalhou = false;
      $falhou = false;
      foreach ($due as $i => $item) {
         if ($ctx->expired(self::START_MARGIN)) {
            $ctx->log(sprintf('orçamento esgotado; %d rotina(s) ficam para a próxima rodada', count($due) - $i));
            break;
         }
         $res = self::execute($item['job'], $ctx, $now, $state[self::stateKey($item['job'])] ?? null);
         if ($res['result'] === null) {
            continue; // outra execução segurava a trava
         }
         if ($res['result'] > 0) {
            $trabalhou = true;
         } elseif ($res['result'] < 0) {
            $falhou = true;
         }
      }

      if ($trabalhou) {
         return 1;
      }
      return $falhou ? -1 : 0;
   }

   /**
    * Jobs dos módulos habilitados, já validados. Módulo que lança exceção ou declara job inválido é
    * ignorado (e registrado), sem derrubar os outros.
    *
    * @return array<int, array{module_key:string, key:string, every:int, run:callable, active:?callable, housekeeping:?callable, replaces:?array}>
    */
   public static function collectJobs(?PluginNextoolJobContext $ctx = null): array {
      global $DB;
      if (!$DB->tableExists('glpi_plugin_nextool_main_modules')) {
         return [];
      }
      require_once NEXTOOL_PHP_DIR . '/inc/modulemanager.class.php';
      $manager = PluginNextoolModuleManager::getInstance();

      $jobs = [];
      foreach ($DB->request([
         'SELECT' => ['module_key'],
         'FROM'   => 'glpi_plugin_nextool_main_modules',
         'WHERE'  => ['is_enabled' => 1, 'is_installed' => 1],
         'ORDER'  => 'module_key',
      ]) as $row) {
         $moduleKey = (string) $row['module_key'];
         try {
            $module = $manager->getModule($moduleKey);
            if ($module === null || !method_exists($module, 'getScheduledJobs')) {
               continue;
            }
            $declarados = $module->getScheduledJobs();
         } catch (\Throwable $e) {
            self::warn($ctx, sprintf('%s: getScheduledJobs falhou: %s', $moduleKey, $e->getMessage()));
            continue;
         }
         if (!is_array($declarados)) {
            continue;
         }
         foreach ($declarados as $decl) {
            $job = self::normalize($moduleKey, $decl);
            if (is_string($job)) {
               self::warn($ctx, sprintf('%s: rotina ignorada (%s)', $moduleKey, $job));
               continue;
            }
            $jobs[] = $job;
         }
      }
      return $jobs;
   }

   /**
    * Valida e normaliza a declaração de um job. Devolve o motivo (string) quando inválida.
    *
    * @return array|string
    */
   public static function normalize(string $moduleKey, $decl) {
      if (!is_array($decl)) {
         return 'declaração não é array';
      }
      $key = (string) ($decl['key'] ?? '');
      if (!preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
         return 'key inválida';
      }
      $every = (int) ($decl['every'] ?? 0);
      if ($every < self::MIN_EVERY || $every > self::MAX_EVERY) {
         return sprintf('%s: every fora de %d..%d', $key, self::MIN_EVERY, self::MAX_EVERY);
      }
      if (!isset($decl['run']) || !is_callable($decl['run'])) {
         return $key . ': run não é callable';
      }
      foreach (['active', 'housekeeping'] as $opcional) {
         if (isset($decl[$opcional]) && !is_callable($decl[$opcional])) {
            return sprintf('%s: %s não é callable', $key, $opcional);
         }
      }
      $replaces = null;
      if (isset($decl['replaces'])) {
         $r = $decl['replaces'];
         if (!is_array($r) || count($r) !== 2 || !is_string($r[0] ?? null) || !is_string($r[1] ?? null)) {
            return $key . ': replaces deve ser [itemtype, nome]';
         }
         // Um módulo só desliga CronTask da própria classe (PluginNextool<Modulo>...).
         if (stripos($r[0], 'PluginNextool' . ucfirst($moduleKey)) !== 0) {
            return $key . ': replaces aponta para CronTask de outro módulo';
         }
         $replaces = [$r[0], $r[1]];
      }
      return [
         'module_key'   => $moduleKey,
         'key'          => $key,
         'every'        => $every,
         'run'          => $decl['run'],
         'active'       => $decl['active'] ?? null,
         'housekeeping' => $decl['housekeeping'] ?? null,
         'replaces'     => $replaces,
      ];
   }

   /** Vencido e não pausado. */
   public static function isDue(array $job, ?array $row, int $now): bool {
      if ($row !== null && !empty($row['paused_until']) && strtotime((string) $row['paused_until']) > $now) {
         return false;
      }
      if ($row === null || empty($row['last_run'])) {
         return true;
      }
      return strtotime((string) $row['last_run']) + (int) $job['every'] <= $now;
   }

   /**
    * Executa um job sob a trava dele e grava o estado.
    *
    * @return array{result:?int, message:string, duration_ms:int}  result null = trava ocupada
    */
   private static function execute(array $job, PluginNextoolJobContext $ctx, int $now, ?array $row): array {
      $trava = self::lockName($job['module_key'], $job['key']);
      if (!self::lock($trava)) {
         return ['result' => null, 'message' => 'em execução por outro processo', 'duration_ms' => 0];
      }
      $jctx = $ctx->scoped($job['module_key'] . '/' . $job['key']);
      $inicio = microtime(true);
      $result = 0;
      $erro = '';
      $ativo = null;
      try {
         if ($job['housekeeping'] !== null) {
            ($job['housekeeping'])($jctx);
         }
         $ativo = $job['active'] === null ? true : (bool) ($job['active'])();
         if ($ativo) {
            $result = (int) ($job['run'])($jctx);
         }
      } catch (\Throwable $e) {
         $result = -1;
         $erro = get_class($e) . ': ' . $e->getMessage();
         $jctx->log('falhou: ' . $erro);
         if (class_exists('Toolbox')) {
            Toolbox::logInFile('plugin_nextool', sprintf("jobs: %s/%s falhou: %s\n", $job['module_key'], $job['key'], $erro));
         }
      } finally {
         self::unlock($trava);
      }
      $duracao = (int) round((microtime(true) - $inicio) * 1000);
      $overrun = $ctx->timeLeft() < 0 ? 1 : 0;
      if ($overrun) {
         $jctx->log(sprintf('passou do orçamento da rodada (%d ms); vai para o fim da próxima', $duracao));
      }
      $mensagem = $erro !== '' ? $erro : $jctx->lastMessage();
      if ($ativo === false && $erro === '' && $mensagem === '') {
         $mensagem = __('Funcionalidade desligada: só a limpeza rodou', 'nextool');
      }
      self::saveResult($job, $row, $now, $result, $duracao, $overrun, $mensagem);

      return ['result' => $result, 'message' => $mensagem, 'duration_ms' => $duracao];
   }

   private static function saveResult(array $job, ?array $row, int $now, int $result, int $duracao, int $overrun, string $mensagem): void {
      global $DB;
      $fails = (int) ($row['fails'] ?? 0);
      $pausa = null;
      if ($result < 0) {
         $fails++;
         if ($fails >= self::MAX_FAILS) {
            // 2x o intervalo do job na 5ª falha, dobrando a cada falha seguinte, até MAX_PAUSE.
            $segundos = (int) min(self::MAX_PAUSE, (int) $job['every'] * (2 ** min(10, $fails - self::MAX_FAILS + 1)));
            $pausa = date('Y-m-d H:i:s', $now + $segundos);
         }
      } else {
         $fails = 0;
      }
      $dados = [
         'last_run'         => date('Y-m-d H:i:s', $now),
         'last_result'      => $result,
         'last_duration_ms' => $duracao,
         'last_overrun'     => $overrun,
         'last_message'     => mb_substr($mensagem, 0, 255),
         'fails'            => $fails,
         'paused_until'     => $pausa,
         'date_mod'         => date('Y-m-d H:i:s'),
      ];
      $chave = ['module_key' => $job['module_key'], 'job_key' => $job['key']];
      if ($row === null || empty($row['id'])) {
         $DB->insert(self::TABLE, $chave + $dados);
      } else {
         $DB->update(self::TABLE, $dados, ['id' => (int) $row['id']]);
      }
   }

   /**
    * Desliga (state=0) a CronTask que o job substitui, se ela ainda existir ligada. Cobre o cliente que
    * atualizou o módulo numa base sem o dispatcher e depois atualizou a base: sem isto, o trabalho rodaria
    * duas vezes. Idempotente e barato (uma consulta por job com `replaces`).
    */
   private static function disableReplaced(array $job, PluginNextoolJobContext $ctx): void {
      global $DB;
      if ($job['replaces'] === null) {
         return;
      }
      [$itemtype, $nome] = $job['replaces'];
      foreach ($DB->request([
         'SELECT' => ['id', 'state'],
         'FROM'   => 'glpi_crontasks',
         'WHERE'  => ['itemtype' => $itemtype, 'name' => $nome],
      ]) as $cron) {
         if ((int) $cron['state'] !== CronTask::STATE_DISABLE) {
            $DB->update('glpi_crontasks', ['state' => CronTask::STATE_DISABLE], ['id' => (int) $cron['id']]);
            $ctx->log(sprintf('ação automática %s/%s desligada: substituída pela rotina %s/%s', $itemtype, $nome, $job['module_key'], $job['key']));
         }
      }
   }

   /**
    * "Executar agora" da tela: roda o job fora da CronTask, com orçamento próprio e a mesma trava.
    *
    * @return array{ok:bool, result:?int, message:string}
    */
   public static function runNow(string $moduleKey, string $jobKey): array {
      require_once __DIR__ . '/jobcontext.class.php';
      if (!self::tableReady()) {
         return ['ok' => false, 'result' => null, 'message' => __('Tabela de rotinas ausente: atualize o plugin', 'nextool')];
      }
      foreach (self::collectJobs() as $job) {
         if ($job['module_key'] === $moduleKey && $job['key'] === $jobKey) {
            $state = self::loadState();
            $res = self::execute($job, PluginNextoolJobContext::forTask(null, self::MANUAL_SECONDS), time(), $state[self::stateKey($job)] ?? null);
            if ($res['result'] === null) {
               return ['ok' => false, 'result' => null, 'message' => __('A rotina já está em execução', 'nextool')];
            }
            return ['ok' => $res['result'] >= 0, 'result' => $res['result'], 'message' => $res['message']];
         }
      }
      return ['ok' => false, 'result' => null, 'message' => __('Rotina não encontrada ou módulo desativado', 'nextool')];
   }

   /** "Retomar": zera as falhas e a pausa. */
   public static function resume(string $moduleKey, string $jobKey): bool {
      global $DB;
      if (!self::tableReady()) {
         return false;
      }
      return (bool) $DB->update(self::TABLE, ['fails' => 0, 'paused_until' => null, 'date_mod' => date('Y-m-d H:i:s')], [
         'module_key' => $moduleKey,
         'job_key'    => $jobKey,
      ]);
   }

   /**
    * Linhas para a tela: jobs declarados pelos módulos habilitados, com o estado gravado.
    *
    * @return array<int, array{module_key:string, key:string, every:int, state:?array}>
    */
   public static function getStatusRows(): array {
      if (!self::tableReady()) {
         return [];
      }
      $state = self::loadState();
      $linhas = [];
      foreach (self::collectJobs() as $job) {
         $linhas[] = [
            'module_key' => $job['module_key'],
            'key'        => $job['key'],
            'every'      => $job['every'],
            'state'      => $state[self::stateKey($job)] ?? null,
         ];
      }
      return $linhas;
   }

   public static function tableReady(): bool {
      global $DB;
      return isset($DB) && $DB->tableExists(self::TABLE);
   }

   /** @return array<string, array> estado por "modulo/job" */
   private static function loadState(): array {
      global $DB;
      $out = [];
      foreach ($DB->request(['FROM' => self::TABLE]) as $row) {
         $out[$row['module_key'] . '/' . $row['job_key']] = $row;
      }
      return $out;
   }

   private static function stateKey(array $job): string {
      return $job['module_key'] . '/' . $job['key'];
   }

   private static function warn(?PluginNextoolJobContext $ctx, string $msg): void {
      if ($ctx !== null) {
         $ctx->log($msg);
      } elseif (class_exists('Toolbox')) {
         Toolbox::logInFile('plugin_nextool', 'jobs: ' . $msg . "\n");
      }
   }

   /** Nome da trava: por banco (dois GLPIs no mesmo MariaDB não disputam) e por job; até 64 caracteres. */
   private static function lockName(string $moduleKey, string $jobKey): string {
      global $DB;
      return 'nxjob_' . substr(md5((string) ($DB->dbdefault ?? '') . '|' . $moduleKey . '|' . $jobKey), 0, 24);
   }

   private static function lock(string $trava): bool {
      global $DB;
      $metodo = method_exists($DB, 'doQuery') ? 'doQuery' : 'query'; // doQuery só a partir do GLPI 10.0.7
      try {
         $res = $DB->$metodo(sprintf("SELECT GET_LOCK('%s', 0) AS l", $trava));
      } catch (\Throwable $e) {
         return false;
      }
      if (!$res instanceof \mysqli_result) {
         return false;
      }
      $row = $res->fetch_assoc();
      return (int) ($row['l'] ?? 0) === 1;
   }

   private static function unlock(string $trava): void {
      global $DB;
      $metodo = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
      try {
         $DB->$metodo(sprintf("SELECT RELEASE_LOCK('%s')", $trava));
      } catch (\Throwable $e) {
         // a trava cai sozinha quando a conexão fecha
      }
   }
}
