<?php
/**
 * NexTool -- `php bin/console plugins:nextool:secrets:rekey` (nextool-dev#269).
 *
 * Depois de `php bin/console security:change_key`, regrava os segredos do NexTool (base e módulos) com a chave nova.
 * Precisa de uma CÓPIA do glpicrypt.key de antes da troca. Sem `--apply`, só simula e mostra o que faria.
 *
 *   php bin/console plugins:nextool:secrets:rekey --old-key-file=/caminho/glpicrypt.key.antiga
 *   php bin/console plugins:nextool:secrets:rekey --old-key-file=/caminho/glpicrypt.key.antiga --apply
 *
 * Nunca imprime valor de segredo: só local e contagem. Ver PluginNextoolSecretRekey.
 *
 * @since pós-6.31.1 (nextool-dev#269)
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginNextoolSecretsRekeyCommand extends Command {

   protected function configure() {
      $this->setName('plugins:nextool:secrets:rekey');
      $this->setDescription('Regrava os segredos do NexTool com a chave atual do GLPI depois de security:change_key');
      $this->addOption('old-key-file', null, InputOption::VALUE_REQUIRED, 'Cópia do glpicrypt.key de ANTES da troca');
      $this->addOption('apply', null, InputOption::VALUE_NONE, 'Grava (sem esta opção, só simula)');
   }

   protected function execute(InputInterface $input, OutputInterface $output): int {
      $dir = defined('NEXTOOL_PHP_DIR') ? NEXTOOL_PHP_DIR : dirname(__DIR__);
      foreach (['dbcompat', 'secretvault', 'alertmanager', 'secretrekey'] as $inc) {
         require_once $dir . '/inc/' . $inc . '.class.php';
      }

      $path = (string) $input->getOption('old-key-file');
      if ($path === '') {
         $output->writeln('<error>Informe --old-key-file com a cópia do glpicrypt.key de antes da troca.</error>');
         return Command::FAILURE;
      }
      try {
         $oldKey = PluginNextoolSecretRekey::readOldKeyFile($path);
      } catch (\Throwable $e) {
         $output->writeln('<error>' . $e->getMessage() . '</error>');
         return Command::FAILURE;
      }

      $apply = (bool) $input->getOption('apply');
      $rekey = new PluginNextoolSecretRekey($oldKey, !$apply);
      $report = $rekey->run();
      ksort($report);

      $output->writeln($apply ? '<info>Regravando segredos com a chave atual</info>' : '<comment>SIMULAÇÃO (nada é gravado; use --apply)</comment>');
      foreach ($report as $local => $r) {
         $output->writeln(sprintf('  %-70s regravar=%d ok=%d falha=%d', $local, $r['rekeyed'], $r['current'], $r['failed']));
      }
      $t = $rekey->totals();
      $output->writeln(sprintf('Total: %d %s, %d já na chave atual, %d sem decifrar com nenhuma das duas chaves.',
         $t['rekeyed'], $apply ? 'regravados' : 'a regravar', $t['current'], $t['failed']));
      if ($t['failed'] > 0) {
         $output->writeln('<comment>Os que não decifram foram cifrados com outra chave (nem a antiga informada, nem a atual): redigite essas credenciais na configuração do módulo.</comment>');
      }

      return $t['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
   }
}
