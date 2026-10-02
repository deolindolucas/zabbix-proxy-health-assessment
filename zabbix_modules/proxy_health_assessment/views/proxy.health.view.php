<?php declare(strict_types = 0);

/**
 * Proxy Health Assessment.
 *
 * @var CView $this
 * @var array $data
 */

$page = (new CHtmlPage())->setTitle(_('Proxy Health Assessment'));
$settings = $data['settings'];

$asset_base = 'modules/proxy_health_assessment/assets';
$asset_path = dirname(__DIR__).'/assets';
$module_manifest = json_decode((string) @file_get_contents(dirname(__DIR__).'/manifest.json'), true);
$module_version = is_array($module_manifest) && isset($module_manifest['version'])
    ? 'v'.$module_manifest['version']
    : 'v4';
$asset_url = static function(string $relative) use ($asset_base, $asset_path): string {
    $url = new CUrl($asset_base.'/'.$relative);
    $file = $asset_path.'/'.$relative;

    if (is_file($file)) {
        $url->setArgument('v', (string) filemtime($file));
    }

    return $url->getUrl();
};

$this
    ->addCssFile($asset_url('css/proxy-health-page.css'));

$field = static function($label, $control, string $help = ''): CDiv {
    $label_items = [$label];
    if ($help !== '') {
        $label_items[] = (new CSpan('?'))
            ->addClass('proxy-health-help')
            ->setAttribute('title', $help)
            ->setAttribute('aria-label', $help);
    }

    return (new CDiv([
        (new CDiv($label_items))->addClass('proxy-health-config-label'),
        $control
    ]))->addClass('proxy-health-config-field');
};

$input = static function(string $label, string $name, $value, string $type = 'text', array $attributes = [], string $help = '') use ($field): CDiv {
    $control = (new CTextBox($name, (string) $value))
        ->setId('proxy-health-'.$name)
        ->setAttribute('type', $type);

    foreach ($attributes as $attribute => $attribute_value) {
        $control->setAttribute($attribute, (string) $attribute_value);
    }

    return $field(
        (new CLabel($label, 'proxy-health-'.$name)),
        $control,
        $help
    );
};

$checkbox = static function(string $label, string $name, string $value, string $help = '') use ($field): CDiv {
    return $field(
        (new CLabel($label, 'proxy-health-'.$name)),
        (new CCheckBox($name, 'Sim'))
            ->setId('proxy-health-'.$name)
            ->setChecked($value === 'Sim')
            ->setUncheckedValue('Nao'),
        $help
    );
};

$host_group_select = static function(array $settings) use ($field): CDiv {
    $selected = $settings['host_groupid'] !== ''
        ? [['id' => $settings['host_groupid'], 'name' => $settings['host_group_name']]]
        : [];

    return $field(
        (new CLabel([_('Host group'), (new CSpan('*'))->addClass('proxy-health-required')], 'host_groupid')),
        (new CMultiSelect([
            'name' => 'host_groupid',
            'object_name' => 'hostGroup',
            'data' => $selected,
            'multiple' => false,
            'popup' => [
                'parameters' => [
                    'srctbl' => 'host_groups',
                    'srcfld1' => 'groupid',
                    'dstfrm' => 'proxy_health_config_form',
                    'dstfld1' => 'host_groupid',
                    'normal_only' => '1'
                ]
            ]
        ]))
            ->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH),
        _('Grupo de hosts que define o escopo principal do assessment. E obrigatorio para coletar proxies.')
    );
};

$proxy_template_select = static function(array $settings) use ($field): CDiv {
    $selected = $settings['proxy_templateid'] !== ''
        ? [['id' => $settings['proxy_templateid'], 'name' => $settings['proxy_template_name']]]
        : [];

    return $field(
        (new CLabel(_('Template de proxy'), 'proxy_templateid')),
        (new CMultiSelect([
            'name' => 'proxy_templateid',
            'object_name' => 'templates',
            'data' => $selected,
            'multiple' => false,
            'popup' => [
                'parameters' => [
                    'srctbl' => 'templates',
                    'srcfld1' => 'hostid',
                    'dstfrm' => 'proxy_health_config_form',
                    'dstfld1' => 'proxy_templateid'
                ]
            ]
        ]))
            ->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH),
        _('Template opcional usado para refinar o Host Group e remover hosts que nao fazem parte do assessment.')
    );
};

$zabbix_server_host_select = static function(array $settings) use ($field): CDiv {
    $selected = $settings['zabbix_server_hostid'] !== ''
        ? [['id' => $settings['zabbix_server_hostid'], 'name' => $settings['zabbix_server_host_name']]]
        : [];

    return $field(
        (new CLabel(_('Zabbix Server'), 'zabbix_server_hostid')),
        (new CMultiSelect([
            'name' => 'zabbix_server_hostid',
            'object_name' => 'hosts',
            'data' => $selected,
            'multiple' => false,
            'popup' => [
                'parameters' => [
                    'srctbl' => 'hosts',
                    'srcfld1' => 'hostid',
                    'dstfrm' => 'proxy_health_config_form',
                    'dstfld1' => 'zabbix_server_hostid',
                    'real_hosts' => '1'
                ]
            ]
        ]))
            ->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH),
        _('Host opcional do Zabbix Server. Quando selecionado, entra em primeiro lugar no assessment.')
    );
};

$block = static function(string $title, array $fields, $toggle = null): CDiv {
    if ($toggle !== null) {
        array_unshift($fields, $toggle);
    }

    return (new CDiv([
        (new CTag('h3', true, $title))->addClass('proxy-health-form-title'),
        (new CDiv($fields))->addClass('proxy-health-config-list')
    ]))
        ->addClass('proxy-health-config-block');
};

$rules_section = static function(string $title, array $items): CDiv {
    return (new CDiv([
        (new CTag('h3', true, $title))->addClass('proxy-health-rule-title'),
        (new CTag('ul', true, array_map(
            static fn(string $item): CTag => new CTag('li', true, $item),
            $items
        )))->addClass('proxy-health-rule-list')
    ]))->addClass('proxy-health-rule-section');
};

$config_form = (new CForm('get'))
    ->setName('proxy_health_config_form')
    ->setId('proxy-health-config-form')
    ->addClass('proxy-health-config-form')
    ->addVar('action', $data['action'])
    ->addItem([
        $block(_('Coleta'), [
            $host_group_select($settings),
            $checkbox(_('Filtrar hosts pelo template de proxy'), 'proxy_template_filter', $settings['proxy_template_filter'], _('Quando habilitado, o template selecionado tambem limita quais hosts do grupo entram no assessment.')),
            $proxy_template_select($settings),
            $checkbox(_('Incluir hosts com template herdado por outro template'), 'proxy_template_indirect', $settings['proxy_template_indirect'], _('Inclui hosts que nao possuem o template selecionado diretamente, mas usam um template filho que herda/linka esse template.')),
            $zabbix_server_host_select($settings),
            $input(_('Versao de corte'), 'version_cut', $settings['version_cut'], 'text', [], _('Versao minima esperada para o proxy ou server. Versoes abaixo entram como achado.')),
            $input(_('Patch minimo'), 'patch_min', $settings['patch_min'], 'number', [], _('Patch minimo aceito dentro da versao de corte informada.')),
            $input(_('Ultimo acesso maximo (s)'), 'lastaccess_max', $settings['lastaccess_max'], 'number', [], _('Idade maxima, em segundos, do ultimo acesso do proxy antes de sair do escopo.'))
        ]),
        $block(_('Thresholds principais do score'), [
            $input(_('Dias de trends'), 'trend_days', $settings['trend_days'], 'number', ['min' => 7, 'max' => 30, 'step' => 1], _('Janela usada para medias historicas. Minimo 7 dias e maximo 30 dias.')),
            $input(_('Unsupported maximo (%)'), 'unsupported_max', $settings['unsupported_max_percent'], 'number', ['min' => 0, 'step' => 1], _('Percentual maximo de itens unsupported no escopo do proxy.')),
            $input(_('Unsupported critico (%)'), 'unsupported_crit', $settings['unsupported_crit_percent'], 'number', ['min' => 0, 'step' => 1], _('Valor em que o desconto atinge o maximo da regra. Entre o limite e este valor o desconto e proporcional.')),
            $input(_('VPS P95 maximo'), 'vps_max', $settings['vps_max'], 'number', [], _('P95 dos maximos horarios de valores por segundo acima deste limite reduz score.')),
            $input(_('VPS P95 critico'), 'vps_crit', $settings['vps_crit'], 'number', [], _('Valor em que o desconto atinge o maximo da regra. Entre o limite e este valor o desconto e proporcional.')),
            $input(_('CPU P95 maxima'), 'cpu_p95_max', $settings['cpu_p95_max'], 'number', [], _('P95 dos maximos horarios de CPU na janela de trends.')),
            $input(_('CPU P95 critica'), 'cpu_p95_crit', $settings['cpu_p95_crit'], 'number', [], _('Valor em que o desconto atinge o maximo da regra. Entre o limite e este valor o desconto e proporcional.')),
            $input(_('CPU media maxima'), 'cpu_avg_max', $settings['cpu_avg_max'], 'number', [], _('Media historica de CPU maxima aceita na janela de trends.')),
            $input(_('CPU media critica'), 'cpu_avg_crit', $settings['cpu_avg_crit'], 'number', [], _('Valor em que o desconto atinge o maximo da regra. Entre o limite e este valor o desconto e proporcional.')),
            $input(_('Memoria P95 maxima'), 'memory_p95_max', $settings['memory_p95_max'], 'number', [], _('P95 dos maximos horarios de memoria na janela de trends.')),
            $input(_('Memoria P95 critica'), 'memory_p95_crit', $settings['memory_p95_crit'], 'number', [], _('Valor em que o desconto atinge o maximo da regra. Entre o limite e este valor o desconto e proporcional.')),
            $input(_('Memoria media maxima'), 'memory_avg_max', $settings['memory_avg_max'], 'number', [], _('Media historica de memoria maxima aceita na janela de trends.')),
            $input(_('Memoria media critica'), 'memory_avg_crit', $settings['memory_avg_crit'], 'number', [], _('Valor em que o desconto atinge o maximo da regra. Entre o limite e este valor o desconto e proporcional.')),
            $input(_('Disco P95 maximo'), 'disk_p95_max', $settings['disk_p95_max'], 'number', [], _('P95 dos maximos horarios de disco na janela de trends.')),
            $input(_('Disco P95 critico'), 'disk_p95_crit', $settings['disk_p95_crit'], 'number', [], _('Valor em que o desconto atinge o maximo da regra. Entre o limite e este valor o desconto e proporcional.')),
            $input(_('Disco media maxima'), 'disk_avg_max', $settings['disk_avg_max'], 'number', [], _('Media historica de disco maxima aceita na janela de trends.')),
            $input(_('Disco media critica'), 'disk_avg_crit', $settings['disk_avg_crit'], 'number', [], _('Valor em que o desconto atinge o maximo da regra. Entre o limite e este valor o desconto e proporcional.')),
            $input(_('Fila 10m P95 maxima'), 'queue_10m_max', $settings['queue_10m_max'], 'number', [], _('P95 dos maximos horarios da fila de itens acima de 10 minutos.')),
            $input(_('Fila 10m P95 critica'), 'queue_10m_crit', $settings['queue_10m_crit'], 'number', [], _('Valor em que o desconto atinge o maximo da regra. Entre o limite e este valor o desconto e proporcional.')),
            $input(_('Preproc queue P95 maxima'), 'preproc_queue_max', $settings['preproc_queue_max'], 'number', [], _('P95 dos maximos horarios da fila de preprocessing.')),
            $input(_('Preproc queue P95 critica'), 'preproc_queue_crit', $settings['preproc_queue_crit'], 'number', [], _('Valor em que o desconto atinge o maximo da regra. Entre o limite e este valor o desconto e proporcional.'))
        ]),
        $block(_('Problemas orfaos'), [], $checkbox(_('Usar este bloco no assessment'), 'consider_orphans', $settings['consider_orphans'], _('Quando habilitado, problemas ativos sem trigger/item valido tambem podem afetar o score.'))),
        $block(_('Configuracao do proxy no score'), [
            $input(_('Threshold pollers'), 'poller_threshold', $settings['poller_threshold'], 'number', [], _('Busy P95 ou media historica acima deste percentual gera avaliacao de ajuste.')),
            $input(_('Threshold caches'), 'cache_threshold', $settings['cache_threshold'], 'number', [], _('Uso P95 ou media historica de cache acima deste percentual gera avaliacao de ajuste.'))
        ], $checkbox(_('Usar este bloco no assessment'), 'consider_config', $settings['consider_config'], _('Quando habilitado, problemas de processos/caches versus configuracao entram no score.'))),
        $block(_('Recomendacoes Process vs Config no resumo'), [], $checkbox(_('Mostrar no resumo sem alterar o score'), 'show_process_recommendations', $settings['show_process_recommendations'], _('Exibe recomendacoes operacionais no resumo visual sem penalizar o score.'))),
        (new CSubmit('apply', _('Aplicar')))->addClass(ZBX_STYLE_BTN_ALT)
    ]);

$tabs = (new CDiv([
    (new CTag('button', true, _('Overview')))
        ->addClass('proxy-health-tab is-selected')
        ->setAttribute('type', 'button')
        ->setAttribute('data-proxy-tab', 'overview')
        ->setAttribute('aria-pressed', 'true'),
    (new CTag('button', true, _('Configuracao')))
        ->addClass('proxy-health-tab')
        ->setAttribute('type', 'button')
        ->setAttribute('data-proxy-tab', 'config')
        ->setAttribute('aria-pressed', 'false'),
    (new CTag('button', true, _('Regras de Negocio')))
        ->addClass('proxy-health-tab')
        ->setAttribute('type', 'button')
        ->setAttribute('data-proxy-tab', 'rules')
        ->setAttribute('aria-pressed', 'false')
]))->addClass('proxy-health-tabs');

$export = (new CDiv([
    (new CTag('button', true, _('Exportar')))
        ->addClass('proxy-health-export-main')
        ->setAttribute('type', 'button')
        ->setAttribute('data-proxy-export', 'xlsx'),
    (new CTag('button', true, '▾'))
        ->addClass('proxy-health-export-toggle')
        ->setAttribute('type', 'button')
        ->setAttribute('data-proxy-export-toggle', '1')
        ->setAttribute('aria-label', _('Selecionar formato de exportacao'))
        ->setAttribute('aria-expanded', 'false'),
    (new CDiv([
        (new CTag('button', true, _('XLSX')))
            ->addClass('proxy-health-export-option')
            ->setAttribute('type', 'button')
            ->setAttribute('data-proxy-export', 'xlsx'),
        (new CTag('button', true, _('CSV')))
            ->addClass('proxy-health-export-option')
            ->setAttribute('type', 'button')
            ->setAttribute('data-proxy-export', 'csv')
    ]))
        ->addClass('proxy-health-export-menu')
        ->setAttribute('data-proxy-export-menu', '1')
]))->addClass('proxy-health-export');

$scope_label = $settings['host_groupid'] !== ''
    ? sprintf(_('grupo %s'), $settings['host_group_name'])
    : _('nenhum host group');
if (($settings['host_group_source'] ?? '') === 'profile') {
    $scope_label .= ' '._('(sua escolha salva)');
}
if (($settings['zabbix_server_host_name'] ?? '') !== '') {
    $scope_label .= ' + '.$settings['zabbix_server_host_name'];
}

$header = (new CDiv([
    (new CDiv([
        (new CTag('h2', true, _('Saude dos proxies')))->addClass('proxy-health-title'),
        (new CDiv(sprintf(_('Assessment %1$s · janela de %2$s dias (P95 dos picos horarios) · escopo: %3$s'),
            $module_version, $settings['trend_days'], $scope_label
        )))->addClass('proxy-health-muted')
    ]))->addClass('proxy-health-header-title'),
    $tabs,
    (new CDiv(
        (new CTextBox('proxy_search', $data['proxy_search']))
            ->setId('proxy-health-search')
            ->setAttribute('type', 'search')
            ->setAttribute('placeholder', _('Filtrar proxy'))
            ->setAttribute('aria-label', _('Filtrar proxy'))
            ->setAttribute('autocomplete', 'off')
    ))->addClass('proxy-health-search'),
    $export
]))->addClass('proxy-health-header');

$legend_item = static function(string $filter, string $label, string $swatch = ''): CTag {
    $content = [];
    if ($swatch !== '') {
        $content[] = (new CSpan())->addClass('proxy-health-swatch '.$swatch);
    }
    $content[] = (new CTag('b', true, '0'))->setAttribute('data-proxy-kpi', $filter);
    $content[] = $filter === 'total'
        ? (new CSpan(' '.$label))->setAttribute('data-proxy-kpi-total-label', '1')
        : ' '.$label;

    return (new CTag('button', true, $content))
        ->addClass('proxy-health-legend-item')
        ->setAttribute('type', 'button')
        ->setAttribute('data-proxy-kpi-filter', $filter)
        ->setAttribute('aria-pressed', 'false');
};

$distribution = (new CDiv([
    (new CDiv([
        $legend_item('total', _('avaliados')),
        $legend_item('ok', _('OK'), 'is-ok'),
        $legend_item('attention', _('Atencao'), 'is-attention'),
        $legend_item('risk', _('Risco / Critico'), 'is-risk'),
        $legend_item('excluded', _('fora do escopo'), 'is-excluded'),
        (new CSpan())
            ->addClass('proxy-health-distribution-summary')
            ->setAttribute('data-proxy-distribution-summary', '1')
    ]))->addClass('proxy-health-distribution-head'),
    (new CDiv())
        ->addClass('proxy-health-distribution-bar')
        ->setAttribute('data-proxy-distribution-bar', '1')
        ->setAttribute('aria-hidden', 'true')
]))->addClass('proxy-health-distribution');

$overview = (new CDiv([
    $data['error'] !== null
        ? (new CDiv([$data['error'], (new CDiv($data['exception'] ?? ''))->addClass('proxy-health-muted')]))
            ->addClass('proxy-health-error')
        : null,
    $distribution,
    (new CDiv())->setId('proxy-health-cards')->addClass('proxy-health-cards'),
    (new CDiv())->addClass('proxy-health-excluded-panel')->setAttribute('data-proxy-excluded-panel', '1')
]))->addClass('proxy-health-pane is-active')->setAttribute('data-proxy-pane', 'overview');

$config = (new CDiv([
    $config_form
]))->addClass('proxy-health-pane')->setAttribute('data-proxy-pane', 'config');

$rules = (new CDiv([
    (new CDiv([
        (new CTag('h2', true, _('Regras de Negocio')))->addClass('proxy-health-title'),
        (new CDiv(_('Criterios tecnicos usados para leitura, avaliacao e interpretacao da saude dos proxies.')))
            ->addClass('proxy-health-muted')
    ]))->addClass('proxy-health-heading'),

    (new CDiv([
        $rules_section(_('Pre-requisitos'), [
            _('Os proxies devem estar em um Host Group selecionavel pelo widget; por padrao e usado Zabbix/Proxies.'),
            _('Se o grupo padrao nao existir, o campo fica vazio para o usuario escolher o grupo de proxies; a escolha fica salva no perfil do usuario e passa a ser usada nas proximas visitas. Um grupo salvo que deixar de existir e descartado automaticamente.'),
            _('O Host Group e obrigatorio e define o escopo principal. O template de proxy e opcional e refina os hosts do grupo.'),
            _('A busca por template indireto e configuravel: quando habilitada, tambem entram hosts que usam templates filhos/herdeiros do template selecionado.'),
            _('O Zabbix Server pode ser selecionado opcionalmente na aba de configuracao e, quando selecionado, entra como primeiro objeto do assessment.'),
            _('Hosts desabilitados ou proxies sem acesso recente acima do limite configurado ficam fora do assessment.'),
            _('As metricas de saude dependem dos itens internos do Zabbix Proxy e de itens basicos do sistema operacional, como CPU, memoria e disco.'),
            _('As configuracoes do proxy dependem de itens com chave num.* coletando valores do arquivo zabbix_proxy.conf, incluindo fallbacks/defaults quando aplicavel.'),
            _('Medias historicas dependem da retencao de trends do Zabbix, da existencia de historico suficiente e da janela configurada entre 7 e 30 dias.')
        ]),
        $rules_section(_('Como as leituras sao realizadas'), [
            _('A coleta parte do Host Group selecionado, aplica o filtro opcional por template de proxy, exclui hosts desabilitados ou offline e busca itens relevantes, problems ativos, triggers e trends da janela configurada.'),
            _('Problemas ativos sao separados entre problemas validos e problemas orfaos quando a trigger ou item associado esta desabilitado ou sem contexto valido.'),
            _('Disco usa vfs.fs.size[/,pused] quando existe; caso contrario, usa pfree convertido ou o filesystem percentual mais relevante encontrado.'),
            _('Processos internos usam zabbix[process,<processo>,avg,busy]; esse valor ja representa o busy do pool e nao deve ser dividido pela quantidade configurada.'),
            _('Caches padronizam pfree como uso = 100 - pfree e pused como uso direto.')
        ]),
        $rules_section(_('Como as avaliacoes sao feitas'), [
            _('O score parte de 100 e sofre penalizacoes por sinais que podem afetar a capacidade do proxy coletar, processar ou enviar dados.'),
            _('Alertas Disaster e alertas relevantes de saude do proxy reduzem score; problemas comuns dos hosts monitorados nao reduzem score por volume bruto.'),
            _('Versao abaixo do patch minimo, unsupported acima do limite, VPS acima do limite, CPU, memoria, disco e filas acima dos thresholds reduzem score.'),
            _('Problemas orfaos so entram no score quando o bloco correspondente esta habilitado na configuracao.'),
            _('Config issues so reduzem score quando o bloco de configuracao do proxy esta habilitado e ha processos/caches acima dos thresholds.')
        ]),
        $rules_section(_('Calculo do score'), [
            _('Cada proxy ou server inicia com score 100; o score minimo exibido e 0.'),
            _('A classificacao final segue os cortes: OK para score maior ou igual a 90, Atencao para maior ou igual a 70, Risco para maior ou igual a 40 e Critico abaixo de 40.'),
            _('Alertas Disaster ativos reduzem 50 pontos; alertas relevantes de saude do proxy/server reduzem 20 pontos.'),
            _('Problemas orfaos Disaster reduzem 50 pontos e problemas orfaos relevantes reduzem 20 pontos, somente quando a opcao de considerar orfaos esta habilitada.'),
            _('Versao abaixo do corte reduz 15 pontos. Itens unsupported acima do limite reduzem ate 15 pontos, de forma proporcional.'),
            _('VPS, CPU, memoria, disco, fila 10m e preprocessing queue (P95 ou media) reduzem ate 10 pontos por criterio.'),
            _('Regras de carga usam desconto proporcional: desconto = pontos maximos x (valor - limite) / (critico - limite), limitado entre 0 e o maximo. Ex.: CPU media com limite 75% e critico 95%: 76% desconta 0,5; 85% desconta 5; 95% ou mais desconta 10.'),
            _('Alertas Disaster e relevantes, versao abaixo do corte e config issues continuam com desconto integral (binario). Se o valor critico nao for maior que o limite, a regra tambem volta a ser binaria.'),
            _('O resumo mostra a contribuicao de cada regra entre parenteses, como "CPU media alta (-5)"; o score usa uma casa decimal.'),
            _('P95 e o percentil 95 dos maximos horarios de trends na janela configurada: representa o pico tipico e ignora as 5% horas mais extremas. Ele substitui a leitura pontual (lastvalue), que dependia do momento em que a tela era aberta.'),
            _('Versao, itens unsupported, ultimo acesso, memoria total e alertas continuam usando o valor atual, por serem estados e nao series de carga.'),
            _('Quando configuracao do proxy esta habilitada, processos/caches com pressao operacional acima dos thresholds reduzem 15 pontos. Recomendacoes de diminuicao podem aparecer em Pontos de Atencao sem reduzir o score.'),
            _('As medias historicas usam a janela de trends configurada na tela, entre 7 e 30 dias.')
        ]),
        $rules_section(_('Zabbix Server'), [
            _('O host do Zabbix Server deve ser selecionado manualmente na configuracao quando tambem deve entrar no assessment.'),
            _('O host selecionado deve possuir monitoramento Linux default, health check do Zabbix Server e o template custom de leitura de configuracao para que as metricas sejam completas.'),
            _('Quando selecionado, o Zabbix Server e sempre exibido antes dos proxies nas listas, detalhes e exportacoes.'),
            _('As mesmas regras de score, capacidade, processos, caches, problemas ativos e configuracoes coletadas sao reaproveitadas para o Zabbix Server quando houver itens equivalentes.')
        ]),
        $rules_section(_('Processos versus configuracao'), [
            _('Processos com parametro configuravel sao comparados com a diretiva equivalente do zabbix_proxy.conf, como StartPollers, StartPreprocessors, StartSNMPPollers e StartTrappers.'),
            _('Managers e processos internos sem diretiva de quantidade, como internal poller, task manager, self-monitoring e preprocessing manager, sao exibidos apenas como leitura operacional.'),
            _('Pollers comuns entram como Avaliar aumento quando o busy P95 ou a media historica ultrapassa o threshold de pollers; pools sem uso no P95 e na media podem recomendar reducao para 1.'),
            _('Pollers/checadores assincronos, como agent poller, SNMP poller, HTTP agent poller e discovery worker, recomendam aumento gradual de 1 processo quando atingem 100% de busy.'),
            _('Discovery worker usa StartDiscoverers como quantidade configurada, preserva o baseline padrao 5 e deve ser interpretado junto com discovery queue quando houver fila.'),
            _('Preprocessing worker usa StartPreprocessors, preserva baseline minimo 16 ou numero de CPUs quando maior, e so recomenda reducao abaixo do configurado quando ainda fica acima desse baseline.'),
            _('Config issues so alteram o score quando indicam pressao operacional acima dos thresholds; recomendacoes de diminuicao podem aparecer no resumo sem penalizar o score.')
        ]),
        $rules_section(_('Caches versus configuracao'), [
            _('Caches sao avaliados pelo uso P95 e pela media historica contra o threshold de caches.'),
            _('Quando o cache esta OK, nao ha recomendacao de ajuste; a coluna Recomendado permanece vazia.'),
            _('Quando o cache passa do threshold, o recomendado usa o item num.recomendado.* quando valido ou calcula localmente com carga desejada de 60%.'),
            _('Valores configurados como 8M, 16M ou 1G sao convertidos para bytes no backend e apresentados em unidade humana no frontend.'),
            _('Proxy memory buffer e outros caches sem parametro configuravel sao exibidos como leitura de apoio, sem recomendacao de configuracao quando nao ha diretiva equivalente.')
        ]),
        $rules_section(_('Boas praticas reforcadas'), [
            _('Manter proxies em versoes recentes e acima do patch minimo definido para o ambiente.'),
            _('Manter unsupported abaixo do percentual definido, pois itens nao suportados indicam perda de cobertura ou coleta incorreta.'),
            _('Observar VPS junto com CPU, memoria, disco, filas e busy dos processos para evitar conclusoes isoladas.'),
            _('Aumentar pollers ou caches apenas quando ha pressao operacional medida, evitando superdimensionamento sem uso.'),
            _('Revisar periodicamente processos com uso zero e quantidade configurada alta para reduzir complexidade operacional.')
        ])
    ]))->addClass('proxy-health-rules')
]))->addClass('proxy-health-pane')->setAttribute('data-proxy-pane', 'rules');

$page->addItem(
    (new CDiv([$header, $overview, $config, $rules]))
        ->setId('proxy-health-assessment')
        ->addClass('proxy-health')
        ->setAttribute('data-proxy-health-payload', $data['payload'])
        ->setAttribute('data-proxy-health-async', '1')
)
    ->addItem((new CTag('script', true))->setAttribute('src', $asset_url('js/xlsx.full.min.js')))
    ->addItem((new CTag('script', true))->setAttribute('src', $asset_url('js/proxy-health-page.js')))
    ->show();
