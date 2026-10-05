<?php
/**
 * NexTool -- CronTask de retenção do cloud link (auditoria de 2026-09-24, ME-08; decisão (i) do owner
 * como DPO: 24 h, com prazo).
 *
 * De hora em hora, apaga as linhas de `glpi_plugin_nextool_cloud_requests` com mais de
 * RETENTION_HOURS (24 h) e os nonces vencidos. Até a 6.24.1 a limpeza era por sorteio dentro do
 * executor (1 em 50 chamadas; nonces, 1 em 100): o prazo dependia do uso, e um ambiente que parava de
 * usar o cloud link guardava as últimas respostas para sempre. Também roda o log do cloud link uma vez
 * por dia e apaga as cópias com mais de LOG_KEEP_DAYS dias (rotateLog()), e redeclara o endereço enquanto
 * houver aviso dele aberto (refreshEndpointIfAlert(), 6.26.1).
 *
 * MODE_EXTERNAL, como a catalogSync: o cloud link já depende do cron do GLPI (é a catalogSync que
 * entrega a credencial do vínculo, e a webhooksync do telegrambot que entrega o token do bot).
 * O GLPI remove a tarefa sozinho no uninstall do plugin (CronTask::unregister).
 *
 * @since pós-6.24.1 (auditoria do cloud link, 2026-09-24)
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolCronCloudPurge {

   /** Log do cloud link (`Toolbox::logInFile`) e o prazo das cópias rodadas (decisão (i): 30 dias). */
   public const LOG_NAME      = 'plugin_nextool_cloud';
   public const LOG_KEEP_DAYS = 30;

   public static function getTypeName($nb = 0) {
      return __('NexTool Cloud link', 'nextool');
   }

   public static function cronInfo($name) {
      if ($name === 'cloudPurge') {
         return [
            'description' => __('Cloud link: apaga os registros de ações com mais de 24 horas e o log com mais de 30 dias', 'nextool'),
         ];
      }
      return [];
   }

   /**
    * @return int >0 = apagou alguma coisa; 0 = nada a apagar
    */
   public static function cronCloudPurge(CronTask $task): int {
      // Cada etapa isolada (auditoria das crons, achados 53 e S2): uma exceção numa delas não impede as outras
      // nem deixa a tarefa sem registro. Etapa com falha faz a execução terminar como erro (-1).
      $fez    = false;
      $falhas = 0;
      $etapa  = static function (string $nome, callable $fn) use ($task, &$falhas): void {
         try {
            $fn();
         } catch (Throwable $e) {
            $falhas++;
            $task->log(sprintf('%s: erro: %s', $nome, $e->getMessage()));
         }
      };

      $etapa('cloud_purge', static function () use ($task, &$fez): void {
         $apagados = self::purge();
         $total    = $apagados['requests'] + $apagados['nonces'];
         $task->addVolume($total);
         $task->log(sprintf('cloud_purge: requests=%d nonces=%d', $apagados['requests'], $apagados['nonces']));
         $fez = $fez || $total > 0;
      });
      // Contato externo (6.27.0): auditoria com mais de 90 dias, ligação com chamado apagado e contato sem
      // chamado que não fala há 90 dias.
      $etapa('cloud_contacts', static function () use ($task, &$fez): void {
         $contatos = NEXTOOL_PHP_DIR . '/inc/cloudcontacts.class.php';
         if (!is_file($contatos)) {
            return;
         }
         require_once $contatos;
         $c = PluginNextoolCloudContacts::purge();
         $task->addVolume($c['log'] + $c['links'] + $c['contacts']);
         $task->log(sprintf('cloud_contacts: auditoria=%d ligacoes=%d contatos=%d', $c['log'], $c['links'], $c['contacts']));
         $fez = $fez || ($c['log'] + $c['links'] + $c['contacts']) > 0;
      });
      $etapa('cloud_log', static function () use ($task, &$fez): void {
         $log = self::rotateLog();
         $task->log(sprintf('cloud_log: rodado=%s copias_apagadas=%d', $log['rotated'] ? 'sim' : 'nao', $log['deleted']));
         $fez = $fez || $log['rotated'] || $log['deleted'] > 0;
      });
      $etapa('cloud_endpoint', static function () use ($task): void {
         $task->log('cloud_endpoint: ' . self::refreshEndpointIfAlert()); // sem_aviso | reported:<destino> | failed:<erro>
      });
      // Ressincronização pedida por um kid desconhecido e que ficou pendente (servidor sem PHP-FPM, que
      // não solta a resposta antes): atendida aqui em até 1 h, em vez de esperar o catalogSync (6 h).
      $etapa('cloud_resync', static function () use ($task): void {
         $resync = NEXTOOL_PHP_DIR . '/inc/cloudresync.class.php';
         if (!is_file($resync)) {
            return;
         }
         require_once $resync;
         $task->log('cloud_resync: ' . PluginNextoolCloudResync::runPending()); // none|validated|throttled|failed
      });

      if ($falhas > 0) {
         return -1;
      }

      return $fez ? 1 : 0;
   }

   /**
    * Retenção do log do cloud link (decisão (i) do owner como DPO: 30 dias, com rotação). Uma vez por
    * dia o log em uso vira `plugin_nextool_cloud.log.<AAAAMMDD-HHMMSS>`, e o próximo `logInFile()` abre
    * outro; cópia sem escrita há mais de LOG_KEEP_DAYS dias é apagada. Cada cópia junta no máximo um
    * dia, então nenhum registro passa de LOG_KEEP_DAYS + 1 dias. A marca de "já rodou hoje" é a própria
    * cópia do dia, sem estado no banco. Até a 6.24.1 o log crescia sem prazo.
    *
    * Público para a bateria provar a retenção num diretório temporário, sem esperar o cron.
    *
    * @param int    $now timestamp (injetável no teste)
    * @param string $dir diretório dos logs (injetável no teste; padrão GLPI_LOG_DIR)
    * @return array{rotated:bool, deleted:int}
    */
   public static function rotateLog(int $now = 0, string $dir = ''): array {
      $now   = $now > 0 ? $now : time();
      $atual = self::logPath($dir);
      $res   = ['rotated' => false, 'deleted' => 0];
      if ($atual === '') {
         return $res;
      }
      clearstatcache();
      if (is_file($atual) && filesize($atual) > 0 && (glob($atual . '.' . date('Ymd', $now) . '-*') ?: []) === []) {
         $res['rotated'] = @rename($atual, $atual . '.' . date('Ymd-His', $now));
      }
      foreach (glob($atual . '.*') ?: [] as $copia) {
         $mtime = @filemtime($copia);
         if ($mtime !== false && $mtime < $now - self::LOG_KEEP_DAYS * 86400 && @unlink($copia)) {
            $res['deleted']++;
         }
      }

      return $res;
   }

   /**
    * Uninstall: apaga o log do cloud link e as cópias rodadas. Sem a tarefa (o GLPI a remove no
    * uninstall), o que ficasse não venceria nunca.
    *
    * @param string $dir diretório dos logs (injetável no teste; padrão GLPI_LOG_DIR)
    * @return int arquivos apagados
    */
   public static function removeLogs(string $dir = ''): int {
      $atual = self::logPath($dir);
      if ($atual === '') {
         return 0;
      }
      $apagados = 0;
      foreach (array_merge([$atual], glob($atual . '.*') ?: []) as $arquivo) {
         if (is_file($arquivo) && @unlink($arquivo)) {
            $apagados++;
         }
      }

      return $apagados;
   }

   /**
    * Aviso do endereço aberto (destino em conflito, recusado ou pendente há mais de 24 h, ou declaração recusada
    * três vezes seguidas): redeclara AGORA, em vez de esperar a reafirmação do ciclo de 6 h. A base só fica
    * sabendo do estado do destino na resposta da declaração, então um estado passageiro do cérebro (o 401 do
    * 1º ping de uma rotação de chave, um conflito que o suporte resolveu promovendo a URL) deixava o aviso aberto
    * por até 6 h; assim ele se corrige em até 1 h. Sem aviso aberto, nada muda. A declaração é idempotente no
    * cérebro (contrato 1.1 §2.13.2); um `pendente` legítimo, à espera da promoção no portal, só redeclara uma
    * vez por hora até alguém promover.
    *
    * @param callable|null $declarar injetável no teste (padrão: PluginNextoolCloudClient::reportEndpoint)
    * @return string `sem_aviso` ou o resultado da declaração (`reported:<destino>`, `failed:<erro>`, `skipped`)
    * @since 6.26.1
    */
   public static function refreshEndpointIfAlert(?callable $declarar = null): string {
      $alertas = NEXTOOL_PHP_DIR . '/inc/alertmanager.class.php';
      if (!is_file($alertas)) {
         return 'sem_aviso';
      }
      require_once $alertas;
      require_once NEXTOOL_PHP_DIR . '/inc/cloudclient.class.php';
      $aberto = PluginNextoolAlertManager::findActiveLocalKey(PluginNextoolCloudClient::DESTINO_FAMILY)
         ?? PluginNextoolAlertManager::findActiveLocalKey(PluginNextoolCloudClient::ALERT_FAMILY);
      if ($aberto === null) {
         return 'sem_aviso';
      }
      $r       = $declarar !== null ? $declarar() : PluginNextoolCloudClient::reportEndpoint();
      $acao    = (string) ($r['action'] ?? 'failed');
      $detalhe = (string) (($r['destino'] ?? '') !== '' ? $r['destino'] : ($r['error'] ?? ''));

      return $acao . ($detalhe !== '' ? ':' . $detalhe : '');
   }

   /** Caminho do log em uso, ou '' sem diretório de logs. */
   private static function logPath(string $dir): string {
      $dir = $dir !== '' ? $dir : (defined('GLPI_LOG_DIR') ? (string) GLPI_LOG_DIR : '');

      return ($dir !== '' && is_dir($dir)) ? rtrim($dir, '/') . '/' . self::LOG_NAME . '.log' : '';
   }

   /**
    * Apaga o que passou do prazo. Público para a bateria provar a retenção sem esperar o cron.
    *
    * @param int $now timestamp (injetável no teste)
    * @return array{requests:int, nonces:int} linhas apagadas. Só informativo: o affectedRows do GLPI
    *         pode vir errado quando a consulta gera warning.
    */
   public static function purge(int $now = 0): array {
      global $DB;
      require_once NEXTOOL_PHP_DIR . '/inc/cloudexecutor.class.php';
      require_once NEXTOOL_PHP_DIR . '/inc/cloudrequestguard.class.php';

      $now  = $now > 0 ? $now : time();
      $alvos = [
         // Mesmo relógio (PHP) com que o executor grava `created_at`.
         'requests' => [
            PluginNextoolCloudExecutor::REQUESTS_TABLE,
            'request_id',
            'created_at',
            $now - (PluginNextoolCloudExecutor::RETENTION_HOURS * 3600),
         ],
         'nonces'   => [PluginNextoolCloudRequestGuard::NONCE_TABLE, 'nonce', 'expires_at', $now],
      ];

      $apagados = ['requests' => 0, 'nonces' => 0];
      foreach ($alvos as $chave => [$tabela, $pk, $coluna, $limite]) {
         if (!$DB->tableExists($tabela)) {
            continue;
         }
         $apagados[$chave] = self::deleteInBatches($tabela, $pk, $coluna, date('Y-m-d H:i:s', $limite));
      }

      return $apagados;
   }

   /** Linhas apagadas por lote e teto de lotes por execução (o resto sai na hora seguinte). */
   public const PURGE_BATCH       = 1000;
   public const PURGE_MAX_BATCHES = 50;

   /**
    * DELETE em lotes pela chave primária (auditoria das crons, achado 53): um DELETE único segurava o lock da
    * tabela que o executor grava a cada ação do cloud link. Cada lote lê até PURGE_BATCH chaves pelo índice
    * da coluna de prazo e apaga só essas.
    *
    * @return int linhas apagadas (contadas pelas chaves lidas, não pelo affectedRows)
    */
   private static function deleteInBatches(string $tabela, string $pk, string $coluna, string $antesDe): int {
      global $DB;
      $total = 0;
      for ($lote = 0; $lote < self::PURGE_MAX_BATCHES; $lote++) {
         $chaves = [];
         foreach ($DB->request([
            'SELECT' => [$pk],
            'FROM'   => $tabela,
            'WHERE'  => [$coluna => ['<', $antesDe]],
            'ORDER'  => [$coluna . ' ASC'],
            'LIMIT'  => self::PURGE_BATCH,
         ]) as $linha) {
            $chaves[] = $linha[$pk];
         }
         if ($chaves === []) {
            break;
         }
         $DB->delete($tabela, [$pk => $chaves]);
         $total += count($chaves);
         if (count($chaves) < self::PURGE_BATCH) {
            break;
         }
      }

      return $total;
   }
}
