<?php
/**
 * Nextools - File Helper
 *
 * Funções utilitárias para operações de arquivo (ex.: remoção recursiva de
 * diretórios). Centraliza lógica usada em hook.php e distributionclient.
 *
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolFileHelper {

   /**
    * Remove um diretório e todo seu conteúdo recursivamente.
    *
    * @param string $dir Caminho do diretório
    * @param bool $throwOnFailure Se true, lança RuntimeException em falha de permissão; se false, ignora silenciosamente
    * @return void
    * @throws RuntimeException quando $throwOnFailure é true e rmdir/unlink falhar
    */
   public static function deleteDirectory(string $dir, bool $throwOnFailure = false): void {
      if (!is_dir($dir)) {
         return;
      }

      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );

      foreach ($iterator as $item) {
         $path = $item->getRealPath();
         if ($item->isDir()) {
            if (!@rmdir($path)) {
               if ($throwOnFailure) {
                  throw new RuntimeException(sprintf(
                     __('Falha ao remover diretório %s. Verifique permissões.', 'nextool'),
                     $path
                  ));
               }
            }
         } else {
            if (!@unlink($path)) {
               if ($throwOnFailure) {
                  throw new RuntimeException(sprintf(
                     __('Falha ao remover arquivo %s. Verifique permissões.', 'nextool'),
                     $path
                  ));
               }
            }
         }
      }

      if (!@rmdir($dir)) {
         if ($throwOnFailure) {
            throw new RuntimeException(sprintf(
               __('Falha ao limpar diretório %s. Verifique permissões.', 'nextool'),
               $dir
            ));
         }
      }
   }
}
