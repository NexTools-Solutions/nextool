<?php
/**
 * Guarda de rede para URL de provedor de IA configurável (provedor "compatível com OpenAI" e Azure OpenAI).
 *
 * Na base desde o cliente de provedor único (PluginNextoolLlmConnector, nextool-mod-aiassist#73); antes era
 * PluginNextoolAiassistUrlGuard, no módulo aiassist.
 *
 * Por que existe: o admin digita a URL e o GLPI faz a requisição do lado do servidor. Sem guarda, a URL vira
 * um caminho para o endereço de metadados da nuvem (169.254.169.254, credenciais da VM) ou para serviços que
 * só o servidor alcança.
 *
 * Regras (decididas no plano de 09/11/2026):
 * - só http/https, sem usuário/senha na URL;
 * - BLOQUEIA link-local (169.254.0.0/16, fe80::/10), 0.0.0.0/8, multicast, faixas reservadas e os nomes/IPs de
 *   metadados das nuvens;
 * - PERMITE rede privada e loopback (Ollama/vLLM/LiteLLM na rede do cliente é o caso de uso principal);
 * - endereço público exige https;
 * - o DNS é resolvido UMA vez e fixado na conexão (CURLOPT_RESOLVE): o host não pode trocar de IP entre a
 *   checagem e a chamada (DNS rebinding); redirecionamentos ficam desligados.
 */
if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

class PluginNextoolLlmUrlGuard {

   private const BLOCKED_HOSTS = [
      'metadata', 'metadata.google.internal', 'metadata.goog', 'metadata.azure.com',
      'instance-data', 'instance-data.ec2.internal', 'metadata.tencentyun.com',
   ];

   /** IPs de metadados fora das faixas link-local (Alibaba, AWS IPv6). */
   private const BLOCKED_IPS = ['100.100.100.200', 'fd00:ec2::254'];

   /**
    * @return array{ok:bool, code:string, scheme?:string, host?:string, port?:int, ip?:string, private?:bool}
    *   code: ok | invalid_url | scheme | userinfo | blocked_host | dns | blocked_ip | https_required
    */
   public static function check(string $url): array {
      $url = trim($url);
      $p = parse_url($url);
      if ($url === '' || !is_array($p) || empty($p['host']) || empty($p['scheme'])) {
         return ['ok' => false, 'code' => 'invalid_url'];
      }
      $scheme = strtolower((string) $p['scheme']);
      if (!in_array($scheme, ['http', 'https'], true)) {
         return ['ok' => false, 'code' => 'scheme'];
      }
      if (isset($p['user']) || isset($p['pass'])) {
         return ['ok' => false, 'code' => 'userinfo'];
      }
      $host = strtolower(trim((string) $p['host'], '[]'));
      $host = rtrim($host, '.');
      if (in_array($host, self::BLOCKED_HOSTS, true)) {
         return ['ok' => false, 'code' => 'blocked_host'];
      }
      $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);

      $ips = self::resolve($host);
      if (empty($ips)) {
         return ['ok' => false, 'code' => 'dns'];
      }
      $allPrivate = true;
      foreach ($ips as $ip) {
         if (self::isForbidden($ip)) {
            return ['ok' => false, 'code' => 'blocked_ip'];
         }
         if (!self::isPrivate($ip)) {
            $allPrivate = false;
         }
      }
      if ($scheme === 'http' && !$allPrivate) {
         return ['ok' => false, 'code' => 'https_required'];
      }
      return ['ok' => true, 'code' => 'ok', 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'private' => $allPrivate];
   }

   /** Mensagem para a tela (nunca o corpo do servidor remoto). */
   public static function message(string $code): string {
      switch ($code) {
         case 'invalid_url':
            return __('Endereço inválido. Informe a URL completa, por exemplo https://servidor:porta/v1.', 'nextool');
         case 'scheme':
            return __('O endereço precisa começar com http:// ou https://.', 'nextool');
         case 'userinfo':
            return __('Não coloque usuário ou senha na URL: use o campo da chave.', 'nextool');
         case 'dns':
            return __('O nome do servidor não foi encontrado (DNS). Confira o endereço.', 'nextool');
         case 'https_required':
            return __('Endereço público exige https://. O http:// só é aceito para servidores da rede interna.', 'nextool');
         case 'blocked_host':
         case 'blocked_ip':
         default:
            return __('Este endereço não é permitido por segurança (metadados da nuvem, link-local ou faixa reservada).', 'nextool');
      }
   }

   /**
    * Opções do Guzzle para chamar a URL já checada: IP fixo (CURLOPT_RESOLVE) e sem redirecionamento.
    */
   public static function guzzleOptions(array $chk): array {
      $opts = ['allow_redirects' => false];
      if (!empty($chk['ok']) && defined('CURLOPT_RESOLVE')) {
         $ip = (string) $chk['ip'];
         $ipForCurl = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
         $opts['curl'] = [CURLOPT_RESOLVE => [$chk['host'] . ':' . $chk['port'] . ':' . $ipForCurl]];
      }
      return $opts;
   }

   /** @return string[] */
   private static function resolve(string $host): array {
      if (filter_var($host, FILTER_VALIDATE_IP)) {
         return [$host];
      }
      $ips = [];
      $v4 = @gethostbynamel($host);
      if (is_array($v4)) {
         $ips = array_merge($ips, $v4);
      }
      $aaaa = @dns_get_record($host, DNS_AAAA);
      if (is_array($aaaa)) {
         foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) {
               $ips[] = (string) $r['ipv6'];
            }
         }
      }
      return array_values(array_unique($ips));
   }

   public static function isForbidden(string $ip): bool {
      $ip = strtolower($ip);
      if (in_array($ip, self::BLOCKED_IPS, true)) {
         return true;
      }
      // IPv4 mapeado em IPv6 (::ffff:169.254.169.254) cai na regra do IPv4.
      if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $ip, $m)) {
         return self::isForbidden($m[1]);
      }
      if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
         return self::inCidr4($ip, '0.0.0.0/8') || self::inCidr4($ip, '169.254.0.0/16')
            || self::inCidr4($ip, '224.0.0.0/4') || self::inCidr4($ip, '240.0.0.0/4');
      }
      if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
         $bin = inet_pton($ip);
         if ($bin === false) {
            return true;
         }
         $first = ord($bin[0]);
         $second = ord($bin[1]);
         if ($bin === str_repeat("\0", 16)) {
            return true; // ::
         }
         if ($first === 0xfe && ($second & 0xc0) === 0x80) {
            return true; // fe80::/10 link-local
         }
         if ($first === 0xff) {
            return true; // multicast
         }
         return false;
      }
      return true;
   }

   public static function isPrivate(string $ip): bool {
      $ip = strtolower($ip);
      if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $ip, $m)) {
         return self::isPrivate($m[1]);
      }
      if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
         foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '100.64.0.0/10'] as $cidr) {
            if (self::inCidr4($ip, $cidr)) {
               return true;
            }
         }
         return false;
      }
      if ($ip === '::1') {
         return true;
      }
      $bin = @inet_pton($ip);
      return $bin !== false && strlen($bin) === 16 && (ord($bin[0]) & 0xfe) === 0xfc; // fc00::/7
   }

   private static function inCidr4(string $ip, string $cidr): bool {
      [$net, $bits] = explode('/', $cidr);
      $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;
      return ((ip2long($ip) & $mask) === (ip2long($net) & $mask));
   }
}
