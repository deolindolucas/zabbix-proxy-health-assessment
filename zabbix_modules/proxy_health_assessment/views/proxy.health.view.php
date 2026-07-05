<?php declare(strict_types = 0);

/**
 * Proxy Health Assessment.
 *
 * @var CView $this
 * @var array $data
 */

$page = (new CHtmlPage())->setTitle(_('Proxy Health Assessment'));
$settings = $data['settings'];

$field = static function($label, $control): CDiv {
    return (new CDiv([$label, $control]))->addClass('proxy-health-config-field');
};

$input = static function(string $label, string $name, $value, string $type = 'text', array $attributes = []) use ($field): CDiv {
    $control = (new CTextBox($name, (string) $value))
        ->setId('proxy-health-'.$name)
        ->setAttribute('type', $type);

    foreach ($attributes as $attribute => $attribute_value) {
        $control->setAttribute($attribute, (string) $attribute_value);
    }

    return $field(
        (new CLabel($label, 'proxy-health-'.$name)),
        $control
    );
};

$checkbox = static function(string $label, string $name, string $value) use ($field): CDiv {
    return $field(
        (new CLabel($label, 'proxy-health-'.$name)),
        (new CCheckBox($name, 'Sim'))
            ->setId('proxy-health-'.$name)
            ->setChecked($value === 'Sim')
            ->setUncheckedValue('Nao')
    );
};

$host_group_select = static function(array $settings) use ($field): CDiv {
    $selected = $settings['host_groupid'] !== ''
        ? [['id' => $settings['host_groupid'], 'name' => $settings['host_group_name']]]
        : [];

    return $field(
        (new CLabel(_('Host group'), 'host_groupid')),
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
            ->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
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
            $input(_('Versao de corte'), 'version_cut', $settings['version_cut']),
            $input(_('Patch minimo'), 'patch_min', $settings['patch_min'], 'number'),
            $input(_('Ultimo acesso maximo (s)'), 'lastaccess_max', $settings['lastaccess_max'], 'number')
        ]),
        $block(_('Thresholds principais do score'), [
            $input(_('Unsupported maximo (%)'), 'unsupported_max', $settings['unsupported_max_percent'], 'number', ['min' => 0, 'step' => 1]),
            $input(_('VPS atual maximo'), 'vps_max', $settings['vps_max'], 'number'),
            $input(_('CPU atual maxima'), 'cpu_current_max', $settings['cpu_current_max'], 'number'),
            $input(_('CPU media 30d maxima'), 'cpu_avg_max', $settings['cpu_avg_max'], 'number'),
            $input(_('Memoria atual maxima'), 'memory_current_max', $settings['memory_current_max'], 'number'),
            $input(_('Memoria media 30d maxima'), 'memory_avg_max', $settings['memory_avg_max'], 'number'),
            $input(_('Disco atual maximo'), 'disk_current_max', $settings['disk_current_max'], 'number'),
            $input(_('Disco media 30d maximo'), 'disk_avg_max', $settings['disk_avg_max'], 'number'),
            $input(_('Fila 10m maxima'), 'queue_10m_max', $settings['queue_10m_max'], 'number'),
            $input(_('Preproc queue maxima'), 'preproc_queue_max', $settings['preproc_queue_max'], 'number')
        ]),
        $block(_('Problemas orfaos'), [], $checkbox(_('Usar este bloco no assessment'), 'consider_orphans', $settings['consider_orphans'])),
        $block(_('Configuracao do proxy no score'), [
            $input(_('Threshold pollers'), 'poller_threshold', $settings['poller_threshold'], 'number'),
            $input(_('Threshold caches'), 'cache_threshold', $settings['cache_threshold'], 'number')
        ], $checkbox(_('Usar este bloco no assessment'), 'consider_config', $settings['consider_config'])),
        $block(_('Recomendacoes Process vs Config no resumo'), [], $checkbox(_('Mostrar no resumo sem alterar o score'), 'show_process_recommendations', $settings['show_process_recommendations'])),
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
    (new CTag('button', true, _('Exportar Relatorio')))
        ->addClass('proxy-health-export-main')
        ->setAttribute('type', 'button')
        ->setAttribute('data-proxy-export', 'xls'),
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
        (new CTag('button', true, _('XLS')))
            ->addClass('proxy-health-export-option')
            ->setAttribute('type', 'button')
            ->setAttribute('data-proxy-export', 'xls'),
        (new CTag('button', true, _('XML')))
            ->addClass('proxy-health-export-option')
            ->setAttribute('type', 'button')
            ->setAttribute('data-proxy-export', 'xml'),
        (new CTag('button', true, _('CSV')))
            ->addClass('proxy-health-export-option')
            ->setAttribute('type', 'button')
            ->setAttribute('data-proxy-export', 'csv')
    ]))
        ->addClass('proxy-health-export-menu')
        ->setAttribute('data-proxy-export-menu', '1')
]))->addClass('proxy-health-export');

$toolbar = (new CDiv([$tabs, $export]))->addClass('proxy-health-toolbar');

$overview = (new CDiv([
    (new CDiv([
        (new CDiv([
            (new CTag('h2', true, _('Saude dos proxies')))->addClass('proxy-health-title'),
            (new CDiv(_('Assessment v3.0 em tempo real, com proxies offline fora do escopo.')))
                ->addClass('proxy-health-muted')
        ]))->addClass('proxy-health-heading'),
        (new CDiv([
            (new CLabel(_('Pesquisar proxy'), 'proxy-health-search'))->addClass('proxy-health-search-label'),
            (new CTextBox('proxy_search', $data['proxy_search']))
                ->setId('proxy-health-search')
                ->setAttribute('type', 'search')
                ->setAttribute('placeholder', _('Nome do proxy'))
                ->setAttribute('autocomplete', 'off'),
            (new CTag('button', true, _('Limpar')))
                ->addClass('proxy-health-search-clear')
                ->setAttribute('type', 'button')
                ->setAttribute('data-proxy-clear-search', '1')
        ]))->addClass('proxy-health-search')
    ]))->addClass('proxy-health-topbar'),

    $data['error'] !== null
        ? (new CDiv([$data['error'], (new CDiv($data['exception'] ?? ''))->addClass('proxy-health-muted')]))
            ->addClass('proxy-health-error')
        : null,

    (new CDiv([
        (new CDiv([(new CDiv('0'))->addClass('proxy-health-kpi-value')->setAttribute('data-proxy-kpi', 'total'), (new CDiv(_('Proxies avaliados')))->addClass('proxy-health-kpi-label')]))->addClass('proxy-health-kpi'),
        (new CDiv([(new CDiv('0'))->addClass('proxy-health-kpi-value')->setAttribute('data-proxy-kpi', 'ok'), (new CDiv(_('OK')))->addClass('proxy-health-kpi-label')]))->addClass('proxy-health-kpi is-ok'),
        (new CDiv([(new CDiv('0'))->addClass('proxy-health-kpi-value')->setAttribute('data-proxy-kpi', 'attention'), (new CDiv(_('Atencao')))->addClass('proxy-health-kpi-label')]))->addClass('proxy-health-kpi is-attention'),
        (new CDiv([(new CDiv('0'))->addClass('proxy-health-kpi-value')->setAttribute('data-proxy-kpi', 'risk'), (new CDiv(_('Risco/Critico')))->addClass('proxy-health-kpi-label')]))->addClass('proxy-health-kpi is-risk')
    ]))->addClass('proxy-health-kpis'),

    (new CDiv())->setId('proxy-health-cards')->addClass('proxy-health-cards'),

    (new CDiv([
        (new CTag('h3', true, _('Detalhes consolidados')))->addClass('proxy-health-title'),
        (new CTag('table', true, [
            (new CTag('thead', true, new CTag('tr', true, [
                new CTag('th', true, _('Proxy')),
                new CTag('th', true, _('State')),
                new CTag('th', true, _('Score')),
                new CTag('th', true, _('Versao')),
                new CTag('th', true, _('VPS atual')),
                new CTag('th', true, _('Unsupported %')),
                new CTag('th', true, _('CPU atual')),
                new CTag('th', true, _('Mem total GB')),
                new CTag('th', true, _('Mem atual')),
                new CTag('th', true, _('Mem media')),
                new CTag('th', true, _('Disco atual')),
                new CTag('th', true, _('Resumo'))
            ]))),
            (new CTag('tbody', true))->setAttribute('data-proxy-table', 'overview')
        ]))->addClass('proxy-health-table')
    ]))->addClass('proxy-health-panel')
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
            _('Hosts desabilitados ou proxies sem acesso recente acima do limite configurado ficam fora do assessment.'),
            _('As metricas de saude dependem dos itens internos do Zabbix Proxy e de itens basicos do sistema operacional, como CPU, memoria e disco.'),
            _('As configuracoes do proxy dependem de itens com chave num.* coletando valores do arquivo zabbix_proxy.conf, incluindo fallbacks/defaults quando aplicavel.'),
            _('Medias de 30 dias dependem da retencao de trends do Zabbix e da existencia de historico suficiente para os itens numericos.')
        ]),
        $rules_section(_('Como as leituras sao realizadas'), [
            _('A coleta parte do Host Group selecionado e busca hosts habilitados, itens relevantes, problems ativos, triggers e trends de 30 dias.'),
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
        $rules_section(_('Processos versus configuracao'), [
            _('Processos com parametro configuravel sao comparados com a diretiva equivalente do zabbix_proxy.conf, como StartPollers, StartPreprocessors, StartSNMPPollers e StartTrappers.'),
            _('Managers e processos internos sem diretiva de quantidade, como internal poller, task manager, self-monitoring e preprocessing manager, sao exibidos apenas como leitura operacional.'),
            _('Avaliar aumento ocorre quando busy atual ou media 30d ultrapassa o threshold de pollers.'),
            _('Avaliar diminuicao ocorre quando o configurado esta acima do recomendado e a media 30d esta baixa, ou quando uso atual e media 30d sao zero e o configurado e maior que 1.'),
            _('A recomendacao de diminuicao para pool sem uso e limitada a 1 processo, independente do recomendado calculado.')
        ]),
        $rules_section(_('Caches versus configuracao'), [
            _('Caches sao avaliados por uso atual e media 30d contra o threshold de caches.'),
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
    (new CDiv([$toolbar, $overview, $config, $rules]))
        ->setId('proxy-health-assessment')
        ->addClass('proxy-health')
        ->setAttribute('data-proxy-health-payload', $data['payload'])
)->show();
