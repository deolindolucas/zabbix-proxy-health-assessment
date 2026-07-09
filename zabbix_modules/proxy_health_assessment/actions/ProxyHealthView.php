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

    private const DEFAULT_HOST_GROUP = 'Zabbix/Proxies';
    private const PROCESS_PREFIX = 'zabbix[process,';
    private const PROFILE_PREFIX = '[proxy_health_assessment]';
    private const TREND_BATCH_SIZE = 30;
    private const IMPORTANT_KEYS = [
        'agent.ping', 'system.cpu.load[all,avg1]', 'system.cpu.num', 'system.cpu.util',
        'system.uptime', 'vm.memory.size[total]', 'vm.memory.size[pavailable]', 'vm.memory.size[pused]',
        'vm.memory.utilization', 'vfs.fs.size[/,pused]', 'vfs.fs.size[/,pfree]',
        'proc.num[zabbix_proxy]', 'zabbix[uptime]',
        'zabbix[version]', 'zabbix[hosts]', 'zabbix[items]', 'zabbix[items_unsupported]',
        'zabbix[requiredperformance]', 'zabbix[preprocessing_queue]', 'zabbix[queue,10m]',
        'zabbix[proxy,{HOST.HOST}, lastaccess]', 'zabbix[proxy_buffer,state,current]',
        'zabbix[proxy_buffer,state,changes]', 'zabbix[proxy_buffer,buffer,pused]',
        'zabbix[rcache,buffer,pfree]', 'zabbix[rcache,buffer,pused]',
        'zabbix[wcache,history,pfree]', 'zabbix[wcache,history,pused]',
        'zabbix[wcache,index,pused]', 'zabbix[wcache,trend,pused]',
        'zabbix[vcache,buffer,pused]', 'zabbix[vmware,buffer,pused]', 'zabbix[proxy_history]',
        'zabbix[queue]', 'zabbix[discovery_queue]', 'zabbix[wcache,values]',
        'zabbix[wcache,values,float]', 'zabbix[wcache,values,uint]',
        'zabbix[wcache,values,str]', 'zabbix[wcache,values,text]',
        'zabbix[wcache,values,log]', 'zabbix[wcache,values,not supported]'
    ];
    private const TREND_KEYS = [
        'system.cpu.util',
        'vm.memory.size[pused]',
        'vm.memory.utilization',
        'vfs.fs.size[/,pused]',
        'vfs.fs.size[/,pfree]',
        'zabbix[proxy_buffer,buffer,pused]',
        'zabbix[rcache,buffer,pfree]',
        'zabbix[rcache,buffer,pused]',
        'zabbix[wcache,history,pfree]',
        'zabbix[wcache,history,pused]',
        'zabbix[wcache,index,pused]',
        'zabbix[wcache,trend,pused]',
        'zabbix[vcache,buffer,pused]',
        'zabbix[vmware,buffer,pused]'
    ];
    private const PROCESS_CONFIG_MAP = [
        'agent poller' => 'num.StartAgentPollers',
        'browser poller' => 'num.StartBrowserPollers',
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
        'preprocessing worker' => 'num.StartPreprocessors',
        'snmp poller' => 'num.StartSNMPPollers',
        'snmp trapper' => 'num.StartSNMPTrapper',
        'trapper' => 'num.StartTrappers',
        'unreachable poller' => 'num.StartPollersUnreachable',
        'vmware collector' => 'num.StartVMwareCollectors'
    ];
    private const RECOMMENDED_CONFIG_MAP = [
        'agent poller' => 'num.recomendado.agent',
        'browser poller' => 'num.recomendado.browser',
        'discoverer' => 'num.recomendado.discoverers',
        'discovery worker' => 'num.recomendado.discoverers',
        'http poller' => 'num.recomendado.http',
        'http agent poller' => 'num.recomendado.httpagent',
        'icmp pinger' => 'num.recomendado.pingers',
        'ipmi poller' => 'num.recomendado.ipmi',
        'java poller' => 'num.recomendado.java',
        'odbc poller' => 'num.recomendado.odbc',
        'poller' => 'num.recomendado.pollers',
        'preprocessing worker' => 'num.recomendado.preprocessors',
        'snmp poller' => 'num.recomendado.snmp',
        'trapper' => 'num.recomendado.trappers',
        'unreachable poller' => 'num.recomendado.unreachable',
        'vmware collector' => 'num.recomendado.vmware'
    ];
    private const NON_CONFIGURABLE_PROCESSES = [
        'availability manager', 'configuration syncer', 'data sender', 'discovery manager',
        'heartbeat sender', 'housekeeper', 'internal poller', 'ipmi manager',
        'preprocessing manager', 'self-monitoring', 'task manager'
    ];
    private const CACHE_CONFIG_MAP = [
        ['Configuration cache', 'num.CacheSize', 'num.CacheSize.bytes', 'num.recomendado.CacheSize', [['zabbix[rcache,buffer,pused]', 'pused'], ['zabbix[rcache,buffer,pfree]', 'pfree']]],
        ['History write cache', 'num.HistoryCacheSize', 'num.HistoryCacheSize.bytes', 'num.recomendado.HistoryCacheSize', [['zabbix[wcache,history,pused]', 'pused'], ['zabbix[wcache,history,pfree]', 'pfree']]],
        ['History index cache', 'num.HistoryIndexCacheSize', 'num.HistoryIndexCacheSize.bytes', 'num.recomendado.HistoryIndexCacheSize', [['zabbix[wcache,index,pused]', 'pused']]],
        ['Trend write cache', 'num.trendcachesize', 'num.TrendCacheSize.bytes', 'num.recomendado.TrendCacheSize', [['zabbix[wcache,trend,pused]', 'pused']]],
        ['Value cache', 'num.valueCacheSize', 'num.ValueCacheSize.bytes', 'num.recomendado.ValueCacheSize', [['zabbix[vcache,buffer,pused]', 'pused']]],
        ['Proxy memory buffer', '', '', '', [['zabbix[proxy_buffer,buffer,pused]', 'pused']]],
        ['VMware cache', 'num.VMwareCacheSize', '', '', [['zabbix[vmware,buffer,pused]', 'pused']]]
    ];
    private const CACHE_DEFAULTS = [
        'num.CacheSize' => '8M',
        'num.HistoryCacheSize' => '16M',
        'num.HistoryIndexCacheSize' => '4M',
        'num.trendcachesize' => '4M',
        'num.valueCacheSize' => '8M',
        'num.VMwareCacheSize' => '8M'
    ];
    private const CACHE_TARGET_LOAD = 0.60;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $valid = $this->validateInput([
            'host_groupid' => 'string',
            'proxy_templateid' => 'string',
            'proxy_template_filter' => 'in Sim,Nao',
            'proxy_template_indirect' => 'in Sim,Nao',
            'zabbix_server_hostid' => 'string',
            'version_cut' => 'string',
            'patch_min' => 'string',
            'unsupported_max' => 'string',
            'vps_max' => 'string',
            'cpu_current_max' => 'string',
            'cpu_avg_max' => 'string',
            'memory_current_max' => 'string',
            'memory_avg_max' => 'string',
            'disk_current_max' => 'string',
            'disk_avg_max' => 'string',
            'queue_10m_max' => 'string',
            'preproc_queue_max' => 'string',
            'lastaccess_max' => 'string',
            'trend_days' => 'string',
            'consider_orphans' => 'in Sim,Nao',
            'consider_config' => 'in Sim,Nao',
            'show_process_recommendations' => 'in Sim,Nao',
            'poller_threshold' => 'string',
            'cache_threshold' => 'string',
            'proxy_search' => 'string',
            'profile' => 'string',
            'debug_profile' => 'string',
            'proxy_async' => 'string',
            'proxy_stage' => 'string',
            'proxy_token' => 'string',
            'proxy_cursor' => 'string',
            'proxy_state' => 'string',
            'proxy_trend_state' => 'string',
            'proxy_trends' => 'string'
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
        $profile = $this->profileStart('doAction', [
            'host_groupid' => $settings['host_groupid'],
            'zabbix_server_hostid' => $settings['zabbix_server_hostid'],
            'profile_enabled' => $settings['profile_enabled'] ? '1' : '0'
        ]);

        $async = in_array($this->getInput('proxy_async', ''), ['1', 'true', 'Sim'], true);

        try {
            if ($async) {
                $data = $this->collectAsyncStage($settings);
            }
            else {
                $data = self::emptyPayload();
                $this->profileStep($profile, 'collect.deferred', [
                    'async' => '1'
                ]);
            }
        }
        catch (Throwable $exception) {
            $this->profileException($profile, $exception);
            $data = array_merge(self::emptyPayload(), [
                'error' => _('Nao foi possivel coletar os dados do assessment. Verifique permissao de API, host group e itens.'),
                'exception' => $exception->getMessage()
            ]);
        }

        $data['settings'] = $settings;
        $data['action'] = $this->getAction();
        $data['proxy_search'] = $this->getInput('proxy_search', '');
        $payload_json = json_encode([
            'proxies' => $data['proxies'],
            'config_items' => $data['config_items'],
            'process_config' => $data['process_config'],
            'cache_config' => $data['cache_config'],
            'orphans' => $data['orphans'],
            'active_problems' => $data['active_problems'],
            'excluded_offline' => $data['excluded_offline'],
            'settings' => $settings,
            'error' => $data['error'] ?? null,
            'exception' => $data['exception'] ?? null,
            'stage' => $data['stage'] ?? null,
            'token' => $data['token'] ?? null,
            'client_state' => $data['client_state'] ?? null,
            'trend_state' => $data['trend_state'] ?? null,
            'cursor' => $data['cursor'] ?? null,
            'trend_items' => $data['trend_items'] ?? null,
            'trend_stats' => $data['trend_stats'] ?? null,
            'total_batches' => $data['total_batches'] ?? null,
            'batch_size' => $data['batch_size'] ?? null,
            'batches_per_request' => $data['batches_per_request'] ?? null,
            'processed_batches' => $data['processed_batches'] ?? null,
            'done' => $data['done'] ?? null
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data['payload'] = base64_encode((string) $payload_json);
        $this->profileStep($profile, 'payload.encoded', [
            'json_bytes' => strlen((string) $payload_json),
            'base64_bytes' => strlen($data['payload'])
        ]);
        $this->profileFinish($profile);

        $this->setResponse(new CControllerResponseData($data));
    }

    private function settings(): array {
        $host_groupid = $this->inputHostGroupId();
        $proxy_templateid = $this->inputTemplateId('proxy_templateid');
        if ($proxy_templateid === '') {
            $proxy_templateid = $this->defaultProxyTemplateId();
        }
        $zabbix_server_hostid = $this->inputHostId('zabbix_server_hostid');
        $unsupported_max_percent = $this->inputNum('unsupported_max', 2);
        if ($unsupported_max_percent > 0 && $unsupported_max_percent < 1) {
            $unsupported_max_percent *= 100;
        }

        return [
            'host_groupid' => $host_groupid,
            'host_group_name' => $this->hostGroupName($host_groupid),
            'proxy_templateid' => $proxy_templateid,
            'proxy_template_name' => $this->templateName($proxy_templateid),
            'proxy_template_filter' => $this->getInput('proxy_template_filter', 'Sim'),
            'proxy_template_indirect' => $this->getInput('proxy_template_indirect', 'Sim'),
            'zabbix_server_hostid' => $zabbix_server_hostid,
            'zabbix_server_host_name' => $this->hostName($zabbix_server_hostid),
            'version_cut' => $this->getInput('version_cut', '7.0.20'),
            'patch_min' => $this->inputNum('patch_min', 20),
            'unsupported_max' => $unsupported_max_percent / 100,
            'unsupported_max_percent' => $unsupported_max_percent,
            'vps_max' => $this->inputNum('vps_max', 300),
            'cpu_current_max' => $this->inputNum('cpu_current_max', 85),
            'cpu_avg_max' => $this->inputNum('cpu_avg_max', 75),
            'memory_current_max' => $this->inputNum('memory_current_max', 85),
            'memory_avg_max' => $this->inputNum('memory_avg_max', 80),
            'disk_current_max' => $this->inputNum('disk_current_max', 85),
            'disk_avg_max' => $this->inputNum('disk_avg_max', 80),
            'queue_10m_max' => $this->inputNum('queue_10m_max', 0),
            'preproc_queue_max' => $this->inputNum('preproc_queue_max', 50),
            'lastaccess_max' => $this->inputNum('lastaccess_max', 900),
            'trend_days' => min(30, max(7, (int) $this->inputNum('trend_days', 30))),
            'consider_orphans' => $this->getInput('consider_orphans', 'Nao'),
            'consider_config' => $this->getInput('consider_config', 'Nao'),
            'show_process_recommendations' => $this->getInput('show_process_recommendations', 'Sim'),
            'poller_threshold' => $this->inputNum('poller_threshold', 75),
            'cache_threshold' => $this->inputNum('cache_threshold', 75),
            'score_ok' => 90,
            'score_attention' => 70,
            'score_risk' => 40,
            'profile_enabled' => in_array($this->getInput('profile', ''), ['1', 'true', 'Sim'], true)
                || in_array($this->getInput('debug_profile', ''), ['1', 'true', 'Sim'], true)
        ];
    }

    private function collectAsyncStage(array $settings): array {
        $stage = $this->getInput('proxy_stage', 'init');

        if ($stage === 'trend') {
            return $this->collectAsyncTrend($settings);
        }

        if ($stage === 'finalize') {
            return $this->collectAsyncFinalize($settings);
        }

        return $this->collectAsyncInit($settings);
    }

    private function collectAsyncInit(array $settings): array {
        $now = time();
        $this->cleanupAsyncStates();
        $profile = $this->profileStart('async.init', [
            'host_groupid' => $settings['host_groupid'],
            'zabbix_server_hostid' => $settings['zabbix_server_hostid']
        ]);
        $state = $this->prepareCollectionState($settings, $now);
        $numeric = $this->numericTrendItems($state['trend_items']);
        $state['trend_items'] = $numeric;
        $state['trends'] = [];
        $state['cursor'] = 0;
        $state['created_at'] = $now;

        $trend_state = [
            'now' => $now,
            'trend_days' => $settings['trend_days'],
            'trend_items' => $numeric
        ];
        $client_state = $state;
        unset($client_state['trend_items'], $client_state['trends'], $client_state['cursor']);

        $this->profileStep($profile, 'async.init.saved', [
            'hosts' => count($state['hosts']),
            'items' => count($state['items']),
            'trend_items' => count($numeric),
            'trend_batches' => count(array_chunk($numeric, self::TREND_BATCH_SIZE))
        ]);
        $this->profileFinish($profile);

        return self::emptyPayload() + [
            'error' => null,
            'stage' => 'init',
            'token' => null,
            'client_state' => $this->encodeClientState($client_state),
            'trend_state' => $this->encodeClientState($trend_state),
            'cursor' => 0,
            'trend_items' => count($numeric),
            'total_batches' => count(array_chunk($numeric, self::TREND_BATCH_SIZE)),
            'batch_size' => self::TREND_BATCH_SIZE,
            'batches_per_request' => 4
        ];
    }

    private function collectAsyncTrend(array $settings): array {
        $token = $this->getInput('proxy_token', '');
        $cursor = max(0, (int) $this->getInput('proxy_cursor', 0));
        $trend_state_payload = $this->getInput('proxy_trend_state', '');
        if ($trend_state_payload !== '') {
            $state = $this->decodeClientState($trend_state_payload);
            $state += ['trends' => []];
        }
        else {
            $state = $this->loadAsyncState($token);
        }
        $profile = $this->profileStart('async.trend', [
            'token' => substr($token, 0, 8),
            'cursor' => $cursor
        ]);

        $batch_count = 4;
        $result = $this->collectTrendSlice(
            $state['trend_items'],
            (int) $state['now'],
            $cursor,
            $batch_count,
            (int) ($state['trend_days'] ?? $settings['trend_days'])
        );
        if ($trend_state_payload === '') {
            $state['trends'] = ($state['trends'] ?? []) + $result['stats'];
            $state['cursor'] = $result['next_cursor'];
            $this->saveAsyncState($state, $token);
        }

        $this->profileStep($profile, 'async.trend.saved', [
            'next_cursor' => $result['next_cursor'],
            'done' => $result['done'] ? '1' : '0',
            'stats_total' => count($result['stats'])
        ]);
        $this->profileFinish($profile);

        return self::emptyPayload() + [
            'error' => null,
            'stage' => 'trend',
            'token' => $token,
            'cursor' => $result['next_cursor'],
            'trend_items' => count($state['trend_items']),
            'total_batches' => count(array_chunk($state['trend_items'], self::TREND_BATCH_SIZE)),
            'processed_batches' => (int) ceil($result['next_cursor'] / self::TREND_BATCH_SIZE),
            'done' => $result['done'],
            'trend_stats' => $result['stats']
        ];
    }

    private function collectAsyncFinalize(array $settings): array {
        $token = $this->getInput('proxy_token', '');
        $state_payload = $this->getInput('proxy_state', '');
        if ($state_payload !== '') {
            $state = $this->decodeClientState($state_payload);
            $trends_payload = $this->getInput('proxy_trends', '');
            $trends = $trends_payload !== '' ? json_decode($trends_payload, true) : [];
            if (!is_array($trends)) {
                throw new \RuntimeException('Trends consolidadas invalidas.');
            }
        }
        else {
            $state = $this->loadAsyncState($token);
            $trends = $state['trends'] ?? [];
        }
        $profile = $this->profileStart('async.finalize', [
            'token' => substr($token, 0, 8)
        ]);
        $data = $this->buildCollectedDataFromState($state, $trends, $settings, (int) $state['now']);
        $data['error'] = null;
        $data['stage'] = 'complete';
        if ($state_payload === '') {
            $this->deleteAsyncState($token);
        }
        $this->profileStep($profile, 'async.finalize.complete', [
            'proxies' => count($data['proxies']),
            'config_items' => count($data['config_items']),
            'process_config' => count($data['process_config']),
            'cache_config' => count($data['cache_config'])
        ]);
        $this->profileFinish($profile);

        return $data;
    }

    private function prepareCollectionState(array $settings, int $now): array {
        $profile = $this->profileStart('collect.prepare', [
            'host_groupid' => $settings['host_groupid'],
            'zabbix_server_hostid' => $settings['zabbix_server_hostid'],
            'proxy_template_filter' => $settings['proxy_template_filter'],
            'proxy_templateid' => $settings['proxy_templateid']
        ]);

        $hosts = [];
        if ($settings['host_groupid'] !== '') {
            $host_get = [
                'output' => ['hostid', 'host', 'name', 'status', 'available'],
                'selectInterfaces' => ['ip', 'dns', 'type', 'main', 'useip'],
                'selectParentTemplates' => ['templateid', 'host', 'name'],
                'groupids' => [$settings['host_groupid']],
                'sortfield' => 'name'
            ];

            $template_filter_ids = $this->proxyTemplateFilterIds($settings);
            if ($template_filter_ids) {
                $this->profileStep($profile, 'prepare.template_filter', [
                    'templates' => count($template_filter_ids)
                ]);
            }

            $hosts = API::Host()->get($host_get);
            if ($template_filter_ids) {
                $hosts_raw_count = count($hosts);
                $hosts = array_values(array_filter($hosts, fn(array $host): bool =>
                    $this->hostMatchesTemplateFilter($host, $template_filter_ids)
                ));
                $this->profileStep($profile, 'prepare.template_filter_hosts', [
                    'before' => $hosts_raw_count,
                    'after' => count($hosts)
                ]);
            }
        }
        $this->profileStep($profile, 'prepare.host_group_hosts', [
            'hosts_raw' => count($hosts)
        ]);

        $hosts_by_id = [];
        foreach (array_values(array_filter($hosts, static fn(array $host): bool => $host['status'] === '0')) as $host) {
            $host['_assessment_role'] = 'proxy';
            $hosts_by_id[$host['hostid']] = $host;
        }

        if ($settings['zabbix_server_hostid'] !== '') {
            $server_hosts = API::Host()->get([
                'output' => ['hostid', 'host', 'name', 'status', 'available'],
                'selectInterfaces' => ['ip', 'dns', 'type', 'main', 'useip'],
                'hostids' => [$settings['zabbix_server_hostid']],
                'limit' => 1
            ]);

            if ($server_hosts && $server_hosts[0]['status'] === '0') {
                $server_hosts[0]['_assessment_role'] = 'server';
                $hosts_by_id[$server_hosts[0]['hostid']] = $server_hosts[0];
            }
            $this->profileStep($profile, 'prepare.server_host', [
                'server_found' => $server_hosts && $server_hosts[0]['status'] === '0' ? '1' : '0'
            ]);
        }

        $hosts = array_values($hosts_by_id);
        usort($hosts, static fn(array $a, array $b): int =>
            (($a['_assessment_role'] ?? 'proxy') === 'server' ? 0 : 1)
                <=> (($b['_assessment_role'] ?? 'proxy') === 'server' ? 0 : 1)
            ?: strnatcasecmp($a['name'] ?: $a['host'], $b['name'] ?: $b['host'])
        );
        $hostids = array_column($hosts, 'hostid');
        $this->profileStep($profile, 'prepare.hosts_ready', [
            'hosts' => count($hosts),
            'hostids' => count($hostids)
        ]);

        if (!$hostids) {
            $this->profileFinish($profile);
            return [
                'now' => $now,
                'hosts' => [],
                'items' => [],
                'trend_items' => [],
                'config_items' => [],
                'active_problems' => [],
                'orphan_problems' => [],
                'problem_counts' => [],
                'orphan_counts' => []
            ];
        }

        $items = $this->collectAssessmentItems($hostids);
        $this->profileStep($profile, 'prepare.assessment_items', [
            'items' => count($items)
        ]);
        $this->enrichDiskItems($items, $hostids, $now);
        $items = self::dedupeItems($items);
        $trend_items = $this->trendItems($items);
        $config_items = $this->collectConfigItems($hostids);
        [$active_problems, $orphan_problems, $problem_counts, $orphan_counts] = $this->collectProblems($hostids);
        $this->profileStep($profile, 'prepare.ready', [
            'items' => count($items),
            'trend_items' => count($trend_items),
            'config_items' => count($config_items),
            'active_problems' => count($active_problems),
            'orphan_problems' => count($orphan_problems)
        ]);
        $this->profileFinish($profile);

        return [
            'now' => $now,
            'hosts' => $hosts,
            'items' => $items,
            'trend_items' => $trend_items,
            'config_items' => $config_items,
            'active_problems' => $active_problems,
            'orphan_problems' => $orphan_problems,
            'problem_counts' => $problem_counts,
            'orphan_counts' => $orphan_counts
        ];
    }

    private function buildCollectedDataFromState(array $state, array $trends, array $settings, int $now): array {
        $items_by_host = $this->groupItemsByHost($state['items'] ?? []);
        $config_by_host = $this->groupItemsByHost($state['config_items'] ?? []);
        $host_by_id = [];
        $excluded = [];
        $proxies = [];
        $process_config = [];
        $cache_config = [];
        $config_rows = [];

        foreach (($state['hosts'] ?? []) as $host) {
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
                $state['problem_counts'][$host['hostid']] ?? [], $state['orphan_counts'][$host['hostid']] ?? [],
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
            (($a['assessment_role'] ?? 'proxy') === 'server' ? 0 : 1)
                <=> (($b['assessment_role'] ?? 'proxy') === 'server' ? 0 : 1)
            ?: ($a['score'] <=> $b['score'])
            ?: strnatcasecmp($a['host'], $b['host'])
        );

        return [
            'proxies' => $proxies,
            'config_items' => $config_rows,
            'process_config' => $process_config,
            'cache_config' => $cache_config,
            'orphans' => $this->problemRows($state['orphan_problems'] ?? [], $host_by_id),
            'active_problems' => $this->problemRows($state['active_problems'] ?? [], $host_by_id),
            'excluded_offline' => $excluded
        ];
    }

    private function collectTrendSlice(array $items, int $now, int $cursor, int $batch_count, int $trend_days): array {
        $profile = $this->profileStart('collectTrendSlice', [
            'items' => count($items),
            'cursor' => $cursor,
            'batch_count' => $batch_count,
            'trend_days' => $trend_days
        ]);
        $trend_days = min(30, max(7, $trend_days));
        $stats = [];
        $offset = $cursor;
        $limit = max(1, $batch_count) * self::TREND_BATCH_SIZE;
        $slice = array_slice($items, $cursor, $limit);

        foreach (array_chunk($slice, self::TREND_BATCH_SIZE) as $index => $batch) {
            $rows = API::Trend()->get([
                'output' => ['itemid', 'num', 'value_avg'],
                'itemids' => array_column($batch, 'itemid'),
                'time_from' => $now - $trend_days * 86400,
                'time_till' => $now
            ]);
            $this->profileStep($profile, 'trend.slice_batch', [
                'batch' => (int) floor(($cursor + $index * self::TREND_BATCH_SIZE) / self::TREND_BATCH_SIZE) + 1,
                'batch_items' => count($batch),
                'rows' => count($rows)
            ]);

            $grouped = [];
            foreach ($rows as $row) {
                $grouped[$row['itemid']][] = $row;
            }
            foreach ($batch as $item) {
                $stats[$item['itemid']] = self::trendStats($grouped[$item['itemid']] ?? [], $item['_trend_mode'] ?? 'raw');
            }
            $offset += count($batch);
            unset($rows, $grouped);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $this->profileFinish($profile);
        return [
            'stats' => $stats,
            'next_cursor' => $offset,
            'done' => $offset >= count($items)
        ];
    }

    private function numericTrendItems(array $items): array {
        return array_values(array_filter($items, static fn(array $item): bool =>
            in_array($item['value_type'], ['0', '3'], true)
        ));
    }

    private function saveAsyncState(array $state, ?string $token = null): string {
        $token = $token !== null && preg_match('/^[a-f0-9]{32}$/', $token) ? $token : bin2hex(random_bytes(16));
        file_put_contents($this->asyncStatePath($token), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $token;
    }

    private function loadAsyncState(string $token): array {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new \RuntimeException('Token de coleta invalido.');
        }
        $path = $this->asyncStatePath($token);
        if (!is_file($path)) {
            throw new \RuntimeException('Estado temporario da coleta nao encontrado.');
        }
        $state = json_decode((string) file_get_contents($path), true);
        if (!is_array($state)) {
            throw new \RuntimeException('Estado temporario da coleta invalido.');
        }
        if ((int) ($state['created_at'] ?? 0) < time() - 1800) {
            $this->deleteAsyncState($token);
            throw new \RuntimeException('Estado temporario da coleta expirado.');
        }
        return $state;
    }

    private function deleteAsyncState(string $token): void {
        if (preg_match('/^[a-f0-9]{32}$/', $token)) {
            $path = $this->asyncStatePath($token);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function asyncStatePath(string $token): string {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'proxy_health_assessment_'.$token.'.json';
    }

    private function encodeClientState(array $state): string {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Nao foi possivel serializar o estado da coleta.');
        }

        if (function_exists('gzencode')) {
            return 'gz:'.base64_encode((string) gzencode($json, 1));
        }

        return 'b64:'.base64_encode($json);
    }

    private function decodeClientState(string $payload): array {
        if (strpos($payload, 'gz:') === 0) {
            if (!function_exists('gzdecode')) {
                throw new \RuntimeException('O PHP nao possui suporte a gzdecode.');
            }
            $decoded = base64_decode(substr($payload, 3), true);
            $json = $decoded !== false ? gzdecode($decoded) : false;
        }
        elseif (strpos($payload, 'b64:') === 0) {
            $json = base64_decode(substr($payload, 4), true);
        }
        else {
            throw new \RuntimeException('Estado de coleta invalido.');
        }

        if ($json === false) {
            throw new \RuntimeException('Nao foi possivel decodificar o estado da coleta.');
        }

        $state = json_decode((string) $json, true);
        if (!is_array($state)) {
            throw new \RuntimeException('Estado de coleta invalido.');
        }

        return $state;
    }

    private function cleanupAsyncStates(int $ttl = 1800): void {
        $limit = time() - $ttl;
        foreach (glob(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'proxy_health_assessment_*.json') ?: [] as $path) {
            if (is_file($path) && filemtime($path) !== false && filemtime($path) < $limit) {
                @unlink($path);
            }
        }
    }

    private static function emptyPayload(): array {
        return [
            'error' => null,
            'proxies' => [],
            'config_items' => [],
            'process_config' => [],
            'cache_config' => [],
            'orphans' => [],
            'active_problems' => [],
            'excluded_offline' => []
        ];
    }

    private function collect(array $settings): array {
        $now = time();
        $profile = $this->profileStart('collect', [
            'host_groupid' => $settings['host_groupid'],
            'zabbix_server_hostid' => $settings['zabbix_server_hostid']
        ]);

        $hosts = [];
        if ($settings['host_groupid'] !== '') {
            $host_get = [
                'output' => ['hostid', 'host', 'name', 'status', 'available'],
                'selectInterfaces' => ['ip', 'dns', 'type', 'main', 'useip'],
                'selectParentTemplates' => ['templateid', 'host', 'name'],
                'groupids' => [$settings['host_groupid']],
                'sortfield' => 'name'
            ];

            $template_filter_ids = $this->proxyTemplateFilterIds($settings);

            $hosts = API::Host()->get($host_get);
            if ($template_filter_ids) {
                $hosts = array_values(array_filter($hosts, fn(array $host): bool =>
                    $this->hostMatchesTemplateFilter($host, $template_filter_ids)
                ));
            }
        }
        $this->profileStep($profile, 'collect.host_group_hosts', [
            'hosts_raw' => count($hosts)
        ]);

        $hosts_by_id = [];
        foreach (array_values(array_filter($hosts, static fn(array $host): bool => $host['status'] === '0')) as $host) {
            $host['_assessment_role'] = 'proxy';
            $hosts_by_id[$host['hostid']] = $host;
        }

        if ($settings['zabbix_server_hostid'] !== '') {
            $server_hosts = API::Host()->get([
                'output' => ['hostid', 'host', 'name', 'status', 'available'],
                'selectInterfaces' => ['ip', 'dns', 'type', 'main', 'useip'],
                'hostids' => [$settings['zabbix_server_hostid']],
                'limit' => 1
            ]);

            if ($server_hosts && $server_hosts[0]['status'] === '0') {
                $server_hosts[0]['_assessment_role'] = 'server';
                $hosts_by_id[$server_hosts[0]['hostid']] = $server_hosts[0];
            }
            $this->profileStep($profile, 'collect.server_host', [
                'server_found' => $server_hosts && $server_hosts[0]['status'] === '0' ? '1' : '0'
            ]);
        }

        $hosts = array_values($hosts_by_id);
        usort($hosts, static fn(array $a, array $b): int =>
            (($a['_assessment_role'] ?? 'proxy') === 'server' ? 0 : 1)
                <=> (($b['_assessment_role'] ?? 'proxy') === 'server' ? 0 : 1)
            ?: strnatcasecmp($a['name'] ?: $a['host'], $b['name'] ?: $b['host'])
        );
        $hostids = array_column($hosts, 'hostid');
        $this->profileStep($profile, 'collect.hosts_ready', [
            'hosts' => count($hosts),
            'hostids' => count($hostids)
        ]);

        if (!$hostids) {
            $this->profileFinish($profile);
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

        $items = $this->collectAssessmentItems($hostids);
        $this->profileStep($profile, 'collect.assessment_items', [
            'items' => count($items)
        ]);
        $this->enrichDiskItems($items, $hostids, $now);
        $this->profileStep($profile, 'collect.disk_items', [
            'items_with_disk' => count($items)
        ]);
        $items = self::dedupeItems($items);
        $trend_items = $this->trendItems($items);
        $this->profileStep($profile, 'collect.trend_items', [
            'items' => count($items),
            'trend_items' => count($trend_items)
        ]);
        $trends = $this->collectTrends($trend_items, $now);
        $this->profileStep($profile, 'collect.trends', [
            'trends' => count($trends)
        ]);
        $config_items = $this->collectConfigItems($hostids);
        $this->profileStep($profile, 'collect.config_items', [
            'config_items' => count($config_items)
        ]);
        [$active_problems, $orphan_problems, $problem_counts, $orphan_counts] = $this->collectProblems($hostids);
        $this->profileStep($profile, 'collect.problems', [
            'active_problems' => count($active_problems),
            'orphan_problems' => count($orphan_problems)
        ]);

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
        $this->profileStep($profile, 'collect.build_rows', [
            'proxies' => count($proxies),
            'config_rows' => count($config_rows),
            'process_rows' => count($process_config),
            'cache_rows' => count($cache_config),
            'excluded' => count($excluded)
        ]);

        usort($proxies, static fn(array $a, array $b): int =>
            (($a['assessment_role'] ?? 'proxy') === 'server' ? 0 : 1)
                <=> (($b['assessment_role'] ?? 'proxy') === 'server' ? 0 : 1)
            ?: ($a['score'] <=> $b['score'])
            ?: strnatcasecmp($a['host'], $b['host'])
        );
        $this->profileFinish($profile);

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

        $version = (string) ($get('zabbix[version]')['lastvalue'] ?? '');
        $total_items = $value('zabbix[items]');
        $unsupported = $value('zabbix[items_unsupported]');
        $unsupported_pct = $total_items ? $unsupported / $total_items : null;
        $cpu_current = $value('system.cpu.util');
        $cpu_avg = $avg('system.cpu.util');
        $mem_current = $value('vm.memory.size[pused]') ?? $value('vm.memory.utilization');
        $mem_avg = $avg('vm.memory.size[pused]') ?? $avg('vm.memory.utilization');
        $disk_pfree_current = $value('vfs.fs.size[/,pfree]');
        $disk_pfree_avg = $avg('vfs.fs.size[/,pfree]');
        $disk_current = $value('vfs.fs.size[/,pused]')
            ?? ($disk_pfree_current !== null ? 100 - $disk_pfree_current : null);
        $disk_avg = $avg('vfs.fs.size[/,pused]')
            ?? ($disk_pfree_avg !== null ? 100 - $disk_pfree_avg : null);
        $lastaccess = $value('zabbix[proxy,{HOST.HOST}, lastaccess]');
        $lastaccess_age = $lastaccess !== null ? max(0, $now - (int) $lastaccess) : null;
        $process_findings = $this->buildProcessRows($host, $items, $cfg_items, $trends, $settings, true);
        $cache_findings = $this->buildCacheRows($host, $items, $cfg_items, $trends, $settings, true);
        $score_config_findings = $settings['consider_config'] === 'Sim'
            ? array_merge(array_column($process_findings, 'finding'), array_column($cache_findings, 'finding'))
            : [];
        $summary_config_findings = $settings['show_process_recommendations'] === 'Sim'
            ? array_merge(
                array_column(array_filter(
                    $this->buildProcessRows($host, $items, $cfg_items, $trends, $settings),
                    static fn(array $row): bool => in_array($row['status'], ['Avaliar aumento', 'Avaliar diminuicao'], true)
                ), 'action'),
                $settings['consider_config'] === 'Sim' ? array_column($cache_findings, 'finding') : []
            )
            : ($settings['consider_config'] === 'Sim' ? array_column($cache_findings, 'finding') : []);

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
        $deduct($cpu_current !== null && $cpu_current > $settings['cpu_current_max'], 10, _('CPU atual alta'));
        $deduct($cpu_avg !== null && $cpu_avg > $settings['cpu_avg_max'], 10, _('CPU media 30d alta'));
        $deduct($mem_current !== null && $mem_current > $settings['memory_current_max'], 10, _('Memoria atual alta'));
        $deduct($mem_avg !== null && $mem_avg > $settings['memory_avg_max'], 10, _('Memoria media 30d alta'));
        $deduct($disk_current !== null && $disk_current > $settings['disk_current_max'], 10, _('Disco atual alto'));
        $deduct($disk_avg !== null && $disk_avg > $settings['disk_avg_max'], 10, _('Disco media 30d alta'));
        $deduct($value('zabbix[queue,10m]') !== null && $value('zabbix[queue,10m]') > $settings['queue_10m_max'], 10, _('Fila 10m acima do limite'));
        $deduct($value('zabbix[preprocessing_queue]') !== null && $value('zabbix[preprocessing_queue]') > $settings['preproc_queue_max'], 10, _('Preprocessing queue acima do limite'));
        $deduct($settings['consider_config'] === 'Sim' && $score_config_findings, 15, implode('; ', $score_config_findings));

        foreach ($summary_config_findings as $finding) {
            if ($finding !== '') {
                $findings[] = $finding;
            }
        }

        $state = $score >= $settings['score_ok']
            ? 'OK'
            : ($score >= $settings['score_attention'] ? 'Atencao' : ($score >= $settings['score_risk'] ? 'Risco' : 'Critico'));

        return [
            'hostid' => $host['hostid'],
            'host' => $host['name'] ?: $host['host'],
            'technical_name' => $host['host'],
            'assessment_role' => $host['_assessment_role'] ?? 'proxy',
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
            'cpu_current' => $cpu_current,
            'cpu_avg' => $cpu_avg,
            'memory_total_gb' => self::bytesToGib($value('vm.memory.size[total]')),
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
            $config_value = $param !== '' ? self::num($cfg_items[$param]['lastvalue'] ?? null) : null;
            $recommended_value = $recommended !== '' ? self::num($cfg_items[$recommended]['lastvalue'] ?? null) : null;
            if ($param === '') {
                $status = in_array($process, self::NON_CONFIGURABLE_PROCESSES, true)
                    ? 'Sem parametro configuravel'
                    : 'Sem mapeamento';
            }
            elseif ($current === 0.0 && $avg === 0.0 && $config_value !== null && $config_value > 1) {
                $status = 'Avaliar diminuicao';
                $recommended_value = 1.0;
            }
            elseif (($current !== null && $current > $settings['poller_threshold'])
                    || ($avg !== null && $avg > $settings['poller_threshold'])) {
                $status = 'Avaliar aumento';
            }
            elseif ($recommended_value !== null && $config_value !== null && $config_value > $recommended_value
                    && $avg !== null && $avg < 50) {
                $status = 'Avaliar diminuicao';
            }
            else {
                $status = 'OK';
            }
            $action = '';
            if ($status === 'Avaliar aumento') {
                $action = sprintf(_('%s: aumentar quantidade configurada (parametro=%s; configurado=%s; recomendado=%s; atual=%s%%; media 30d=%s%%)'),
                    $process,
                    $param,
                    self::displayValue($config_value),
                    self::displayValue($recommended_value),
                    self::displayValue($current),
                    self::displayValue($avg)
                );
            }
            elseif ($status === 'Avaliar diminuicao') {
                $action = $current === 0.0 && $avg === 0.0 && $config_value !== null && $config_value > 1
                    ? sprintf(_('%s: diminuir quantidade configurada para 1 (parametro=%s; sem uso atual ou media 30d; configurado=%s)'),
                        $process,
                        $param,
                        self::displayValue($config_value)
                    )
                    : sprintf(_('%s: avaliar diminuicao da quantidade configurada (parametro=%s; configurado=%s; recomendado=%s; media 30d=%s%%)'),
                        $process,
                        $param,
                        self::displayValue($config_value),
                        self::displayValue($recommended_value),
                        self::displayValue($avg)
                    );
            }
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
                'config_value' => $config_value,
                'recommended_param' => $recommended,
                'recommended_value' => $recommended_value,
                'status' => $status,
                'action' => $action,
                'finding' => $status === 'Avaliar aumento'
                    ? sprintf(_('%s: uso acima do threshold de processos'), $process)
                    : ''
            ];
        }
        return $rows;
    }

    private function buildCacheRows(array $host, array $items, array $cfg_items, array $trends,
            array $settings, bool $only_findings = false): array {
        $rows = [];
        foreach (self::CACHE_CONFIG_MAP as $meta) {
            [$name, $param, $bytes_param, $recommended_param, $candidates] = $meta;
            $selected_key = null;
            $selected_mode = null;
            $selected_item = null;
            foreach ($candidates as $candidate) {
                [$key, $mode] = $candidate;
                if (isset($items[$key])) {
                    $selected_key = $key;
                    $selected_mode = $mode;
                    $selected_item = $items[$key];
                    break;
                }
            }
            if ($selected_item === null) {
                continue;
            }
            $current = self::cacheUsed($selected_item['lastvalue'] ?? null, $selected_mode);
            $avg = self::cacheUsed($trends[$selected_item['itemid']]['avg30d'] ?? null, $selected_mode);
            $configured_value = $param !== ''
                ? (($cfg_items[$param]['lastvalue'] ?? null) ?: (self::CACHE_DEFAULTS[$param] ?? null))
                : null;
            $configured_bytes = $bytes_param !== ''
                ? self::positiveNum($cfg_items[$bytes_param]['lastvalue'] ?? null)
                : null;
            $configured_bytes = $configured_bytes ?? self::sizeToBytes($configured_value);
            $status = (($current !== null && $current > $settings['cache_threshold'])
                || ($avg !== null && $avg > $settings['cache_threshold'])) ? 'Avaliar ajuste' : 'OK';
            $recommended_bytes = null;
            if ($status === 'Avaliar ajuste') {
                $recommended_bytes = $recommended_param !== ''
                    ? self::positiveNum($cfg_items[$recommended_param]['lastvalue'] ?? null)
                    : null;
                $usage_for_recommendation = self::maxNum([$current, $avg]);
                if ($recommended_bytes === null && $configured_bytes !== null && $usage_for_recommendation !== null) {
                    $recommended_bytes = ceil(($configured_bytes * ($usage_for_recommendation / 100)) / self::CACHE_TARGET_LOAD);
                }
            }
            if ($only_findings && $status !== 'Avaliar ajuste') {
                continue;
            }
            $rows[] = [
                'host' => $host['name'] ?: $host['host'],
                'cache' => $name,
                'key' => $selected_key,
                'current' => $current,
                'avg30d' => $avg,
                'config_param' => $param,
                'config_bytes_param' => $bytes_param,
                'recommended_param' => $recommended_param,
                'config_value' => self::value($configured_value),
                'config_bytes' => $configured_bytes,
                'recommended_bytes' => $recommended_bytes,
                'status' => $status,
                'finding' => $status === 'Avaliar ajuste'
                    ? $name.' acima do threshold de caches'
                    : '',
                'action' => $status === 'Avaliar ajuste'
                    ? ($param !== ''
                        ? sprintf('%s: avaliar ajuste de %s (configurado=%s; recomendado bytes=%s)',
                            $name, $param, self::displayValue($configured_value), self::displayValue($recommended_bytes))
                        : $name.' acima do threshold; parametro nao mapeado')
                    : 'Dentro dos limites configurados'
            ];
        }
        return $rows;
    }

    private function collectAssessmentItems(array $hostids): array {
        $output = ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock',
            'value_type', 'units', 'status', 'state', 'error'];
        $exact_items = API::Item()->get([
            'output' => $output,
            'hostids' => $hostids,
            'filter' => ['key_' => self::IMPORTANT_KEYS],
            'inherited' => true,
            'sortfield' => 'name'
        ]);
        $process_items = API::Item()->get([
            'output' => $output,
            'hostids' => $hostids,
            'search' => ['key_' => self::PROCESS_PREFIX],
            'startSearch' => true,
            'inherited' => true,
            'sortfield' => 'name'
        ]);

        return array_merge($exact_items, $process_items);
    }

    private function trendItems(array $items): array {
        return array_values(array_filter($items, static fn(array $item): bool =>
            in_array($item['value_type'], ['0', '3'], true)
            && (
                in_array($item['key_'], self::TREND_KEYS, true)
                || strpos($item['key_'], self::PROCESS_PREFIX) === 0
            )
        ));
    }

    private function collectConfigItems(array $hostids): array {
        if (!$hostids) {
            return [];
        }
        return API::Item()->get([
            'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue', 'lastclock',
                'value_type', 'units', 'state', 'status', 'error'],
            'hostids' => $hostids,
            'search' => ['key_' => 'num.'],
            'sortfield' => 'name'
        ]);
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
            'startSearch' => true,
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
        $profile = $this->profileStart('collectTrends', [
            'items' => count($items)
        ]);
        $numeric = array_values(array_filter($items, static fn(array $item): bool =>
            in_array($item['value_type'], ['0', '3'], true)
        ));
        $this->profileStep($profile, 'trend.numeric_items', [
            'numeric' => count($numeric),
            'batches' => count(array_chunk($numeric, 120))
        ]);
        $stats = [];
        foreach (array_chunk($numeric, 120) as $index => $batch) {
            try {
                $rows = API::Trend()->get([
                    'output' => ['itemid', 'num', 'value_avg'],
                    'itemids' => array_column($batch, 'itemid'),
                    'time_from' => $now - 30 * 86400,
                    'time_till' => $now
                ]);
                $this->profileStep($profile, 'trend.batch', [
                    'batch' => $index + 1,
                    'batch_items' => count($batch),
                    'rows' => count($rows)
                ]);
            }
            catch (Throwable $exception) {
                $this->profileException($profile, $exception);
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
        $this->profileFinish($profile);
        return $stats;
    }

    private function groupItemsByHost(array $items): array {
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item['hostid']][$item['key_']] = $item;
        }
        return $grouped;
    }

    private static function dedupeItems(array $items): array {
        $by_id = [];
        foreach ($items as $item) {
            $key = (string) ($item['itemid'] ?? '');
            if ($key === '') {
                $key = ($item['hostid'] ?? '').'|'.($item['key_'] ?? '');
            }
            $by_id[$key] = $item;
        }
        return array_values($by_id);
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

    private function inputHostGroupId(): string {
        $input = $this->getInput('host_groupid', '');
        if (is_array($input)) {
            $input = reset($input) ?: '';
        }
        $input = (string) $input;
        if ($input !== '') {
            return $input;
        }

        $groups = API::HostGroup()->get([
            'output' => ['groupid', 'name'],
            'filter' => ['name' => [self::DEFAULT_HOST_GROUP]],
            'limit' => 1
        ]);

        return $groups ? (string) $groups[0]['groupid'] : '';
    }

    private function inputHostId(string $name): string {
        $input = $this->getInput($name, '');
        if (is_array($input)) {
            $input = reset($input) ?: '';
        }

        return (string) $input;
    }

    private function inputTemplateId(string $name): string {
        $input = $this->getInput($name, '');
        if (is_array($input)) {
            $input = reset($input) ?: '';
        }

        return (string) $input;
    }

    private function defaultProxyTemplateId(): string {
        $templates = API::Template()->get([
            'output' => ['templateid', 'host', 'name'],
            'filter' => ['host' => ['Zabbix proxy health', 'Template App Zabbix Proxy']],
            'limit' => 1
        ]);

        if (!$templates) {
            $templates = API::Template()->get([
                'output' => ['templateid', 'host', 'name'],
                'filter' => ['name' => ['Zabbix proxy health', 'Template App Zabbix Proxy']],
                'limit' => 1
            ]);
        }

        return $templates ? (string) $templates[0]['templateid'] : '';
    }

    private function hostGroupName(string $groupid): string {
        if ($groupid === '') {
            return self::DEFAULT_HOST_GROUP;
        }

        $groups = API::HostGroup()->get([
            'output' => ['groupid', 'name'],
            'groupids' => [$groupid],
            'limit' => 1
        ]);

        return $groups ? (string) $groups[0]['name'] : self::DEFAULT_HOST_GROUP;
    }

    private function hostName(string $hostid): string {
        if ($hostid === '') {
            return '';
        }

        $hosts = API::Host()->get([
            'output' => ['hostid', 'host', 'name'],
            'hostids' => [$hostid],
            'limit' => 1
        ]);

        return $hosts ? (string) ($hosts[0]['name'] ?: $hosts[0]['host']) : '';
    }

    private function templateName(string $templateid): string {
        if ($templateid === '') {
            return '';
        }

        $templates = API::Template()->get([
            'output' => ['templateid', 'host', 'name'],
            'templateids' => [$templateid],
            'limit' => 1
        ]);

        return $templates ? (string) ($templates[0]['name'] ?: $templates[0]['host']) : '';
    }

    private function proxyTemplateFilterIds(array $settings): array {
        if (($settings['proxy_template_filter'] ?? 'Nao') !== 'Sim' || ($settings['proxy_templateid'] ?? '') === '') {
            return [];
        }

        $selected = (string) $settings['proxy_templateid'];
        $filter_ids = [$selected => true];
        if (($settings['proxy_template_indirect'] ?? 'Sim') !== 'Sim') {
            return array_keys($filter_ids);
        }

        $templates = API::Template()->get([
            'output' => ['templateid', 'host', 'name'],
            'selectParentTemplates' => ['templateid']
        ]);

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($templates as $template) {
                $templateid = (string) $template['templateid'];
                if (isset($filter_ids[$templateid])) {
                    continue;
                }

                foreach (($template['parentTemplates'] ?? []) as $parent) {
                    if (isset($filter_ids[(string) $parent['templateid']])) {
                        $filter_ids[$templateid] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        }

        return array_keys($filter_ids);
    }

    private function hostMatchesTemplateFilter(array $host, array $template_filter_ids): bool {
        $allowed = array_flip(array_map('strval', $template_filter_ids));
        foreach (($host['parentTemplates'] ?? []) as $template) {
            if (isset($allowed[(string) $template['templateid']])) {
                return true;
            }
        }

        return false;
    }

    private function profileStart(string $scope, array $context = []): array {
        $enabled = false;
        if (isset($context['profile_enabled'])) {
            $enabled = $context['profile_enabled'] === '1';
            unset($context['profile_enabled']);
        }
        else {
            $enabled = in_array($this->getInput('profile', ''), ['1', 'true', 'Sim'], true)
                || in_array($this->getInput('debug_profile', ''), ['1', 'true', 'Sim'], true);
        }

        $now = microtime(true);
        $profile = [
            'enabled' => $enabled,
            'scope' => $scope,
            'id' => substr(str_replace('.', '', uniqid('', true)), -10),
            'start' => $now,
            'last' => $now
        ];

        $this->profileLog($profile, 'start', $context);
        return $profile;
    }

    private function profileStep(array &$profile, string $step, array $context = []): void {
        if (!$profile['enabled']) {
            return;
        }

        $now = microtime(true);
        $context += [
            'step_ms' => round(($now - $profile['last']) * 1000, 2),
            'total_ms' => round(($now - $profile['start']) * 1000, 2),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ];
        $profile['last'] = $now;
        $this->profileLog($profile, $step, $context);
    }

    private function profileFinish(array &$profile): void {
        $this->profileStep($profile, 'finish');
    }

    private function profileException(array &$profile, Throwable $exception): void {
        $this->profileStep($profile, 'exception', [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);
    }

    private function profileLog(array $profile, string $event, array $context = []): void {
        if (!$profile['enabled']) {
            return;
        }

        error_log(self::PROFILE_PREFIX.' '.json_encode([
            'id' => $profile['id'],
            'scope' => $profile['scope'],
            'event' => $event,
            'context' => $context
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

    private static function positiveNum($value): ?float {
        $num = self::num($value);
        return $num !== null && $num > 0 ? $num : null;
    }

    private static function sizeToBytes($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMGT]?B?|[KMGT])?\s*$/i', (string) $value, $matches) !== 1) {
            return null;
        }
        $unit = strtoupper($matches[2] ?? 'B');
        if (in_array($unit, ['K', 'M', 'G', 'T'], true)) {
            $unit .= 'B';
        }
        $multipliers = [
            'B' => 1,
            'KB' => 1024,
            'MB' => 1024 ** 2,
            'GB' => 1024 ** 3,
            'TB' => 1024 ** 4
        ];
        return isset($multipliers[$unit])
            ? round((float) $matches[1] * $multipliers[$unit])
            : null;
    }

    private static function displayValue($value): string {
        if ($value === null || $value === '') {
            return '-';
        }
        $num = self::num($value);
        if ($num === null) {
            return (string) $value;
        }
        return abs($num - round($num)) < 0.0001
            ? (string) (int) round($num)
            : rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
    }

    private static function bytesToGib(?float $bytes): ?float {
        return $bytes !== null ? $bytes / 1024 / 1024 / 1024 : null;
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
