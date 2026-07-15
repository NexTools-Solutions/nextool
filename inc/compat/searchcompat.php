<?php
/**
 * Compat de versao -- shim da interface de Search do GLPI 11.
 *
 * O GLPI 11 expoe \Glpi\Search\DefaultSearchRequestInterface; o GLPI 10 nao.
 * Classes do plugin (ex.: PluginNextoolValidationAttempt) declaram
 * `implements \Glpi\Search\DefaultSearchRequestInterface`, clausula resolvida
 * no LOAD da classe -- no GLPI 10 dispararia Fatal "Interface not found" e
 * mataria o plugin_init.
 *
 * Este shim declara a interface APENAS quando o core nao a fornece
 * (interface_exists com autoload). No GLPI 11 o autoload encontra a real e o
 * bloco e ignorado (mantendo instanceof do core verdadeiro -> sort/order
 * default da grade preservado). No GLPI 10 declara o stub, o `implements`
 * resolve contra ele e o core do 10 consome o metodo via method_exists
 * (comportamento intacto). Guardado por interface_exists + require_once
 * idempotente; NUNCA class_alias cru (evita o Fatal do issue glpi/glpi#11347).
 *
 * Assinatura identica a real do core GLPI 11 (verificada).
 */

namespace Glpi\Search;

if (!\interface_exists(DefaultSearchRequestInterface::class)) {
    interface DefaultSearchRequestInterface
    {
        public static function getDefaultSearchRequest(): array;
    }
}
