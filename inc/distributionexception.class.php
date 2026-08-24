<?php
declare(strict_types=1);
/**
 * NexTool -- Exception de comunicação com o ContainerAPI (#241).
 *
 * Carrega o `code` estruturado devolvido pelo servidor (ex.: environment_not_provisioned)
 * e o HTTP status, permitindo que chamadores decidam por código em vez de heurística
 * sobre a string da mensagem (que quebra em i18n). Estende RuntimeException, então
 * todos os catch (Throwable|RuntimeException) existentes seguem funcionando.
 *
 * @license GPLv3+
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolDistributionException extends RuntimeException {

   private string $errorCode;

   private int $httpCode;

   public function __construct(string $message, string $errorCode = '', int $httpCode = 0, ?Throwable $previous = null) {
      parent::__construct($message, 0, $previous);
      $this->errorCode = $errorCode;
      $this->httpCode  = $httpCode;
   }

   /** Código estruturado do servidor (ex.: environment_not_provisioned); '' quando ausente. */
   public function getErrorCode(): string {
      return $this->errorCode;
   }

   /** HTTP status da resposta que originou o erro; 0 quando desconhecido. */
   public function getHttpCode(): int {
      return $this->httpCode;
   }
}
