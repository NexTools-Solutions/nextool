#!/usr/bin/env bash
#
# NexTool Solutions - instalador do plugin base para GLPI 10/11 (Linux)
#
# O QUE ESTE SCRIPT FAZ, EM RESUMO:
#   1. Confirma que esta rodando dentro (ou aponta pra) uma instalacao real do GLPI.
#   2. Baixa a versao mais recente do plugin, publicada no GitHub Releases.
#   3. Confere o SHA256 do pacote baixado contra o SHA256 publicado no mesmo release
#      (garante que o arquivo nao foi corrompido/adulterado no caminho).
#   4. Faz backup da instalacao anterior (se existir) antes de sobrescrever.
#   5. Extrai o pacote em plugins/nextool, ajusta dono e permissoes dos arquivos.
#   6. Recarrega o PHP-FPM (com uma checagem de seguranca: so recarrega se conseguir
#      identificar UM UNICO processo, pra nao arriscar reiniciar o processo errado
#      num servidor que rode mais de uma instancia).
#
# Este script SEMPRE instala a versao mais recente publicada (releases/latest).
# Nao ha suporte a fixar uma versao antiga -- se precisar de uma versao especifica,
# baixe manualmente em https://github.com/NexTools-Solutions/nextool/releases
#
# Uso:
#   curl -fsSL https://raw.githubusercontent.com/NexTools-Solutions/nextool/main/install.sh | sudo bash
#   sudo bash install.sh --glpi-root=/var/www/glpi
#   sudo bash install.sh --force
#   docker exec -u root <container> bash -c 'curl -fsSL <url> | bash'   # GLPI em Docker
#
# Repositorio (codigo aberto pra conferencia): https://github.com/NexTools-Solutions/nextool
#
set -euo pipefail

PUBLIC_REPO="NexTools-Solutions/nextool"
GITHUB_RELEASES="https://github.com/${PUBLIC_REPO}/releases"
INSTALL_URL="https://raw.githubusercontent.com/${PUBLIC_REPO}/main/install.sh"

GLPI_ROOT_OVERRIDE=""
FORCE=0
WEB_USER_OVERRIDE=""
workdir=""
ALLOW_UNVERIFIED_PACKAGE=0
DRY_RUN=0

# As 3 funcoes abaixo so existem pra padronizar a saida do script (prefixo fixo,
# aviso vai pro stderr, erro sempre aborta com código de saída 1). Nenhuma faz
# nada alem de imprimir texto -- seguras de ler e auditar.
log()  { printf '[nextool-install] %s\n' "$*"; }
warn() { printf '[nextool-install] AVISO: %s\n' "$*" >&2; }
die()  { printf '[nextool-install] ERRO: %s\n' "$*" >&2; exit 1; }

usage() {
  cat <<'EOF'
Instalador do plugin base NexTool para GLPI 10/11.

Opcoes:
  --glpi-root=CAMINHO        Raiz do GLPI (default: detecta automaticamente -- pasta atual
                             ou uma varredura pelos locais mais comuns de instalacao)
  --web-user=USUARIO         Forca o usuario do servidor web (default: detecta automaticamente)
  --force                    Reinstala mesmo se ja existir a mesma versao (faz backup antes)
  --allow-unverified-package Permite prosseguir sem verificacao de SHA256 se o release nao
                             publicar o checksum (NAO recomendado -- so existe como escape
                             hatch para um cenario que nao deveria acontecer)
  --dry-run                  Baixa e verifica o pacote, mas nao altera nada no disco
  -h, --help                 Mostra esta ajuda

O SHA256 do pacote e sempre conferido antes de instalar (aborta se o release nao
publicar o checksum, a menos que --allow-unverified-package seja usado). Sempre
instala a versao mais recente (releases/latest) -- nao ha opcao de fixar versao.

Precisa ser executado como root (sudo) para ajustar dono/permissoes dos
arquivos extraidos e recarregar o PHP-FPM.

GLPI em Docker: rode o instalador DENTRO do container, porque no host o GLPI
nao existe no disco. Nao ha sudo dentro do container -- use "-u root":
  docker exec -u root <container> bash -c 'curl -fsSL <url-do-install.sh> | bash'
Se o script for executado no host, ele detecta os containers com GLPI e mostra
o comando pronto para cada um.
EOF
}

# Le os argumentos de linha de comando um a um e liga as flags correspondentes.
# Qualquer coisa nao reconhecida aborta o script (nao tenta "adivinhar" a intencao).
for arg in "$@"; do
  case "$arg" in
    --glpi-root=*) GLPI_ROOT_OVERRIDE="${arg#--glpi-root=}" ;;
    --web-user=*) WEB_USER_OVERRIDE="${arg#--web-user=}" ;;
    --force) FORCE=1 ;;
    --allow-unverified-package) ALLOW_UNVERIFIED_PACKAGE=1 ;;
    --dry-run) DRY_RUN=1 ;;
    -h|--help) usage; exit 0 ;;
    *) die "opcao desconhecida: $arg (use --help)" ;;
  esac
done

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
#
# Antes de fazer qualquer coisa, confirma que o ambiente tem tudo que o resto
# do script vai precisar. A ideia e falhar cedo com uma mensagem clara, em vez
# de morrer no meio da instalacao com um erro confuso de "comando nao encontrado".

preflight() {
  [[ "$(uname -s)" == "Linux" ]] || die "este instalador cobre apenas Linux (bare-metal ou container)."

  # Precisa de root pra poder trocar o dono dos arquivos extraidos (pro usuario
  # do servidor web) e pra poder recarregar o PHP-FPM. Sem isso, os arquivos
  # ficariam com o dono errado e o GLPI nao conseguiria ler o plugin.
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    die "execute como root (sudo bash install.sh ...) -- necessario para ajustar dono/permissoes dos arquivos extraidos e recarregar o PHP-FPM."
  fi

  # Ferramentas de linha de comando padrao de qualquer distribuicao Linux --
  # nenhuma delas precisa ser instalada manualmente em condicoes normais. De
  # proposito, o script NAO depende de python3, jq ou qualquer outro pacote
  # extra: so usa o que ja vem em qualquer instalacao minima de Linux.
  local missing=()
  for bin in curl tar sha256sum awk grep stat mktemp; do
    command -v "$bin" >/dev/null 2>&1 || missing+=("$bin")
  done
  [[ ${#missing[@]} -eq 0 ]] || die "ferramentas ausentes: ${missing[*]}"
}

# ---------------------------------------------------------------------------
# Deteccao da raiz do GLPI
# ---------------------------------------------------------------------------

# Confere se um diretorio "parece" a raiz de uma instalacao GLPI de verdade,
# olhando pra 2 marcadores que existem tanto no GLPI 10 quanto no GLPI 11:
#   - inc/includes.php: arquivo interno do GLPI, presente nas duas versoes
#     (NAO usamos front/central.php como marcador porque esse arquivo nao
#     existe mais no GLPI 11, que mudou pra um roteamento novo).
#   - uma pasta plugins/ ou marketplace/, onde o plugin vai ser instalado.
is_glpi_root() {
  local dir="$1"
  [[ -f "$dir/inc/includes.php" ]] || return 1
  [[ -d "$dir/plugins" || -d "$dir/marketplace" ]] || return 1
  return 0
}

# Varre um punhado de locais onde GLPI costuma ser instalado (paths exatos
# primeiro, depois uma busca limitada por baixo de raizes web comuns) e
# devolve, por linha, cada pasta encontrada que passa em is_glpi_root(). Nao
# faz nenhuma suposicao "as cegas" -- so aceita o que realmente bate com os
# marcadores de uma instalacao GLPI de verdade.
#
# A busca com "find" e limitada de proposito (--maxdepth 4, so por baixo de
# um punhado de raizes conhecidas) para nao varrer o disco inteiro -- o
# mesmo cuidado que se toma com grep -r na raiz "/".
discover_glpi_roots() {
  local exact_candidates=(/var/www/glpi /var/www/html/glpi /var/www/html /usr/share/glpi /srv/glpi /opt/glpi)
  local c
  for c in "${exact_candidates[@]}"; do
    if [[ -d "$c" ]] && is_glpi_root "$c"; then
      printf '%s\n' "$c"
    fi
  done

  local search_roots=(/var/www /srv /opt /usr/share)
  local marker candidate
  for c in "${search_roots[@]}"; do
    [[ -d "$c" ]] || continue
    while IFS= read -r -d '' marker; do
      candidate="$(dirname "$(dirname "$marker")")"
      is_glpi_root "$candidate" && printf '%s\n' "$candidate"
    done < <(find "$c" -maxdepth 4 -type f -path '*/inc/includes.php' -print0 2>/dev/null)
  done
}

# ---------------------------------------------------------------------------
# Docker: quando o GLPI roda em container, ele simplesmente nao existe no
# filesystem do host -- nenhuma varredura em /var/www, /srv, /opt ou /usr/share
# vai encontrar. Em vez de so dizer "nao encontrei nada", olhamos os containers
# em execucao e mostramos o comando exato pra rodar o instalador la dentro.
#
# De proposito NAO instalamos sozinhos dentro do container: um mesmo host pode
# hospedar varios clientes, e escolher o container errado seria grave. E a
# mesma postura de quando ha mais de uma instalacao no disco -- o script para e
# deixa a escolha com o operador.
#
# Cada container e inspecionado com UM unico "docker exec" (o loop dos caminhos
# roda la dentro), pra nao multiplicar chamadas num host com muitos containers.
# ---------------------------------------------------------------------------
docker_glpi_candidates() {
  command -v docker >/dev/null 2>&1 || return 0
  docker info >/dev/null 2>&1 || return 0

  local names name root timeout_cmd=""
  command -v timeout >/dev/null 2>&1 && timeout_cmd="timeout 5"

  names="$(docker ps --format '{{.Names}}' 2>/dev/null)" || return 0
  [[ -n "$names" ]] || return 0

  while IFS= read -r name; do
    [[ -n "$name" ]] || continue
    root="$($timeout_cmd docker exec "$name" sh -c '
      for r in /usr/share/glpi /var/www/glpi /var/www/html/glpi /var/www/html /srv/glpi /opt/glpi; do
        if [ -f "$r/inc/includes.php" ]; then printf "%s" "$r"; exit 0; fi
      done' 2>/dev/null)" || true
    # So aceita o que realmente parece um caminho absoluto. Container sem shell
    # (imagem distroless, como portainer) faz o docker devolver texto de erro
    # -- "OCI runtime exec failed: ... \"sh\": executable file not found" --
    # em vez de saida vazia, e sem esta guarda esse texto seria exibido como se
    # fosse a raiz do GLPI daquele container.
    case "$root" in
      /*) [[ "$root" == *[[:space:]]* ]] || printf '%s	%s
' "$name" "$root" ;;
    esac
  done <<< "$names"
}

# Decide qual pasta usar como raiz do GLPI, na seguinte ordem de confianca:
#   1. --glpi-root explicito (o operador sabe o caminho, usa exatamente esse).
#   2. A pasta atual, se for uma raiz GLPI valida (rodar de dentro da pasta
#      do GLPI e o jeito mais comum de usar este tipo de instalador).
#   3. Uma varredura real do sistema de arquivos (discover_glpi_roots), caso
#      nenhuma das duas opcoes acima resolva -- assim o script encontra a
#      instalacao mesmo que o operador tenha rodado de outro lugar.
# So desiste (e pede --glpi-root manual) se a varredura nao achar nada, ou
# se achar mais de uma instalacao e nao houver como decidir sozinho.
resolve_glpi_root() {
  if [[ -n "$GLPI_ROOT_OVERRIDE" ]]; then
    is_glpi_root "$GLPI_ROOT_OVERRIDE" || die "diretorio informado em --glpi-root nao parece ser uma raiz GLPI valida: $GLPI_ROOT_OVERRIDE"
    printf '%s' "$GLPI_ROOT_OVERRIDE"
    return
  fi

  if is_glpi_root "$PWD"; then
    printf '%s' "$PWD"
    return
  fi

  log "Pasta atual nao e a raiz do GLPI -- procurando a instalacao real no sistema..." >&2

  local found=()
  local candidate
  while IFS= read -r candidate; do
    [[ -n "$candidate" ]] || continue
    local already=0 f
    for f in "${found[@]:-}"; do
      [[ "$f" == "$candidate" ]] && already=1
    done
    [[ "$already" -eq 0 ]] && found+=("$candidate")
  done < <(discover_glpi_roots)

  case "${#found[@]}" in
    0)
      local docker_hints cname croot
      docker_hints="$(docker_glpi_candidates)"
      if [[ -n "$docker_hints" ]]; then
        warn "nao ha GLPI no filesystem deste host, mas encontrei GLPI dentro de container(es) Docker."
        printf '
' >&2
        printf 'O GLPI esta dentro do container, entao o instalador precisa rodar la dentro
' >&2
        printf '(nao existe sudo dentro do container -- use "docker exec -u root"):

' >&2
        while IFS=$'	' read -r cname croot; do
          [[ -n "$cname" ]] || continue
          printf '  # container %s (GLPI em %s)
' "$cname" "$croot" >&2
          printf "  docker exec -u root %s bash -c 'curl -fsSL %s | bash'

" "$cname" "$INSTALL_URL" >&2
        done <<< "$docker_hints"
        die "escolha o container do ambiente que voce quer instalar e rode o comando correspondente."
      fi
      die "nao encontrei nenhuma instalacao GLPI no sistema. Rode 'cd /caminho/do/glpi' antes, ou informe --glpi-root=/caminho/do/glpi"
      ;;
    1)
      log "Instalacao GLPI encontrada em: ${found[0]}" >&2
      printf '%s' "${found[0]}"
      ;;
    *)
      warn "mais de uma instalacao GLPI encontrada: ${found[*]}"
      die "informe qual usar via --glpi-root=/caminho/do/glpi"
      ;;
  esac
}

# ---------------------------------------------------------------------------
# Release: descoberta da versao mais recente (sem API do GitHub, sem JSON)
# ---------------------------------------------------------------------------

# Descobre qual e a tag da release mais recente sem chamar a API do GitHub e
# sem precisar interpretar nenhum JSON. Funciona porque a pagina
# ".../releases/latest" sempre responde com um redirecionamento (HTTP 302)
# apontando pra ".../releases/tag/<TAG-DA-VERSAO-MAIS-RECENTE>" -- so
# precisamos perguntar pra onde esse redirecionamento aponta (sem de fato
# segui-lo, so lendo o cabecalho "Location"). --proto-redir '=https' garante
# que, se algum dia isso mudasse pra seguir o redirect, continuaria em HTTPS.
#
# Essa e a razao pela qual este script NAO depende de python3 nem de jq: nao
# ha nenhum JSON pra interpretar em lugar nenhum do fluxo de instalacao.
resolve_latest_tag() {
  local redirect
  redirect="$(curl -s -o /dev/null --proto '=https' --proto-redir '=https' -w '%{redirect_url}' "${GITHUB_RELEASES}/latest" || true)"
  [[ -n "$redirect" ]] || die "nao consegui descobrir a versao mais recente (falha de rede ao consultar ${GITHUB_RELEASES}/latest)."

  local tag
  tag="$(printf '%s' "$redirect" | grep -oE '[^/]+$' || true)"
  [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "resposta inesperada do GitHub ao resolver a versao mais recente: ${redirect}"
  printf '%s' "$tag"
}

# Baixa um arquivo obrigatorio (o pacote em si). --proto e --proto-redir
# travam em HTTPS mesmo que o servidor tente redirecionar pra outro protocolo.
# Se falhar, aborta o script -- sem o pacote nao ha instalacao possivel.
download_asset() {
  local url="$1" dest="$2"
  curl -fsSL --proto '=https' --proto-redir '=https' --tlsv1.2 -o "$dest" "$url" || die "falha ao baixar $url"
}

# Igual a download_asset, mas pra um arquivo OPCIONAL (o checksum). Devolve
# sucesso/falha em vez de abortar, porque a ausencia do checksum e tratada
# como uma decisao explicita de seguranca em main() (fail-closed por padrao,
# com --allow-unverified-package como excecao consciente), nao como um erro
# fatal de rede.
try_download_asset() {
  local url="$1" dest="$2"
  curl -fsSL --proto '=https' --proto-redir '=https' --tlsv1.2 -o "$dest" "$url" 2>/dev/null
}

# ---------------------------------------------------------------------------
# Verificacao de integridade
# ---------------------------------------------------------------------------

# Compara o SHA256 do arquivo baixado com o SHA256 publicado no release. Isso
# protege contra download corrompido (rede instavel) e contra adulteracao no
# caminho (o arquivo e o checksum vem de origens/momentos diferentes na
# infraestrutura do GitHub). Se nao bater, o script para -- nunca instala um
# pacote cujo conteudo nao confere com o que foi publicado.
verify_sha256() {
  local archive="$1" sha_file="$2"
  local expected actual
  expected="$(awk '{print $1}' "$sha_file" | head -1)"
  [[ -n "$expected" ]] || die "arquivo de checksum vazio ou invalido."
  actual="$(sha256sum "$archive" | awk '{print $1}')"
  [[ "$expected" == "$actual" ]] || die "SHA256 nao confere (esperado=$expected obtido=$actual) -- pacote pode estar corrompido ou adulterado. Abortando."
  log "SHA256 OK ($actual)"
}

# ---------------------------------------------------------------------------
# Instalacao existente
# ---------------------------------------------------------------------------

# Le a versao do plugin ja instalado (se houver), direto da constante que fica
# dentro do setup.php. Serve pra decidir se ha algo a fazer (mesma versao) ou
# se precisa de --force (versao diferente, ja existe algo no lugar).
installed_version() {
  local setup_file="$1/plugins/nextool/setup.php"
  [[ -f "$setup_file" ]] || return 1
  grep -oP "PLUGIN_NEXTOOL_VERSION.*?'\K[0-9]+\.[0-9]+\.[0-9]+" "$setup_file" 2>/dev/null || true
}

# Antes de sobrescrever uma instalacao existente, guarda uma copia comprimida
# da pasta atual. Se algo der errado na extracao ou na versao nova, o cliente
# nao fica sem nenhuma versao funcionando -- so precisa restaurar o backup.
backup_existing() {
  local glpi_root="$1"
  local ts backup_file
  ts="$(date +%Y%m%d%H%M%S)"
  backup_file="${glpi_root}/plugins/nextool.backup-${ts}.tar.gz"
  log "Fazendo backup da instalacao atual em ${backup_file}..."
  tar -czf "$backup_file" -C "${glpi_root}/plugins" nextool || die "falha ao gerar backup -- abortando antes de sobrescrever."
  log "Backup criado: ${backup_file}"
}

# ---------------------------------------------------------------------------
# Extracao e permissoes
# ---------------------------------------------------------------------------

# Descobre qual usuario do sistema roda o servidor web, pra que os arquivos do
# plugin fiquem com o dono certo (senao o PHP nao consegue ler o proprio
# plugin). A ordem de tentativa e, do mais confiavel pro mais generico:
#   1. --web-user, se o operador informou explicitamente.
#   2. O dono de um arquivo/pasta que o GLPI ja usa em producao (files/,
#      config/config_db.php) -- reflete quem REALMENTE roda ali, em vez de
#      supor por nome de distribuicao.
#   3. Um nome comum de usuario web (apache, www-data, nginx), o que existir
#      no sistema.
detect_web_user() {
  if [[ -n "$WEB_USER_OVERRIDE" ]]; then
    id -u "$WEB_USER_OVERRIDE" >/dev/null 2>&1 || die "usuario informado em --web-user nao existe: $WEB_USER_OVERRIDE"
    printf '%s' "$WEB_USER_OVERRIDE"
    return
  fi

  local probe owner
  for probe in "$GLPI_ROOT/files" "$GLPI_ROOT/config/config_db.php"; do
    if [[ -e "$probe" ]]; then
      owner="$(stat -c '%U' "$probe" 2>/dev/null || true)"
      if [[ -n "$owner" && "$owner" != "root" ]]; then
        printf '%s' "$owner"
        return
      fi
    fi
  done

  local candidate
  for candidate in apache www-data nginx; do
    if id -u "$candidate" >/dev/null 2>&1; then
      printf '%s' "$candidate"
      return
    fi
  done

  die "nao consegui detectar o usuario do servidor web. Informe manualmente via --web-user=<usuario>"
}

# Aplica o dono (usuario do servidor web) e permissoes padrao (755 pastas, 644
# arquivos) na pasta do plugin recem-extraida. Em hosts com SELinux ativo
# (comum em RHEL/CentOS/Alma), tambem restaura o contexto de seguranca via
# restorecon -- sem isso, o Apache pode receber "Permission denied" mesmo com
# as permissoes Unix corretas, porque o SELinux bloqueia por fora.
apply_ownership_and_permissions() {
  local target="$1" web_user="$2"
  local web_group
  web_group="$(id -gn "$web_user" 2>/dev/null || echo "$web_user")"

  chown -R "${web_user}:${web_group}" "$target"
  find "$target" -type d -exec chmod 755 {} +
  find "$target" -type f -exec chmod 644 {} +
  log "Dono/permissoes ajustados (${web_user}:${web_group}, dirs 755, arquivos 644)."

  if command -v getenforce >/dev/null 2>&1 && [[ "$(getenforce 2>/dev/null)" == "Enforcing" ]]; then
    if command -v restorecon >/dev/null 2>&1; then
      if restorecon -RF "$target" 2>/dev/null; then
        log "Contexto SELinux restaurado (restorecon)."
      else
        warn "restorecon falhou -- pode ser necessario ajustar o contexto SELinux manualmente."
      fi
    else
      warn "SELinux em modo Enforcing e restorecon nao encontrado -- rode manualmente: chcon -R -t httpd_sys_content_t $target"
    fi
  fi
}

# Recarrega o PHP-FPM depois de trocar os arquivos do plugin. Isso e necessario
# porque muitos servidores GLPI rodam com o OPcache configurado pra nao checar
# se os arquivos mudaram (opcache.validate_timestamps=Off) -- sem recarregar,
# o PHP continua servindo o codigo ANTIGO, mesmo com os arquivos novos ja no
# disco, e o cliente teria a falsa impressao de que a instalacao nao funcionou.
#
# A parte importante: so envia o sinal de recarga se conseguir identificar
# EXATAMENTE UM processo php-fpm master no sistema. Se houver mais de um
# (servidor compartilhado rodando varias instancias/versoes de PHP), o script
# prefere NAO mexer em nada automaticamente a correr o risco de reiniciar a
# instancia errada -- nesse caso, pede pro operador recarregar manualmente.
reload_opcache() {
  local matches count fpm_pid

  matches="$(pgrep -f 'php-fpm: master process' 2>/dev/null || true)"
  [[ -z "$matches" ]] && matches="$(pgrep php-fpm 2>/dev/null || true)"
  count=0
  [[ -n "$matches" ]] && count="$(printf '%s\n' "$matches" | grep -c .)"

  if [[ "$count" -eq 1 ]]; then
    fpm_pid="$matches"
    if kill -USR2 "$fpm_pid" 2>/dev/null; then
      log "PHP-FPM recarregado (OPcache invalidado)."
      return
    fi
  elif [[ "$count" -gt 1 ]]; then
    warn "mais de um processo php-fpm master visivel neste host (${count}) -- por seguranca NAO vou enviar sinal automaticamente (poderia atingir a instancia errada). Recarregue manualmente o PHP-FPM que atende ${GLPI_ROOT} (ex.: 'kill -USR2 <pid-master-correto>')."
    return
  fi

  # Sem processo php-fpm detectavel via pgrep (ex.: Apache com mod_php em vez
  # de php-fpm): tenta reiniciar pelo systemd, testando os nomes de servico
  # mais comuns em cada distribuicao/versao de PHP.
  if command -v systemctl >/dev/null 2>&1; then
    local svc
    for svc in php-fpm php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm apache2 httpd; do
      if systemctl is-active --quiet "$svc" 2>/dev/null; then
        if systemctl reload "$svc" 2>/dev/null; then
          log "Servico ${svc} recarregado."
          return
        fi
      fi
    done
  fi

  warn "nao consegui recarregar automaticamente o PHP-FPM/servidor web. Recarregue manualmente (ex.: 'kill -USR2 <pid-master-php-fpm>' ou 'systemctl reload php-fpm') para o plugin ser reconhecido."
}

# ---------------------------------------------------------------------------
# Main -- orquestra os passos acima, na ordem
# ---------------------------------------------------------------------------

main() {
  preflight

  local glpi_root
  glpi_root="$(resolve_glpi_root)"
  log "Raiz do GLPI: ${glpi_root}"
  GLPI_ROOT="$glpi_root"

  # Detecta o usuario web ANTES de baixar/extrair qualquer coisa. Se o
  # detector falhar (ou --web-user apontar pra um usuario que nao existe), e
  # melhor abortar aqui, sem ter mexido em nada, do que descobrir isso so
  # depois de ja ter substituido a instalacao antiga.
  local web_user
  web_user="$(detect_web_user)"
  log "Usuario do servidor web: ${web_user}"

  local current_version
  current_version="$(installed_version "$glpi_root" || true)"
  if [[ -n "$current_version" ]]; then
    log "Instalacao existente detectada: nextool ${current_version}"
  fi

  log "Consultando release mais recente em ${PUBLIC_REPO}..."
  local tag version tarball_url sha256_url
  tag="$(resolve_latest_tag)"
  version="${tag#v}"
  log "Release alvo: ${tag}"

  # As URLs de download de um asset de release no GitHub seguem sempre o
  # mesmo padrao previsivel: .../releases/download/<tag>/<nome-do-arquivo>.
  # Como o nome do arquivo (nextool-<versao>.tar.gz) e uma convencao estavel
  # do nosso proprio pipeline de release, montamos a URL direto, sem precisar
  # perguntar pra API do GitHub quais arquivos existem.
  tarball_url="${GITHUB_RELEASES}/download/${tag}/nextool-${version}.tar.gz"
  sha256_url="${GITHUB_RELEASES}/download/${tag}/nextool-${version}.tar.gz.sha256"

  if [[ -n "$current_version" && "$tag" == "v${current_version}" && "$FORCE" != "1" ]]; then
    log "Versao ${current_version} ja instalada e e igual ao release mais recente."
    # Mesmo sem nada pra baixar, reafirma dono/permissoes -- assim, rodar o
    # script de novo tambem serve pra corrigir uma instalacao que por algum
    # motivo tenha ficado com o dono errado (ex.: uma execucao anterior que
    # falhou bem no meio do ajuste de permissoes), sem exigir --force.
    apply_ownership_and_permissions "${glpi_root}/plugins/nextool" "$web_user"
    log "Nada a baixar (use --force para reinstalar do zero)."
    exit 0
  fi

  if [[ -n "$current_version" && "$FORCE" != "1" ]]; then
    die "ja existe uma instalacao (versao ${current_version}). Use --force para reinstalar (um backup sera criado antes), ou use o auto-update dentro do proprio GLPI (menu NexTool Solutions) para atualizar com seguranca."
  fi

  workdir="$(mktemp -d)"
  trap 'rm -rf "$workdir"' EXIT

  local archive_file="${workdir}/nextool-${tag#v}.tar.gz"
  local sha256_file="${workdir}/nextool-${tag#v}.tar.gz.sha256"

  log "Baixando pacote..."
  download_asset "$tarball_url" "$archive_file"

  if try_download_asset "$sha256_url" "$sha256_file"; then
    verify_sha256 "$archive_file" "$sha256_file"
  elif [[ "$ALLOW_UNVERIFIED_PACKAGE" == "1" ]]; then
    warn "release nao publica checksum SHA256 -- prosseguindo SEM NENHUMA verificacao de integridade (--allow-unverified-package)."
  else
    die "release ${tag} nao publica checksum SHA256 -- abortando por seguranca (fail-closed). Use --allow-unverified-package para prosseguir mesmo assim (NAO recomendado)."
  fi

  if [[ "$DRY_RUN" == "1" ]]; then
    log "--dry-run: pacote baixado e verificado com sucesso. Nada foi alterado no disco."
    exit 0
  fi

  # Antes de extrair, confere se o pacote nao tem nada suspeito: caminhos que
  # tentem sair da pasta de destino (path traversal, ex.: "../../etc/passwd")
  # ou links simbolicos (que poderiam apontar pra fora da pasta de destino).
  # O artefato oficial nunca tem nenhum dos dois -- se aparecer, e sinal de
  # pacote corrompido ou adulterado.
  log "Verificando integridade do tar (path traversal e symlinks)..."
  if tar -tzf "$archive_file" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
    die "arquivo tar contem caminhos suspeitos -- abortando por seguranca."
  fi
  if tar -tvzf "$archive_file" | grep -Eq '^l'; then
    die "arquivo tar contem symlinks -- abortando por seguranca (artefato oficial nao deveria conter links simbolicos)."
  fi

  local stage_dir="${workdir}/stage"
  mkdir -p "$stage_dir"
  tar --no-same-owner -xzf "$archive_file" -C "$stage_dir"
  [[ -f "${stage_dir}/nextool/setup.php" ]] || die "pacote extraido nao contem setup.php -- artefato invalido."

  mkdir -p "${glpi_root}/plugins"

  if [[ -d "${glpi_root}/plugins/nextool" ]]; then
    backup_existing "$glpi_root"
    rm -rf "${glpi_root}/plugins/nextool"
  fi

  mv "${stage_dir}/nextool" "${glpi_root}/plugins/nextool"
  log "Plugin extraido em ${glpi_root}/plugins/nextool"

  apply_ownership_and_permissions "${glpi_root}/plugins/nextool" "$web_user"

  reload_opcache

  echo
  log "Instalacao concluida: nextool ${tag} em ${glpi_root}/plugins/nextool"
  echo
  echo "Proximos passos (dentro do GLPI):"
  echo "  1. Configurar > Plugins > localizar 'NexTool Solutions' > Instalar > Ativar"
  echo "  2. Menu 'NexTool Solutions' > aba Licenciamento > clicar em 'Sincronizar'"
  echo "  3. Aba Modulos > baixar/ativar os modulos desejados"
  echo
  echo "IMPORTANTE: se for instalar/ativar pelo console (bin/console plugin:install),"
  echo "  rode SEMPRE como o usuario do servidor web (ex.: --user ${web_user}), NUNCA"
  echo "  como root/--allow-superuser -- o proprio passo de instalacao cria as pastas"
  echo "  de dados do plugin (files/_plugins/nextool) e elas ficam com o dono de quem"
  echo "  rodou o comando."
}

main "$@"
