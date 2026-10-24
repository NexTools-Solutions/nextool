# NexTool Solutions – Hub de Soluções para GLPI

O **NexTool Solutions** é um hub de soluções dentro do GLPI: você habilita apenas o que precisa e mantém tudo centralizado em uma única interface, com instalação guiada e licenciamento integrado quando aplicável.

Compatível com **GLPI 10, 11 e 12**: o mesmo pacote instala nas três versões, sem download separado. No GLPI 12 aparecem os módulos já certificados para ele; os demais ganham a versão compatível aos poucos.

---

## Requisitos

- GLPI 10.0.0 a 12.0.x, com a versão de PHP suportada pelo seu GLPI.
- Extensões PHP **curl** (download dos pacotes), **phar** (extração dos .tar.gz) e **zip**.
- Extensão **sodium** ou **openssl 3.0+** do sistema, para conferir a assinatura oficial dos pacotes antes de instalar uma atualização.
- Permissão de escrita do usuário do servidor web em `plugins/nextool/` e em `files/_plugins/nextool/` (pasta de dados do GLPI).
- Acesso de saída à internet (HTTPS) para o catálogo, a licença e o download dos módulos.

O próprio NexTool confere esses pontos e avisa, na tela, o que estiver faltando.

---

## Instalação

- **Automática (Linux):** a partir da pasta raiz do seu GLPI, execute:

  ```bash
  curl -fsSL https://raw.githubusercontent.com/NexTools-Solutions/nextool/main/install.sh | sudo bash
  ```

  O script (código aberto, [install.sh](install.sh)) detecta a raiz do GLPI, baixa a versão mais recente, verifica a integridade do pacote via SHA256, extrai, ajusta dono/permissões e recarrega o PHP-FPM. Rode com `--help` pra ver as opções (`--glpi-root`, `--force`, `--web-user`, `--dry-run`).
- **Manual:** baixe a versão mais recente em <a href="https://github.com/NexTools-Solutions/nextool/releases/latest" target="_blank" rel="noopener">Releases</a> e instale pelo mecanismo padrão de plugins do GLPI.
- Cada solução (módulo) tem sua própria página com detalhes e demonstração no site oficial - os links estão na lista abaixo.

---

## Módulos

Cada módulo abaixo tem uma página dedicada em **[nextoolsolutions.com](https://nextoolsolutions.com/plugins-glpi)** com recursos, telas e detalhes.

### Módulos gratuitos

- **[AI Assist](https://nextoolsolutions.com/plugins-glpi/ai-assist)**  \
  Descrição: IA no chamado e na base de conhecimento – resumo, sugestão de resposta, análise de sentimento, triagem e classificação automáticas, sugestão de artigos antes de abrir o chamado e detecção de lacunas da base. Funciona com a sua chave (OpenAI, Gemini ou Anthropic) ou no modo gerenciado pela NexTool, com créditos.  \
  Problema que resolve: triagem lenta, respostas repetidas e base de conhecimento que não acompanha o que já foi resolvido.
- **[Barcode](https://nextoolsolutions.com/plugins-glpi/barcode)**  \
  Descrição: cria e imprime etiquetas com código de barras (Code 128) ou QR Code para computadores, monitores, impressoras e demais ativos, direto das listas ou em lote, com modelos por entidade.  \
  Problema que resolve: etiquetagem patrimonial feita fora do GLPI e sem padrão.
- **[Branding](https://nextoolsolutions.com/plugins-glpi/branding)**  \
  Descrição: personaliza favicon, título, logos, cores, fundo da tela de login e rodapé do GLPI, sem editar o core.  \
  Problema que resolve: GLPI sem a identidade visual da organização.
- **[Column Resize](https://nextoolsolutions.com/plugins-glpi/column-resize)**  \
  Descrição: redimensiona as colunas das listagens do GLPI por arraste e salva a preferência por usuário.  \
  Problema que resolve: listagens apertadas e ajuste repetitivo de colunas.
- **[CVE Scan](https://nextoolsolutions.com/plugins-glpi/cve-scan)**  \
  Descrição: verifica se a versão instalada do GLPI tem vulnerabilidades publicadas (GitHub Security Advisories do projeto GLPI e NVD), confere a configuração do servidor (PHP, cabeçalhos HTTP, cookie de sessão, permissões, senhas padrão) e dá uma nota de segurança de 0 a 100, com a correção sugerida para cada item.  \
  Problema que resolve: GLPI exposto a falhas já conhecidas e configurações inseguras que passam despercebidas.
- **[Essentials](https://nextoolsolutions.com/plugins-glpi/essentials)**  \
  Descrição: coletânea de pequenos ajustes de interface e comportamento, cada um ativável individualmente – por exemplo, pausar o SLA nos status Aprovação e Planejado e esconder o código técnico nos dropdowns de categoria.  \
  Problema que resolve: pequenos atritos do dia a dia que exigiriam customização manual de CSS/JS.
- **[Holiday Sync](https://nextoolsolutions.com/plugins-glpi/holiday-sync)**  \
  Descrição: gera os feriados de mais de 200 países, com regiões, e os vincula em massa aos calendários do GLPI.  \
  Problema que resolve: cadastro manual de feriado por feriado, um calendário de cada vez.
- **[Mail Analyzer](https://nextoolsolutions.com/plugins-glpi/mail-analyzer)**  \
  Descrição: reconhece as conversas por e-mail, junta as respostas relacionadas no mesmo chamado e evita duplicidades causadas por respostas com cópia (CC).  \
  Problema que resolve: enxurrada de chamados duplicados gerados por cadeias de e-mail.
- **[Print Counters](https://nextoolsolutions.com/plugins-glpi/print-counters)**  \
  Descrição: transforma os contadores de página que o GLPI já coleta das impressoras em custo por página, franquia contratual, excedente e faturamento por período, com alertas de toner e de impressora sem leitura.  \
  Problema que resolve: outsourcing de impressão controlado em planilha, fora do inventário.
- **[Rule Inspector](https://nextoolsolutions.com/plugins-glpi/rule-inspector)**  \
  Descrição: mostra, numa aba do chamado, todas as regras de negócio aplicáveis e aplicadas, com os critérios atendidos e não atendidos.  \
  Problema que resolve: troubleshooting demorado de regras complexas.
- **[Security Guard](https://nextoolsolutions.com/plugins-glpi/security-guard)**  \
  Descrição: proteção contra força bruta no login web, na API, no CalDAV e na recuperação de senha, com bloqueio temporário por regra (usuário, IP ou ambos), exceções, GeoIP e painel.  \
  Problema que resolve: tentativas de invasão por senha sem nenhum bloqueio no GLPI.
- **[Smart Assign](https://nextoolsolutions.com/plugins-glpi/smart-assign)**  \
  Descrição: distribui os novos chamados automaticamente entre os técnicos do grupo responsável pela categoria, em modo balanceamento de carga ou rodízio.  \
  Problema que resolve: sobrecarga de alguns técnicos e filas mal distribuídas.
- **[Smart Notify](https://nextoolsolutions.com/plugins-glpi/smart-notify)**  \
  Descrição: sino de notificações na barra do GLPI, em tempo real, com os eventos dos chamados, problemas e mudanças, avisos da equipe, alertas dos módulos NexTool e preferências individuais por usuário.  \
  Problema que resolve: atualizações importantes perdidas por falta de aviso centralizado.
- **[Telegram Bot](https://nextoolsolutions.com/plugins-glpi/telegram-bot)**  \
  Descrição: envia as notificações do GLPI pelo Telegram, com vínculo de usuário por chat.  \
  Problema que resolve: alertas que só chegam por e-mail.
- **[Ticket Tracker](https://nextoolsolutions.com/plugins-glpi/ticket-tracker)**  \
  Descrição: registra automaticamente quem visualizou cada chamado, quantas vezes e quando, em uma aba dedicada.  \
  Problema que resolve: falta de visibilidade sobre quem leu e acompanhou o chamado.
- **[Translate](https://nextoolsolutions.com/plugins-glpi/translate)**  \
  Descrição: traduz os itens da timeline de chamados, problemas e mudanças para o idioma de cada usuário, sob demanda, com DeepL, Google Translate ou IA (OpenAI, Gemini, Claude).  \
  Problema que resolve: atendimento multilíngue dependendo de ferramentas externas.
- **[Vault Send](https://nextoolsolutions.com/plugins-glpi/vault-send)**  \
  Descrição: envia senhas e credenciais pelo chamado com links de uso único do Bitwarden Send ou Vaultwarden, cifradas no navegador, com validade, limite de aberturas e revogação.  \
  Problema que resolve: senhas escritas em acompanhamentos, que ficam no histórico e seguem por e-mail.
- **[WhatsApp Bot](https://nextoolsolutions.com/plugins-glpi/whatsapp-bot)**  \
  Descrição: notificações dos chamados pelo WhatsApp, bot para abrir e acompanhar chamados e responder aprovações, e conversas do técnico com o usuário. Funciona com a sua Evolution API ou com a instância gerenciada pela NexTool.  \
  Problema que resolve: usuários que não acompanham e-mail nem o portal.

### Módulos licenciados

- **[Access Matrix](https://nextoolsolutions.com/plugins-glpi/access-matrix)**  \
  Descrição: matriz visual de permissões CRUD de sistemas externos por cargo, grupo e usuário, com importação e exportação em CSV.  \
  Problema que resolve: controle de acesso a sistemas externos espalhado e sem centralização.
- **[Assinatura Digital](https://nextoolsolutions.com/plugins-glpi/digital-signature)**  \
  Descrição: assinatura eletrônica no GLPI pelo DocuSeal, Autentique, DocuSign ou TOTVS Sign, com documentos de chamados, contratos, ativos, fornecedores e mais, e múltiplos signatários.  \
  Problema que resolve: assinatura eletrônica fora do fluxo de atendimento.
- **[Contract Hours](https://nextoolsolutions.com/plugins-glpi/contract-hours)**  \
  Descrição: cronômetro no chamado com faturamento por contrato, arredondamento configurável, tarefa automática, painel de saldo e relatórios por período.  \
  Problema que resolve: horas de contrato registradas de forma imprecisa e manual.
- **[Escalonamento de Aprovação](https://nextoolsolutions.com/plugins-glpi/approval-flow)**  \
  Descrição: fluxos de aprovação multinível por categoria, com múltiplos aprovadores e ações configuráveis para aprovação e recusa.  \
  Problema que resolve: aprovações no improviso, sem sequência, histórico ou governança.
- **[Form Extender](https://nextoolsolutions.com/plugins-glpi/form-extender)**  \
  Descrição: adiciona novos tipos de campo aos formulários nativos do GLPI, como o seletor de categorias ITIL.  \
  Problema que resolve: formulários nativos limitados aos tipos de campo padrão.
- **[Geolocation](https://nextoolsolutions.com/plugins-glpi/geolocation)**  \
  Descrição: captura a localização do navegador e registra o endereço ao adicionar acompanhamentos ou soluções no chamado.  \
  Problema que resolve: ausência de comprovação de posição em atendimentos de campo.
- **[Gestão de Estoque](https://nextoolsolutions.com/plugins-glpi/stock-management)**  \
  Descrição: debita insumos do estoque nativo ao registrar o uso no chamado, com rastreabilidade nos dois sentidos (chamado e insumo).  \
  Problema que resolve: falta de controle sobre a saída de materiais por atendimento.
- **[GLPI Sync](https://nextoolsolutions.com/plugins-glpi/glpi-sync)**  \
  Descrição: sincroniza chamados entre instâncias GLPI nos dois sentidos – envia, importa ou faz os dois – com acompanhamentos, tarefas, soluções e status.  \
  Problema que resolve: chamados isolados em instâncias GLPI separadas.
- **[Mail Interactions](https://nextoolsolutions.com/plugins-glpi/mail-interactions)**  \
  Descrição: permite aprovar ou recusar validações e responder pesquisas de satisfação por e-mail, sem login, com link de uso único.  \
  Problema que resolve: usuários que não acessam o portal, travando validações e feedback.
- **[Mercado Eletrônico](https://nextoolsolutions.com/plugins-glpi/mercado-eletronico)**  \
  Descrição: cria requisições de compra no Mercado Eletrônico a partir do chamado, com catálogo sincronizado (produtos, grupos, fornecedores).  \
  Problema que resolve: dupla aprovação e redigitação de pedidos de compra.
- **[My Dashboard](https://nextoolsolutions.com/plugins-glpi/my-dashboard)**  \
  Descrição: painéis sobre o motor nativo de dashboards do GLPI 11, com filtros de período e entidade, cards personalizados e painéis prontos dos módulos NexTool.  \
  Problema que resolve: indicadores montados fora do GLPI, em planilha.
- **[Order Service](https://nextoolsolutions.com/plugins-glpi/order-service)**  \
  Descrição: gera a Ordem de Serviço em PDF a partir do chamado, com cabeçalho e dados do prestador configuráveis.  \
  Problema que resolve: formalização manual e documentos inconsistentes entregues ao cliente.
- **[Pending Survey](https://nextoolsolutions.com/plugins-glpi/pending-survey)**  \
  Descrição: bloqueia a abertura de novos chamados pelo usuário final enquanto houver pesquisa de satisfação pendente.  \
  Problema que resolve: baixa taxa de resposta às pesquisas e falta de feedback.
- **[Problem Flow](https://nextoolsolutions.com/plugins-glpi/problem-flow)**  \
  Descrição: regras que avaliam os chamados e criam Problemas ITIL automaticamente, vinculando os chamados relacionados.  \
  Problema que resolve: recorrências tratadas isoladamente, sem gestão de problema.
- **[Project Import](https://nextoolsolutions.com/plugins-glpi/project-import)**  \
  Descrição: importa projetos e tarefas para o GLPI a partir de planilhas Excel, com pré-visualização do que muda, decisão item a item e relatório de cada importação.  \
  Problema que resolve: cronogramas redigitados à mão no módulo de projetos.
- **[Regras de Chamado](https://nextoolsolutions.com/plugins-glpi/ticket-rules)**  \
  Descrição: campos obrigatórios ao solucionar ou fechar, mínimo de caracteres e exigência de categoria ou tipo nos chamados.  \
  Problema que resolve: chamados fechados sem as informações que a equipe precisa.
- **[Rule Extender](https://nextoolsolutions.com/plugins-glpi/rule-extender)**  \
  Descrição: adiciona ações de atribuição (Localização, Supervisor, Categoria, Título) ao motor de regras de direitos, sem editar o core.  \
  Problema que resolve: regras de atribuição nativas sem ação para preencher esses campos.
- **[SSO Login](https://nextoolsolutions.com/plugins-glpi/sso-login)**  \
  Descrição: entrada no GLPI com a conta Microsoft (Entra ID), Google ou de qualquer provedor OpenID Connect, com vínculo seguro de identidade e perfis atribuídos pelos grupos do provedor.  \
  Problema que resolve: mais uma senha para o usuário e perfis mantidos à mão.
- **[SubTask Flow](https://nextoolsolutions.com/plugins-glpi/subtask-flow)**  \
  Descrição: encadeia subtarefas por relações pai-filho entre modelos, condicionadas à solução escolhida ao concluir cada tarefa.  \
  Problema que resolve: fluxos de tarefas montados manualmente a cada chamado.
- **[Ticket Flow](https://nextoolsolutions.com/plugins-glpi/ticket-flow)**  \
  Descrição: abre chamados automaticamente a partir dos modelos nativos do GLPI, conforme a categoria e o evento configurados.  \
  Problema que resolve: abertura manual e repetitiva de chamados recorrentes.
- **[Workflow](https://nextoolsolutions.com/plugins-glpi/workflow)**  \
  Descrição: hub de fluxos de trabalho – automatize processos nos chamados com fluxos visuais de tarefas.  \
  Problema que resolve: processos de atendimento conduzidos de memória, sem sequência garantida.

---

## Como o NexTool Solutions aparece no GLPI

Depois de instalado e ativado, o **NexTool Solutions** aparece como um novo item de menu no GLPI, com uma tela central que reúne:

- **Módulos**: busca por nome/descrição, filtros por status (Ativado, Desativado, Download, Atualização, Gratuito, Licenciado) e cards com nome, descrição, status e plano, com os botões para Download, Instalar/Ativar e Configurações.
- **Licenciamento**: plano do ambiente, módulos disponíveis, status de validação e o vínculo com a sua conta NexTool.
- **Novidades**: comunicados oficiais da NexTool e alertas do próprio ambiente.
- **Serviços**: implantação, consultoria e desenvolvimento sob medida oferecidos pela NexTool.
- Uma aba para a configuração de cada módulo instalado que tenha configurações.
- **Manutenção**: as rotinas automáticas dos módulos e o estado de cada uma.
- **Sobre**: o NexTool, como funciona o licenciamento e os canais oficiais.
- **Logs**: registros de licença, configuração e ações nos módulos.

O botão **Suporte**, no topo da tela, abre um atendimento com a equipe NexTool pelo portal.

Fluxo rápido (administrador GLPI):

1. Instalar e ativar o plugin NexTool pelo mecanismo padrão de plugins do GLPI.
2. Acessar o item de menu **NexTool Solutions** no GLPI.
3. Aceitar as Políticas de Uso e vincular o ambiente à sua conta NexTool (o código de vínculo é gerado na própria tela e confirmado no portal) – é o que libera o download dos módulos, inclusive os gratuitos.
4. Na aba **Módulos**, escolher uma solução e usar **Download** e depois **Instalar/Ativar**.
5. Quando aplicável, acessar a aba de configuração da solução para ajustar o comportamento.

Soluções **gratuitas** ficam disponíveis mesmo sem licença ativa; soluções **licenciadas** só aparecem liberadas quando o plano do ambiente cobre o módulo.

---

## Idiomas

A interface acompanha o idioma de cada usuário do GLPI: português (Brasil e Portugal), inglês, espanhol, francês, italiano, alemão e russo.

---

## Licença e modelo de distribuição

Este projeto é um **hub de soluções para GLPI**.

- O **Hub** (este plugin) é distribuído sob a licença **GPL-3.0-or-later**.
- As soluções disponibilizadas através do Hub podem ser **gratuitas** ou **licenciadas**, com ativação conforme o plano contratado. A licença dos módulos licenciados vale por 12 meses, renovada a cada período, com suporte, atualizações e acompanhamento técnico enquanto estiver ativa.

Mesmo quando um módulo é licenciado, ele é entregue **com código-fonte aberto** e sob licença **GPLv3 ou compatível**.

Em caso de conflito entre qualquer texto comercial e a licença GPLv3, **prevalece a GPLv3**.

---

## Privacidade e dados

O NexTool Solutions se conecta ao **servidor da NexTool** para o catálogo, o licenciamento, o download e a atualização das soluções e as novidades. Nessa comunicação vão apenas dados técnicos do ambiente: identificador do ambiente, domínio do GLPI, versões do GLPI, do PHP e do NexTool, idioma padrão e fuso horário da instância e módulos instalados; o servidor registra também o IP de origem da conexão. Ela não envia conteúdo de chamados, senhas nem dados dos usuários.

Alguns recursos opcionais enviam dados para fora do GLPI **somente quando o administrador os ativa**:

- **AI Assist:** com a sua chave, o texto do chamado vai direto ao provedor de IA escolhido; no modo gerenciado, passa pela NexTool até o provedor. Nomes, e-mails, telefones e CPF/CNPJ são trocados por marcadores antes do envio (padrão).
- **WhatsApp Bot:** com a sua Evolution API, as mensagens vão ao seu servidor; com a instância gerenciada, passam pelo servidor de atendimento da NexTool.
- **Translate:** o texto do item traduzido vai ao provedor de tradução escolhido (DeepL, Google ou IA).
- **Relatório técnico do suporte:** só quando você opta por anexá-lo ao atendimento, com e-mails, IPs e tokens mascarados.

Para detalhes completos de privacidade, licenciamento, redistribuição e políticas de uso, consulte no site oficial:

- **[Termos de uso](https://nextoolsolutions.com/termos-de-uso)**
- **[Política de privacidade](https://nextoolsolutions.com/politica-de-privacidade)**
