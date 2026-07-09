(() => {
    'use strict';

    const fmt = (value, digits = 1, suffix = '') => {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        const number = Number(value);
        return Number.isFinite(number) ? `${number.toFixed(digits)}${suffix}` : String(value);
    };

    const pct = (value) => value === null || value === undefined ? '—' : `${(Number(value) * 100).toFixed(2)}%`;
    const humanBytes = (value) => {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return String(value);
        }
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let current = Math.abs(number);
        let unit = 0;
        while (current >= 1024 && unit < units.length - 1) {
            current /= 1024;
            unit += 1;
        }
        const signed = number < 0 ? -current : current;
        const digits = unit === 0 || current >= 100 ? 0 : 1;
        return `${signed.toLocaleString('pt-BR', {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits
        })} ${units[unit]}`;
    };
    const normalize = (value) => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase();
    const cacheAction = (row) => {
        if (row.status !== 'Avaliar ajuste') {
            return row.action || row.finding || '—';
        }
        if (!row.config_param) {
            return row.action || row.finding || '—';
        }
        return `${row.cache}: avaliar ajuste de ${row.config_param} `
            + `(configurado=${row.config_value ?? '—'}; recomendado=${humanBytes(row.recommended_bytes)})`;
    };
    const splitSummary = (summary) => {
        const parts = [];
        let current = '';
        let depth = 0;

        String(summary || '').split('').forEach((character) => {
            if (character === '(') {
                depth += 1;
            }
            else if (character === ')' && depth > 0) {
                depth -= 1;
            }

            if (character === ';' && depth === 0) {
                if (current.trim() !== '') {
                    parts.push(current.trim());
                }
                current = '';
                return;
            }

            current += character;
        });

        if (current.trim() !== '') {
            parts.push(current.trim());
        }

        return parts;
    };
    const objectType = (proxy) => proxy.assessment_role === 'server' ? 'Zabbix Server' : 'Zabbix Proxy';

    class ProxyHealthPage {
        constructor(root) {
            this.root = root;
            this.data = this.decodePayload(root.dataset.proxyHealthPayload);
            this.search = root.querySelector('#proxy-health-search');
            this.cards = root.querySelector('#proxy-health-cards');
            this.exportMenu = root.querySelector('[data-proxy-export-menu]');
            this.exportToggle = root.querySelector('[data-proxy-export-toggle]');
            this.expanded = new Set();
            this.loading = this.createLoadingState();
            this.kpiFilter = null;
            this.excludedOpen = false;

            root.addEventListener('click', (event) => this.onClick(event));
            root.addEventListener('keydown', (event) => this.onKeyDown(event));
            document.addEventListener('click', (event) => this.onDocumentClick(event));
            this.search?.addEventListener('input', () => this.render());

            this.render();
            if (root.dataset.proxyHealthAsync === '1') {
                this.loadAssessment();
            }
        }

        decodePayload(payload) {
            if (!payload) {
                return {
                    proxies: [],
                    config_items: [],
                    process_config: [],
                    cache_config: [],
                    orphans: [],
                    active_problems: [],
                    excluded_offline: [],
                    settings: {}
                };
            }
            const bytes = Uint8Array.from(atob(payload), (character) => character.charCodeAt(0));
            return JSON.parse(new TextDecoder('utf-8').decode(bytes));
        }

        createLoadingState() {
            const panel = document.createElement('div');
            panel.className = 'proxy-health-loading';
            panel.innerHTML = `
                <div class="proxy-health-loading-title">Coletando assessment</div>
                <div class="proxy-health-loading-step" data-proxy-loading-step>Preparando requisicao</div>
                <div class="proxy-health-loading-bar"><span data-proxy-loading-bar style="width: 8%"></span></div>
                <div class="proxy-health-loading-percent" data-proxy-loading-percent>8%</div>
            `;
            this.root.prepend(panel);
            return {
                panel,
                step: panel.querySelector('[data-proxy-loading-step]'),
                bar: panel.querySelector('[data-proxy-loading-bar]'),
                percent: panel.querySelector('[data-proxy-loading-percent]')
            };
        }

        setLoading(step, percent) {
            if (!this.loading) {
                return;
            }
            this.loading.step.textContent = step;
            this.loading.bar.style.width = `${percent}%`;
            this.loading.percent.textContent = `${percent}%`;
        }

        async fetchAssessmentStage(stage, params = {}) {
            const url = new URL(window.location.href);
            const body = new URLSearchParams();
            body.set('proxy_async', '1');
            body.set('proxy_stage', stage);
            Object.entries(params).forEach(([key, value]) => {
                if (value !== null && value !== undefined) {
                    body.set(key, value);
                }
            });

            const response = await fetch(url.toString(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body
            });
            const html = await response.text();
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const freshRoot = doc.querySelector('[data-proxy-health-payload]');
            if (!freshRoot) {
                throw new Error('Payload do assessment nao encontrado na resposta');
            }
            const payload = this.decodePayload(freshRoot.dataset.proxyHealthPayload);
            if (payload.error) {
                throw new Error(payload.exception || payload.error);
            }
            return payload;
        }

        async loadAssessment() {
            try {
                this.setLoading('Preparando hosts, itens e configuracoes', 8);
                const init = await this.fetchAssessmentStage('init');
                let cursor = Number(init.cursor || 0);
                const total = Number(init.trend_items || 0);
                const clientState = init.client_state;
                const trendState = init.trend_state;
                const trendStats = {};
                const trendDays = Number(init.settings?.trend_days || this.data.settings?.trend_days || 30);

                if (!clientState || !trendState) {
                    throw new Error('Estado da coleta nao retornado pelo backend');
                }

                while (cursor < total) {
                    const percent = total > 0 ? Math.min(82, 18 + Math.round((cursor / total) * 64)) : 82;
                    const batches = init.total_batches ? ` (${Math.ceil(cursor / Number(init.batch_size || 30))}/${init.total_batches})` : '';
                    this.setLoading(`Coletando trends de ${trendDays} dias${batches}`, percent);
                    const trend = await this.fetchAssessmentStage('trend', {
                        proxy_trend_state: trendState,
                        proxy_cursor: String(cursor)
                    });
                    Object.assign(trendStats, trend.trend_stats || {});
                    cursor = Number(trend.cursor || cursor);
                    if (trend.done || cursor >= total) {
                        break;
                    }
                }

                this.setLoading('Consolidando score e recomendacoes', 88);
                this.data = await this.fetchAssessmentStage('finalize', {
                    proxy_state: clientState,
                    proxy_trends: JSON.stringify(trendStats)
                });
                this.setLoading('Renderizando painel', 100);
                this.render();
                window.setTimeout(() => this.loading?.panel.remove(), 450);
            }
            catch (error) {
                this.setLoading(`Falha na coleta: ${error.message}`, 100);
                this.loading?.panel.classList.add('is-error');
            }
        }

        onClick(event) {
            const tab = event.target.closest('[data-proxy-tab]');
            if (tab && this.root.contains(tab)) {
                this.selectTab(tab.dataset.proxyTab);
                return;
            }

            const kpi = event.target.closest('[data-proxy-kpi-filter]');
            if (kpi && this.root.contains(kpi)) {
                this.toggleKpiFilter(kpi.dataset.proxyKpiFilter);
                return;
            }

            const exportButton = event.target.closest('[data-proxy-export]');
            if (exportButton && this.root.contains(exportButton)) {
                this.closeExportMenu();
                this.exportReport(exportButton.dataset.proxyExport);
                return;
            }

            const exportToggle = event.target.closest('[data-proxy-export-toggle]');
            if (exportToggle && this.root.contains(exportToggle)) {
                this.toggleExportMenu();
                return;
            }

            if (event.target.closest('[data-proxy-clear-search]') && this.search) {
                this.search.value = '';
                this.search.focus();
                this.render();
                return;
            }

            const expand = event.target.closest('[data-proxy-expand]');
            if (expand && this.root.contains(expand)) {
                const host = expand.dataset.proxyExpand;
                if (this.expanded.has(host)) {
                    this.expanded.delete(host);
                }
                else {
                    this.expanded.add(host);
                }
                this.render();
            }
        }

        onKeyDown(event) {
            const kpi = event.target.closest('[data-proxy-kpi-filter]');
            if (kpi && this.root.contains(kpi) && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                this.toggleKpiFilter(kpi.dataset.proxyKpiFilter);
            }
        }

        onDocumentClick(event) {
            if (!this.root.contains(event.target)) {
                this.closeExportMenu();
            }
        }

        toggleExportMenu() {
            const open = !this.exportMenu?.classList.contains('is-open');
            this.exportMenu?.classList.toggle('is-open', open);
            this.exportToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        closeExportMenu() {
            this.exportMenu?.classList.remove('is-open');
            this.exportToggle?.setAttribute('aria-expanded', 'false');
        }

        selectTab(name) {
            this.root.querySelectorAll('[data-proxy-tab]').forEach((button) => {
                const selected = button.dataset.proxyTab === name;
                button.classList.toggle('is-selected', selected);
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            this.root.querySelectorAll('[data-proxy-pane]').forEach((pane) => {
                pane.classList.toggle('is-active', pane.dataset.proxyPane === name);
            });
        }

        toggleKpiFilter(name) {
            if (name === 'excluded') {
                this.excludedOpen = !this.excludedOpen;
                this.kpiFilter = null;
                this.render();
                return;
            }

            this.excludedOpen = false;
            this.kpiFilter = this.kpiFilter === name ? null : name;
            this.render();
        }

        searchFilteredProxies() {
            const needle = normalize(this.search?.value ?? '');
            return this.data.proxies.filter((proxy) =>
                needle === ''
                || normalize(proxy.host).includes(needle)
                || normalize(proxy.technical_name).includes(needle)
            );
        }

        filteredProxies(proxies) {
            if (!this.kpiFilter || this.kpiFilter === 'total') {
                return proxies;
            }

            return proxies.filter((proxy) => {
                if (this.kpiFilter === 'ok') {
                    return proxy.state === 'OK';
                }
                if (this.kpiFilter === 'attention') {
                    return proxy.state === 'Atencao';
                }
                if (this.kpiFilter === 'risk') {
                    return proxy.state === 'Risco' || proxy.state === 'Critico';
                }
                return true;
            });
        }

        render() {
            const baseProxies = this.searchFilteredProxies();
            const proxies = this.filteredProxies(baseProxies);
            this.renderKpis(baseProxies);
            this.renderExcludedPanel();
            this.renderCards(proxies);
            this.renderOverviewTable(proxies);
            this.renderConfigTables();
        }

        renderKpis(proxies) {
            const counts = {
                total: proxies.length,
                ok: proxies.filter((proxy) => proxy.state === 'OK').length,
                attention: proxies.filter((proxy) => proxy.state === 'Atencao').length,
                risk: proxies.filter((proxy) => proxy.state === 'Risco' || proxy.state === 'Critico').length,
                excluded: (this.data.excluded_offline || []).length
            };
            Object.entries(counts).forEach(([key, value]) => {
                const target = this.root.querySelector(`[data-proxy-kpi="${key}"]`);
                if (target) {
                    target.textContent = String(value);
                }
            });
            this.root.querySelectorAll('[data-proxy-kpi-filter]').forEach((card) => {
                const selected = card.dataset.proxyKpiFilter === 'excluded'
                    ? this.excludedOpen
                    : this.kpiFilter === card.dataset.proxyKpiFilter;
                card.classList.toggle('is-selected', selected);
                card.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
        }

        renderExcludedPanel() {
            const panel = this.root.querySelector('[data-proxy-excluded-panel]');
            if (!panel) {
                return;
            }

            panel.replaceChildren();
            panel.classList.toggle('is-open', this.excludedOpen);
            if (!this.excludedOpen) {
                return;
            }

            const excluded = this.data.excluded_offline || [];
            const title = document.createElement('h3');
            title.textContent = 'Proxies fora do escopo';
            panel.append(title);

            if (excluded.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'proxy-health-muted';
                empty.textContent = 'Nenhum proxy foi deixado de fora pelos filtros atuais.';
                panel.append(empty);
                return;
            }

            const table = document.createElement('table');
            table.className = 'proxy-health-table';
            table.innerHTML = `
                <thead>
                    <tr>
                        <th>Proxy</th>
                        <th>Motivo</th>
                        <th>Idade do ultimo acesso</th>
                    </tr>
                </thead>
                <tbody></tbody>
            `;
            const tbody = table.querySelector('tbody');
            excluded.forEach((row) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${this.escapeHtml(row.host || '—')}</td>
                    <td>${this.escapeHtml(row.reason || '—')}</td>
                    <td>${this.escapeHtml(this.ageLabel(row.lastaccess_age))}</td>
                `;
                tbody.append(tr);
            });
            panel.append(table);
        }

        ageLabel(seconds) {
            const value = Number(seconds);
            if (!Number.isFinite(value)) {
                return '—';
            }
            if (value < 60) {
                return `${Math.round(value)}s`;
            }
            if (value < 3600) {
                return `${Math.round(value / 60)}min`;
            }
            if (value < 86400) {
                return `${Math.round(value / 3600)}h`;
            }
            return `${Math.round(value / 86400)}d`;
        }

        escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        renderCards(proxies) {
            this.cards.replaceChildren();

            if (proxies.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'proxy-health-empty';
                empty.textContent = 'Nenhum proxy encontrado para o filtro atual.';
                this.cards.append(empty);
                return;
            }

            proxies.forEach((proxy) => {
                const card = document.createElement('article');
                card.className = `proxy-health-card is-${proxy.state.toLocaleLowerCase()}`;

                const score = document.createElement('div');
                score.className = 'proxy-health-score-ring';
                score.style.setProperty('--score', proxy.score);
                score.innerHTML = `<strong>${proxy.score}</strong><span>${proxy.state}</span>`;

                const title = document.createElement('h3');
                title.textContent = proxy.host;

                const meta = document.createElement('div');
                meta.className = 'proxy-health-card-meta';
                meta.textContent = `${objectType(proxy)} · Versao ${proxy.version || '—'} · VPS ${fmt(proxy.vps_current, 0)} · Mem ${fmt(proxy.memory_total_gb, 1, ' GB')}`;

                const bars = document.createElement('div');
                bars.className = 'proxy-health-bars';
                [
                    ['CPU', proxy.cpu_current],
                    ['Memoria', proxy.memory_current],
                    ['Disco', proxy.disk_current ?? proxy.disk_avg]
                ].forEach(([label, value]) => bars.append(this.bar(label, value)));

                const summary = this.summaryCards(proxy.summary);

                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'proxy-health-expand';
                toggle.dataset.proxyExpand = proxy.host;
                toggle.setAttribute('aria-expanded', this.expanded.has(proxy.host) ? 'true' : 'false');
                toggle.textContent = this.expanded.has(proxy.host) ? 'Ocultar leituras' : 'Expandir leituras';

                card.append(score, title, meta, bars, summary, toggle);

                if (this.expanded.has(proxy.host)) {
                    card.append(this.details(proxy.host));
                }

                this.cards.append(card);
            });
        }

        details(host) {
            const wrapper = document.createElement('div');
            wrapper.className = 'proxy-health-card-details';
            const processConfig = this.data.process_config.filter((row) => row.host === host);
            const cacheConfig = this.data.cache_config.filter((row) => row.host === host);
            const usedConfigKeys = new Set();
            processConfig.forEach((row) => {
                if (row.config_param) {
                    usedConfigKeys.add(row.config_param);
                }
                if (row.recommended_param) {
                    usedConfigKeys.add(row.recommended_param);
                }
            });
            cacheConfig.forEach((row) => {
                if (row.config_param) {
                    usedConfigKeys.add(row.config_param);
                }
                if (row.config_bytes_param) {
                    usedConfigKeys.add(row.config_bytes_param);
                }
                if (row.recommended_param) {
                    usedConfigKeys.add(row.recommended_param);
                }
            });

            const configurableProcessRows = processConfig
                .filter((row) => row.status !== 'Sem parametro configuravel')
                .map((row) => [
                    row.process,
                    fmt(row.current, 1, '%'),
                    fmt(row.avg30d, 1, '%'),
                    row.config_param || '—',
                    row.config_value ?? '—',
                    row.recommended_value ?? '—',
                    row.status,
                    row.action || '—'
                ]);
            const nonConfigurableProcessRows = processConfig
                .filter((row) => row.status === 'Sem parametro configuravel')
                .map((row) => [
                    row.process,
                    fmt(row.current, 1, '%'),
                    fmt(row.avg30d, 1, '%'),
                    row.status
                ]);
            const otherConfigRows = this.data.config_items
                .filter((row) => row.host === host && !usedConfigKeys.has(row.key))
                .map((row) => [row.key || row.name, row.value ?? '—']);

            wrapper.append(
                this.detailTable('Pollers e processos com configuracao equivalente',
                    ['Parametro', 'Leitura atual', 'Media trends', 'Item de configuracao', 'Configurado', 'Recomendado', 'Status', 'Acao sugerida'],
                    configurableProcessRows
                ),
                this.detailTable('Demais processos internos',
                    ['Parametro', 'Leitura atual', 'Media trends', 'Status'],
                    nonConfigurableProcessRows
                ),
                this.detailTable('Caches versus configuracao',
                    ['Cache', 'Uso atual', 'Media trends', 'Parametro', 'Configurado', 'Recomendado', 'Status', 'Acao sugerida'],
                    cacheConfig
                        .map((row) => [
                            row.cache, fmt(row.current, 1, '%'), fmt(row.avg30d, 1, '%'),
                            row.config_param || '—', row.config_value ?? '—',
                            humanBytes(row.recommended_bytes),
                            row.status, cacheAction(row)
                        ])
                ),
                this.detailTable('Outras configuracoes coletadas',
                    ['Parametro', 'Configurado'],
                    otherConfigRows
                )
            );

            return wrapper;
        }

        detailTable(title, headers, rows) {
            const section = document.createElement('section');
            section.className = 'proxy-health-detail-section';

            const heading = document.createElement('h4');
            heading.textContent = title;
            section.append(heading);

            const table = document.createElement('table');
            table.className = 'proxy-health-detail-table';
            const thead = document.createElement('thead');
            const headRow = document.createElement('tr');
            headers.forEach((label) => {
                const th = document.createElement('th');
                th.textContent = label;
                headRow.append(th);
            });
            thead.append(headRow);

            const tbody = document.createElement('tbody');
            if (rows.length === 0) {
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = headers.length;
                cell.textContent = 'Sem dados para este proxy.';
                row.append(cell);
                tbody.append(row);
            }
            else {
                rows.forEach((values) => {
                    const row = document.createElement('tr');
                    values.forEach((value) => {
                        const cell = document.createElement('td');
                        cell.textContent = value ?? '—';
                        row.append(cell);
                    });
                    tbody.append(row);
                });
            }

            table.append(thead, tbody);
            section.append(table);
            return section;
        }

        bar(label, value) {
            const row = document.createElement('div');
            row.className = 'proxy-health-bar-row';
            const width = Math.max(0, Math.min(100, Number(value) || 0));
            row.innerHTML = `<span></span><div><i style="width: ${width}%"></i></div><b></b>`;
            row.children[0].textContent = label;
            row.children[2].textContent = fmt(value, 1, '%');
            return row;
        }

        renderOverviewTable(proxies) {
            this.replaceRows('overview', proxies, (proxy) => [
                proxy.host,
                objectType(proxy),
                proxy.state,
                proxy.score,
                proxy.version || '—',
                fmt(proxy.vps_current, 0),
                pct(proxy.unsupported_pct),
                fmt(proxy.cpu_current, 1, '%'),
                fmt(proxy.memory_total_gb, 1, ' GB'),
                fmt(proxy.memory_current, 1, '%'),
                fmt(proxy.memory_avg, 1, '%'),
                fmt(proxy.disk_current, 1, '%'),
                proxy.summary
            ]);
        }

        renderConfigTables() {
        }

        exportReport(format) {
            const stamp = new Date().toISOString()
                .replace(/[-:]/g, '')
                .replace(/\..+/, '')
                .replace('T', '_');

            if (format === 'xlsx') {
                const workbook = this.xlsxWorkbook();
                if (workbook === null) {
                    return;
                }

                this.downloadFile(
                    `proxy_health_assessment_${stamp}.xlsx`,
                    workbook,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                );
                return;
            }

            if (format !== 'csv') {
                return;
            }

            const rows = this.exportRows();
            const headers = [
                'secao', 'proxy', 'tipo', 'parametro', 'leitura_atual', 'media_trends',
                'item_configuracao', 'configurado', 'recomendado', 'status',
                'acao_sugerida', 'valor', 'resumo'
            ];
            const csv = [
                headers,
                ...rows.map((row) => headers.map((header) => row[header] ?? ''))
            ]
                .map((row) => row.map((value) => this.csvEscape(value)).join(';'))
                .join('\r\n');
            this.downloadFile(`proxy_health_assessment_${stamp}.csv`, `\uFEFF${csv}`, 'text/csv;charset=utf-8');
        }

        usedConfigKeysByHost() {
            const usedByHost = new Map();
            const markUsed = (host, key) => {
                if (!key) {
                    return;
                }
                if (!usedByHost.has(host)) {
                    usedByHost.set(host, new Set());
                }
                usedByHost.get(host).add(key);
            };

            this.data.process_config.forEach((row) => {
                markUsed(row.host, row.config_param);
                markUsed(row.host, row.recommended_param);
            });
            this.data.cache_config.forEach((row) => {
                markUsed(row.host, row.config_param);
                markUsed(row.host, row.config_bytes_param);
                markUsed(row.host, row.recommended_param);
            });

            return usedByHost;
        }

        exportRows() {
            const rows = [];
            const add = (row) => rows.push(row);

            this.data.proxies.forEach((proxy) => {
                [
                    ['State', proxy.state],
                    ['Score', proxy.score],
                    ['Versao', proxy.version || '—'],
                    ['VPS atual', fmt(proxy.vps_current, 0)],
                    ['Unsupported %', pct(proxy.unsupported_pct)],
                    ['CPU atual', fmt(proxy.cpu_current, 1, '%')],
                    ['Memoria total', fmt(proxy.memory_total_gb, 1, ' GB')],
                    ['Memoria atual', fmt(proxy.memory_current, 1, '%')],
                    ['Memoria media trends', fmt(proxy.memory_avg, 1, '%')],
                    ['Disco atual', fmt(proxy.disk_current, 1, '%')]
                ].forEach(([parameter, value]) => add({
                    secao: 'Overview',
                    proxy: proxy.host,
                    tipo: objectType(proxy),
                    parametro: parameter,
                    valor: value,
                    status: proxy.state,
                    resumo: proxy.summary
                }));
                add({
                    secao: 'Overview',
                    proxy: proxy.host,
                    tipo: objectType(proxy),
                    parametro: 'Resumo',
                    valor: proxy.summary,
                    status: proxy.state,
                    resumo: proxy.summary
                });
            });

            this.data.process_config.forEach((row) => {
                const configurable = row.status !== 'Sem parametro configuravel';
                add({
                    secao: configurable ? 'Processos configuraveis' : 'Processos internos',
                    proxy: row.host,
                    parametro: row.process,
                    leitura_atual: fmt(row.current, 1, '%'),
                    media_trends: fmt(row.avg30d, 1, '%'),
                    item_configuracao: configurable ? (row.config_param || '—') : '',
                    configurado: configurable ? (row.config_value ?? '—') : '',
                    recomendado: configurable ? (row.recommended_value ?? '—') : '',
                    status: row.status,
                    acao_sugerida: configurable ? (row.action || '—') : ''
                });
            });

            this.data.cache_config.forEach((row) => add({
                secao: 'Caches',
                proxy: row.host,
                parametro: row.cache,
                leitura_atual: fmt(row.current, 1, '%'),
                media_trends: fmt(row.avg30d, 1, '%'),
                item_configuracao: row.config_param || '—',
                configurado: row.config_value ?? '—',
                recomendado: humanBytes(row.recommended_bytes),
                status: row.status,
                acao_sugerida: cacheAction(row)
            }));

            const usedByHost = this.usedConfigKeysByHost();
            this.data.config_items.forEach((row) => {
                if (usedByHost.get(row.host)?.has(row.key)) {
                    return;
                }
                add({
                    secao: 'Outras configuracoes',
                    proxy: row.host,
                    parametro: row.key || row.name,
                    configurado: row.value ?? '—',
                    valor: row.value ?? '—',
                    status: row.state || ''
                });
            });

            (this.data.excluded_offline || []).forEach((row) => {
                add({
                    secao: 'Fora do escopo',
                    proxy: row.host || '—',
                    parametro: 'Motivo',
                    valor: row.reason || '—',
                    status: 'Fora do escopo',
                    resumo: `${row.reason || '—'}; ultimo acesso=${this.ageLabel(row.lastaccess_age)}`
                });
            });

            return rows;
        }

        summaryCards(summaryText) {
            const wrapper = document.createElement('div');
            wrapper.className = 'proxy-health-summary-cards';

            splitSummary(summaryText).forEach((summary) => {
                const card = document.createElement('div');
                card.className = 'proxy-health-summary-card';

                const title = document.createElement('strong');
                const detail = document.createElement('span');
                const parts = this.summaryParts(summary);

                title.textContent = parts.title;
                detail.textContent = parts.detail;
                card.append(title, detail);
                wrapper.append(card);
            });

            return wrapper;
        }

        summaryParts(summary) {
            const colon = summary.indexOf(':');
            if (colon > 0 && colon < 60) {
                return {
                    title: summary.slice(0, colon).trim(),
                    detail: summary.slice(colon + 1).trim()
                };
            }

            const normalized = normalize(summary);
            const known = [
                ['disco', 'Disco'],
                ['cpu', 'CPU'],
                ['memoria', 'Memoria'],
                ['preprocessing queue', 'Preprocessing queue'],
                ['fila', 'Fila'],
                ['unsupported', 'Itens unsupported'],
                ['versao', 'Versao'],
                ['alerta', 'Alerta'],
                ['vps', 'VPS']
            ];
            const matched = known.find(([needle]) => normalized.includes(needle));

            return {
                title: matched ? matched[1] : 'Resumo',
                detail: summary
            };
        }

        xlsxWorkbook() {
            if (typeof XLSX === 'undefined') {
                window.alert('Biblioteca de exportacao XLSX nao carregada.');
                return null;
            }

            const workbook = XLSX.utils.book_new();
            this.spreadsheetSheets().forEach((sheet) => {
                const worksheet = XLSX.utils.aoa_to_sheet([sheet.headers, ...sheet.rows]);
                const widths = sheet.headers.map((header, index) => {
                    const values = [header, ...sheet.rows.map((row) => row[index] ?? '')];
                    const max = values.reduce((length, value) =>
                        Math.max(length, String(value).length),
                    10);

                    return {wch: Math.min(Math.max(max + 2, 12), 80)};
                });
                worksheet['!cols'] = widths;
                XLSX.utils.book_append_sheet(workbook, worksheet, this.sheetName(sheet.name));
            });

            return XLSX.write(workbook, {
                bookType: 'xlsx',
                type: 'array'
            });
        }

        spreadsheetSheets() {
            const usedByHost = this.usedConfigKeysByHost();

            return [
                {
                    name: 'Overview',
                    headers: [
                        'Proxy', 'Tipo', 'State', 'Score', 'Versao', 'VPS atual', 'Unsupported %',
                        'CPU atual', 'Mem total GB', 'Mem atual', 'Mem media trends',
                        'Disco atual', 'Resumo'
                    ],
                    rows: this.data.proxies.map((proxy) => [
                        proxy.host,
                        objectType(proxy),
                        proxy.state,
                        proxy.score,
                        proxy.version || '—',
                        fmt(proxy.vps_current, 0),
                        pct(proxy.unsupported_pct),
                        fmt(proxy.cpu_current, 1, '%'),
                        fmt(proxy.memory_total_gb, 1, ' GB'),
                        fmt(proxy.memory_current, 1, '%'),
                        fmt(proxy.memory_avg, 1, '%'),
                        fmt(proxy.disk_current, 1, '%'),
                        proxy.summary
                    ])
                },
                {
                    name: 'Processos Config',
                    headers: [
                        'Proxy', 'Parametro', 'Leitura atual', 'Media trends',
                        'Item de configuracao', 'Configurado', 'Recomendado',
                        'Status', 'Acao sugerida'
                    ],
                    rows: this.data.process_config
                        .filter((row) => row.status !== 'Sem parametro configuravel')
                        .map((row) => [
                            row.host,
                            row.process,
                            fmt(row.current, 1, '%'),
                            fmt(row.avg30d, 1, '%'),
                            row.config_param || '—',
                            row.config_value ?? '—',
                            row.recommended_value ?? '—',
                            row.status,
                            row.action || '—'
                        ])
                },
                {
                    name: 'Processos Internos',
                    headers: ['Proxy', 'Parametro', 'Leitura atual', 'Media trends', 'Status'],
                    rows: this.data.process_config
                        .filter((row) => row.status === 'Sem parametro configuravel')
                        .map((row) => [
                            row.host,
                            row.process,
                            fmt(row.current, 1, '%'),
                            fmt(row.avg30d, 1, '%'),
                            row.status
                        ])
                },
                {
                    name: 'Caches',
                    headers: [
                        'Proxy', 'Cache', 'Uso atual', 'Media trends', 'Parametro',
                        'Configurado', 'Recomendado', 'Status', 'Acao sugerida'
                    ],
                    rows: this.data.cache_config.map((row) => [
                        row.host,
                        row.cache,
                        fmt(row.current, 1, '%'),
                        fmt(row.avg30d, 1, '%'),
                        row.config_param || '—',
                        row.config_value ?? '—',
                        row.status === 'Avaliar ajuste' ? humanBytes(row.recommended_bytes) : '—',
                        row.status,
                        cacheAction(row)
                    ])
                },
                {
                    name: 'Outras Configs',
                    headers: ['Proxy', 'Parametro', 'Configurado', 'Estado'],
                    rows: this.data.config_items
                        .filter((row) => !usedByHost.get(row.host)?.has(row.key))
                        .map((row) => [
                            row.host,
                            row.key || row.name,
                            row.value ?? '—',
                            row.state || ''
                        ])
                },
                {
                    name: 'Fora do Escopo',
                    headers: ['Proxy', 'Motivo', 'Idade ultimo acesso'],
                    rows: (this.data.excluded_offline || []).map((row) => [
                        row.host || '—',
                        row.reason || '—',
                        this.ageLabel(row.lastaccess_age)
                    ])
                }
            ];
        }

        sheetName(name) {
            return String(name)
                .replace(/[\[\]:*?/\\]/g, ' ')
                .slice(0, 31);
        }

        csvEscape(value) {
            const text = String(value ?? '');
            return /[;"\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
        }

        downloadFile(filename, content, type) {
            const blob = new Blob([content], {type});
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.append(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        }

        replaceRows(name, rows, mapper) {
            const tbody = this.root.querySelector(`[data-proxy-table="${name}"]`);
            if (!tbody) {
                return;
            }
            tbody.replaceChildren();
            if (rows.length === 0) {
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = 13;
                cell.textContent = 'Sem dados.';
                row.append(cell);
                tbody.append(row);
                return;
            }
            rows.forEach((item) => {
                const tr = document.createElement('tr');
                mapper(item).forEach((value) => {
                    const td = document.createElement('td');
                    td.textContent = value ?? '—';
                    tr.append(td);
                });
                tbody.append(tr);
            });
        }
    }

    const init = () => {
        const root = document.getElementById('proxy-health-assessment');
        if (root) {
            new ProxyHealthPage(root);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    }
    else {
        init();
    }
})();
