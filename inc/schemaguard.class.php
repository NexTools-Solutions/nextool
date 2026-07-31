<?php
declare(strict_types=1);
/**
 * PluginNextoolSchemaGuard -- guard de evolucao de schema de modulo (issue #159).
 *
 * PROBLEMA
 * O schema do modulo nasce do `sql/install.sql` (CREATE TABLE IF NOT EXISTS) e evolui
 * pelo `sql/upgrade.sql` + `runMigrations()`. Se o autor acrescenta uma coluna nova no
 * CREATE TABLE mas esquece o ALTER correspondente, quem JA tinha a tabela nunca recebe a
 * coluna -- `CREATE TABLE IF NOT EXISTS` nao altera tabela existente. Instalacao nova fica
 * correta, base instalada fica para tras, e o sintoma aparece longe da causa
 * ("Unknown column" em runtime, meses depois).
 *
 * COMO DETECTA -- "schema sombra"
 * Executa os CREATE TABLE do install.sql com os nomes reescritos para um prefixo proprio,
 * le o resultado no information_schema e compara com as tabelas reais. Quem interpreta o
 * SQL e o proprio MySQL: nao ha parser de SQL em PHP para errar.
 *
 * Por que NAO parsear com regex: tentativa inicial produziu FALSO POSITIVO -- um
 * install.sql que fecha com `) COLLATE=... ENGINE=...;` escapou do padrao esperado e o
 * casamento engoliu o CREATE TABLE seguinte, atribuindo as colunas de uma tabela a outra.
 * Com auto-correcao ligada, isso teria adicionado coluna na tabela errada. A formatacao do
 * SQL varia entre os ~38 modulos; so o banco le SQL com seguranca.
 *
 * O QUE CORRIGE
 * Apenas ADITIVO: ADD COLUMN do que falta. NUNCA dropa coluna, NUNCA altera tipo de coluna
 * existente, NUNCA mexe em tabela que nao seja do proprio modulo. Diferenca de TIPO entre
 * declarado e real e apenas REPORTADA -- alterar tipo pode truncar dado do cliente.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolSchemaGuard {

   /** Prefixo das tabelas-sombra. Curto: nomes de tabela tem limite de 64 chars. */
   private const SHADOW_PREFIX = 'nxshdw_';

   /**
    * Compara o schema declarado no install.sql com o schema real.
    *
    * @param string $moduleKey
    * @param string $installSqlPath caminho do sql/install.sql do modulo
    * @return array{
    *   missing: array<string, array<string,string>>,  // tabela => [coluna => definicao DDL]
    *   type_diff: array<int, string>,                 // apenas reportado, nunca corrigido
    *   skipped: array<int, string>,                   // tabelas nao existentes no banco
    *   errors: array<int, string>
    * }
    */
   public static function inspect(string $moduleKey, string $installSqlPath): array {
      global $DB;

      $result = ['missing' => [], 'type_diff' => [], 'skipped' => [], 'errors' => []];

      if (!is_file($installSqlPath) || !is_readable($installSqlPath)) {
         return $result;
      }
      $sql = (string) @file_get_contents($installSqlPath);
      if (trim($sql) === '') {
         return $result;
      }

      $statements = self::extractCreateTableStatements($sql);
      if (empty($statements)) {
         return $result;
      }

      $shadowCreated = [];
      try {
         foreach ($statements as $realTable => $statement) {
            $shadowTable = self::shadowName($realTable);

            // Tabela real ausente = módulo não instalado neste ambiente: nada a comparar.
            if (!$DB->tableExists($realTable)) {
               $result['skipped'][] = $realTable;
               continue;
            }

            self::dropShadow($shadowTable); // resto de execução anterior interrompida
            $shadowSql = self::rewriteToShadow($statement, $realTable, $shadowTable);

            if (!self::runDdl($shadowSql)) {
               $result['errors'][] = sprintf('falha ao criar sombra de %s', $realTable);
               continue;
            }
            $shadowCreated[] = $shadowTable;

            $declared = self::readColumns($shadowTable);
            $actual   = self::readColumns($realTable);
            if (empty($declared)) {
               $result['errors'][] = sprintf('sombra de %s sem colunas legíveis', $realTable);
               continue;
            }

            foreach ($declared as $column => $meta) {
               if (!isset($actual[$column])) {
                  $result['missing'][$realTable][$column] = $meta['ddl'];
                  continue;
               }
               // Tipo divergente: só reporta. Alterar tipo pode truncar dado do cliente.
               if (strcasecmp($meta['type'], $actual[$column]['type']) !== 0) {
                  $result['type_diff'][] = sprintf(
                     '%s.%s declarado "%s", real "%s"',
                     $realTable, $column, $meta['type'], $actual[$column]['type']
                  );
               }
            }
         }
      } catch (\Throwable $e) {
         $result['errors'][] = $e->getMessage();
      } finally {
         // As sombras SEMPRE saem, inclusive se algo acima explodiu.
         foreach ($shadowCreated as $shadowTable) {
            self::dropShadow($shadowTable);
         }
      }

      return $result;
   }

   /**
    * Aplica as colunas ausentes (ADD COLUMN). Somente aditivo.
    *
    * @param array<string, array<string,string>> $missing saída de inspect()['missing']
    * @return array{applied: array<int,string>, failed: array<int,string>}
    */
   public static function heal(string $moduleKey, array $missing): array {
      global $DB;

      $out = ['applied' => [], 'failed' => []];

      foreach ($missing as $table => $columns) {
         if (!$DB->tableExists($table)) {
            continue;
         }
         foreach ($columns as $column => $ddl) {
            // Releitura antes de aplicar: outro request pode ter curado no meio.
            if ($DB->fieldExists($table, $column, false)) {
               continue;
            }
            $stmt = sprintf(
               'ALTER TABLE `%s` ADD COLUMN `%s` %s',
               str_replace('`', '', $table),
               str_replace('`', '', $column),
               $ddl
            );
            if (self::runDdl($stmt)) {
               $out['applied'][] = $table . '.' . $column;
               Toolbox::logInFile('plugin_nextool', sprintf(
                  "[SCHEMA] %s: coluna ausente adicionada -- %s.%s (%s)\n",
                  $moduleKey, $table, $column, $ddl
               ));
            } else {
               $out['failed'][] = $table . '.' . $column;
               Toolbox::logInFile('plugin_nextool', sprintf(
                  "[SCHEMA] %s: FALHA ao adicionar %s.%s (%s)\n",
                  $moduleKey, $table, $column, $ddl
               ));
            }
         }
      }

      return $out;
   }

   /**
    * Conveniência: inspeciona e cura num passo. Usado pela fase 2 do update de módulo.
    *
    * @return array{missing_count:int, applied:array<int,string>, failed:array<int,string>, type_diff:array<int,string>}
    */
   public static function inspectAndHeal(string $moduleKey, string $installSqlPath): array {
      $report = self::inspect($moduleKey, $installSqlPath);

      $missingCount = 0;
      foreach ($report['missing'] as $cols) {
         $missingCount += count($cols);
      }

      $healed = ['applied' => [], 'failed' => []];
      if ($missingCount > 0) {
         Toolbox::logInFile('plugin_nextool', sprintf(
            "[SCHEMA] %s: %d coluna(s) declarada(s) no install.sql ausente(s) no banco -- aplicando\n",
            $moduleKey, $missingCount
         ));
         $healed = self::heal($moduleKey, $report['missing']);
      }

      foreach ($report['type_diff'] as $diff) {
         // Reportado, nunca corrigido: mudar tipo pode truncar dado.
         Toolbox::logInFile('plugin_nextool',
            sprintf("[SCHEMA] %s: divergência de TIPO (não alterada) -- %s\n", $moduleKey, $diff));
      }
      foreach ($report['errors'] as $err) {
         Toolbox::logInFile('plugin_nextool',
            sprintf("[SCHEMA] %s: guard não pôde concluir -- %s\n", $moduleKey, $err));
      }

      return [
         'missing_count' => $missingCount,
         'applied'       => $healed['applied'],
         'failed'        => $healed['failed'],
         'type_diff'     => $report['type_diff'],
      ];
   }

   // ------------------------------------------------------------------
   // Internos
   // ------------------------------------------------------------------

   /**
    * Extrai SOMENTE os statements CREATE TABLE, indexados pelo nome da tabela.
    *
    * Deliberadamente ignora todo o resto do arquivo (INSERT, ALTER, DROP...): o guard nunca
    * deve reexecutar efeito colateral do install -- e um install.sql que altere tabela do
    * CORE do GLPI jamais pode ser reexecutado por engano aqui.
    *
    * @return array<string,string> tabela => statement
    */
   private static function extractCreateTableStatements(string $sql): array {
      $out = [];
      foreach (self::splitStatements($sql) as $chunk) {
         $chunk = trim($chunk);
         if ($chunk === '' || !preg_match('/^CREATE\s+TABLE/i', $chunk)) {
            continue;
         }
         if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([A-Za-z0-9_]+)[`"]?/i', $chunk, $m)) {
            continue;
         }
         $out[$m[1]] = $chunk;
      }
      return $out;
   }

   /**
    * Divide o arquivo em statements pelo ';' que está FORA de literal e de comentário.
    *
    * Um `explode(';', ...)` ingênuo não serve: os install.sql têm ';' DENTRO de textos de
    * COMMENT -- por exemplo `COMMENT '0=não definir; 1=Incidente; 2=Requisição'`. Cortar ali
    * gera SQL inválido, e o CREATE TABLE inteiro é perdido (eram 10 dos 12 módulos que
    * falhavam na primeira execução contra o catálogo real). Este scanner acompanha aspas
    * simples, duplas e crase, o escape por barra invertida e o escape por aspa duplicada
    * ('' dentro de '...'), além de pular comentários -- de linha e blocos.
    *
    * @return array<int,string>
    */
   private static function splitStatements(string $sql): array {
      $statements = [];
      $current    = '';
      $quote      = null;   // ', " ou ` quando dentro de literal
      $len        = strlen($sql);

      for ($i = 0; $i < $len; $i++) {
         $ch   = $sql[$i];
         $next = $i + 1 < $len ? $sql[$i + 1] : '';

         if ($quote !== null) {
            $current .= $ch;
            if ($ch === '\\' && $next !== '') {   // \' e \" não fecham o literal
               $current .= $next;
               $i++;
               continue;
            }
            if ($ch === $quote) {
               if ($next === $quote) {            // '' literal dentro de '...'
                  $current .= $next;
                  $i++;
                  continue;
               }
               $quote = null;
            }
            continue;
         }

         // Fora de literal: comentário de linha (-- ou #) vai até o fim da linha.
         if (($ch === '-' && $next === '-') || $ch === '#') {
            while ($i < $len && $sql[$i] !== "\n") {
               $i++;
            }
            $current .= "\n";
            continue;
         }
         // Fora de literal: bloco /* ... */
         if ($ch === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i   = $end === false ? $len : $end + 1;
            continue;
         }
         if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $current .= $ch;
            continue;
         }
         if ($ch === ';') {
            $statements[] = $current;
            $current = '';
            continue;
         }
         $current .= $ch;
      }

      if (trim($current) !== '') {
         $statements[] = $current;
      }
      return $statements;
   }

   /**
    * Nome da tabela-sombra: prefixo + hash do nome real + sufixo ÚNICO da execução.
    *
    * NÃO usar prefixo + nome completo: nomes de tabela de módulo chegam perto dos 64
    * caracteres que o MySQL permite (ex.:
    * `glpi_plugin_nextool_contracthours_contract_visibility_groups`) e o prefixo estourava
    * o limite. O hash dá comprimento fixo.
    *
    * O sufixo por execução NÃO é cosmético: com nome determinístico, dois requests que
    * inspecionam o MESMO módulo ao mesmo tempo criam a MESMA sombra -- um dropa enquanto o
    * outro lê e o segundo conclui "sombra sem colunas legíveis", abortando o guard daquele
    * request (reproduzido com 4 processos simultâneos). Não chegava a causar ALTER indevido
    * (a leitura da sombra é atômica: ou vem completa, ou vem vazia e é tratada como erro),
    * mas o guard deixava de concluir e o log enchia de erro. Com o sufixo, cada execução tem
    * a sua sombra e ninguém pisa no alheio.
    *
    * Contrapartida: uma sombra órfã (processo morto no meio) não é mais reaproveitada pela
    * execução seguinte. É inofensiva -- tabela vazia, prefixo reconhecível -- e some no
    * `finally` em qualquer execução que termine normalmente.
    */
   private static function shadowName(string $realTable): string {
      return self::SHADOW_PREFIX . substr(md5($realTable), 0, 12) . '_' . self::runId();
   }

   /** Identificador curto e estável DENTRO de uma execução (request/processo). */
   private static function runId(): string {
      static $runId = null;
      if ($runId === null) {
         $runId = substr(md5(getmypid() . '|' . uniqid('', true)), 0, 8);
      }
      return $runId;
   }

   /**
    * Reescreve o CREATE TABLE para criar a SOMBRA.
    *
    * Além de trocar o nome da tabela, REMOVE as cláusulas de FOREIGN KEY/CONSTRAINT: no
    * MySQL o nome de uma constraint é global no schema, não por tabela -- criar a sombra
    * com a mesma constraint da tabela real falha com "Duplicate foreign key constraint
    * name" (era 10 dos 12 erros na primeira execução contra os módulos reais). A sombra
    * existe só para o banco nos dizer QUAIS COLUNAS o CREATE TABLE declara; integridade
    * referencial é irrelevante para isso.
    */
   private static function rewriteToShadow(string $statement, string $realTable, string $shadowTable): string {
      // Só a PRIMEIRA ocorrência (o alvo do CREATE TABLE); nomes de índice/coluna que
      // repitam o nome da tabela não devem ser tocados.
      $pattern = '/(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)([`"]?)' . preg_quote($realTable, '/') . '\2/i';
      $statement = (string) preg_replace($pattern, '${1}`' . $shadowTable . '`', $statement, 1);

      // Remove a cláusula de FK inteira. O alvo do REFERENCES traz a lista de colunas entre
      // parênteses -- `REFERENCES `tabela` (`id`)` -- então o padrão precisa consumir esse
      // segundo par de parênteses ANTES das cláusulas ON DELETE/UPDATE; casar só até o
      // primeiro ')' deixava um `ON DELETE CASCADE` órfão e o CREATE virava sintaxe inválida.
      $fk = '(?:CONSTRAINT\s+[`"]?[\w]+[`"]?\s+)?FOREIGN\s+KEY\s*\([^)]*\)\s*'
          . 'REFERENCES\s+[`"]?[\w]+[`"]?\s*\([^)]*\)'
          . '(?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|RESTRICT|SET\s+NULL|NO\s+ACTION))*';

      $statement = (string) preg_replace('/,\s*' . $fk . '/i', '', $statement);       // no meio/fim da lista
      $statement = (string) preg_replace('/(\(\s*)' . $fk . '\s*,/i', '${1}', $statement); // como 1ª cláusula

      return $statement;
   }

   /**
    * Colunas de uma tabela: [coluna => ['type' => tipo, 'ddl' => definição p/ ADD COLUMN]].
    * Lê do information_schema (fonte do próprio banco).
    */
   private static function readColumns(string $table): array {
      global $DB;

      $out = [];
      try {
         $iterator = $DB->request([
            'FROM'  => 'information_schema.COLUMNS',
            'WHERE' => ['TABLE_SCHEMA' => new \QueryExpression('DATABASE()'), 'TABLE_NAME' => $table],
         ]);
      } catch (\Throwable $e) {
         return $out;
      }

      foreach ($iterator as $row) {
         $name = (string) ($row['COLUMN_NAME'] ?? '');
         if ($name === '') {
            continue;
         }
         $type     = (string) ($row['COLUMN_TYPE'] ?? '');
         $nullable = strtoupper((string) ($row['IS_NULLABLE'] ?? 'YES')) === 'YES';
         $default  = $row['COLUMN_DEFAULT'] ?? null;
         $extra    = (string) ($row['EXTRA'] ?? '');
         $comment  = (string) ($row['COLUMN_COMMENT'] ?? '');

         $ddl = $type;
         $ddl .= $nullable ? ' NULL' : ' NOT NULL';
         if ($default !== null) {
            // CURRENT_TIMESTAMP e afins não podem ir entre aspas.
            $isExpression = preg_match('/^(CURRENT_TIMESTAMP|NULL|current_timestamp\(\))/i', (string) $default) === 1;
            $ddl .= ' DEFAULT ' . ($isExpression ? (string) $default : "'" . str_replace("'", "''", (string) $default) . "'");
         } elseif ($nullable) {
            $ddl .= ' DEFAULT NULL';
         }
         // AUTO_INCREMENT jamais entra num ADD COLUMN avulso (exige ser chave).
         if ($extra !== '' && stripos($extra, 'auto_increment') === false) {
            $ddl .= ' ' . $extra;
         }
         if ($comment !== '') {
            $ddl .= " COMMENT '" . str_replace("'", "''", $comment) . "'";
         }

         $out[$name] = ['type' => $type, 'ddl' => $ddl, 'extra' => $extra];
      }

      return $out;
   }

   /** Executa DDL tolerando as diferenças de API entre GLPI 10 e 11. */
   private static function runDdl(string $statement): bool {
      global $DB;

      try {
         // Mesmo padrão do BaseModule::execDdl(): doQuery existe a partir do GLPI 10.0.7;
         // em 10.0.0-10.0.6 (dentro do MIN do artefato único) só há query(). Chamada por
         // nome dinâmico -- o method_exists é o próprio guard de versão e, no GLPI 11,
         // cai sempre em doQuery.
         $ddlMethod = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
         $DB->$ddlMethod($statement);
         return true;
      } catch (\Throwable $e) {
         Toolbox::logInFile('plugin_nextool',
            '[SCHEMA] DDL falhou: ' . $e->getMessage() . ' | ' . substr($statement, 0, 160) . "\n");
         return false;
      }
   }

   /** Remove uma tabela-sombra. Silencioso: sombra órfã não pode quebrar nada. */
   private static function dropShadow(string $shadowTable): void {
      if (strncmp($shadowTable, self::SHADOW_PREFIX, strlen(self::SHADOW_PREFIX)) !== 0) {
         return; // trava de segurança: só dropa o que tem o prefixo
      }
      self::runDdl(sprintf('DROP TABLE IF EXISTS `%s`', str_replace('`', '', $shadowTable)));
   }
}
