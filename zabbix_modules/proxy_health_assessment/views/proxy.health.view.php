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

$multiselect = static function(string $name, string $object_name, string $id, string $label, array $popup): CMultiSelect {
    return (new CMultiSelect([
        'name' => $name,
        'object_name' => $object_name,
        'data' => $id !== '' ? [['id' => $id, 'name' => $label]] : [],
        'multiple' => false,
        'popup' => ['parameters' => $popup + ['dstfrm' => 'proxy_health_config_form', 'dstfld1' => $name]]
    ]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH);
};

$hint = static fn(string $text): CSpan => (new CSpan($text))->addClass(ZBX_STYLE_GREY);

$checkbox = static fn(string $name, string $value, string $label): CCheckBox => (new CCheckBox($name, 'Sim'))
    ->setId($name)
    ->setChecked($value === 'Sim')
    ->setUncheckedValue('Nao')
    ->setLabel($label);

$number = static fn(string $name, $value, int $width = ZBX_TEXTAREA_TINY_WIDTH): CTextBox => (new CTextBox($name, (string) $value))
    ->setId($name)
    ->setWidth($width);

$section = static fn(string $title): CTag => (new CTag('h4', true, $title))->addClass('input-section-header');

$rules_section = static function(string $title, array $items): CDiv {
    return (new CDiv([
        (new CTag('h3', true, $title))->addClass('proxy-health-rule-title'),
        (new CTag('ul', true, array_map(
            static fn(string $item): CTag => new CTag('li', true, $item),
            $items
        )))->addClass('proxy-health-rule-list')
    ]))->addClass('proxy-health-rule-section');
};

// Limites do score: uma linha por metrica (atencao, critico, unidade, desconto maximo).
$limit_rows = [
    [_('CPU (pico)'), 'cpu_p95_max', 'cpu_p95_crit', '%', 10],
    [_('CPU media'), 'cpu_avg_max', 'cpu_avg_crit', '%', 10],
    [_('Memoria (pico)'), 'memory_p95_max', 'memory_p95_crit', '%', 10],
    [_('Memoria media'), 'memory_avg_max', 'memory_avg_crit', '%', 10],
    [_('Disco (pico)'), 'disk_p95_max', 'disk_p95_crit', '%', 10],
    [_('Disco media'), 'disk_avg_max', 'disk_avg_crit', '%', 10],
    [_('VPS (pico)'), 'vps_max', 'vps_crit', _('vps'), 10],
    [_('Fila > 10 min (pico)'), 'queue_10m_max', 'queue_10m_crit', _('itens'), 10],
    [_('Fila de preprocessing (pico)'), 'preproc_queue_max', 'preproc_queue_crit', _('itens'), 10],
    [_('Itens unsupported'), 'unsupported_max', 'unsupported_crit', '%', 15]
];
$setting_value = static fn(string $name) => match ($name) {
    'unsupported_max' => $settings['unsupported_max_percent'],
    'unsupported_crit' => $settings['unsupported_crit_percent'],
    default => $settings[$name]
};
$limits = (new CTable())
    ->addClass('proxy-health-limits')
    ->setHeader([_('Metrica'), _('Atencao a partir de'), _('Critico em'), _('Desconto max.')]);
foreach ($limit_rows as [$label, $warn, $crit, $unit, $points]) {
    $limits->addRow([
        $label,
        [$number($warn, $setting_value($warn)), ' ', $hint($unit)],
        [$number($crit, $setting_value($crit)), ' ', $hint($unit)],
        $hint(sprintf(_('%1$s pts'), $points))
    ]);
}

$frontend_version = $settings['frontend_version'] !== '' ? $settings['frontend_version'] : '?';
$version_hint = $settings['version_cut_source'] === 'frontend'
    ? sprintf(_('versao deste frontend: %1$s'), $frontend_version)
    : sprintf(_('frontend: %1$s'), $frontend_version);

$form_list = (new CFormList('proxy_health_config_list'))
    ->addRow($section(_('Escopo da coleta')))
    ->addRow(
        (new CLabel(_('Host group'), 'host_groupid_ms'))->setAsteriskMark(),
        [
            $multiselect('host_groupid', 'hostGroup', $settings['host_groupid'], $settings['host_group_name'], [
                'srctbl' => 'host_groups', 'srcfld1' => 'groupid', 'normal_only' => '1'
            ]),
            ' ',
            $hint(($settings['host_group_source'] ?? '') === 'profile'
                ? sprintf(_('Sua escolha salva · padrao: %1$s'), $settings['host_group_default'])
                : sprintf(_('Padrao: %1$s; a escolha fica salva para as proximas visitas'), $settings['host_group_default'])
            )
        ],
        'proxy-health-field-host-group'
    )
    ->addRow(
        new CLabel(_('Template de proxy'), 'proxy_templateid_ms'),
        [
            $multiselect('proxy_templateid', 'templates', $settings['proxy_templateid'], $settings['proxy_template_name'], [
                'srctbl' => 'templates', 'srcfld1' => 'hostid'
            ]),
            (new CDiv($checkbox('proxy_template_filter', $settings['proxy_template_filter'],
                _('Usar o template para filtrar os hosts do grupo')
            )))->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
            (new CDiv($checkbox('proxy_template_indirect', $settings['proxy_template_indirect'],
                _('Incluir hosts com o template herdado por outro template')
            )))->addClass(ZBX_STYLE_FORM_INPUT_MARGIN)
        ]
    )
    ->addRow(
        new CLabel(_('Zabbix server'), 'zabbix_server_hostid_ms'),
        [
            $multiselect('zabbix_server_hostid', 'hosts', $settings['zabbix_server_hostid'],
                $settings['zabbix_server_host_name'], ['srctbl' => 'hosts', 'srcfld1' => 'hostid', 'real_hosts' => '1']
            ),
            ' ',
            $hint(_('Opcional; entra em primeiro lugar no assessment'))
        ]
    )
    ->addRow(
        (new CLabel(_('Versao minima'), 'version_cut'))->setAsteriskMark(),
        [
            $number('version_cut', $settings['version_cut']),
            ' ', $hint(_('patch')), ' ',
            $number('patch_min', $settings['patch_min']),
            ' ',
            $hint(sprintf(_('abaixo de %1$s.%2$s desconta 15 pontos · %3$s'),
                $settings['version_cut'], $settings['patch_min'], $version_hint
            ))
        ]
    )
    ->addRow(
        (new CLabel(_('Fora do escopo apos'), 'lastaccess_max'))->setAsteriskMark(),
        [
            $number('lastaccess_max', $settings['lastaccess_max_label']),
            ' ',
            $hint(_('sem contato com o server; aceita sufixos de tempo (s, m, h, d)'))
        ]
    )
    ->addRow(
        (new CLabel(_('Janela de trends'), 'trend_days'))->setAsteriskMark(),
        [$number('trend_days', $settings['trend_days'].'d'), ' ', $hint(_('de 7d a 30d'))]
    )
    ->addRow(
        (new CLabel(_('Horizonte da projecao'), 'forecast_horizon'))->setAsteriskMark(),
        [
            $number('forecast_horizon', $settings['forecast_horizon'].'d'),
            ' ',
            $hint(_('sempre relativo a hoje (ex.: 15d, 30d, 8w); padrao 30d. Usado nas tendencias, que nao descontam da nota.'))
        ]
    )
    ->addRow($section(_('Limites do score')))
    ->addRow(
        _('Metricas'),
        [
            $limits,
            $hint(_('Abaixo de "atencao" nao desconta; entre os dois o desconto e proporcional; a partir de "critico" desconta o maximo.'))
        ]
    )
    ->addRow($section(_('Regras opcionais')))
    ->addRow(_('Problemas orfaos'), $checkbox('consider_orphans', $settings['consider_orphans'],
        _('Contar no score problemas sem trigger ou item valido')
    ))
    ->addRow(_('Configuracao do proxy'), [
        $checkbox('consider_config', $settings['consider_config'], _('Contar no score')),
        (new CSpan([
            ' ', $hint(_('pollers acima de')), ' ',
            $number('poller_threshold', $settings['poller_threshold'], ZBX_TEXTAREA_TINY_WIDTH)
                ->setEnabled($settings['consider_config'] === 'Sim'),
            ' ', $hint('%'), ' ', $hint(_('caches acima de')), ' ',
            $number('cache_threshold', $settings['cache_threshold'], ZBX_TEXTAREA_TINY_WIDTH)
                ->setEnabled($settings['consider_config'] === 'Sim'),
            ' ', $hint('%')
        ]))->addClass('proxy-health-inline-fields')
    ])
    ->addRow(_('Recomendacoes'), $checkbox('show_process_recommendations', $settings['show_process_recommendations'],
        _('Mostrar ajustes de processos no resumo (sem alterar o score)')
    ));

$config_form = (new CForm('get'))
    ->setName('proxy_health_config_form')
    ->setId('proxy-health-config-form')
    ->addVar('action', $data['action'])
    ->addItem(
        (new CTabView())
            ->addTab('proxy_health_config', null, $form_list)
            ->setFooter(makeFormFooter(
                new CSubmit('apply', _('Aplicar')),
                [new CRedirectButton(_('Restaurar padroes'), (new CUrl('zabbix.php'))
                    ->setArgument('action', $data['action'])
                    ->getUrl()
                )]
            ))
    );

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
        (new CDiv(sprintf(_('Assessment %1$s · janela de %2$s dias · escopo: %3$s'),
            $module_version, $settings['trend_days'], $scope_label
        )))
            ->addClass('proxy-health-muted')
            ->setAttribute('title', sprintf(_('Valores "pico %1$sd" = percentil 95 dos picos horarios dos trends dos ultimos %1$s dias. Detalhes em Regras de Negocio.'), $settings['trend_days']))
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

// Resumo visual das regras (escala, descontos com os limites atuais, fluxo). Os textos completos seguem abaixo.
$fmt_limit = static fn($warn, $crit, string $unit = ''): string => sprintf('%1$s%3$s → %2$s%3$s',
    rtrim(rtrim(number_format((float) $warn, 2, ',', ''), '0'), ','),
    rtrim(rtrim(number_format((float) $crit, 2, ',', ''), '0'), ','),
    $unit
);
$rule_tag = static fn(string $type): CSpan => (new CSpan(match ($type) {
    'binary' => _('binaria'),
    'proportional' => _('proporcional'),
    default => _('opcional')
}))->addClass('proxy-health-rule-tag is-'.$type);

$score_rules = [
    [_('Alerta Disaster ativo'), _('problema atual'), ['binary'], '—', '50'],
    [_('Alerta de saude do proxy ativo'), _('problema atual'), ['binary'], '—', '20'],
    [_('Versao abaixo do corte'), _('valor atual'), ['binary'],
        sprintf('< %1$s.%2$s', $settings['version_cut'], $settings['patch_min']), '15'],
    [_('Itens unsupported'), _('valor atual'), ['proportional'],
        $fmt_limit($settings['unsupported_max_percent'], $settings['unsupported_crit_percent'], '%'), '15'],
    [_('CPU pico · media'), sprintf(_('trends %1$sd'), $settings['trend_days']), ['proportional'],
        $fmt_limit($settings['cpu_p95_max'], $settings['cpu_p95_crit'], '%').' · '
            .$fmt_limit($settings['cpu_avg_max'], $settings['cpu_avg_crit'], '%'), '10 + 10'],
    [_('Memoria pico · media'), sprintf(_('trends %1$sd'), $settings['trend_days']), ['proportional'],
        $fmt_limit($settings['memory_p95_max'], $settings['memory_p95_crit'], '%').' · '
            .$fmt_limit($settings['memory_avg_max'], $settings['memory_avg_crit'], '%'), '10 + 10'],
    [_('Disco pico · media'), sprintf(_('trends %1$sd'), $settings['trend_days']), ['proportional'],
        $fmt_limit($settings['disk_p95_max'], $settings['disk_p95_crit'], '%').' · '
            .$fmt_limit($settings['disk_avg_max'], $settings['disk_avg_crit'], '%'), '10 + 10'],
    [_('VPS pico'), sprintf(_('trends %1$sd'), $settings['trend_days']), ['proportional'],
        $fmt_limit($settings['vps_max'], $settings['vps_crit']), '10'],
    [_('Fila > 10 min · preprocessing (pico)'), sprintf(_('trends %1$sd'), $settings['trend_days']), ['proportional'],
        $fmt_limit($settings['queue_10m_max'], $settings['queue_10m_crit']).' · '
            .$fmt_limit($settings['preproc_queue_max'], $settings['preproc_queue_crit']), '10 + 10'],
    [_('Problemas orfaos (Disaster · relevante)'), _('problema atual'), ['optional', 'binary'],
        $settings['consider_orphans'] === 'Sim' ? _('ligado') : _('desligado'), '50 · 20'],
    [_('Processos e caches acima do threshold'), sprintf(_('trends %1$sd'), $settings['trend_days']), ['optional', 'binary'],
        ($settings['consider_config'] === 'Sim' ? _('ligado') : _('desligado'))
            .sprintf(' (%1$s%% · %2$s%%)', $settings['poller_threshold'], $settings['cache_threshold']), '15']
];
$score_table = (new CTable())
    ->addClass('proxy-health-rules-table')
    ->setHeader([_('Regra'), _('Leitura'), _('Tipo'), _('Limites atuais'),
        (new CColHeader(_('Desconto max.')))->addClass('is-right')
    ]);
foreach ($score_rules as [$name, $reading, $types, $limit, $points]) {
    $score_table->addRow([
        $name,
        (new CSpan($reading))->addClass('proxy-health-muted'),
        array_map($rule_tag, $types),
        (new CSpan($limit))->addClass('proxy-health-num'),
        (new CCol((new CSpan($points))->addClass('proxy-health-num')))->addClass('is-right')
    ]);
}

$scale_band = static fn(string $label, int $width, string $class): CDiv => (new CDiv($label))
    ->addClass('proxy-health-scale-band '.$class)
    ->addStyle('width: '.$width.'%;');

$flow_steps = [
    [_('1. Escopo'), sprintf(_('Host group (padrao %1$s ou a escolha salva) e Zabbix server opcional.'), $settings['host_group_default'])],
    [_('2. Filtro'), _('Template de proxy opcional, com herdados; hosts desabilitados saem.')],
    [_('3. Fora do escopo'), sprintf(_('Proxy sem contato ha mais de %1$s (proxy.get) ou que nunca conectou.'), $settings['lastaccess_max_label'])],
    [_('4. Leituras'), sprintf(_('Itens internos, CPU, memoria, disco, problemas ativos e trends de %1$s dias via API.'), $settings['trend_days'])],
    [_('5. Nota'), _('100 menos os descontos da tabela; o estado segue a escala.')]
];
$flow = [];
foreach ($flow_steps as $i => [$title, $text]) {
    if ($i > 0) {
        $flow[] = (new CSpan('→'))->addClass('proxy-health-flow-arrow')->setAttribute('aria-hidden', 'true');
    }
    $flow[] = (new CDiv([new CTag('strong', true, $title), (new CSpan($text))->addClass('proxy-health-muted')]))
        ->addClass('proxy-health-flow-step');
}

$rules_visual = [
    (new CDiv([
        (new CTag('h3', true, _('Como a nota e lida')))->addClass('proxy-health-rule-title'),
        (new CDiv([
            (new CDiv([
                (new CDiv([
                    $scale_band(_('Critico < 40'), 40, 'is-critical'),
                    $scale_band(_('Risco 40–69'), 30, 'is-risk'),
                    $scale_band(_('Atencao 70–89'), 20, 'is-attention'),
                    $scale_band(_('OK'), 10, 'is-ok')
                ]))->addClass('proxy-health-scale'),
                (new CDiv([new CSpan('0'), new CSpan('40'), new CSpan('70'), new CSpan('90'), new CSpan('100')]))
                    ->addClass('proxy-health-scale-ticks proxy-health-num')
            ])),
            (new CDiv([
                new CDiv(_('Cada proxy (e o Zabbix server, se selecionado) parte de 100 e perde pontos por regra; a nota minima e 0 e usa uma casa decimal.')),
                (new CDiv(_('Desconto proporcional = pontos maximos x (valor - atencao) / (critico - atencao), limitado entre 0 e o maximo. Ex.: CPU media com atencao 75% e critico 95%: 85% desconta 5.')))
                    ->addClass('proxy-health-muted')
            ]))->addClass('proxy-health-scale-text')
        ]))->addClass('proxy-health-scale-grid')
    ]))->addClass('proxy-health-rule-section'),
    (new CDiv([
        (new CTag('h3', true, _('Regras que descontam pontos')))->addClass('proxy-health-rule-title'),
        $score_table,
        (new CDiv(_('Pico = percentil 95 dos picos horarios de trends: o pico que o proxy atinge com frequencia, ignorando as 5% horas mais extremas. Media = media dos trends na janela. Estados (versao, unsupported, ultimo acesso, memoria total, alertas) usam o valor atual.')))
            ->addClass('proxy-health-muted proxy-health-rules-note')
    ]))->addClass('proxy-health-rule-section'),
    (new CDiv([
        (new CTag('h3', true, _('Como a coleta funciona')))->addClass('proxy-health-rule-title'),
        (new CDiv($flow))->addClass('proxy-health-flow')
    ]))->addClass('proxy-health-rule-section')
];

$rules = (new CDiv([
    (new CDiv([
        (new CTag('h2', true, _('Regras de Negocio')))->addClass('proxy-health-title'),
        (new CDiv(_('Criterios tecnicos usados para leitura, avaliacao e interpretacao da saude dos proxies.')))
            ->addClass('proxy-health-muted')
    ]))->addClass('proxy-health-heading'),

    (new CDiv($rules_visual))->addClass('proxy-health-rules proxy-health-rules-visual'),

    (new CTag('h3', true, _('Regras detalhadas')))->addClass('proxy-health-rules-subtitle'),

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
            _('VPS, CPU, memoria, disco, fila 10m e preprocessing queue (pico ou media) reduzem ate 10 pontos por criterio.'),
            _('Regras de carga usam desconto proporcional: desconto = pontos maximos x (valor - limite) / (critico - limite), limitado entre 0 e o maximo. Ex.: CPU media com limite 75% e critico 95%: 76% desconta 0,5; 85% desconta 5; 95% ou mais desconta 10.'),
            _('Alertas Disaster e relevantes, versao abaixo do corte e config issues continuam com desconto integral (binario). Se o valor critico nao for maior que o limite, a regra tambem volta a ser binaria.'),
            _('O resumo mostra a contribuicao de cada regra entre parenteses, como "CPU media alta (-5)"; o score usa uma casa decimal.'),
            sprintf(_('O que e o "pico %1$sd": para cada hora o Zabbix guarda nos trends o menor, o medio e o maior valor do item. O modulo pega o maior valor de cada hora dos ultimos %1$s dias (%2$s horas), descarta as 5%% horas mais altas (cerca de %3$s horas) e usa o maior valor que sobra. Tecnicamente e o percentil 95 (P95) dos picos horarios.'),
                $settings['trend_days'], $settings['trend_days'] * 24, (int) round($settings['trend_days'] * 24 * 0.05)
            ),
            _('Por que pico e nao o valor atual ou o maximo: o valor atual (lastvalue) depende do momento em que a tela e aberta, e o maximo absoluto e dominado por um unico evento (um restart, um backup). O pico ignora esses eventos isolados e mostra o nivel que o proxy realmente atinge com frequencia. Ex.: memoria com pico 30d de 92% significa que, em 95% das horas do mes, o maior uso da hora ficou em ate 92%.'),
            _('Media: media de todas as amostras dos trends na janela, ponderada pelo numero de amostras de cada hora. Mostra o nivel de uso constante, enquanto o pico mostra a folga nos momentos de carga.'),
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
            _('Pollers comuns entram como Avaliar aumento quando o pico de busy ou a media historica ultrapassa o threshold de pollers; pools sem uso no pico e na media podem recomendar reducao para 1.'),
            _('Pollers/checadores assincronos, como agent poller, SNMP poller, HTTP agent poller e discovery worker, recomendam aumento gradual de 1 processo quando atingem 100% de busy.'),
            _('Discovery worker usa StartDiscoverers como quantidade configurada, preserva o baseline padrao 5 e deve ser interpretado junto com discovery queue quando houver fila.'),
            _('Preprocessing worker usa StartPreprocessors, preserva baseline minimo 16 ou numero de CPUs quando maior, e so recomenda reducao abaixo do configurado quando ainda fica acima desse baseline.'),
            _('Config issues so alteram o score quando indicam pressao operacional acima dos thresholds; recomendacoes de diminuicao podem aparecer no resumo sem penalizar o score.')
        ]),
        $rules_section(_('Caches versus configuracao'), [
            _('Caches sao avaliados pelo pico de uso e pela media historica contra o threshold de caches.'),
            _('Quando o cache esta OK, nao ha recomendacao de ajuste; a coluna Recomendado permanece vazia.'),
            _('Quando o cache passa do threshold, o recomendado usa o item num.recomendado.* quando valido ou calcula localmente com carga desejada de 60%.'),
            _('Valores configurados como 8M, 16M ou 1G sao convertidos para bytes no backend e apresentados em unidade humana no frontend.'),
            _('Proxy memory buffer e outros caches sem parametro configuravel sao exibidos como leitura de apoio, sem recomendacao de configuracao quando nao ha diretiva equivalente.')
        ]),
        $rules_section(_('Tendencias de recursos'), [
            _('As tendencias nunca descontam da nota: a nota mede o estado atual, a tendencia mostra para onde o proxy vai.'),
            sprintf(_('Recursos acompanhados: disco, memoria, load por nucleo (load / numero de CPUs), busy dos processos internos, uso dos caches e VPS. A projecao vai ate o horizonte configurado (hoje %1$s dias, sempre relativo a data atual).'), $settings['forecast_horizon']),
            _('A serie usada e o pico diario (percentil 95 dos picos horarios de cada dia) da janela de trends; a inclinacao e calculada por Theil-Sen (mediana das inclinacoes entre todos os pares de dias), que ignora dias atipicos. "Pico hoje" e o ponto de hoje sobre essa reta. Com menos de 14 dias de dados a tendencia aparece como dados insuficientes.'),
            _('Nenhuma consulta extra e feita: a tendencia reaproveita os mesmos trends ja buscados para o pico. A coluna de sparkline das abas de processos e caches usa a mesma serie diaria.'),
            _('Dois limites: threshold (limite de trigger: o threshold de pollers para processos, o de caches para caches e o VPS maximo para VPS) e teto (limite fisico: 100% para disco, memoria, processos e caches; o numero de CPUs para o load). Disco, memoria e load nao tem threshold; VPS nao tem teto.'),
            _('Situacao: Teto (vermelho) quando a projecao passa do teto no horizonte; Threshold (laranja) quando passa so do threshold; amarelo quando cresce; verde quando estavel (variacao menor que 5% do limite no horizonte); azul quando desce. A etiqueta traz o prazo estimado, por exemplo "Teto em ~16d".'),
            _('Grafico: linha cheia com o pico diario da janela de trends, linha tracejada com a projecao ate o horizonte e, quando proximas da serie, a linha do threshold (amarela tracejada, como as linhas de trigger do Zabbix) e a do teto (vermelha).'),
            _('Vira card no detalhe do proxy e icone (exclamacao com relogio) na linha recolhida quando: disco ou memoria projetados para 100% ou mais; load projetado acima do numero de CPUs; ou um processo/cache projetado para 100% ou mais.')
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
