<?php declare(strict_types = 0);

namespace Modules\ProxyHealthAssessment\Actions;

use API;
use CController;
use CControllerResponseData;
use CRoleHelper;
use Throwable;

/**
 * Controller do Proxy Health Assessment.
 */
class ProxyHealthView extends CController {

    private const PROCESS_PREFIX = 'zabbix[process,';
    private const IMPORTANT_KEYS = [
        'agent.ping', 'system.cpu.load[all,avg1]', 'system.cpu.num', 'system.cpu.util',
        'system.uptime', 'vm.memory.size[pavailable]', 'vm.memory.size[pused]',
        'vm.memory.utilization', 'proc.num[zabbix_proxy]', 'zabbix[uptime]',
        'zabbix[version]', 'zabbix[hosts]', 'zabbix[items]', 'zabbix[items_unsupported]',
        'zabbix[requiredperformance]', 'zabbix[preprocessing_queue]', 'zabbix[queue,10m]',
        'zabbix[proxy,{HOST.HOST}, lastaccess]', 'zabbix[proxy_buffer,state,current]',
        'zabbix[proxy_buffer,state,changes]', 'zabbix[proxy_buffer,buffer,pused]',
        'zabbix[rcache,buffer,pfree]', 'zabbix[wcache,history,pfree]',
        'zabbix[wcache,index,pused]', 'zabbix[vmware,buffer,pused]', 'zabbix[wcache,values]',
        'zabbix[wcache,values,float]', 'zabbix[wcache,values,uint]',
        'zabbix[wcache,values,str]', 'zabbix[wcache,values,text]',
        'zabbix[wcache,values,log]', 'zabbix[wcache,values,not supported]'
    ];
    private const PROCESS_CONFIG_MAP = [
        'agent poller' => 'num.StartAgentPollers',
        'discoverer' => 'num.StartDiscoverers',
        'discovery worker' => 'num.StartDiscoverers',
        'history syncer' => 'num.StartDBSyncers',
        'http agent poller' => 'num.StartHTTPAgentPollers',
        'http poller' => 'num.StartHTTPPollers',
        'icmp pinger' => 'num.StartPingers',
        'ipmi poller' => 'num.StartIPMIPollers',
        'java poller' => 'num.StartJavaPollers',
        'odbc poller' => 'num.StartODBCPollers',
        'poller' => 'num.poller',
        'snmp poller' => 'num.StartSNMPPollers',
        'snmp trapper' => 'num.StartSNMPTrapper',
        'trapper' => 'num.StartTrappers',
        'unreachable poller' => 'num.StartPollersUnreachable'
    ];
    private const RECOMMENDED_CONFIG_MAP = [
        'http poller' => 'num.recomendado.http',
        'ipmi poller' => 'num.recomendado.ipmi',
        'odbc poller' => 'num.recomendado.odbc',
        'poller' => 'num.ideal.pollers',
        'trapper' => 'num.recomendado.trappers',
        'unreachable poller' => 'num.recomendado.unreachable'
    ];
    private const CACHE_CONFIG_MAP = [
        'zabbix[rcache,buffer,pfree]' => ['Configuration cache', 'num.CacheSize', 'pfree'],
        'zabbix[wcache,history,pfree]' => ['History write cache', '', 'pfree'],
        'zabbix[wcache,index,pused]' => ['History index cache', '', 'pused'],
        'zabbix[proxy_buffer,buffer,pused]' => ['Proxy memory buffer', '', 'pused'],
        'zabbix[vmware,buffer,pused]' => ['VMware cache', '', 'pused']
    ];

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $valid = $this->validateInput([
            'proxy_template_id' => 'string',
            'config_template_id' => 'string',
            'version_cut' => 'string',
            'patch_min' => 'string',
            'unsupported_max' => 'string',
            'vps_max' => 'string',
            'process_current_max' => 'string',
            'process_avg_max' => 'string',
            'cpu_current_max' => 'string',
            'cpu_avg_max' => 'string',
            'memory_current_max' => 'string',
            'memory_avg_max' => 'string',
            'disk_current_max' => 'string',
            'disk_avg_max' => 'string',
            'queue_10m_max' => 'string',
            'preproc_queue_max' => 'string',
            'lastaccess_max' => 'string',
            'consider_orphans' => 'in Sim,Nao',
            'consider_config' => 'in Sim,Nao',
            'poller_threshold' => 'string',
            'cache_threshold' => 'string',
            'proxy_search' => 'string'
        ]);

        if (!$valid) {
            $this->setResponse(new \CControllerResponseFatal());
        }

        return $valid;
    }

    protected function checkPermissions(): bool {
        return !defined(CRoleHelper::class.'::UI_ADMINISTRATION_GENERAL')
            || $this->checkAccess(CRoleHelper::UI_ADMINISTRATION_GENERAL);
    }

    protected function doAction(): void {
        $settings = $this->settings();

        try {
            $data = $this->collect($settings);
            $data['error'] = null;
        }
        catch (Throwable $exception) {
            $data = [
                'error' => _('Nao foi possivel coletar os dados do assessment. Verifique permissao de API, templates e itens.'),
                'exception' => $exception->getMessage(),
                'proxies' => [],
                'config_items' => [],
                'process_config' => [],
                'cache_config' => [],
                'orphans' => [],
                'active_problems' => [],
                'excluded_offline' => []
            ];
        }

        $data['settings'] = $settings;
        $data['action'] = $this->getAction();
        $data['proxy_search'] = $this->getInput('proxy_search', '');
        $data['payload'] = base64_encode(json_encode([
            'proxies' => $data['proxies'],
            'config_items' => $data['config_items'],
            'process_config' => $data['process_config'],
            'cache_config' => $data['cache_config'],
            'orphans' => $data['orphans'],
            'active_problems' => $data['active_problems'],
            'excluded_offline' => $data['excluded_offline'],
            'settings' => $settings
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->setResponse(new CControllerResponseData($data));
    }

    private function settings(): array {
        return [
            'proxy_template_id' => $this->getInput('proxy_template_id', '12064'),
            'config_template_id' => $this->getInput('config_template_id', '88293'),
            'version_cut' => $this->getInput('version_cut', '7.0.20'),
            'patch_min' => $this->inputNum('patch_min', 20),
            'unsupported_max' => $this->inputNum('unsupported_max', 0.02),
            'vps_max' => $this->inputNum('vps_max', 300),
            'process_current_max' => $this->inputNum('process_current_max', 80),
            'process_avg_max' => $this->inputNum('process_avg_max', 75),
            'cpu_current_max' => $this->inputNum('cpu_current_max', 85),
            'cpu_avg_max' => $this->inputNum('cpu_avg_max', 75),
            'memory_current_max' => $this->inputNum('memory_current_max', 85),
            'memory_avg_max' => $this->inputNum('memory_avg_max', 80),
            'disk_current_max' => $this->inputNum('disk_current_max', 85),
            'disk_avg_max' => $this->inputNum('disk_avg_max', 80),
            'queue_10m_max' => $this->inputNum('queue_10m_max', 0),
            'preproc_queue_max' => $this->inputNum('preproc_queue_max', 50),
            'lastaccess_max' => $this->inputNum('lastaccess_max', 900),
            'consider_orphans' => $this->getInput('consider_orphans', 'Nao'),
            'consider_config' => $this->getInput('consider_config', 'Nao'),
            'poller_threshold' => $this->inputNum('poller_threshold', 75),
            'cache_threshold' => $this->inputNum('cache_threshold', 75),
            'score_ok' => 90,
            'score_attention' => 70,
            'score_risk' => 40
        ];
    }

    private function collect(array $settings): array {
        $now = time();
        $hosts = API::Host()->get([
            'output' => ['hostid', 'host', 'name', 'status', 'available'],
            'selectInterfaces' => ['ip', 'dns', 'type', 'main', 'useip'],
            'templateids' => [$settings['proxy_template_id']],
            'sortfield' => 'name'
        ]);
        $hosts = array_values(array_filter($hosts, static fn(array $host): bool => $host['status'] === '0'));
        $hostids = array_column($hosts, 'hostid');

        if (!$hostids) {
            return [
                'proxies' => [],
                'config_items' => [],
                'process_config' => [],
                'cache_config' => [],
                'orphans' => [],
                'active_problems' => [],
                'excluded_offline' => []
            ];
        }

        $items = API::Item()->get([
            'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock',
                'value_type', 'units', 'status', 'state', 'error'],
            'hostids' => $hostids,
            'inherited' => true,
            'sortfield' => 'name'
        ]);
        $items = array_values(array_filter($items, static fn(array $item): bool =>
            in_array($item['key_'], self::IMPORTANT_KEYS, true)
            || strpos($item['key_'], self::PROCESS_PREFIX) === 0
        ));

        $this->enrichDiskItems($items, $hostids, $now);
        $trends = $this->collectTrends($items, $now);
        $config_items = $this->collectConfigItems($settings['config_template_id'], $hostids);
        [$active_problems, $orphan_problems, $problem_counts, $orphan_counts] = $this->collectProblems($hostids);

        $items_by_host = $this->groupItemsByHost($items);
        $config_by_host = $this->groupItemsByHost($config_items);
        $host_by_id = [];
        $excluded = [];
        $proxies = [];
        $process_config = [];
        $cache_config = [];
        $config_rows = [];

        foreach ($hosts as $host) {
            $host_by_id[$host['hostid']] = $host;
            $host_items = $items_by_host[$host['hostid']] ?? [];
            $lastaccess = self::num($host_items['zabbix[proxy,{HOST.HOST}, lastaccess]']['lastvalue'] ?? null);
            $lastaccess_age = $lastaccess !== null ? max(0, $now - (int) $lastaccess) : null;

            if ($lastaccess_age !== null && $lastaccess_age > $settings['lastaccess_max']) {
                $excluded[] = [
                    'host' => $host['name'] ?: $host['host'],
                    'lastaccess_age' => $lastaccess_age,
                    'reason' => _('Ultimo acesso acima do limite configurado')
                ];
                continue;
            }

            foreach (($config_by_host[$host['hostid']] ?? []) as $cfg) {
                $config_rows[] = [
                    'host' => $host['name'] ?: $host['host'],
                    'name' => $cfg['name'],
                    'key' => $cfg['key_'],
                    'value' => self::value($cfg['lastvalue']),
                    'units' => $cfg['units'] ?? '',
                    'lastclock' => self::dateLabel($cfg['lastclock'] ?? 0),
                    'state' => $cfg['state'] === '0' ? 'Normal' : 'Not supported',
                    'error' => $cfg['error'] ?? ''
                ];
            }

            $proxy = $this->buildProxyRow(
                $host, $host_items, $config_by_host[$host['hostid']] ?? [], $trends,
                $problem_counts[$host['hostid']] ?? [], $orphan_counts[$host['hostid']] ?? [],
                $settings, $now
            );
            $proxies[] = $proxy;

            foreach ($this->buildProcessRows($host, $host_items, $config_by_host[$host['hostid']] ?? [], $trends, $settings) as $row) {
                $process_config[] = $row;
            }
            foreach ($this->buildCacheRows($host, $host_items, $config_by_host[$host['hostid']] ?? [], $trends, $settings) as $row) {
                $cache_config[] = $row;
            }
        }

        usort($proxies, static fn(array $a, array $b): int =>
            ($a['score'] <=> $b['score']) ?: strnatcasecmp($a['host'], $b['host'])
        );

        return [
            'proxies' => $proxies,
            'config_items' => $config_rows,
            'process_config' => $process_config,
            'cache_config' => $cache_config,
            'orphans' => $this->problemRows($orphan_problems, $host_by_id),
            'active_problems' => $this->problemRows($active_problems, $host_by_id),
            'excluded_offline' => $excluded
        ];
    }

    private function buildProxyRow(array $host, array $items, array $cfg_items, array $trends,
            array $problem_count, array $orphan_count, array $settings, int $now): array {
        $get = static fn(string $key): ?array => $items[$key] ?? null;
        $value = static fn(string $key) => self::num($items[$key]['lastvalue'] ?? null);
        $avg = static fn(string $key) => isset($items[$key]) ? ($trends[$items[$key]['itemid']]['avg30d'] ?? null) : null;
        $process_values = [];
        $process_avg_values = [];
        foreach ($items as $item) {
            if (strpos($item['key_'], self::PROCESS_PREFIX) === 0) {
                $process_values[] = self::num($item['lastvalue'] ?? null);
                $process_avg_values[] = $trends[$item['itemid']]['avg30d'] ?? null;
            }
        }

        $version = (string) ($get('zabbix[version]')['lastvalue'] ?? '');
        $total_items = $value('zabbix[items]');
        $unsupported = $value('zabbix[items_unsupported]');
        $unsupported_pct = $total_items ? $unsupported / $total_items : null;
        $cpu_current = $value('system.cpu.util');
        $cpu_avg = $avg('system.cpu.util');
        $mem_current = $value('vm.memory.size[pused]');
        if ($mem_current === null && $value('vm.memory.size[pavailable]') !== null) {
            $mem_current = 100 - $value('vm.memory.size[pavailable]');
        }
        $mem_avg = $avg('vm.memory.size[pused]');
        $disk_current = $value('vfs.fs.size[/,pused]');
        $disk_avg = $avg('vfs.fs.size[/,pused]');
        $lastaccess = $value('zabbix[proxy,{HOST.HOST}, lastaccess]');
        $lastaccess_age = $lastaccess !== null ? max(0, $now - (int) $lastaccess) : null;
        $config_findings = $settings['consider_config'] === 'Sim'
            ? array_merge(
                array_column($this->buildProcessRows($host, $items, $cfg_items, $trends, $settings, true), 'finding'),
                array_column($this->buildCacheRows($host, $items, $cfg_items, $trends, $settings, true), 'finding')
            )
            : [];

        $findings = [];
        $score = 100;
        $deduct = function(bool $condition, int $points, string $label) use (&$score, &$findings): void {
            if ($condition) {
                $score = max(0, $score - $points);
                $findings[] = $label;
            }
        };

        $deduct(($problem_count['disaster'] ?? 0) > 0, 50, _('Alerta Disaster ativo'));
        $deduct(($problem_count['relevant'] ?? 0) > 0, 20, _('Alerta de saude do proxy ativo'));
        if ($settings['consider_orphans'] === 'Sim') {
            $deduct(($orphan_count['disaster'] ?? 0) > 0, 50, _('Alerta Disaster orfao considerado'));
            $deduct(($orphan_count['relevant'] ?? 0) > 0, 20, _('Alerta de saude orfao considerado'));
        }
        $deduct(self::versionPatch($version) !== null && self::versionPatch($version) < $settings['patch_min'], 15, _('Versao abaixo do corte'));
        $deduct($unsupported_pct !== null && $unsupported_pct > $settings['unsupported_max'], 15, _('Itens unsupported acima do limite'));
        $deduct($value('zabbix[wcache,values]') !== null && $value('zabbix[wcache,values]') > $settings['vps_max'], 10, _('VPS atual acima do limite'));
        $deduct(self::maxNum($process_values) !== null && self::maxNum($process_values) > $settings['process_current_max'], 10, _('Processo busy atual alto'));
        $deduct(self::maxNum($process_avg_values) !== null && self::maxNum($process_avg_values) > $settings['process_avg_max'], 15, _('Media 30d de processo alta'));
        $deduct($cpu_current !== null && $cpu_current > $settings['cpu_current_max'], 10, _('CPU atual alta'));
        $deduct($cpu_avg !== null && $cpu_avg > $settings['cpu_avg_max'], 10, _('CPU media 30d alta'));
        $deduct($mem_current !== null && $mem_current > $settings['memory_current_max'], 10, _('Memoria atual alta'));
        $deduct($mem_avg !== null && $mem_avg > $settings['memory_avg_max'], 10, _('Memoria media 30d alta'));
        $deduct($disk_current !== null && $disk_current > $settings['disk_current_max'], 10, _('Disco atual alto'));
        $deduct($disk_avg !== null && $disk_avg > $settings['disk_avg_max'], 10, _('Disco media 30d alta'));
        $deduct($value('zabbix[queue,10m]') !== null && $value('zabbix[queue,10m]') > $settings['queue_10m_max'], 10, _('Fila 10m acima do limite'));
        $deduct($value('zabbix[preprocessing_queue]') !== null && $value('zabbix[preprocessing_queue]') > $settings['preproc_queue_max'], 10, _('Preprocessing queue acima do limite'));
        $deduct($settings['consider_config'] === 'Sim' && $config_findings, 15, implode('; ', $config_findings));

        $state = $score >= $settings['score_ok']
            ? 'OK'
            : ($score >= $settings['score_attention'] ? 'Atencao' : ($score >= $settings['score_risk'] ? 'Risco' : 'Critico'));

        return [
            'hostid' => $host['hostid'],
            'host' => $host['name'] ?: $host['host'],
            'technical_name' => $host['host'],
            'interface' => $this->hostInterface($host),
            'score' => $score,
            'state' => $state,
            'summary' => $findings ? implode('; ', array_unique(array_filter($findings))) : _('Proxy dentro dos parametros configurados'),
            'version' => $version,
            'patch' => self::versionPatch($version),
            'lastaccess_age' => $lastaccess_age,
            'unsupported_pct' => $unsupported_pct,
            'unsupported' => $unsupported,
            'items' => $total_items,
            'vps_current' => $value('zabbix[wcache,values]'),
            'process_current_max' => self::maxNum($process_values),
            'process_avg_max' => self::maxNum($process_avg_values),
            'cpu_current' => $cpu_current,
            'cpu_avg' => $cpu_avg,
            'memory_current' => $mem_current,
            'memory_avg' => $mem_avg,
            'disk_current' => $disk_current,
            'disk_avg' => $disk_avg,
            'queue_10m' => $value('zabbix[queue,10m]'),
            'preproc_queue' => $value('zabbix[preprocessing_queue]'),
            'active_relevant' => $problem_count['relevant'] ?? 0,
            'active_disaster' => $problem_count['disaster'] ?? 0,
            'orphan_relevant' => $orphan_count['relevant'] ?? 0,
            'orphan_disaster' => $orphan_count['disaster'] ?? 0
        ];
    }

    private function buildProcessRows(array $host, array $items, array $cfg_items, array $trends,
            array $settings, bool $only_findings = false): array {
        $rows = [];
        foreach ($items as $item) {
            if (strpos($item['key_'], self::PROCESS_PREFIX) !== 0) {
                continue;
            }
            $process = self::processName($item['key_']);
            $param = self::PROCESS_CONFIG_MAP[$process] ?? '';
            $recommended = self::RECOMMENDED_CONFIG_MAP[$process] ?? '';
            $current = self::num($item['lastvalue'] ?? null);
            $avg = $trends[$item['itemid']]['avg30d'] ?? null;
            $status = $param === ''
                ? 'Sem mapeamento'
                : (($current !== null && $current > $settings['poller_threshold'])
                    || ($avg !== null && $avg > $settings['poller_threshold']) ? 'Avaliar aumento' : 'OK');
            if ($only_findings && $status !== 'Avaliar aumento') {
                continue;
            }
            $rows[] = [
                'host' => $host['name'] ?: $host['host'],
                'process' => $process,
                'key' => $item['key_'],
                'current' => $current,
                'avg30d' => $avg,
                'config_param' => $param,
                'config_value' => $param !== '' ? self::value($cfg_items[$param]['lastvalue'] ?? null) : null,
                'recommended_param' => $recommended,
                'recommended_value' => $recommended !== '' ? self::value($cfg_items[$recommended]['lastvalue'] ?? null) : null,
                'status' => $status,
                'finding' => $status === 'Avaliar aumento' ? $param.' acima do threshold de pollers' : ''
            ];
        }
        return $rows;
    }

    private function buildCacheRows(array $host, array $items, array $cfg_items, array $trends,
            array $settings, bool $only_findings = false): array {
        $rows = [];
        foreach (self::CACHE_CONFIG_MAP as $key => $meta) {
            if (!isset($items[$key])) {
                continue;
            }
            [$name, $param, $mode] = $meta;
            $current = self::cacheUsed($items[$key]['lastvalue'] ?? null, $mode);
            $avg = self::cacheUsed($trends[$items[$key]['itemid']]['avg30d'] ?? null, $mode);
            $status = (($current !== null && $current > $settings['cache_threshold'])
                || ($avg !== null && $avg > $settings['cache_threshold'])) ? 'Avaliar ajuste' : 'OK';
            if ($only_findings && $status !== 'Avaliar ajuste') {
                continue;
            }
            $rows[] = [
                'host' => $host['name'] ?: $host['host'],
                'cache' => $name,
                'key' => $key,
                'current' => $current,
                'avg30d' => $avg,
                'config_param' => $param,
                'config_value' => $param !== '' ? self::value($cfg_items[$param]['lastvalue'] ?? null) : null,
                'status' => $status,
                'finding' => $status === 'Avaliar ajuste' ? $name.' acima do threshold de caches' : ''
            ];
        }
        return $rows;
    }

    private function collectConfigItems(string $templateid, array $hostids): array {
        if ($templateid === '') {
            return [];
        }
        $template_items = API::Item()->get([
            'output' => ['key_'],
            'templateids' => [$templateid],
            'sortfield' => 'name'
        ]);
        $keys = array_values(array_unique(array_column($template_items, 'key_')));
        $items = [];
        foreach (array_chunk($keys, 20) as $batch) {
            $items = array_merge($items, API::Item()->get([
                'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock',
                    'value_type', 'units', 'state', 'status', 'error'],
                'hostids' => $hostids,
                'filter' => ['key_' => $batch],
                'sortfield' => 'name'
            ]));
        }
        return $items;
    }

    private function collectProblems(array $hostids): array {
        $problems = API::Problem()->get([
            'output' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged'],
            'hostids' => $hostids,
            'recent' => false,
            'sortfield' => 'eventid',
            'sortorder' => ZBX_SORT_DOWN
        ]);
        $triggerids = array_values(array_unique(array_filter(array_column($problems, 'objectid'))));
        $triggers = $triggerids ? API::Trigger()->get([
            'output' => ['triggerid', 'description', 'priority', 'value', 'status', 'state', 'error'],
            'triggerids' => $triggerids,
            'selectHosts' => ['hostid', 'host', 'name', 'status'],
            'selectItems' => ['itemid', 'hostid', 'name', 'key_', 'status', 'state', 'error']
        ]) : [];

        $trigger_by_id = [];
        $trigger_to_host = [];
        foreach ($triggers as $trigger) {
            $trigger_by_id[$trigger['triggerid']] = $trigger;
            foreach ($trigger['hosts'] ?? [] as $host) {
                $trigger_to_host[$trigger['triggerid']] = $host['hostid'];
            }
        }

        $active = [];
        $orphans = [];
        $active_counts = [];
        $orphan_counts = [];
        foreach ($problems as $problem) {
            $hostid = $trigger_to_host[$problem['objectid']] ?? null;
            $orphan = $this->orphanReason($problem, $trigger_by_id) !== '';
            $bucket = $orphan ? 'orphan' : 'active';
            if ($orphan) {
                $problem['_hostid'] = $hostid;
                $problem['_orphan_reason'] = $this->orphanReason($problem, $trigger_by_id);
                $orphans[] = $problem;
            }
            else {
                $problem['_hostid'] = $hostid;
                $active[] = $problem;
            }

            if ($hostid !== null) {
                if ($orphan) {
                    $orphan_counts[$hostid]['total'] = ($orphan_counts[$hostid]['total'] ?? 0) + 1;
                    $orphan_counts[$hostid]['disaster'] = ($orphan_counts[$hostid]['disaster'] ?? 0)
                        + ((int) $problem['severity'] >= 5 ? 1 : 0);
                    $orphan_counts[$hostid]['relevant'] = ($orphan_counts[$hostid]['relevant'] ?? 0)
                        + ($this->isRelevantProblem($problem) ? 1 : 0);
                }
                else {
                    $active_counts[$hostid]['total'] = ($active_counts[$hostid]['total'] ?? 0) + 1;
                    $active_counts[$hostid]['disaster'] = ($active_counts[$hostid]['disaster'] ?? 0)
                        + ((int) $problem['severity'] >= 5 ? 1 : 0);
                    $active_counts[$hostid]['relevant'] = ($active_counts[$hostid]['relevant'] ?? 0)
                        + ($this->isRelevantProblem($problem) ? 1 : 0);
                }
            }
        }

        return [$active, $orphans, $active_counts, $orphan_counts];
    }

    private function problemRows(array $problems, array $host_by_id): array {
        return array_map(function(array $problem) use ($host_by_id): array {
            $host = $host_by_id[$problem['_hostid'] ?? ''] ?? [];
            return [
                'host' => $host['name'] ?? $host['host'] ?? '',
                'severity' => self::severity((int) ($problem['severity'] ?? 0)),
                'severity_num' => (int) ($problem['severity'] ?? 0),
                'name' => $problem['name'] ?? '',
                'relevant' => $this->isRelevantProblem($problem) ? 'Sim' : 'Nao',
                'clock' => self::dateLabel($problem['clock'] ?? 0),
                'age' => self::ageLabel($problem['clock'] ?? 0),
                'acknowledged' => ($problem['acknowledged'] ?? '0') === '1' ? 'Sim' : 'Nao',
                'orphan_reason' => $problem['_orphan_reason'] ?? '',
                'eventid' => $problem['eventid'] ?? '',
                'triggerid' => $problem['objectid'] ?? ''
            ];
        }, $problems);
    }

    private function enrichDiskItems(array &$items, array $hostids, int $now): void {
        $disk_items = API::Item()->get([
            'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock',
                'value_type', 'units', 'status', 'state', 'error'],
            'hostids' => $hostids,
            'search' => ['key_' => 'vfs.fs.size'],
            'sortfield' => 'name'
        ]);
        $by_host = [];
        foreach ($disk_items as $item) {
            if (preg_match('/^vfs\.fs\.size\[(.+),p(used|free)\]$/', $item['key_'], $match) !== 1) {
                continue;
            }
            $value = self::num($item['lastvalue'] ?? null);
            if ($value === null) {
                continue;
            }
            $filesystem = $match[1];
            $mode = $match[2];
            $used = $mode === 'used' ? $value : 100 - $value;
            $priority = $filesystem === '/' && $mode === 'used' ? 0
                : ($filesystem === '/' && $mode === 'free' ? 1 : ($mode === 'used' ? 2 : 3));
            $by_host[$item['hostid']][] = [$priority, -$used, $used, $mode, $item];
        }
        foreach ($by_host as $hostid => $candidates) {
            usort($candidates, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
            [$priority, $sort, $used, $mode, $item] = $candidates[0];
            $item['key_'] = 'vfs.fs.size[/,pused]';
            $item['name'] = 'Selected disk usage, % used';
            $item['lastvalue'] = (string) $used;
            $item['units'] = '%';
            $item['_trend_mode'] = $mode === 'free' ? 'pfree' : 'raw';
            $items[] = $item;
        }
    }

    private function collectTrends(array $items, int $now): array {
        $numeric = array_values(array_filter($items, static fn(array $item): bool =>
            in_array($item['value_type'], ['0', '3'], true)
        ));
        $stats = [];
        foreach (array_chunk($numeric, 120) as $batch) {
            try {
                $rows = API::Trend()->get([
                    'output' => ['itemid', 'clock', 'num', 'value_min', 'value_avg', 'value_max'],
                    'itemids' => array_column($batch, 'itemid'),
                    'time_from' => $now - 30 * 86400,
                    'time_till' => $now
                ]);
            }
            catch (Throwable $exception) {
                return [];
            }
            $grouped = [];
            foreach ($rows as $row) {
                $grouped[$row['itemid']][] = $row;
            }
            foreach ($batch as $item) {
                $stats[$item['itemid']] = self::trendStats($grouped[$item['itemid']] ?? [], $item['_trend_mode'] ?? 'raw');
            }
        }
        return $stats;
    }

    private function groupItemsByHost(array $items): array {
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item['hostid']][$item['key_']] = $item;
        }
        return $grouped;
    }

    private function orphanReason(array $problem, array $trigger_by_id): string {
        $trigger = $trigger_by_id[$problem['objectid'] ?? ''] ?? null;
        if ($trigger === null) {
            return _('Trigger nao retornada pela API ou sem contexto valido');
        }
        if ($trigger['status'] !== '0') {
            return _('Trigger desabilitada');
        }
        if (!$trigger['items']) {
            return _('Trigger sem itens associados retornados pela API');
        }
        foreach ($trigger['items'] as $item) {
            if ($item['status'] !== '0') {
                return _('Item desabilitado: ').($item['key_'] ?: $item['name'] ?: $item['itemid']);
            }
        }
        return '';
    }

    private function isRelevantProblem(array $problem): bool {
        if ((int) ($problem['severity'] ?? 0) >= 5) {
            return true;
        }
        $text = strtolower((string) ($problem['name'] ?? ''));
        foreach (['proxy', 'zabbix', 'poller', 'trapper', 'preprocessing', 'queue', 'cache',
            'history', 'configuration', 'version', 'cpu', 'mem', 'memory', 'disco',
            'disk', 'filesystem', 'load', 'unsupported', 'processo', 'process'] as $word) {
            if (strpos($text, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    private function hostInterface(array $host): string {
        foreach (($host['interfaces'] ?? []) as $interface) {
            if ($interface['main'] === '1') {
                return $interface['useip'] === '1' ? $interface['ip'] : ($interface['dns'] ?: $interface['ip']);
            }
        }
        return '';
    }

    private function inputNum(string $name, float $default): float {
        return self::num($this->getInput($name, (string) $default)) ?? $default;
    }

    private static function num($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float) $value : null;
    }

    private static function value($value) {
        $num = self::num($value);
        return $num ?? $value;
    }

    private static function maxNum(array $values): ?float {
        $nums = array_values(array_filter(array_map([self::class, 'num'], $values), static fn($v): bool => $v !== null));
        return $nums ? max($nums) : null;
    }

    private static function versionPatch(string $version): ?int {
        return preg_match('/^\d+\.\d+\.(\d+)/', $version, $matches) === 1 ? (int) $matches[1] : null;
    }

    private static function processName(string $key): string {
        return preg_match('/^zabbix\[process,([^,]+),/', $key, $matches) === 1 ? $matches[1] : $key;
    }

    private static function cacheUsed($value, string $mode): ?float {
        $num = self::num($value);
        if ($num === null) {
            return null;
        }
        return $mode === 'pfree' ? 100 - $num : $num;
    }

    private static function trendStats(array $rows, string $mode): array {
        $samples = 0;
        $weighted = 0.0;
        $max = null;
        foreach ($rows as $row) {
            $count = (int) ($row['num'] ?? 0);
            $avg = self::num($row['value_avg'] ?? null);
            $value_max = self::num($row['value_max'] ?? null);
            $value_min = self::num($row['value_min'] ?? null);
            if ($mode === 'pfree') {
                $avg = $avg !== null ? 100 - $avg : null;
                $value_max = $value_min !== null ? 100 - $value_min : null;
            }
            if ($avg !== null && $count > 0) {
                $weighted += $avg * $count;
                $samples += $count;
            }
            if ($value_max !== null) {
                $max = $max === null ? $value_max : max($max, $value_max);
            }
        }
        return ['avg30d' => $samples ? $weighted / $samples : null, 'max30d' => $max];
    }

    private static function severity(int $severity): string {
        return ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'][$severity]
            ?? (string) $severity;
    }

    private static function dateLabel($clock): string {
        $clock = (int) $clock;
        return $clock > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $clock) : '';
    }

    private static function ageLabel($clock): string {
        $seconds = max(0, time() - (int) $clock);
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60).'m';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600).'h '.floor(($seconds % 3600) / 60).'m';
        }
        return floor($seconds / 86400).'d '.floor(($seconds % 86400) / 3600).'h';
    }
}
