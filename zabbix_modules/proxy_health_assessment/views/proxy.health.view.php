<?php declare(strict_types = 0);

/**
 * Proxy Health Assessment.
 *
 * @var CView $this
 * @var array $data
 */

$page = (new CHtmlPage())->setTitle(_('Proxy Health Assessment'));
$settings = $data['settings'];

$input = static function(string $label, string $name, $value, string $type = 'text'): array {
    return [
        (new CLabel($label, 'proxy-health-'.$name)),
        (new CTextBox($name, (string) $value))
            ->setId('proxy-health-'.$name)
            ->setAttribute('type', $type)
    ];
};

$select_yes_no = static function(string $label, string $name, string $value): array {
    return [
        (new CLabel($label, 'proxy-health-'.$name)),
        (new CSelect($name))
            ->setId('proxy-health-'.$name)
            ->setValue($value)
            ->addOptions(CSelect::createOptionsFromArray(['Nao' => _('Nao'), 'Sim' => _('Sim')]))
    ];
};

$config_form = (new CForm('get'))
    ->setId('proxy-health-config-form')
    ->addClass('proxy-health-config-form')
    ->addVar('action', $data['action'])
    ->addItem([
        (new CTag('h3', true, _('Parametros da coleta')))->addClass('proxy-health-form-title'),
        ...$input(_('Template de proxy'), 'proxy_template_id', $settings['proxy_template_id']),
        ...$input(_('Template de configuracao'), 'config_template_id', $settings['config_template_id']),
        ...$input(_('Versao de corte'), 'version_cut', $settings['version_cut']),
        ...$input(_('Patch minimo'), 'patch_min', $settings['patch_min'], 'number'),
        ...$input(_('Ultimo acesso maximo (s)'), 'lastaccess_max', $settings['lastaccess_max'], 'number'),
        (new CTag('h3', true, _('Thresholds do score')))->addClass('proxy-health-form-title'),
        ...$input(_('Unsupported maximo'), 'unsupported_max', $settings['unsupported_max'], 'number'),
        ...$input(_('VPS atual maximo'), 'vps_max', $settings['vps_max'], 'number'),
        ...$input(_('Process busy atual maximo'), 'process_current_max', $settings['process_current_max'], 'number'),
        ...$input(_('Process busy media 30d maxima'), 'process_avg_max', $settings['process_avg_max'], 'number'),
        ...$input(_('CPU atual maxima'), 'cpu_current_max', $settings['cpu_current_max'], 'number'),
        ...$input(_('CPU media 30d maxima'), 'cpu_avg_max', $settings['cpu_avg_max'], 'number'),
        ...$input(_('Memoria atual maxima'), 'memory_current_max', $settings['memory_current_max'], 'number'),
        ...$input(_('Memoria media 30d maxima'), 'memory_avg_max', $settings['memory_avg_max'], 'number'),
        ...$input(_('Disco atual maximo'), 'disk_current_max', $settings['disk_current_max'], 'number'),
        ...$input(_('Disco media 30d maximo'), 'disk_avg_max', $settings['disk_avg_max'], 'number'),
        ...$input(_('Fila 10m maxima'), 'queue_10m_max', $settings['queue_10m_max'], 'number'),
        ...$input(_('Preproc queue maxima'), 'preproc_queue_max', $settings['preproc_queue_max'], 'number'),
        ...$select_yes_no(_('Considerar problemas orfaos?'), 'consider_orphans', $settings['consider_orphans']),
        ...$select_yes_no(_('Considerar configuracao do proxy?'), 'consider_config', $settings['consider_config']),
        ...$input(_('Threshold pollers'), 'poller_threshold', $settings['poller_threshold'], 'number'),
        ...$input(_('Threshold caches'), 'cache_threshold', $settings['cache_threshold'], 'number'),
        (new CSubmit('apply', _('Aplicar')))->addClass(ZBX_STYLE_BTN_ALT)
    ]);

$overview = (new CDiv([
    (new CDiv([
        (new CTag('button', true, _('Overview')))
            ->addClass('proxy-health-tab is-selected')
            ->setAttribute('type', 'button')
            ->setAttribute('data-proxy-tab', 'overview')
            ->setAttribute('aria-pressed', 'true'),
        (new CTag('button', true, _('Configuracao')))
            ->addClass('proxy-health-tab')
            ->setAttribute('type', 'button')
            ->setAttribute('data-proxy-tab', 'config')
            ->setAttribute('aria-pressed', 'false')
    ]))->addClass('proxy-health-tabs'),

    (new CDiv([
        (new CDiv([
            (new CTag('h2', true, _('Saude dos proxies')))->addClass('proxy-health-title'),
            (new CDiv(_('Assessment v2.0 em tempo real, com proxies offline fora do escopo.')))
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
                new CTag('th', true, _('Proc max')),
                new CTag('th', true, _('CPU atual')),
                new CTag('th', true, _('Mem atual')),
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

$page->addItem(
    (new CDiv([$overview, $config]))
        ->setId('proxy-health-assessment')
        ->addClass('proxy-health')
        ->setAttribute('data-proxy-health-payload', $data['payload'])
)->show();
