# Changelog — Proxy Health Assessment

## 4.4.0
- **Fallback do host group:** o padrão continua `Zabbix/Proxies`. Se ele não existir, o campo fica vazio e a tela pede para o usuário escolher o grupo de proxies; o botão do aviso leva direto ao campo, destacado. A escolha fica salva no perfil do usuário e é usada nas próximas visitas, com "(sua escolha salva)" no cabeçalho. Um grupo informado ou salvo que deixe de existir é descartado.
- **Tema claro:** os textos coloridos (estado, links, problemas esmaecidos) têm cores próprias por tema, separadas das cores das barras, todas com contraste ≥ 4,5:1 nos temas claro e escuro do Zabbix 7.0 e 8.0.
- A coluna "Nota" tem largura fixa, então o estado e a barra ficam alinhados entre as linhas ("82", "82,1"). Memória total igual a 0 não aparece mais como "0 GB RAM".

## 4.3.2
- Enquanto a coleta assíncrona roda, a tabela não diz mais "Nenhum proxy encontrado" e a legenda mostra "—" no lugar de zeros. Se a coleta falhar, a mensagem aponta para o erro.
- Removido o caminho síncrono de coleta (`collect()`/`collectTrends()`, cerca de 220 linhas), que não era mais chamado: a tela usa só o fluxo assíncrono (init → trend → finalize).

## 4.3.1
- Notas e descontos em pt-BR ("62,1", "−7,8"), como as colunas de métricas.
- Validado num frontend Zabbix 7.0.30 com dados de produção e publicado em produção.

## 4.3.0 — redesign da visão geral
- Cabeçalho em uma linha (contexto, abas, busca, exportar).
- Faixa de distribuição com legenda clicável no lugar dos cinco cartões de KPI.
- Tabela densa com uma linha por objeto e as métricas em colunas: valor em fonte monoespaçada e barra de 4 px com marca no limite de atenção configurado.
- A linha se expande e mostra a composição da nota (100 → descontos), os problemas ativos que causaram o desconto e as leituras de processos e caches.
- Proxies fora do escopo ficam numa linha recolhível no rodapé.
- Saíram o painel "Detalhes consolidados" e os cartões com anel de nota.
- O tema do Zabbix 8.0 estiliza `<section>` globalmente; o módulo neutraliza isso nos próprios blocos.

## 4.2.0
- A detecção de proxy offline usa o `lastaccess` do `proxy.get`, casando o host com o proxy pelo nome (técnico ou visível, sem diferenciar maiúsculas). O item interno de lastaccess virou só fallback. Proxies sem nenhum contato também ficam fora do escopo.
- Quando o host group padrão (`Zabbix/Proxies`) não existe, a tela explica o motivo e leva para a aba Configuração.
- O painel de diagnóstico deixou de ser `<aside>`: o tema do Zabbix 8.0 aplica `aside { grid-area: sidebar }` globalmente.

## 4.1.1
- "Versão abaixo do corte" passou a comparar a versão completa com `major.minor` do corte + patch mínimo. Antes comparava só o patch e penalizava qualquer 8.0.x ou 7.2/7.4 com patch baixo. Pré-releases (alpha/beta/rc) contam como abaixo da versão final.

## 4.1.0
- Regras de carga usam o P95 dos picos horários de trends no lugar do valor do momento, com desconto proporcional entre o limite de atenção e o crítico.

## Próximos passos
- Tendência (diferença semanal, regressão nas médias diárias ou `forecast()`/`timeleft()`) e estado "Atenção preditiva".
- Abrir o PR desta branch para a `main`.
- Exportação: decidir se os valores em texto ("26.2%") seguem o formato pt-BR da tela ou continuam com ponto para planilhas.
