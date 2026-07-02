# Zabbix Proxy Health Assessment

Script standalone em Python para extrair dados de proxies Zabbix, avaliar a saude operacional desses proxies e gerar uma planilha `.xlsx` estruturada com metodologia de score, dados brutos, problemas ativos, problemas orfaos, configuracoes de proxy, comparativo de processos versus configuracao e comparativo de caches versus configuracao.

O objetivo do assessment e responder uma pergunta bem pratica:

> O proxy esta saudavel como componente de monitoramento, ou existe algum sinal de que ele pode parar de coletar/processar corretamente?

Por isso, o score nao mede a quantidade bruta de problemas dos hosts monitorados por um proxy. Ele foca em sinais que afetam o proprio proxy, a aplicacao Zabbix Proxy, os processos internos, filas, caches e recursos do sistema operacional.

A coleta e adaptativa: alem das chaves essenciais do assessment, o script descobre os itens habilitados nos templates informados em `--proxy-template-id` e `--config-template-id`. Isso reduz o risco de deixar fora leituras simples quando o template de saude ou o template de configuracao evoluem.

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
  --proxy-template-id 12064 \
  --config-template-id 88293 \
  --output-xlsx webmonitor_proxy_health_assessment_v2_0.xlsx
```

Tambem e aceito informar a URL completa da API:

```bash
--api-url https://webmonitor.com.br/api_jsonrpc.php
```

O script normaliza automaticamente para a URL base usada pela biblioteca oficial `zabbix-utils`.

### Parametros

| Parametro | Obrigatorio | Descricao |
|---|---:|---|
| `--api-url` | Sim | URL base do Zabbix ou URL completa `api_jsonrpc.php`. |
| `--token` | Sim | Token de API do Zabbix. |
| `--proxy-template-id` | Sim | ID do template usado pelos hosts de proxy. |
| `--config-template-id` | Sim | ID do template que coleta configuracoes do arquivo do proxy. |
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
| Unsupported maximo | `2%` | Limite de itens unsupported em relacao ao total de itens monitorados pelo proxy. |
| VPS atual maximo | `300` | Limite para valores por segundo processados pelo proxy. |
| CPU atual/media 30d | `85%` / `75%` | Limites de CPU do sistema operacional. |
| Memoria atual/media 30d | `85%` / `80%` | Limites de memoria do sistema operacional. |
| Disco atual/media 30d | `85%` / `80%` | Limites de disco do filesystem selecionado. |
| Fila 10m maxima | `0` | Qualquer item sem dados ha 10 minutos passa a ser relevante. |
| Preproc queue maxima | `50` | Limite da fila de preprocessing. |
| Considerar Problemas orfaos? | `Nao` | Controla se problemas orfaos entram no score. |
| Considerar configuracao do Proxy? | `Nao` | Controla se Process vs Config e Cache vs Config entram no score. |
| Threshold pollers | `75%` | Limite para busy atual ou media 30d nos processos mapeados. |
| Threshold caches | `75%` | Limite para uso atual ou media 30d dos caches. |
| Mostrar recomendacoes Process vs Config no resumo? | `Sim` | Mostra recomendacoes de aumento/diminuicao no resumo do proxy sem alterar o score. |

### Overview

Resumo executivo dos proxies avaliados. Contem uma linha por proxy online/habilitado, com:

- estado final;
- score;
- versao;
- percentual de unsupported;
- VPS atual;
- CPU, memoria total, memoria atual/media 30d e disco;
- resumo textual do proxy.

### Host Health

Aba principal do assessment tecnico. Contem todas as metricas consolidadas por proxy e formulas de score.

Colunas importantes:

- `Unsupported %`: itens unsupported dividido pelo total de itens.
- `VPS atual`: valor atual de `zabbix[wcache,values]`.
- `Disco atual %` e `Disco media 30d %`: uso de disco selecionado pelo fallback descrito abaixo.
- `Config issues`: quantidade de problemas encontrados em `Process vs Config` e `Cache vs Config`.
- `Score`: pontuacao recalculavel.
- `State`: classificacao derivada do score.
- `Resumo do proxy`: texto objetivo com os achados que atacam o score.

Quando a avaliacao de configuracao esta ligada, caches acima do threshold entram no score e no resumo. As recomendacoes de `Process vs Config` podem aparecer no resumo por meio de `Mostrar recomendacoes Process vs Config no resumo?`, mas essa opcao nao altera o score.

```text
Configuration cache acima do threshold de caches;
http poller: diminuir pollers;
```

### Proxy Config

Lista os itens coletados do template de configuracao do proxy. A ideia e evitar acesso manual ao arquivo de configuracao do Zabbix Proxy.

Exemplos de parametros esperados:

- `num.StartAgentPollers`
- `num.StartSNMPPollers`
- `num.StartHTTPPollers`
- `num.StartTrappers`
- `num.StartPollersUnreachable`
- `num.CacheSize`
- `num.valueCacheSize`
- `num.trendcachesize`

O script coleta todas as chaves habilitadas do template de configuracao informado, nao apenas os exemplos acima.

### Process vs Config

Compara os processos internos do proxy com os parametros configurados.

Exemplos de mapeamento:

| Processo Zabbix | Parametro de configuracao |
|---|---|
| `agent poller` | `num.StartAgentPollers` |
| `snmp poller` | `num.StartSNMPPollers` |
| `http poller` | `num.StartHTTPPollers` |
| `http agent poller` | `num.StartHTTPAgentPollers` |
| `icmp pinger` | `num.StartPingers` |
| `trapper` | `num.StartTrappers` |
| `unreachable poller` | `num.StartPollersUnreachable` |
| `history syncer` | `num.StartDBSyncers` |
| `odbc poller` | `num.StartODBCPollers` |
| `ipmi poller` | `num.StartIPMIPollers` |
| `java poller` | `num.StartJavaPollers` |

Importante: o script nao divide o busy pela quantidade de pollers.

O item `zabbix[process,...,avg,busy]` ja representa o percentual de ocupacao daquele pool de processos. A quantidade configurada entra como contexto para indicar qual parametro deve ser revisado quando o busy ultrapassa o threshold.

Regra:

```text
Se Busy atual % > Threshold pollers
OU Busy media 30d % > Threshold pollers
=> Status = Avaliar aumento
```

Por padrao, `Threshold pollers = 75%`.

Para reducao, existem duas regras:

```text
Se Valor configurado > Valor recomendado
E Busy media 30d % < 50
=> Status = Avaliar diminuicao
```

Excecao importante:

```text
Se Busy atual % = 0
E Busy media 30d % = 0
E Valor configurado > 1
=> Status = Avaliar diminuicao
=> Acao sugerida = diminuir numero de pollers para 1
```

Nesse caso, o valor recomendado coletado e ignorado porque o pool nao demonstra uso real no proxy.

### Cache vs Config

Compara uso dos caches do Zabbix Proxy com o threshold configurado.

Alguns itens do Zabbix retornam percentual livre (`pfree`) e outros percentual usado (`pused`). Para padronizar:

```text
Se o item e pfree: uso = 100 - pfree
Se o item e pused: uso = pused
```

Regra:

```text
Se Uso atual % > Threshold caches
OU Uso media 30d % > Threshold caches
=> Status = Avaliar ajuste
```

Por padrao, `Threshold caches = 75%`.

Quando existem as duas formas para o mesmo cache, o script prefere a metrica `pused` e usa `pfree` apenas como fallback.

### Process 30d

Lista os processos internos do proxy com:

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
| Alerta relevante de saude do proxy | `-20` |
| Problema orfao Disaster, se habilitado | `-50` |
| Problema orfao relevante, se habilitado | `-20` |
| Versao abaixo do patch minimo | `-15` |
| Unsupported acima do limite | `-15` |
| VPS atual acima do limite | `-10` |
| CPU atual/media 30d acima do limite | `-10` cada |
| Memoria atual/media 30d acima do limite | `-10` cada |
| Disco atual/media 30d acima do limite | `-10` cada |
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

Nem todo template/proxy possui exatamente o item `vfs.fs.size[/,pused]`.

Para preencher `Disco atual %` e `Disco media 30d %`, o script tenta selecionar a melhor metrica percentual disponivel nesta ordem:

1. `vfs.fs.size[/,pused]`
2. `vfs.fs.size[/,pfree]`, convertido para uso com `100 - pfree`
3. maior filesystem percentual disponivel com `pused` ou `pfree`

Se nenhum item percentual de disco existir para o host, as colunas de disco permanecem vazias. Isso indica ausencia de dado coletavel no Zabbix para aquele proxy, nao erro de formula.

## Segurança

- Nao coloque tokens no README, no Git ou em arquivos versionados.
- Passe o token via argumento, variavel de ambiente ou mecanismo seguro do seu ambiente de execucao.
- Os arquivos `.xlsx` e `.json` gerados sao ignorados pelo `.gitignore` por poderem conter dados sensiveis do ambiente.

## Limitacoes conhecidas

- As medias de 30 dias usam `trend.get`, portanto dependem da retencao de trends do Zabbix.
- A analise de disco depende da existencia de itens percentuais `vfs.fs.size[...,pused/pfree]`.
- A comparacao de pollers usa o percentual busy do proprio Zabbix. A quantidade configurada e usada para indicar qual parametro revisar, nao como divisor matematico.
- A qualidade da aba `Proxy Config` depende do template de configuracao informado coletar corretamente os valores do arquivo do proxy.
- A coleta acompanha as chaves habilitadas nos templates informados; itens novos passam a aparecer em `Raw Items`/`Proxy Config`, mas so alteram o score quando fazem parte das regras documentadas.
