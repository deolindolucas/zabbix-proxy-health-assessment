# Changelog — Proxy Health Assessment

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
- Esconder "Nenhum proxy encontrado" enquanto a coleta assíncrona ainda está rodando.
- Tendência (diferença semanal, regressão nas médias diárias ou `forecast()`/`timeleft()`) e estado "Atenção preditiva".
- Remover o caminho síncrono de coleta (`collect()`/`collectTrends()`), que não é mais usado.
- Abrir o PR desta branch para a `main`.
- Validar o tema claro e a exportação XLSX/CSV com muitos proxies.
