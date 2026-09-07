<?php
/**
 * NexTool -- Criacao de `Document` do GLPI a partir de um arquivo em disco.
 *
 * A receita "garantir GLPI_UPLOAD_DIR, nome com sufixo aleatorio, mover com
 * fallback copy+unlink, hash ANTES do add (o GLPI MOVE o arquivo), Document->add
 * com vinculo ao item" existia copiada em 4 modulos (digitalsignature, autentique,
 * orderservice, signaturepad) e as copias ja divergiam: duas gravavam o SHA256 no
 * documento e duas nao -- o PDF assinado que voltava por uma delas nao tinha o
 * mesmo rastro de integridade (audit-deep 2026-09-06, nextool-dev#257).
 *
 * Contrato: o arquivo de origem e CONSUMIDO (movido para o diretorio de upload
 * do GLPI e, dali, movido pelo proprio core para o storage de documentos). Quem
 * chama nao precisa apagar nada em caso de sucesso; em falha o arquivo de origem
 * pode ter sido movido para GLPI_UPLOAD_DIR -- o helper limpa o que criou.
 *
 * @since 6.15.0
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolDocumentHelper {

   /**
    * Cria um Document a partir de um arquivo em disco e (opcionalmente) o
    * vincula a um item.
    *
    * @param string $path    arquivo de origem (fora ou dentro de GLPI_UPLOAD_DIR)
    * @param array  $options
    *   - name (string, obrigatorio)       nome do Document (truncado em 250)
    *   - filename (string)                nome "original" exibido/baixado (default: basename do path)
    *   - entities_id (int, default 0)
    *   - is_recursive (int 0|1, default 0)
    *   - documentcategories_id (int, default 0)
    *   - itemtype / items_id              vinculo criado pelo post_addItem do core
    *   - prefix (string, default 'nextool') prefixo do nome gravado em GLPI_UPLOAD_DIR
    *   - extension (string)               extensao do nome gravado (default: a do path, ou 'bin')
    *   - comment (string)                 comentario do Document; o SHA256 e acrescentado
    *   - with_sha256 (bool, default true) grava "SHA256: <hash>" no comentario
    *   - log_channel (string)             nome do arquivo de log (Toolbox::logInFile) para falhas
    *   - users_id (int)                   dono do documento (default: sessao, se houver)
    * @return array{ok:bool, documents_id:int, sha256:string, stored_filename:string, error:string}
    */
   public static function createFromFile(string $path, array $options): array {
      $fail = static function (string $error, string $logChannel = ''): array {
         if ($logChannel !== '') {
            Toolbox::logInFile($logChannel, '[DocumentHelper] ' . $error . "\n");
         }
         return ['ok' => false, 'documents_id' => 0, 'sha256' => '', 'stored_filename' => '', 'error' => $error];
      };

      $logChannel = (string)($options['log_channel'] ?? '');
      $name       = trim((string)($options['name'] ?? ''));
      if ($name === '') {
         return $fail('nome do documento ausente', $logChannel);
      }
      if ($path === '' || !is_file($path) || !is_readable($path)) {
         return $fail('arquivo de origem inexistente ou ilegivel: ' . $path, $logChannel);
      }

      $uploadDir = defined('GLPI_UPLOAD_DIR') ? rtrim((string)GLPI_UPLOAD_DIR, DIRECTORY_SEPARATOR) : '';
      if ($uploadDir === '') {
         return $fail('GLPI_UPLOAD_DIR indisponivel', $logChannel);
      }
      if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
         return $fail('nao foi possivel criar GLPI_UPLOAD_DIR', $logChannel);
      }

      // Nome gravado: <prefixo>-<8 hex>.<ext>. Nunca o nome vindo do usuario
      // (traversal); o nome "bonito" vai em `filename`, que o core so exibe.
      $prefix = preg_replace('/[^a-z0-9_-]/i', '', (string)($options['prefix'] ?? 'nextool')) ?: 'nextool';
      $ext    = strtolower((string)($options['extension'] ?? pathinfo($path, PATHINFO_EXTENSION)));
      $ext    = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
      try {
         $sufixo = bin2hex(random_bytes(4));
      } catch (Throwable $e) {
         $sufixo = substr(sha1(uniqid('', true)), 0, 8);
      }
      $stored = $prefix . '-' . $sufixo . '.' . $ext;
      $final  = $uploadDir . DIRECTORY_SEPARATOR . $stored;

      $jaNoUploadDir = realpath(dirname($path)) === realpath($uploadDir);
      if (!$jaNoUploadDir) {
         if (!@rename($path, $final)) {
            if (!@copy($path, $final)) {
               return $fail('nao foi possivel mover o arquivo para GLPI_UPLOAD_DIR', $logChannel);
            }
            @unlink($path);
         }
      } else {
         // Ja esta no diretorio de upload (ex.: UploadHelper::store): usa como esta.
         $stored = basename($path);
         $final  = $path;
      }
      if (!is_file($final) || !is_readable($final)) {
         return $fail('arquivo nao chegou ao diretorio de upload', $logChannel);
      }

      // SHA256 ANTES do add(): o core move o arquivo e o caminho deixa de existir.
      $sha256 = '';
      if (($options['with_sha256'] ?? true) !== false) {
         $h = @hash_file('sha256', $final);
         $sha256 = $h !== false ? $h : '';
      }

      $comment = trim((string)($options['comment'] ?? ''));
      if ($sha256 !== '') {
         $comment = trim($comment . ($comment !== '' ? "\n" : '') . 'SHA256: ' . $sha256);
      }

      $input = [
         'name'                  => Toolbox::substr($name, 0, 250),
         'entities_id'           => max(0, (int)($options['entities_id'] ?? 0)),
         'is_recursive'          => ((int)($options['is_recursive'] ?? 0)) === 1 ? 1 : 0,
         'documentcategories_id' => max(0, (int)($options['documentcategories_id'] ?? 0)),
         'upload_file'           => $stored,
         'filename'              => (string)($options['filename'] ?? basename($path)),
      ];
      if ($comment !== '') {
         $input['comment'] = $comment;
      }
      if (!empty($options['users_id'])) {
         $input['users_id'] = (int)$options['users_id'];
      }
      $itemtype = (string)($options['itemtype'] ?? '');
      $itemsId  = (int)($options['items_id'] ?? 0);
      if ($itemtype !== '' && $itemsId > 0) {
         $input['itemtype'] = $itemtype;
         $input['items_id'] = $itemsId;
      }

      try {
         $document   = new Document();
         $documentId = $document->add($input);
      } catch (Throwable $e) {
         @unlink($final);
         return $fail('Document::add falhou: ' . $e->getMessage(), $logChannel);
      }
      if (!$documentId) {
         @unlink($final);
         return $fail('Document::add nao devolveu id', $logChannel);
      }

      return [
         'ok'              => true,
         'documents_id'    => (int)$documentId,
         'sha256'          => $sha256,
         'stored_filename' => $stored,
         'error'           => '',
      ];
   }
}
