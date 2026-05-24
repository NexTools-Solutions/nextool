<?php
declare(strict_types=1);
/**
 * -------------------------------------------------------------------------
 * NexTool Solutions - Validation Exception
 * -------------------------------------------------------------------------
 * Exception específica que módulos podem lançar dentro de dispatchers
 * `pre_item_add` / `pre_item_update` para ABORTAR a criação/atualização
 * do item GLPI. O HookDispatcher faz rethrow desta exception, em vez de
 * apenas logar como qualquer outro erro, permitindo que o GLPI mostre
 * a mensagem ao usuário e bloqueie a operação.
 * -------------------------------------------------------------------------
 * @author Richard Loureiro - https://linkedin.com/in/richard-ti/ - https://github.com/RPGMais/nextool
 * @copyright 2025 Richard Loureiro
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://linkedin.com/in/richard-ti
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolValidationException extends RuntimeException {
}
