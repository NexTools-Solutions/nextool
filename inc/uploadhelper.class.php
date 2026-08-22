<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - UploadHelper
 * -------------------------------------------------------------------------
 * Validação e armazenamento de arquivos enviados por USUÁRIO.
 *
 * Por que uma classe separada de PluginNextoolFileHelper: aquela processa
 * artefatos que NÓS distribuímos (download de módulo, extração de pacote,
 * cópia recursiva). Esta processa bytes vindos do navegador de um usuário do
 * cliente. São domínios de confiança diferentes, e juntá-los daria a todo call
 * site de upload, por herança, acesso a deleteDirectory() e à extração de
 * arquivos compactados.
 *
 * Motivação (auditoria 2026-08-22, finding ME-10): cinco módulos implementavam
 * upload de forma independente e divergente. O `branding` era o único que
 * validava por EXTENSÃO do nome enviado pelo cliente - um .png com bytes de
 * SVG/HTML passava - enquanto orderservice/autentique/signaturepad/aiassist já
 * conferiam o MIME real. Este helper elimina a variante frágil e evita a sexta.
 *
 * REGRAS NÃO NEGOCIÁVEIS (hard-coded, fora de $options):
 *  - is_uploaded_file() sempre;
 *  - MIME REAL via finfo sempre - a extensão, quando checada, é ADICIONAL,
 *    nunca substituta;
 *  - a extensão final do arquivo gravado deriva do MIME DETECTADO, não do nome
 *    enviado: elimina "foo.php.png" e ".htaccess";
 *  - o destino final é validado com realpath() contra $targetDir (anti-traversal
 *    via $file['name']);
 *  - SVG fora do perfil default (ver PROFILE_IMAGE).
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

final class PluginNextoolUploadHelper {

   /**
    * Imagens rasterizadas. SVG deliberadamente FORA: servido inline executa
    * script na origem do GLPI. Quem realmente precisa opta por
    * PROFILE_IMAGE_WITH_SVG e assume servir com Content-Disposition: attachment
    * e X-Content-Type-Options: nosniff.
    */
   public const PROFILE_IMAGE = [
      'image/png'  => 'png',
      'image/jpeg' => 'jpg',
      'image/gif'  => 'gif',
      'image/webp' => 'webp',
      'image/vnd.microsoft.icon' => 'ico',
      'image/x-icon'             => 'ico',
   ];

   /** Só para quem serve o arquivo COM as defesas descritas acima. */
   public const PROFILE_IMAGE_WITH_SVG = self::PROFILE_IMAGE + ['image/svg+xml' => 'svg'];

   public const PROFILE_PDF = ['application/pdf' => 'pdf'];

   /** Teto conservador; cada call site ajusta via $options['max_bytes']. */
   public const DEFAULT_MAX_BYTES = 2 * 1024 * 1024;

   /**
    * Valida o upload SEM tocar no destino. Não move nada.
    *
    * @param array $file    entrada de $_FILES
    * @param array $options ver README da classe (allowed_mimes, max_bytes,
    *                       allowed_extensions, max_width, max_height,
    *                       require_image, base_name)
    * @return array{ok:bool, code:string, error:string, mime:string,
    *               extension:string, size:int, width:int|null, height:int|null}
    */
   public static function validate(array $file, array $options = []): array {
      $allowed  = $options['allowed_mimes'] ?? self::PROFILE_IMAGE;
      $maxBytes = (int)($options['max_bytes'] ?? self::DEFAULT_MAX_BYTES);

      $fail = static function (string $code, string $error): array {
         return ['ok' => false, 'code' => $code, 'error' => $error, 'mime' => '',
                 'extension' => '', 'size' => 0, 'width' => null, 'height' => null];
      };

      // 1. Erro reportado pelo próprio PHP. Inspecionado PRIMEIRO: um arquivo
      //    acima de upload_max_filesize chega com tmp_name vazio, e quem testa
      //    só is_uploaded_file o descarta sem saber por quê.
      $uploadErr = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
      if ($uploadErr === UPLOAD_ERR_NO_FILE) {
         return $fail('no_file', __('nenhum arquivo enviado', 'nextool'));
      }
      if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
         return $fail('too_large_php', __('o arquivo excede o limite de upload do servidor', 'nextool'));
      }
      if ($uploadErr !== UPLOAD_ERR_OK) {
         return $fail('upload_error', sprintf(__('falha ao receber o arquivo (código %d)', 'nextool'), $uploadErr));
      }

      $tmp = (string)($file['tmp_name'] ?? '');
      if ($tmp === '' || !is_uploaded_file($tmp)) {
         return $fail('not_uploaded', __('arquivo inválido', 'nextool'));
      }

      $size = (int)($file['size'] ?? filesize($tmp));
      if ($size > $maxBytes) {
         return $fail('too_large', sprintf(
            __('o arquivo excede o limite de %s', 'nextool'),
            Toolbox::getSize($maxBytes)
         ));
      }

      // 2. MIME REAL. É o gate central - nunca confiar no nome nem no
      //    Content-Type declarado pelo navegador.
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime  = strtolower((string)$finfo->file($tmp));
      if (!isset($allowed[$mime])) {
         return $fail('bad_type', __('formato de arquivo não suportado', 'nextool'));
      }
      $extension = $allowed[$mime];

      // 3. Extensão do NOME, quando exigida, é checagem ADICIONAL: pega o caso
      //    de conteúdo válido com nome enganoso (relatorio.pdf que é PNG).
      if (!empty($options['allowed_extensions'])) {
         $named = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
         if ($named !== '' && !in_array($named, (array)$options['allowed_extensions'], true)) {
            return $fail('bad_extension', __('a extensão do arquivo não é permitida', 'nextool'));
         }
      }

      // 4. Dimensões (aiassist limita a 1024x1024).
      $width = $height = null;
      $needsImage = !empty($options['require_image'])
                 || isset($options['max_width']) || isset($options['max_height']);
      if ($needsImage) {
         $info = @getimagesize($tmp);
         if ($info === false) {
            // .ico e alguns .webp não são cobertos por getimagesize; só é erro
            // quando o call site EXIGE imagem verificável.
            if (!empty($options['require_image'])) {
               return $fail('not_an_image', __('o arquivo não é uma imagem válida', 'nextool'));
            }
         } else {
            [$width, $height] = $info;
            if (isset($options['max_width']) && $width > (int)$options['max_width']) {
               return $fail('too_wide', sprintf(__('a imagem excede %d pixels de largura', 'nextool'), (int)$options['max_width']));
            }
            if (isset($options['max_height']) && $height > (int)$options['max_height']) {
               return $fail('too_tall', sprintf(__('a imagem excede %d pixels de altura', 'nextool'), (int)$options['max_height']));
            }
         }
      }

      return ['ok' => true, 'code' => 'ok', 'error' => '', 'mime' => $mime,
              'extension' => $extension, 'size' => $size, 'width' => $width, 'height' => $height];
   }

   /**
    * validate() + move_uploaded_file() para $targetDir.
    *
    * $targetDir é OBRIGATÓRIO e sem default: onde o arquivo mora é decisão de
    * política de backup/upgrade de cada módulo (GLPI_PLUGIN_DOC_DIR,
    * GLPI_UPLOAD_DIR ou modules/<x>/files - este último é apagado num
    * redownload do módulo).
    *
    * @param array $options aceita ainda: base_name, fallback_dirs, dir_mode
    * @return array validate() + {stored_filename, path, original_filename,
    *               target_dir, used_fallback}
    */
   public static function store(array $file, string $targetDir, array $options = []): array {
      $result = self::validate($file, $options);
      $result += ['stored_filename' => '', 'path' => '', 'original_filename' => (string)($file['name'] ?? ''),
                  'target_dir' => $targetDir, 'used_fallback' => false];
      if (!$result['ok']) {
         return $result;
      }

      $mode = (int)($options['dir_mode'] ?? 0755);
      $dir  = $targetDir;
      if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
         $dir = '';
         foreach ((array)($options['fallback_dirs'] ?? []) as $candidate) {
            if (is_dir($candidate) || (@mkdir($candidate, $mode, true) || is_dir($candidate))) {
               $dir = $candidate;
               $result['used_fallback'] = true;
               break;
            }
         }
         if ($dir === '') {
            $result['ok'] = false;
            $result['code'] = 'no_target_dir';
            $result['error'] = __('não foi possível preparar o diretório de destino', 'nextool');
            return $result;
         }
      }

      // Nome final: base sanitizada + sufixo aleatório + extensão do MIME
      // DETECTADO. Nada do nome original sobrevive sem passar por slugify.
      $base = (string)($options['base_name'] ?? '');
      if ($base === '') {
         $base = (string)($options['fallback_prefix'] ?? 'upload');
      }
      $base = Toolbox::slugify($base);
      if ($base === '') {
         $base = 'upload';
      }
      $stored = $base . '-' . self::uniqueSuffix() . '.' . $result['extension'];
      $dest   = rtrim($dir, '/') . '/' . $stored;

      // Anti-traversal: o destino resolvido tem de ficar sob o diretório alvo.
      $realDir = realpath($dir);
      if ($realDir === false
          || strpos(realpath(dirname($dest)) ?: '', rtrim($realDir, '/')) !== 0) {
         $result['ok'] = false;
         $result['code'] = 'unsafe_path';
         $result['error'] = __('destino de gravação inválido', 'nextool');
         return $result;
      }

      if (!move_uploaded_file($file['tmp_name'], $dest)) {
         $result['ok'] = false;
         $result['code'] = 'move_failed';
         $result['error'] = __('não foi possível gravar o arquivo no servidor', 'nextool');
         return $result;
      }

      // O GLPI 11 reprocessa $_FILES em alguns fluxos; sem isto o arquivo já
      // movido volta a ser tratado como upload pendente. O autentique já fazia
      // isso na mão - é conhecimento tácito que precisava subir para cá.
      self::forgetUploadedFile($file);

      $result['stored_filename'] = $stored;
      $result['path']            = $dest;
      $result['target_dir']      = $dir;
      return $result;
   }

   /**
    * validate() + conteúdo em memória, sem gravar em disco.
    *
    * Existe porque o aiassist converte a imagem em data: URI na própria config.
    * Sem este método o helper atenderia 4 dos 5 call sites - o pior resultado
    * possível para um item cuja razão de ser é uniformizar segurança.
    *
    * @return array validate() + {contents: string}
    */
   public static function readBytes(array $file, array $options = []): array {
      $result = self::validate($file, $options);
      $result['contents'] = '';
      if (!$result['ok']) {
         return $result;
      }
      $raw = @file_get_contents($file['tmp_name']);
      if ($raw === false) {
         $result['ok'] = false;
         $result['code'] = 'read_failed';
         $result['error'] = __('não foi possível ler o arquivo enviado', 'nextool');
         return $result;
      }
      $result['contents'] = $raw;
      return $result;
   }

   /** Sufixo único; random_bytes com degradação para uniqid (3 cópias no ecossistema). */
   private static function uniqueSuffix(): string {
      try {
         return bin2hex(random_bytes(4));
      } catch (Exception $e) {
         return substr(sha1(uniqid('', true)), 0, 8);
      }
   }

   /** Remove a entrada de $_FILES correspondente a este arquivo já movido. */
   private static function forgetUploadedFile(array $file): void {
      $tmp = (string)($file['tmp_name'] ?? '');
      if ($tmp === '' || !isset($_FILES) || !is_array($_FILES)) {
         return;
      }
      foreach ($_FILES as $key => $entry) {
         if (is_array($entry) && ($entry['tmp_name'] ?? null) === $tmp) {
            unset($_FILES[$key]);
            return;
         }
      }
   }
}
