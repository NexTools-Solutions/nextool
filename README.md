# NexTool Solutions – Hub de Soluções para GLPI 10 e 11

O **NexTool Solutions** é um hub de soluções dentro do GLPI: você habilita apenas o que precisa e mantém tudo centralizado em uma única interface, com instalação guiada e licenciamento integrado quando aplicável.

Importante: este projeto possui **linha para GLPI 10** e **linha para GLPI 11**. Se você usa GLPI 11, pode seguir normalmente (veja a seção “Versões”).

---

## Versões (GLPI 10 e GLPI 11)

Este repositório (`RPGMais/nextool`) possui **duas linhas** do plugin NexTool:

- <a href="https://github.com/RPGMais/nextool/releases?q=plugin%3Anextool%5BGLPI_10%5D" target="_blank" rel="noopener"><strong>GLPI 10</strong>: branch <code>glpi-10</code> (esta linha)</a>
- <a href="https://github.com/RPGMais/nextool/releases?q=plugin%3Anextool%5BGLPI_11%5D" target="_blank" rel="noopener"><strong>GLPI 11</strong>: branch <code>main</code> (código e releases próprios)</a>

**Regra crítica:** não fazer merge entre `main` e `glpi-10`. Se uma mudança precisa existir nas duas versões, portar (cherry-pick/manual) e testar em cada linha.

---
### Módulos (GLPI 10)

Links de filtro (uma página com todas as releases do módulo nesta linha):

- <a href="https://github.com/RPGMais/nextool/releases?q=modulo%3Aaiassist%5BGLPI_10%5D" target="_blank" rel="noopener">AI Assist</a>  \
  Descrição: resumos, sugestões e sinais de sentimento para agilizar a triagem de tickets.  \
  Problema que resolve: atendimento lento e priorização inconsistente em filas grandes.
- <a href="https://github.com/RPGMais/nextool/releases?q=modulo%3Acolumnresize%5BGLPI_10%5D" target="_blank" rel="noopener">Column Resize</a>  \
  Descrição: ajusta e salva preferências de largura de colunas em telas do GLPI.  \
  Problema que resolve: telas “apertadas” e perda de tempo ajustando colunas repetidamente.
- <a href="https://github.com/RPGMais/nextool/releases?q=modulo%3Amailanalyzer%5BGLPI_10%5D" target="_blank" rel="noopener">Mail Analyzer</a>  \
  Descrição: reduz ruído de e-mail e ajuda a evitar tickets duplicados gerados por cadeias.  \
  Problema que resolve: duplicidade de chamados e aumento de volume por e-mails em CC.
- <a href="https://github.com/RPGMais/nextool/releases?q=modulo%3Aruleinspector%5BGLPI_10%5D" target="_blank" rel="noopener">Rule Inspector</a>  \
  Descrição: ajuda a inspecionar e depurar regras do GLPI com mais transparência.  \
  Problema que resolve: troubleshooting demorado em regras complexas (e-mail, tickets, rotinas).
- <a href="https://github.com/RPGMais/nextool/releases?q=modulo%3Asmartassign%5BGLPI_10%5D" target="_blank" rel="noopener">Smart Assign</a>  \
  Descrição: distribui tickets automaticamente por regras (balanceamento, rodízio, categorias).  \
  Problema que resolve: sobrecarga em alguns técnicos e filas mal distribuídas.
- <a href="https://github.com/RPGMais/nextool/releases?q=modulo%3Atemplate%5BGLPI_10%5D" target="_blank" rel="noopener">Template</a>  \
  Descrição: padroniza conteúdo e acelera preenchimentos/rotinas do time (modelos e atalhos).  \
  Problema que resolve: respostas e registros inconsistentes e retrabalho em tarefas repetitivas.

---

## Como o NexTool Solutions aparece no GLPI

Depois de instalado e ativado, o **NexTool Solutions** aparece como um novo item de menu no GLPI (ao lado dos menus principais), com uma tela central que reúne:

- Uma aba de **Módulos** contendo cards com nome, descrição, status e plano, e botões para Download, Instalar/Ativar e Configurações.
- Uma aba de **Contato**, com canais oficiais de suporte e materiais de ajuda.
- Uma aba de **Licenciamento** do ambiente (plano, módulos disponíveis, status de validação).
- Uma aba de **Logs**, para acompanhar registros importantes do plugin.

---

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
