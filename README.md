# NexTool – Plataforma de Módulos para GLPI 11

O **NexTool** é um plugin modular para o **GLPI 11** que centraliza diversos recursos em forma de módulos, todos acessíveis a partir de uma única interface dentro do GLPI.  
Ele foi pensado para administradores de GLPI que querem **adicionar funcionalidades prontas** sem precisar instalar vários plugins separados.

**Uso sem módulos:** o plugin pode ser usado **sem baixar nenhum módulo** ou apenas com os que o administrador escolher. O núcleo é autocontido; o download de módulos opcionais ocorre **somente quando o administrador clica em Download/Instalar** no catálogo, dentro do GLPI. Os módulos opcionais são distribuídos pela nossa própria infraestrutura, via **HTTPS**, com **checksum SHA256** para verificação de integridade antes da instalação.

---

## O que o NexTool Solutions faz!

- **Catálogo único de módulos** dentro do GLPI (cards com nome, descrição, status e plano free/licenciados).
- **Instalação e atualização guiada** dos módulos (Download → Instalar → Ativar).
- **Integração com licenciamento**, liberando módulos licenciados conforme o plano contratado.
- **Gestão de dados por módulo** (acessar/apagar dados pela própria interface do plugin).

---

## Módulos incluídos (visão geral)

### AI Assist

- Gera **resumos automáticos** e **sugestões de resposta** para tickets.
- Analisa o **sentimento** com base no histórico recente do solicitante.
- Integra com provedores de IA (OpenAI e Gemini) e permite escolha de modelo.
- Exibe ações diretamente na timeline e na aba do chamado.

**Benefício:** acelera o atendimento e melhora a priorização de chamados.

### Autentique

- Envia documentos vinculados aos tickets para assinatura digital.
- Gerencia lista de signatários (quem precisa assinar).
- Acompanha o status de cada assinatura em tempo real.
- Integra com a plataforma Autentique.
- Notifica no GLPI quando o documento é assinado.

**Benefício:** elimina papel e agiliza processos que exigem assinatura formal.

### Mail Interactions

- Permite aprovar/rejeitar validações por e-mail, sem o usuário precisar fazer login.
- Envia pedidos de validação de solução e pesquisas de satisfação por e-mail.
- Processa automaticamente as respostas no GLPI (links seguros).

**Benefício:** usuários interagem com o suporte diretamente pelo e-mail, sem acessar o sistema.

### Mail Analyzer

- Evita criação de tickets duplicados a partir de cadeias de e-mail em CC.
- Identifica respostas legítimas às notificações do GLPI.
- Notifica remetentes quando um e-mail é recusado.

**Benefício:** reduz ruído na caixa de entrada e mantém o GLPI mais limpo.

### Order Service (Ordem de Serviço)

- Gera um PDF de Ordem de Serviço dentro do chamado.
- Permite configurar cabeçalho, logo e dados do prestador.
- Exibe followups públicos e fluxo de aprovação quando existirem.

**Benefício:** padroniza entregas e facilita a formalização com o cliente.

### Geolocation

- Captura localização geográfica diretamente do navegador.
- Insere endereço e coordenadas em acompanhamentos/soluções.
- Mantém histórico completo por ticket.

**Benefício:** melhora rastreabilidade e evidências de atendimento em campo.

### Pending Survey

- Bloqueia a abertura de novos chamados se o usuário tiver pesquisa de satisfação pendente.
- Garante que a pesquisa seja respondida antes de permitir novos tickets.
- Exibe mensagem clara explicando o motivo do bloqueio.
- Pode ser configurado por entidade/perfil.

**Benefício:** aumenta a taxa de resposta das pesquisas e melhora a qualidade do feedback.

### Smart Assign

- Atribui tickets automaticamente por categoria, grupo ou regras definidas.
- Modos de distribuição: **balanceamento** (distribui de forma mais equilibrada) ou **rodízio** (sequencial).
- Evita sobrecarga em técnicos específicos e reduz tempo de atribuição manual.
- Funciona em tempo real na criação do ticket.

**Benefício:** distribui o trabalho de forma justa e automatizada, otimizando o fluxo de atendimento.

### Signature Pad (Assinatura Manual)

- Carrega um PDF padrão configurável como modelo de assinatura.
- Gera link interno de assinatura (mouse ou touch) para coleta diretamente no navegador.
- Salva o PDF assinado como documento vinculado ao chamado no GLPI.

**Benefício:** permite coletar assinaturas manuais em campo sem depender de ferramentas externas.

### Ticket Flow (Fluxo de Chamados)

- Automatiza a abertura de chamados filhos com base em critérios configuráveis (categoria + evento).
- Aciona modelos de chamado nativos do GLPI ao criar ou atualizar tickets.
- Permite encadear fluxos para processos complexos com múltiplas etapas.

**Benefício:** automatiza processos que dependem de chamados subsequentes, reduzindo trabalho manual.

### Approval Flow (Escalonamento de Aprovação)

- Define fluxos de aprovação multinível por categoria de chamado.
- Modelo de dados em árvore: caminhos independentes para aprovação e recusa em cada nível.
- Ações configuráveis por resultado: não fazer nada, solucionar, fechar ou escalonar para o próximo nível.
- Hooks automáticos: criação de ticket aciona validação; resposta à validação executa a ação configurada.
- Suporte a modelos de aprovação (ITILValidationTemplate) com targets por usuário e grupo.

**Benefício:** automatiza fluxos de aprovação complexos com ramificação, eliminando escalonamentos manuais.

### Gestão de Estoque

- Debita insumos do estoque diretamente a partir de chamados.
- Adiciona aba no Ticket e no Insumo (Consumable) para registrar saídas.
- Rastreabilidade bidirecional: do chamado para o insumo e vice-versa.

**Benefício:** controla o consumo de materiais vinculado a atendimentos, com rastreio completo.

---

## Como o NexTool Solutions aparece no GLPI

Depois de instalado e ativado, será exibida a aba **Configurar → Geral → NexTool Solutions** com:

- O **Catálogo de Módulos** contendo cards com nome, descrição, status e plano free/licenciados, e botões para Download, Instalar/Ativar e Acessar/Apagar dados.
- Uma aba de **Licença e status** do ambiente (plano, módulos disponíveis, status de validação).
- Uma aba de **Contato**, com canais oficiais de suporte e materiais de ajuda.
- Uma aba de **Logs**, para acompanhar registros importantes do plugin (validações, downloads de módulos, etc.).

---

## Visão rápida do fluxo de uso (administrador GLPI)

1. **Instalar e ativar o plugin NexTool** pelo mecanismo padrão de plugins do GLPI.
2. Acessar a tela de **configuração do NexTool** em **Configurar → Geral → NexTool Solutions**.
3. Conferir o **status de licença** e o **identificador do ambiente**.
4. Clicar em **Sincronizar** (quando aplicável) para atualizar o catálogo de módulos.
5. Na tela de módulos, usar:
   - **Download** + **Instalar / Ativar** para habilitar um módulo.
   - **Acessar dados / Apagar dados** para gerenciar as informações de cada módulo.

Módulos **FREE** ficam disponíveis mesmo sem licença ativa; módulos **licenciados** só aparecem liberados quando o plano do ambiente cobre aquele módulo.

---

## Requisitos básicos

- **GLPI 11**
- **PHP 8.1+**

---

## Licença e modelo de distribuição

Este projeto é um **hub de módulos para GLPI**.

- O **Hub** (este plugin) é distribuído sob a licença **GPL-3.0-or-later**.
- Os **módulos** disponibilizados através do Hub podem ser:
  - **gratuitos**, ou
  - **licenciados**, com acesso mediante contratação/assinatura.

Mesmo quando um módulo é licenciado, ele é entregue **com código-fonte aberto** e sob licença **GPLv3 ou compatível**.

O pagamento refere-se ao **serviço de disponibilização, suporte e/ou conveniência**, e **não** impõe restrições adicionais às liberdades garantidas pela GPL.

Em caso de conflito entre qualquer texto comercial e a licença GPLv3, **prevalece a GPLv3**.

---

## Privacidade e dados

O Hub pode se conectar a um **servidor externo** para:

- listar módulos disponíveis;
- baixar módulos e atualizações;
- validar informações técnicas básicas (por exemplo: versão do GLPI, versão do Hub).

**O plugin não foi projetado para enviar conteúdo de chamados, senhas ou dados sensíveis dos usuários finais para o servidor do desenvolvedor.**

Os dados eventualmente coletados (por exemplo: logs de acesso, IP, dados de contato do cliente) são tratados em conformidade com a LGPD/GDPR, conforme descrito na nossa Política de Privacidade:

Antes de usar em produção, recomenda-se que você:

- revise a Política de Privacidade;
- verifique se o uso do Hub e dos módulos está em conformidade com as políticas internas da sua organização.

Para detalhes jurídicos completos sobre licenciamento, redistribuição, privacidade e proteção de dados, consulte também o documento **[POLICIES_OF_USE.md](./POLICIES_OF_USE.md)** incluído na raiz deste plugin.
