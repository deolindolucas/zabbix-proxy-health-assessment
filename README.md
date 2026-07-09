# Zabbix Proxy Health Assessment

Script standalone em Python e modulo/widget para Zabbix para extrair dados de proxies Zabbix, avaliar a saude operacional desses componentes e gerar relatorios estruturados com metodologia de score, dados brutos, problemas ativos, problemas orfaos, configuracoes, comparativo de processos versus configuracao e comparativo de caches versus configuracao.

O objetivo do assessment e responder uma pergunta bem pratica:

> O proxy ou o Zabbix Server esta saudavel como componente de monitoramento, ou existe algum sinal de que ele pode parar de coletar/processar corretamente?

Por isso, o score nao mede a quantidade bruta de problemas dos hosts monitorados por um proxy. Ele foca em sinais que afetam o proprio proxy, o Zabbix Server quando selecionado, a aplicacao Zabbix, os processos internos, filas, caches e recursos do sistema operacional.

A coleta parte de um Host Group. Por padrao o script usa `Zabbix/Proxies`, mas isso pode ser alterado com `--host-group`. As leituras de configuracao sao coletadas diretamente nos hosts avaliados procurando itens com chave `num.*`, sem exigir informar IDs de template.

No widget, tambem e possivel selecionar opcionalmente o host relacionado ao Zabbix Server. Quando selecionado, ele entra no assessment como o primeiro objeto avaliado, antes dos proxies.

## Versao 4.0

A versao 4.0 consolida o widget para uso em ambientes maiores e com filtros mais controlados:

- o **Host group** passa a ser o escopo obrigatorio do assessment;
- o filtro por template de proxy continua opcional;
- a busca por hosts com template aplicado indiretamente agora e configuravel por checkbox;
- o topo do Overview mostra tambem quantos objetos ficaram **Fora do escopo**;
- o card **Fora do escopo** abre um drilldown com proxy, motivo e idade do ultimo acesso;
- a janela de trends usada nas medias historicas passou a ser configuravel entre `7` e `30` dias;
- a aba **Configuracao** recebeu layout mais largo e botoes de ajuda em cada opcao;
- a aba **Regras de Negocio** foi revisada para explicar os filtros, exclusoes e a janela de trends configuravel.

## Componentes

### Script standalone

Arquivo principal para gerar planilha `.xlsx` fora do frontend Zabbix. Ele consulta a API, coleta metricas, gera JSON intermediario opcional e monta o workbook final.

### Widget/Modulo Zabbix

Modulo localizado em `zabbix_modules/proxy_health_assessment`.

Ele oferece uma visao interativa dentro do Zabbix com:

- selecao do Host Group dos proxies;
- selecao opcional do host do Zabbix Server;
- configuracao dos thresholds do assessment;
- cards de saude por objeto avaliado, um objeto por linha;
- diagnosticos em cards compactos dentro de cada objeto;
- tabelas expandidas de processos, caches e configuracoes;
- aba de Regras de Negocio;
- exportacao em `XLSX` real e `CSV`.

O `XLSX` e o formato default de exportacao do widget. As opcoes antigas de `XLS`/`XML` foram removidas porque eram workarounds de compatibilidade e podiam gerar aviso no Excel.

## Requisitos

- Python 3.10 ou superior.
- Acesso de rede ao frontend/API do Zabbix.
- Token de API Zabbix com permissao de leitura para hosts, templates, itens, trends, triggers e problems.
- Bibliotecas Python:

```bash
python -m pip install -r requirements.txt
```

As dependencias sao:

- `zabbix-utils`: biblioteca oficial do Zabbix para uso da API.
- `openpyxl`: geracao do arquivo `.xlsx`.

O script nao depende de Codex, Node.js, artifact-tool, requests, planilhas preexistentes ou caminhos locais especificos.

## Uso

Exemplo:

```bash
python zabbix_proxy_health_assessment_v2.py \
  --api-url https://webmonitor.com.br \
  --token "SEU_TOKEN" \
  --host-group "Zabbix/Proxies" \
  --output-xlsx webmonitor_proxy_health_assessment_v3_0.xlsx
```

Tambem e aceito informar a URL completa da API:

```bash
--api-url https://webmonitor.com.br/api_jsonrpc.php
```

O script normaliza automaticamente para a URL base usada pela biblioteca oficial `zabbix-utils`.

## Uso do widget

No Zabbix, acesse o modulo **Proxy Health** no menu onde ele estiver habilitado para o ambiente.

Na aba **Configuracao**, defina:

- **Host group**: grupo obrigatorio que contem os Zabbix Proxies avaliados. Padrao esperado: `Zabbix/Proxies`.
- **Template de proxy**: filtro opcional para remover hosts do grupo que nao pertencem ao assessment.
- **Incluir hosts com template herdado por outro template**: quando habilitado, tambem considera hosts que usam templates filhos/herdeiros do template selecionado.
- **Zabbix Server**: host opcional que representa o Zabbix Server da instalacao.
- **Dias de trends**: janela das medias historicas usadas no score e nas recomendacoes, limitada entre `7` e `30` dias.
- thresholds de versao, unsupported, VPS, CPU, memoria, disco, filas, pollers e caches;
- se problemas orfaos entram no score;
- se configuracoes de processos/caches entram no score;
- se recomendacoes de Process vs Config aparecem no resumo sem alterar o score.

No Overview, os cards superiores tambem funcionam como filtros clicaveis. O card **Fora do escopo** nao filtra os objetos avaliados; ele abre um drilldown com os objetos removidos antes do score, normalmente por ultimo acesso acima do limite configurado.

Quando o host **Zabbix Server** e selecionado:

- ele entra no assessment mesmo que nao esteja no Host Group dos proxies;
- ele e exibido sempre antes dos proxies;
- ele nao duplica caso tambem esteja no Host Group selecionado;
- ele aparece como `Zabbix Server` nos cards, no Overview e nas exportacoes;
- as mesmas regras de score sao aplicadas quando existirem itens equivalentes.

Pre-requisitos recomendados para o host do Zabbix Server:

- monitoramento Linux default, como `Linux by Zabbix agent` ou equivalente;
- health check do Zabbix Server;
- template custom de leitura de configuracao com itens `num.*`;
- itens internos equivalentes aos usados na avaliacao de processos, filas e caches.

Sem esses itens, o host ainda pode aparecer no assessment, mas metricas ausentes ficam vazias e nao sao avaliadas.

### Parametros

| Parametro | Obrigatorio | Descricao |
|---|---:|---|
| `--api-url` | Sim | URL base do Zabbix ou URL completa `api_jsonrpc.php`. |
| `--token` | Sim | Token de API do Zabbix. |
| `--host-group` | Nao | Host Group que contem os proxies. Padrao: `Zabbix/Proxies`. |
| `--template-id` | Nao | Filtro complementar por template ID. Use quando o host group do ambiente ainda contem hosts que nao sao proxies. |
| `--input-json` | Nao | Regera a planilha a partir de um JSON ja coletado, sem nova consulta ao Zabbix. |
| `--exclude-host` | Nao | Remove um host pelo nome tecnico ou visivel. Pode ser informado mais de uma vez. |
| `--output-xlsx` | Sim | Caminho do arquivo `.xlsx` final. |
| `--output-json` | Nao | Caminho opcional para salvar o JSON intermediario. Por padrao fica ao lado do XLSX. |
| `--skip-disk` | Nao | Ignora a etapa complementar de descoberta de disco. |
| `--skip-validate` | Nao | Ignora a validacao final do pacote XLSX. |

## Abas geradas

### Config

Aba de parametros editaveis. Ela permite recalcular o score diretamente no Excel sem executar nova coleta.

Principais parametros:

| Parametro | Padrao | Uso |
|---|---:|---|
| Versao de corte | `7.0.20` | Referencia humana para a versao esperada. |
| Patch minimo | `20` | Penaliza versoes `7.0.x` abaixo do patch minimo. |
| Unsupported maximo | `2%` | Limite de itens unsupported em relacao ao total de itens monitorados pelo objeto avaliado. |
| VPS atual maximo | `300` | Limite para valores por segundo processados pelo objeto avaliado. |
| Dias de trends | `30` | Janela das medias historicas. No widget, pode variar entre `7` e `30` dias. |
| CPU atual/media historica | `85%` / `75%` | Limites de CPU do sistema operacional. |
| Memoria atual/media historica | `85%` / `80%` | Limites de memoria do sistema operacional. |
| Disco atual/media historica | `85%` / `80%` | Limites de disco do filesystem selecionado. |
| Fila 10m maxima | `0` | Qualquer item sem dados ha 10 minutos passa a ser relevante. |
| Preproc queue maxima | `50` | Limite da fila de preprocessing. |
| Considerar Problemas orfaos? | `Nao` | Controla se problemas orfaos entram no score. |
| Considerar configuracao do Proxy? | `Nao` | Controla se Process vs Config e Cache vs Config entram no score. |
| Threshold pollers | `75%` | Limite para busy atual ou media historica nos processos mapeados. |
| Threshold caches | `75%` | Limite para uso atual ou media historica dos caches. |
| Mostrar recomendacoes Process vs Config no resumo? | `Sim` | Mostra recomendacoes de aumento/diminuicao no resumo do objeto sem alterar o score. |

### Overview

Resumo executivo dos objetos avaliados. Contem uma linha por proxy online/habilitado e, quando selecionado, uma linha prioritaria para o Zabbix Server, com:

- tipo do objeto (`Zabbix Server` ou `Zabbix Proxy`);
- estado final;
- score;
- versao;
- percentual de unsupported;
- VPS atual;
- CPU, memoria total, memoria atual/media historica e disco;
- resumo textual do objeto avaliado.

### Host Health

Aba principal do assessment tecnico no script standalone. Contem todas as metricas consolidadas por proxy e formulas de score.

Colunas importantes:

- `Unsupported %`: itens unsupported dividido pelo total de itens.
- `VPS atual`: valor atual de `zabbix[wcache,values]`.
- `Disco atual %` e `Disco media 30d %`: uso de disco selecionado pelo fallback descrito abaixo. No widget, a janela equivalente pode ser ajustada em `Dias de trends`.
- `Config issues`: quantidade de problemas encontrados em `Process vs Config` e `Cache vs Config`.
- `Score`: pontuacao recalculavel.
- `State`: classificacao derivada do score.
- `Resumo do proxy`: texto objetivo com os achados que atacam o score.

Quando a avaliacao de configuracao esta ligada, caches acima do threshold entram no score e no resumo. As recomendacoes de `Process vs Config` podem aparecer no resumo por meio de `Mostrar recomendacoes Process vs Config no resumo?`, mas essa opcao nao altera o score.

O resumo do proxy e recalculavel no Excel, mas evita funcoes modernas como `TEXTJOIN` e `FILTER` para manter compatibilidade com ambientes em portugues e instalacoes do Excel que removem essas formulas ao abrir o arquivo. A formula final usa colunas auxiliares ocultas e apenas funcoes mais basicas, como `IF`, `COUNTIFS` e concatenacao com `&`.

```text
Configuration cache acima do threshold de caches;
http poller: diminuir pollers;
```

### Proxy Config

Lista os itens de configuracao coletados diretamente dos hosts avaliados. A coleta busca itens com chave `num.*`, evitando acesso manual ao arquivo de configuracao do Zabbix Proxy ou Zabbix Server e dispensando informar o template de configuracao.

Exemplos de parametros esperados:

- `num.StartAgentPollers`
- `num.StartSNMPPollers`
- `num.StartHTTPPollers`
- `num.StartTrappers`
- `num.StartPollersUnreachable`
- `num.CacheSize`
- `num.valueCacheSize`
- `num.trendcachesize`

O script/widget coleta todas as chaves `num.*` presentes nos hosts avaliados, nao apenas os exemplos acima.

### Process vs Config

Compara os processos internos do objeto avaliado com os parametros configurados.

Exemplos de mapeamento:

| Processo Zabbix | Parametro de configuracao |
|---|---|
| `agent poller` | `num.StartAgentPollers` |
| `browser poller` | `num.StartBrowserPollers` |
| `snmp poller` | `num.StartSNMPPollers` |
| `http poller` | `num.StartHTTPPollers` |
| `http agent poller` | `num.StartHTTPAgentPollers` |
| `icmp pinger` | `num.StartPingers` |
| `discovery worker` | `num.StartDiscoverers` |
| `preprocessing worker` | `num.StartPreprocessors` |
| `trapper` | `num.StartTrappers` |
| `unreachable poller` | `num.StartPollersUnreachable` |
| `history syncer` | `num.StartDBSyncers` |
| `odbc poller` | `num.StartODBCPollers` |
| `ipmi poller` | `num.StartIPMIPollers` |
| `java poller` | `num.StartJavaPollers` |
| `vmware collector` | `num.StartVMwareCollectors` |

No Zabbix 7.0+, processos de discovery e preprocessing sao avaliados pelos workers:

- `discovery worker` usa recomendacao conservadora: mantem o configurado e recomenda apenas `+1` quando a media do busy chega a 100%.
- `preprocessing worker` usa `StartPreprocessors` e a recomendacao padrao baseada em carga desejada.
- Managers como `discovery manager` e `preprocessing manager` aparecem nas leituras, mas nao entram como parametros configuraveis de quantidade recomendada.

Importante: o script nao divide o busy pela quantidade de pollers.

O item `zabbix[process,...,avg,busy]` ja representa o percentual de ocupacao daquele pool de processos. A quantidade configurada entra como contexto para indicar qual parametro deve ser revisado quando o busy ultrapassa o threshold.

Regra:

```text
Se Busy atual % > Threshold pollers
OU Busy media historica % > Threshold pollers
=> Status = Avaliar aumento
```

Por padrao, `Threshold pollers = 75%`.

Para reducao, existem duas regras:

```text
Se Valor configurado > Valor recomendado
E Busy media historica % < 50
=> Status = Avaliar diminuicao
```

Excecao importante:

```text
Se Busy atual % = 0
E Busy media historica % = 0
E Valor configurado > 1
=> Status = Avaliar diminuicao
=> Acao sugerida = diminuir numero de pollers para 1
```

Nesse caso, o valor recomendado coletado e ignorado porque o pool nao demonstra uso real no objeto avaliado.

### Cache vs Config

Compara uso dos caches do Zabbix Proxy ou Zabbix Server com o threshold configurado, quando os itens equivalentes existem.

Alguns itens do Zabbix retornam percentual livre (`pfree`) e outros percentual usado (`pused`). Para padronizar:

```text
Se o item e pfree: uso = 100 - pfree
Se o item e pused: uso = pused
```

Regra:

```text
Se Uso atual % > Threshold caches
OU Uso media historica % > Threshold caches
=> Status = Avaliar ajuste
```

Por padrao, `Threshold caches = 75%`.

Quando existem as duas formas para o mesmo cache, o script prefere a metrica `pused` e usa `pfree` apenas como fallback.

Quando existem itens de configuracao em string, como `8M`, `4M` ou `1G`, o script converte o valor para bytes para permitir calculo numerico. Se o template ja trouxer itens `.bytes` e `num.recomendado.*` de cache, eles sao usados quando validos; se vierem vazios ou zerados, o script recalcula localmente.

Formula de recomendacao de cache:

```text
ceil((cache_configurado_bytes * (uso_percentual / 100)) / 0.60)
```

O valor `0.60` reflete a carga desejada default de 60%. Nao ha folga adicional para caches.

### Process 30d

Lista os processos internos do objeto avaliado com:

- busy atual;
- media 30 dias;
- maximo 30 dias;
- ultima coleta;
- estado do item.

### Active Problems

Lista problems ativos validos. O script tenta remover problems orfaos desta aba para evitar divergencia com o frontend.

### Orphan Problems

Lista problems considerados orfaos, por exemplo:

- trigger desabilitada;
- trigger sem contexto valido retornado pela API;
- item associado desabilitado.

Por padrao, problems orfaos nao entram no score. Essa decisao pode ser alterada na aba `Config`.

### Raw Items

Tabela de auditoria com os itens brutos usados no assessment, incluindo valores atuais, unidade, ultima coleta, media 30d e maximo 30d quando disponiveis.

## Metodologia do score

O score comeca em 100 e sofre descontos quando criterios configurados sao violados.

Penalizacoes principais:

| Condicao | Penalizacao |
|---|---:|
| Alerta Disaster ativo | `-50` |
| Alerta relevante de saude do proxy/server | `-20` |
| Problema orfao Disaster, se habilitado | `-50` |
| Problema orfao relevante, se habilitado | `-20` |
| Versao abaixo do patch minimo | `-15` |
| Unsupported acima do limite | `-15` |
| VPS atual acima do limite | `-10` |
| CPU atual/media historica acima do limite | `-10` cada |
| Memoria atual/media historica acima do limite | `-10` cada |
| Disco atual/media historica acima do limite | `-10` cada |
| Fila 10m acima do limite | `-10` |
| Preprocessing queue acima do limite | `-10` |
| Config issues, se habilitado | `-15` |

O score nunca fica abaixo de 0.

`Config issues` considera processos com `Avaliar aumento` e caches com `Avaliar ajuste` quando `Considerar configuracao do Proxy? = Sim`. Recomendacoes de `Avaliar diminuicao` sao operacionais e podem aparecer no resumo, mas nao reduzem o score.

## States

Os states sao derivados do score:

| State | Regra padrao |
|---|---|
| OK | `score >= 90` |
| Atencao | `score >= 70` |
| Risco | `score >= 40` |
| Critico | `score < 40` |

Os limites tambem ficam na aba `Config`.

## Fallback de disco

Nem todo template/host possui exatamente o item `vfs.fs.size[/,pused]`.

Para preencher `Disco atual %` e `Disco media 30d %`, o script tenta selecionar a melhor metrica percentual disponivel nesta ordem:

1. `vfs.fs.size[/,pused]`
2. `vfs.fs.size[/,pfree]`, convertido para uso com `100 - pfree`
3. maior filesystem percentual disponivel com `pused` ou `pfree`

Se nenhum item percentual de disco existir para o host, as colunas de disco permanecem vazias. Isso indica ausencia de dado coletavel no Zabbix para aquele objeto, nao erro de formula.

## Segurança

- Nao coloque tokens no README, no Git ou em arquivos versionados.
- Passe o token via argumento, variavel de ambiente ou mecanismo seguro do seu ambiente de execucao.
- Os arquivos `.xlsx` e `.json` gerados sao ignorados pelo `.gitignore` por poderem conter dados sensiveis do ambiente.

## Limitacoes conhecidas

- As medias historicas usam `trend.get`, portanto dependem da retencao de trends do Zabbix. No widget, a janela configuravel vai de `7` a `30` dias.
- A analise de disco depende da existencia de itens percentuais `vfs.fs.size[...,pused/pfree]`.
- A comparacao de pollers usa o percentual busy do proprio Zabbix. A quantidade configurada e usada para indicar qual parametro revisar, nao como divisor matematico.
- A qualidade da aba `Proxy Config` depende dos hosts avaliados possuirem itens `num.*` coletando corretamente os valores do arquivo de configuracao.
- No widget, o Zabbix Server so entra no assessment quando um host e selecionado manualmente na aba de configuracao.
- Itens novos passam a aparecer em `Proxy Config` quando usam chave `num.*`, mas so alteram o score quando fazem parte das regras documentadas.
