# NexTool Solutions – Hub de Soluções para GLPI

O **NexTool Solutions** é um hub de soluções dentro do GLPI: você habilita apenas o que precisa e mantém tudo centralizado em uma única interface, com instalação guiada e licenciamento integrado quando aplicável.

Compatível com **GLPI 10** e **GLPI 11**: o mesmo pacote instala nas duas versões, sem download separado.

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
  Descrição: resumos automáticos de threads longas e sugestões de resposta com IA (OpenAI GPT e Google Gemini).  \
  Problema que resolve: triagem lenta de chamados extensos e respostas inconsistentes.
- **[Mail Analyzer](https://nextoolsolutions.com/plugins-glpi/mail-analyzer)**  \
  Descrição: analisa e-mails recebidos e distingue nova solicitação de resposta a chamado existente, reconhecendo respostas automáticas.  \
  Problema que resolve: enxurrada de tickets duplicados gerados por cadeias e respostas de e-mail.
- **[Smart Assign](https://nextoolsolutions.com/plugins-glpi/smart-assign)**  \
  Descrição: atribui chamados automaticamente por categoria, grupo ou entidade, em modo balanceamento de carga ou rodízio.  \
  Problema que resolve: sobrecarga de alguns técnicos e filas mal distribuídas.
- **[Column Resize](https://nextoolsolutions.com/plugins-glpi/column-resize)**  \
  Descrição: redimensiona as colunas das listagens do GLPI por arraste e salva a preferência por usuário.  \
  Problema que resolve: listagens apertadas e ajuste repetitivo de colunas.
- **[Rule Inspector](https://nextoolsolutions.com/plugins-glpi/rule-inspector)**  \
  Descrição: mostra um log detalhado de cada avaliação das regras do GLPI, com os critérios aprovados e reprovados.  \
  Problema que resolve: troubleshooting demorado de regras complexas.
- **[WhatsApp Bot](https://nextoolsolutions.com/plugins-glpi/whatsapp-bot)**  \
  Descrição: envia notificações de chamados no WhatsApp via Evolution API, com configuração por tipo de evento e perfil.  \
  Problema que resolve: usuários que não acompanham e-mail nem o portal.
- **[CVE Scan](https://nextoolsolutions.com/plugins-glpi/cve-scan)**  \
  Descrição: cruza os softwares dos ativos com a base CVE do NIST e exibe as vulnerabilidades por severidade no próprio ativo.  \
  Problema que resolve: inventário sem visibilidade das falhas de segurança conhecidas.
- **[Smart Notify](https://nextoolsolutions.com/plugins-glpi/smart-notify)**  \
  Descrição: sino de notificações na navbar que agrega 11 fontes de eventos ITIL, com preferências individuais por usuário.  \
  Problema que resolve: atualizações importantes perdidas por falta de aviso centralizado.
- **[Branding](https://nextoolsolutions.com/plugins-glpi/branding)**  \
  Descrição: personaliza favicon, título, logo, cores, fundo da tela de login e rodapé do GLPI, sem editar o core.  \
  Problema que resolve: GLPI sem a identidade visual da organização.
- **[GLPI Bug Fixes](https://nextoolsolutions.com/plugins-glpi/glpi-bug-fixes)**  \
  Descrição: reúne correções visuais e comportamentais do GLPI, cada uma ativável individualmente, sem editar o código-fonte.  \
  Problema que resolve: pequenos bugs de interface acumulando atrito no dia a dia.
- **[Ticket Tracker](https://nextoolsolutions.com/plugins-glpi/ticket-tracker)**  \
  Descrição: registra automaticamente quem visualizou cada ticket, quantas vezes e quando, em uma aba dedicada.  \
  Problema que resolve: falta de visibilidade sobre quem leu e acompanhou o chamado.
- **[Translate](https://nextoolsolutions.com/plugins-glpi/translate)**  \
  Descrição: traduz chamados e acompanhamentos na própria timeline, por item e por usuário, com DeepL, Google Translate ou IA.  \
  Problema que resolve: atendimento multilíngue dependendo de ferramentas externas.

### Módulos licenciados

- **[Autentique](https://nextoolsolutions.com/plugins-glpi/autentique)**  \
  Descrição: envia contratos e documentos do chamado para assinatura digital na plataforma Autentique, com controle de signatários e status.  \
  Problema que resolve: coleta de assinatura fora do GLPI e sem rastreio do processo.
- **[Assinatura Manual](https://nextoolsolutions.com/plugins-glpi/signature-pad)**  \
  Descrição: captura assinatura manuscrita (dedo, mouse ou stylus) e a incorpora ao PDF, dentro do GLPI.  \
  Problema que resolve: coleta de assinatura em campo dependendo de papel ou ferramentas externas.
- **[Escalonamento de Aprovação](https://nextoolsolutions.com/plugins-glpi/approval-flow)**  \
  Descrição: fluxos de aprovação multinível por categoria, com múltiplos aprovadores e integração às validações do GLPI e ao Telegram.  \
  Problema que resolve: aprovações no improviso, sem sequência, histórico ou governança.
- **[Ticket Flow](https://nextoolsolutions.com/plugins-glpi/ticket-flow)**  \
  Descrição: cria chamados automaticamente a partir de templates completos, por agendamento ou evento do GLPI.  \
  Problema que resolve: abertura manual e repetitiva de chamados recorrentes.
- **[Gestão de Estoque](https://nextoolsolutions.com/plugins-glpi/stock-management)**  \
  Descrição: debita insumos do estoque nativo ao registrar o uso no chamado, com estorno e exportação em CSV.  \
  Problema que resolve: falta de controle sobre a saída de materiais por atendimento.
- **[Mail Interactions](https://nextoolsolutions.com/plugins-glpi/mail-interactions)**  \
  Descrição: permite aprovar/rejeitar validações e responder pesquisas de satisfação por e-mail, sem login, com token de uso único.  \
  Problema que resolve: usuários que não acessam o portal, travando validações e feedback.
- **[Order Service](https://nextoolsolutions.com/plugins-glpi/order-service)**  \
  Descrição: gera Ordem de Serviço em PDF a partir do chamado, com logo, cabeçalho e campos configuráveis por entidade.  \
  Problema que resolve: formalização manual e documentos inconsistentes entregues ao cliente.
- **[Geolocation](https://nextoolsolutions.com/plugins-glpi/geolocation)**  \
  Descrição: captura a localização GPS do navegador e insere o endereço no acompanhamento do chamado.  \
  Problema que resolve: ausência de comprovação de posição em atendimentos de campo.
- **[Pending Survey](https://nextoolsolutions.com/plugins-glpi/pending-survey)**  \
  Descrição: bloqueia a abertura de novos chamados pelo usuário final enquanto houver pesquisa de satisfação pendente.  \
  Problema que resolve: baixa taxa de resposta às pesquisas e falta de feedback.
- **[Telegram Bot](https://nextoolsolutions.com/plugins-glpi/telegram-bot)**  \
  Descrição: entrega notificações de chamados e permite aprovar validações pelo Telegram, com vínculo de usuário por chat_id.  \
  Problema que resolve: falta de agilidade para alertas e aprovações fora do GLPI.
- **[Problem Flow](https://nextoolsolutions.com/plugins-glpi/problem-flow)**  \
  Descrição: detecta incidentes recorrentes por categoria ou serviço e cria registros de Problema ITIL, vinculando os chamados relacionados.  \
  Problema que resolve: recorrências tratadas isoladamente, sem gestão de problema.
- **[Automações](https://nextoolsolutions.com/plugins-glpi/automations)**  \
  Descrição: dispara webhooks configuráveis por evento do GLPI, com payload customizável e autenticação por header ou HMAC.  \
  Problema que resolve: GLPI isolado de n8n, Power Automate e APIs próprias.
- **[Access Matrix](https://nextoolsolutions.com/plugins-glpi/access-matrix)**  \
  Descrição: matriz visual de permissões CRUD de sistemas externos por cargo, grupo e usuário, com resolução em camadas.  \
  Problema que resolve: controle de acesso a sistemas externos espalhado e sem centralização.
- **[Contract Hours](https://nextoolsolutions.com/plugins-glpi/contract-hours)**  \
  Descrição: cronômetro na timeline do ticket com arredondamento faturável, tarefa automática e baixa no saldo do contrato.  \
  Problema que resolve: horas de contrato registradas de forma imprecisa e manual.
- **[SubTask Flow](https://nextoolsolutions.com/plugins-glpi/subtask-flow)**  \
  Descrição: encadeia subtarefas por relações pai-filho entre templates, condicionadas à solução escolhida ao concluir cada tarefa.  \
  Problema que resolve: fluxos de tarefas montados manualmente a cada chamado.
- **[GLPI Sync](https://nextoolsolutions.com/plugins-glpi/glpi-sync)**  \
  Descrição: replica tickets entre instâncias GLPI via API REST, com acompanhamentos, tarefas e soluções ativáveis por item.  \
  Problema que resolve: chamados isolados em instâncias GLPI separadas.
- **[Form Extender](https://nextoolsolutions.com/plugins-glpi/form-extender)**  \
  Descrição: adiciona novos tipos de campo aos formulários nativos, começando pelo Category Picker de categorias ITIL.  \
  Problema que resolve: formulários nativos limitados aos tipos de campo padrão.
- **[Mercado Eletrônico](https://nextoolsolutions.com/plugins-glpi/mercado-eletronico)**  \
  Descrição: cria requisições de compra no Mercado Eletrônico a partir do chamado, com catálogo sincronizado (produtos, grupos, fornecedores).  \
  Problema que resolve: dupla aprovação e redigitação de pedidos de compra.
- **[Rule Extender](https://nextoolsolutions.com/plugins-glpi/rule-extender)**  \
  Descrição: adiciona ações de atribuição (Localização, Supervisor, Categoria, Título) ao motor de regras de direitos, sem editar o core.  \
  Problema que resolve: regras de atribuição nativas sem ação para preencher esses campos.
- **[Assinatura Digital](https://nextoolsolutions.com/plugins-glpi/digital-signature)**  \
  Descrição: integra o GLPI ao DocuSeal para assinatura eletrônica de documentos de chamados, contratos e ativos, com múltiplos signatários.  \
  Problema que resolve: assinatura eletrônica fora do fluxo de atendimento.

---

## Como o NexTool Solutions aparece no GLPI

Depois de instalado e ativado, o **NexTool Solutions** aparece como um novo item de menu no GLPI (ao lado dos menus principais), com uma tela central que reúne:

- Uma aba de **Módulos** com busca por nome/descrição, filtros por status (chips de Ativado, Desativado, Download, Atualização, Gratuito, Licenciado), cards com nome, descrição, status e plano, e botões para Download, Instalar/Ativar e Configurações.
- Uma aba de **Contato**, com canais oficiais de suporte e materiais de ajuda.
- Uma aba de **Licenciamento** do ambiente (plano, módulos disponíveis, status de validação).
- Uma aba de **Logs**, para acompanhar registros importantes do plugin.

Fluxo rápido (administrador GLPI):

1. Instalar e ativar o plugin NexTool pelo mecanismo padrão de plugins do GLPI.
2. Acessar o item de menu **NexTool Solutions** no GLPI.
3. Na aba **Licenciamento**, conferir o status do ambiente e clicar em **Sincronizar** (quando aplicável) para atualizar o catálogo.
4. Na aba **Módulos**, escolher uma solução e usar **Download** e depois **Instalar/Ativar**.
5. Quando aplicável, acessar **Configurações** para ajustar o comportamento da solução.

Soluções **gratuitas** ficam disponíveis mesmo sem licença ativa; soluções **licenciadas** só aparecem liberadas quando o plano do ambiente cobre o módulo.

---

## Licença e modelo de distribuição

Este projeto é um **hub de soluções para GLPI**.

- O **Hub** (este plugin) é distribuído sob a licença **GPL-3.0-or-later**.
- As soluções disponibilizadas através do Hub podem ser **gratuitas** ou **licenciadas**, com ativação conforme o plano contratado.

Mesmo quando um módulo é licenciado, ele é entregue **com código-fonte aberto** e sob licença **GPLv3 ou compatível**.

Em caso de conflito entre qualquer texto comercial e a licença GPLv3, **prevalece a GPLv3**.

---

## Privacidade e dados

O NexTool Solutions pode se conectar a um **servidor externo** para habilitar recursos como catálogo, licenciamento e distribuição das soluções.

O plugin não foi projetado para enviar conteúdo de chamados, senhas ou dados sensíveis dos usuários finais para o servidor do desenvolvedor.

Para detalhes completos de privacidade, licenciamento, redistribuição e políticas de uso, consulte **[POLICIES_OF_USE.md](./POLICIES_OF_USE.md)**.
