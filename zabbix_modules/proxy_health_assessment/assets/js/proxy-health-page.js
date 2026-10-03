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
    const stateClass = (state) => ({OK: 'ok', Atencao: 'attention', Risco: 'risk', Critico: 'critical'})[state] || 'ok';
    const stateLabel = (state) => ({OK: 'OK', Atencao: 'Atenção', Risco: 'Risco', Critico: 'Crítico'})[state] || state;
    // Notas e descontos em pt-BR, com no maximo uma casa decimal ("62,1", "−7,8", "80").
    const points1 = (value) => {
        const number = Number(value);
        return Number.isFinite(number)
            ? number.toLocaleString('pt-BR', {maximumFractionDigits: 1})
            : '—';
    };
    const TREND_STATUS = {
        ceiling: ['is-limit', 'Teto'],
        threshold: ['is-threshold', 'Threshold'],
        growing: ['is-growing', 'Crescendo'],
        stable: ['is-stable', 'Estavel'],
        decreasing: ['is-decreasing', 'Descendo'],
        insufficient: ['is-insufficient', 'Dados insuficientes']
    };
    const TREND_GROUPS = {vm: 'Capacidade da VM', process: 'Processos', cache: 'Caches', load: 'Carga'};
    // Threshold = limite de trigger (cor); teto = limite fisico (100% ou numero de CPUs; gera card).
    const trendLimits = (row) => ({
        threshold: row.capacity ? null : Number(row.limit),
        ceiling: row.capacity ? Number(row.limit) : (row.unit === '%' ? 100 : null)
    });
    const trendStatus = (row) => {
        if (row.card) {
            return 'ceiling';
        }
        return row.status === 'limit' ? 'threshold' : row.status;
    };
    const shortDays = (days) => days === null || days === undefined ? '' : `~${Math.max(1, Math.round(days))}d`;
    const trendStatusLabel = (row, status) => {
        if (status === 'ceiling') {
            return row.card_days !== null && row.card_days !== undefined && row.card_days <= 0
                ? 'Ja no teto'
                : `Teto em ${shortDays(row.card_days)}`;
        }
        if (status === 'threshold') {
            return row.days_to_limit !== null && row.days_to_limit !== undefined && row.days_to_limit <= 0
                ? 'Acima do threshold'
                : `Threshold em ${shortDays(row.days_to_limit)}`;
        }
        return (TREND_STATUS[status] || TREND_STATUS.insufficient)[1];
    };
    const trendValue = (value, unit) => value === null || value === undefined
        ? '—'
        : `${Number(value).toLocaleString('pt-BR', {maximumFractionDigits: unit === '%' ? 1 : 2})}${unit}`;
    const slopeLabel = (slope, unit) => {
        if (slope === null || slope === undefined) {
            return '—';
        }
        const value = Number(slope);
        const suffix = unit === '%' ? '%/dia' : '/dia';
        if (Math.abs(value) < (unit === '%' ? 0.005 : 0.0005)) {
            return `≈ 0${suffix}`;
        }
        const text = Math.abs(value) < 1
            ? value.toLocaleString('pt-BR', {maximumSignificantDigits: 2})
            : value.toLocaleString('pt-BR', {maximumFractionDigits: 1});
        return `${value > 0 ? '+' : ''}${text}${suffix}`;
    };
    const daysLabel = (days) => days === null || days === undefined
        ? ''
        : (days <= 0 ? 'ja no limite' : `em ~${Math.max(1, Math.round(days))} dias`);
    const TREND_ICON = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 3v11"></path><path d="M12 19.5v.5"></path></svg>'
        + '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>';
    const percentFormat = (value) => `${value.toLocaleString('pt-BR', {minimumFractionDigits: 1, maximumFractionDigits: 1})}%`;
    const countFormat = (value) => Math.round(value).toLocaleString('pt-BR');
    // Colunas da tabela: campo do payload e os settings de atencao/critico usados na regua de cada barra.
    const METRIC_COLUMNS = [
        {label: 'CPU P95', field: 'cpu_p95', warn: 'cpu_p95_max', crit: 'cpu_p95_crit', percent: true, format: percentFormat},
        {label: 'Mem P95', field: 'memory_p95', warn: 'memory_p95_max', crit: 'memory_p95_crit', percent: true, format: percentFormat},
        {label: 'Mem média', field: 'memory_avg', warn: 'memory_avg_max', crit: 'memory_avg_crit', percent: true, format: percentFormat},
        {label: 'Disco P95', field: 'disk_p95', warn: 'disk_p95_max', crit: 'disk_p95_crit', percent: true, format: percentFormat},
        {label: 'VPS P95', field: 'vps_p95', warn: 'vps_max', crit: 'vps_crit', format: countFormat},
        {label: 'Fila 10m', field: 'queue_10m_p95', warn: 'queue_10m_max', crit: 'queue_10m_crit', format: countFormat},
        {label: 'Unsup.', field: 'unsupported_pct', factor: 100, warn: 'unsupported_max_percent', crit: 'unsupported_crit_percent', percent: true, format: percentFormat}
    ];

    class ProxyHealthPage {
        constructor(root) {
            this.root = root;
            this.bootError = null;
            try {
                this.data = this.decodePayload(root.dataset.proxyHealthPayload);
            }
            catch (error) {
                console.error('Proxy Health Assessment: failed to decode initial payload.', error);
                this.bootError = error;
                this.data = this.emptyPayload();
            }
            this.search = root.querySelector('#proxy-health-search');
            this.cards = root.querySelector('#proxy-health-cards');
            this.exportMenu = root.querySelector('[data-proxy-export-menu]');
            this.exportToggle = root.querySelector('[data-proxy-export-toggle]');
            this.expanded = new Set();
            this.loading = this.createLoadingState();
            this.kpiFilter = null;
            this.excludedOpen = false;
            this.openHost = undefined;
            this.detailTabs = {};

            root.addEventListener('click', (event) => this.onClick(event));
            root.querySelector('#consider_config')?.addEventListener('change', () => this.syncOptionalFields());
            root.addEventListener('keydown', (event) => this.onKeyDown(event));
            document.addEventListener('click', (event) => this.onDocumentClick(event));
            this.search?.addEventListener('input', () => this.render());

            // Enquanto a coleta assincrona roda, a tabela fica vazia sem dizer "nenhum proxy".
            this.collecting = root.dataset.proxyHealthAsync === '1';
            this.render();
            if (this.collecting) {
                this.loadAssessment();
            }
        }

        emptyPayload() {
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

        decodePayload(payload) {
            if (!payload) {
                return this.emptyPayload();
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
                this.collecting = false;
                this.render();
                window.setTimeout(() => this.loading?.panel.remove(), 450);
            }
            catch (error) {
                this.setLoading(`Falha na coleta: ${error.message}`, 100);
                this.loading?.panel.classList.add('is-error');
                this.collecting = false;
                this.collectFailed = true;
                this.render();
            }
        }

        onClick(event) {
            const tab = event.target.closest('[data-proxy-tab]');
            if (tab && this.root.contains(tab)) {
                this.selectTab(tab.dataset.proxyTab);
                if (tab.dataset.proxyFocus) {
                    this.focusConfigField(tab.dataset.proxyFocus);
                }
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

            const expand = event.target.closest('[data-proxy-expand]');
            if (expand && this.root.contains(expand)) {
                const host = expand.dataset.proxyExpand;
                const tab = expand.dataset.proxyOpenTab;
                const current = this.detailTabs[host] === 'trends' ? 'trends' : 'readings';
                const wanted = tab === 'trends' ? 'trends' : 'readings';
                if (this.expanded.has(host) && (!tab || current === wanted)) {
                    this.expanded.delete(host);
                }
                else {
                    this.expanded.add(host);
                    if (tab) {
                        this.detailTabs[host] = tab;
                    }
                }
                this.render();
                return;
            }

            const idleToggle = event.target.closest('[data-proxy-trend-idle]');
            if (idleToggle && this.root.contains(idleToggle)) {
                this.trendIdleOpen = this.trendIdleOpen || new Set();
                const key = idleToggle.dataset.proxyTrendIdle;
                if (this.trendIdleOpen.has(key)) {
                    this.trendIdleOpen.delete(key);
                }
                else {
                    this.trendIdleOpen.add(key);
                }
                this.render();
                return;
            }

            const detailTab = event.target.closest('[data-proxy-detail-tab]');
            if (detailTab && this.root.contains(detailTab)) {
                this.detailTabs[detailTab.dataset.proxyHost] = detailTab.dataset.proxyDetailTab;
                this.render();
                return;
            }

            const selected = event.target.closest('[data-proxy-select]');
            if (selected && this.root.contains(selected) && !event.target.closest('a')) {
                const host = selected.dataset.proxySelect;
                this.openHost = this.openHost === host ? null : host;
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

        // Leva o usuario ao campo da configuracao (ex.: host group vazio quando o padrao nao existe).
        focusConfigField(name) {
            const row = this.root.querySelector(`[data-proxy-pane="config"] #proxy-health-field-${name.replace(/_/g, '-').replace('-groupid', '-group')}`);
            if (!row) {
                return;
            }
            row.classList.add('is-highlighted');
            row.scrollIntoView({block: 'center'});
            row.querySelector(`#${name}_ms, input:not([type="hidden"])`)?.focus();
            window.setTimeout(() => row.classList.remove('is-highlighted'), 2500);
        }

        // Regra opcional "configuracao do proxy": os thresholds so ficam editaveis com a regra ligada.
        syncOptionalFields() {
            const toggle = this.root.querySelector('#consider_config');
            this.root.querySelectorAll('#poller_threshold, #cache_threshold').forEach((input) => {
                input.disabled = !toggle?.checked;
            });
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
                this.render();
                if (this.excludedOpen) {
                    this.root.querySelector('[data-proxy-excluded-panel]')?.scrollIntoView({block: 'nearest'});
                }
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
            this.renderDistribution(baseProxies);
            this.renderCards(proxies);
            this.renderExcludedPanel();
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
                    target.textContent = this.collecting || this.collectFailed ? '—' : String(value);
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

        renderDistribution(proxies) {
            const bar = this.root.querySelector('[data-proxy-distribution-bar]');
            const summary = this.root.querySelector('[data-proxy-distribution-summary]');
            const excluded = (this.data.excluded_offline || []).length;
            const groups = [
                ['ok', proxies.filter((proxy) => proxy.state === 'OK').length],
                ['attention', proxies.filter((proxy) => proxy.state === 'Atencao').length],
                ['risk', proxies.filter((proxy) => proxy.state === 'Risco' || proxy.state === 'Critico').length],
                ['excluded', excluded]
            ];

            if (bar) {
                bar.replaceChildren();
                groups.filter(([, count]) => count > 0).forEach(([name, count]) => {
                    const segment = document.createElement('span');
                    segment.className = `is-${name}`;
                    segment.style.flexGrow = String(count);
                    bar.append(segment);
                });
            }

            const totalLabel = this.root.querySelector('[data-proxy-kpi-total-label]');
            if (totalLabel) {
                totalLabel.textContent = proxies.length === 1 ? ' avaliado' : ' avaliados';
            }

            if (summary) {
                if (proxies.length === 0) {
                    summary.textContent = '';
                    return;
                }
                const worst = proxies.reduce((min, proxy) => Number(proxy.score) < Number(min.score) ? proxy : min, proxies[0]);
                const counts = new Map();
                proxies.forEach((proxy) => this.deductions(proxy.summary).forEach((deduction) => {
                    counts.set(deduction.label, (counts.get(deduction.label) || 0) + 1);
                }));
                const common = [...counts.entries()].sort((a, b) => b[1] - a[1])[0];
                summary.textContent = `Pior nota ${points1(worst.score)} (${worst.host})`
                    + (common ? ` · desconto mais comum: ${common[0]}` : ' · nenhum desconto aplicado');
            }
        }

        renderExcludedPanel() {
            const panel = this.root.querySelector('[data-proxy-excluded-panel]');
            if (!panel) {
                return;
            }

            panel.replaceChildren();
            const excluded = this.data.excluded_offline || [];
            panel.hidden = excluded.length === 0;
            panel.classList.toggle('is-open', this.excludedOpen);
            if (excluded.length === 0) {
                return;
            }

            const head = document.createElement('button');
            head.type = 'button';
            head.className = 'proxy-health-excluded-head';
            head.dataset.proxyKpiFilter = 'excluded';
            head.setAttribute('aria-expanded', this.excludedOpen ? 'true' : 'false');
            head.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6l6 6-6 6"></path></svg><span class="proxy-health-swatch is-excluded"></span><strong></strong><span class="proxy-health-muted"></span>';
            head.querySelector('strong').textContent = `${excluded.length} fora do escopo`;
            head.querySelector('.proxy-health-muted').textContent = excluded
                .map((row) => `${row.host || '—'} (${this.ageLabel(row.lastaccess_age)})`)
                .join(' · ');
            panel.append(head);

            if (!this.excludedOpen) {
                return;
            }

            const table = document.createElement('table');
            table.className = 'proxy-health-table';
            table.innerHTML = `
                <thead>
                    <tr>
                        <th>Proxy</th>
                        <th>Motivo</th>
                        <th>Ultimo contato ha</th>
                    </tr>
                </thead>
                <tbody></tbody>
            `;
            const tbody = table.querySelector('tbody');
            excluded.forEach((row) => {
                const tr = document.createElement('tr');
                [row.host || '—', row.reason || '—', this.ageLabel(row.lastaccess_age)].forEach((value) => {
                    const td = document.createElement('td');
                    td.textContent = value;
                    tr.append(td);
                });
                tbody.append(tr);
            });
            panel.append(table);
        }

        ageLabel(seconds) {
            if (seconds === null || seconds === undefined || seconds === '') {
                return '—';
            }
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

            const notice = this.hostGroupNotice();
            if (notice) {
                this.cards.append(notice);
            }

            if (this.collecting) {
                return;
            }

            if (proxies.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'proxy-health-empty';
                empty.textContent = this.collectFailed
                    ? 'A coleta nao foi concluida; veja o erro acima.'
                    : 'Nenhum proxy encontrado para o filtro atual.';
                this.cards.append(empty);
                return;
            }

            // Na primeira renderizacao abre o pior objeto quando ele nao esta OK; depois respeita o usuario.
            if (this.openHost === undefined) {
                this.openHost = proxies[0].state !== 'OK' ? proxies[0].host : null;
            }

            const wrapper = document.createElement('div');
            wrapper.className = 'proxy-health-grid-wrap';
            const table = document.createElement('table');
            table.className = 'proxy-health-grid';

            const thead = document.createElement('thead');
            const headRow = document.createElement('tr');
            headRow.append(this.headCell('Objeto', 'is-left'), this.headCell('Nota', 'is-left'));
            METRIC_COLUMNS.forEach((column) => headRow.append(this.headCell(column.label)));
            headRow.append(this.headCell('Descontos', 'is-left'));
            thead.append(headRow);

            const tbody = document.createElement('tbody');
            proxies.forEach((proxy) => {
                const open = proxy.host === this.openHost;
                tbody.append(this.objectRow(proxy, open));
                if (open) {
                    tbody.append(this.detailRow(proxy));
                }
            });

            table.append(thead, tbody);
            wrapper.append(table);
            this.cards.append(wrapper);
        }

        headCell(label, className = '') {
            const th = document.createElement('th');
            th.scope = 'col';
            th.textContent = label;
            if (className) {
                th.className = className;
            }
            return th;
        }

        hostGroupNotice() {
            const settings = this.data.settings || {};
            if (!settings.host_group_missing) {
                return null;
            }

            const notice = document.createElement('div');
            notice.className = 'proxy-health-notice';
            notice.setAttribute('role', 'status');
            const text = document.createElement('span');
            text.textContent = settings.host_group_source === 'invalid'
                ? 'O host group informado nao existe mais, entao nenhum proxy foi avaliado. Escolha o grupo de proxies; a escolha fica salva para as proximas visitas.'
                : `O grupo padrao "${settings.host_group_default || 'Zabbix/Proxies'}" nao existe neste Zabbix, entao nenhum proxy foi avaliado. Escolha o grupo de proxies; a escolha fica salva para as proximas visitas.`;
            const action = document.createElement('button');
            action.type = 'button';
            action.className = 'proxy-health-notice-action';
            action.dataset.proxyTab = 'config';
            action.dataset.proxyFocus = 'host_groupid';
            action.textContent = 'Escolher grupo de proxies';
            notice.append(text, action);
            return notice;
        }

        objectRow(proxy, open) {
            const row = document.createElement('tr');
            row.className = `proxy-health-row is-${stateClass(proxy.state)}${open ? ' is-open' : ''}`;
            row.dataset.proxySelect = proxy.host;

            const objectCell = document.createElement('td');
            objectCell.className = 'is-left proxy-health-object';
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'proxy-health-row-toggle';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6l6 6-6 6"></path></svg><span></span>';
            toggle.querySelector('span').textContent = proxy.host;
            const alerts = proxy.trend_alerts || [];
            if (alerts.length > 0) {
                const badge = document.createElement('span');
                badge.className = 'proxy-health-trend-badge';
                badge.innerHTML = TREND_ICON;
                badge.title = alerts.map((alert) => this.trendAlertTitle(alert)).join('\n');
                badge.setAttribute('aria-label', `Tendencia: ${badge.title}`);
                toggle.append(badge);
            }
            const meta = document.createElement('div');
            meta.className = 'proxy-health-object-meta';
            meta.textContent = [
                proxy.assessment_role === 'server' ? 'Server' : 'Proxy',
                proxy.version || 'versao —',
                Number(proxy.memory_total_gb) > 0
                    ? `${Number(proxy.memory_total_gb).toLocaleString('pt-BR', {maximumFractionDigits: 1})} GB RAM`
                    : null
            ].filter(Boolean).join(' · ');
            objectCell.append(toggle, meta);

            const scoreCell = document.createElement('td');
            scoreCell.className = 'is-left';
            const score = Number(proxy.score);
            const scoreBox = document.createElement('div');
            scoreBox.className = 'proxy-health-score';
            const scoreValue = document.createElement('strong');
            scoreValue.textContent = points1(score);
            const scoreSide = document.createElement('div');
            const stateText = document.createElement('span');
            stateText.className = 'proxy-health-state';
            stateText.textContent = stateLabel(proxy.state);
            const scoreMeter = document.createElement('div');
            scoreMeter.className = 'proxy-health-meter';
            const scoreFill = document.createElement('i');
            scoreFill.style.width = `${Math.max(0, Math.min(100, score || 0))}%`;
            scoreMeter.append(scoreFill);
            scoreSide.append(stateText, scoreMeter);
            scoreBox.append(scoreValue, scoreSide);
            scoreCell.append(scoreBox);

            row.append(objectCell, scoreCell);
            METRIC_COLUMNS.forEach((column) => row.append(this.metricCell(proxy, column)));
            row.append(this.deductionCell(proxy));
            return row;
        }

        metricCell(proxy, column) {
            const cell = document.createElement('td');
            const raw = proxy[column.field];
            const value = raw === null || raw === undefined || raw === '' ? null : Number(raw) * (column.factor || 1);
            const settings = this.data.settings || {};
            const warn = Number(settings[column.warn]);
            const crit = Number(settings[column.crit]);

            const label = document.createElement('span');
            label.className = 'proxy-health-num';
            label.textContent = value === null || !Number.isFinite(value) ? '—' : column.format(value);
            cell.append(label);

            if (value === null || !Number.isFinite(value) || !Number.isFinite(warn)) {
                return cell;
            }

            const scale = column.percent ? 100 : Math.max(Number.isFinite(crit) ? crit : 0, warn * 2, value, 1);
            const level = Number.isFinite(crit) && crit > warn && value >= crit
                ? 'is-risk'
                : (value > warn ? 'is-attention' : 'is-ok');
            const meter = document.createElement('div');
            meter.className = `proxy-health-meter ${level}`;
            meter.title = `Limite de atencao ${column.format(warn)}${Number.isFinite(crit) ? ` · critico ${column.format(crit)}` : ''}`;
            const fill = document.createElement('i');
            fill.style.width = `${Math.max(1, Math.min(100, (value / scale) * 100))}%`;
            const tick = document.createElement('b');
            tick.style.left = `${Math.min(100, (warn / scale) * 100)}%`;
            meter.append(fill, tick);
            cell.append(meter);
            return cell;
        }

        deductionCell(proxy) {
            const cell = document.createElement('td');
            cell.className = 'is-left';
            const deductions = this.deductions(proxy.summary);
            if (deductions.length === 0) {
                const none = document.createElement('span');
                none.className = 'proxy-health-muted';
                none.textContent = 'Sem descontos';
                cell.append(none);
                return cell;
            }

            const list = document.createElement('div');
            list.className = 'proxy-health-chips';
            deductions.slice(0, 3).forEach((deduction) => {
                const chip = document.createElement('span');
                chip.className = `proxy-health-chip is-${stateClass(proxy.state)}`;
                const points = document.createElement('b');
                points.textContent = `−${points1(deduction.points)}`;
                const text = document.createElement('span');
                text.textContent = deduction.label;
                chip.append(points, text);
                list.append(chip);
            });
            if (deductions.length > 3) {
                const more = document.createElement('span');
                more.className = 'proxy-health-muted';
                more.textContent = `+${deductions.length - 3}`;
                list.append(more);
            }
            cell.append(list);
            return cell;
        }

        // "Alerta: Alerta de saude do proxy ativo (-20)" -> {label: 'Alerta de saude do proxy ativo', points: 20}.
        // Itens sem "(-X)" sao achados que nao descontam pontos.
        deductions(summaryText) {
            return splitSummary(summaryText)
                .map((summary) => {
                    const match = summary.match(/^(.*?)\s*\(\s*-\s*([\d.,]+)\s*\)\s*$/);
                    if (!match) {
                        return null;
                    }
                    const points = Number(match[2].replace(',', '.'));
                    return Number.isFinite(points) && points > 0
                        ? {label: match[1].replace(/^[^:]{1,40}:\s*/, '').trim(), points}
                        : null;
                })
                .filter(Boolean)
                .sort((a, b) => b.points - a.points);
        }

        findings(summaryText) {
            return splitSummary(summaryText)
                .filter((summary) => !/\(\s*-\s*[\d.,]+\s*\)\s*$/.test(summary))
                .filter((summary) => !/dentro dos parametros/i.test(summary));
        }

        detailRow(proxy) {
            const row = document.createElement('tr');
            row.className = `proxy-health-detail-row is-${stateClass(proxy.state)}`;
            const cell = document.createElement('td');
            cell.colSpan = METRIC_COLUMNS.length + 3;

            const alerts = proxy.trend_alerts || [];
            if (alerts.length > 0) {
                const cards = document.createElement('div');
                cards.className = 'proxy-health-trend-cards';
                alerts.forEach((alert) => cards.append(this.trendCard(alert)));
                cell.append(cards);
            }

            const grid = document.createElement('div');
            grid.className = 'proxy-health-detail-grid';
            grid.append(this.scoreComposition(proxy), this.problemList(proxy));
            cell.append(grid);

            const expanded = this.expanded.has(proxy.host);
            const showingTrends = expanded && this.detailTabs[proxy.host] === 'trends';
            const actions = document.createElement('div');
            actions.className = 'proxy-health-detail-actions';
            const readings = document.createElement('button');
            readings.type = 'button';
            readings.className = 'proxy-health-link-button';
            readings.dataset.proxyExpand = proxy.host;
            readings.dataset.proxyOpenTab = 'process_config';
            readings.setAttribute('aria-expanded', expanded && !showingTrends ? 'true' : 'false');
            readings.textContent = expanded && !showingTrends ? 'Ocultar leituras de processos e caches' : 'Ver leituras de processos e caches';
            const trendsButton = document.createElement('button');
            trendsButton.type = 'button';
            trendsButton.className = 'proxy-health-link-button';
            trendsButton.dataset.proxyExpand = proxy.host;
            trendsButton.dataset.proxyOpenTab = 'trends';
            trendsButton.setAttribute('aria-expanded', showingTrends ? 'true' : 'false');
            trendsButton.textContent = showingTrends ? 'Ocultar tendencias' : 'Ver tendencias';
            if (alerts.length > 0) {
                const count = document.createElement('span');
                count.className = 'proxy-health-trend-count';
                count.textContent = String(alerts.length);
                trendsButton.append(' ', count);
            }
            actions.append(readings, trendsButton);
            cell.append(actions);
            if (expanded) {
                cell.append(this.details(proxy.host));
            }

            row.append(cell);
            return row;
        }

        scoreComposition(proxy) {
            const section = document.createElement('section');
            section.className = 'proxy-health-detail-block';
            const title = document.createElement('h4');
            title.textContent = 'Composicao da nota';

            const score = Math.max(0, Number(proxy.score) || 0);
            const deductions = this.deductions(proxy.summary);
            const bar = document.createElement('div');
            bar.className = 'proxy-health-composition';
            const remaining = document.createElement('div');
            remaining.className = `is-remaining is-${stateClass(proxy.state)}`;
            remaining.style.width = `${score}%`;
            remaining.textContent = `${points1(score)} restantes`;
            bar.append(remaining);
            deductions.forEach((deduction) => {
                const part = document.createElement('div');
                part.className = 'is-deduction';
                part.style.width = `${deduction.points}%`;
                part.title = `${deduction.label} (−${points1(deduction.points)})`;
                part.textContent = deduction.points >= 6 ? `−${points1(deduction.points)}` : '';
                bar.append(part);
            });

            const explain = document.createElement('p');
            explain.className = 'proxy-health-muted';
            explain.textContent = deductions.length === 0
                ? 'Partiu de 100 e nenhuma regra descontou pontos.'
                : `Partiu de 100. ${deductions.map((d) => `${d.label} (−${points1(d.points)})`).join('; ')}.`;
            section.append(title, bar, explain);

            const findings = this.findings(proxy.summary);
            if (findings.length > 0) {
                const label = document.createElement('h4');
                label.textContent = 'Recomendacoes sem desconto';
                const list = document.createElement('ul');
                list.className = 'proxy-health-findings';
                findings.forEach((finding) => {
                    const item = document.createElement('li');
                    item.textContent = finding;
                    list.append(item);
                });
                section.append(label, list);
            }
            return section;
        }

        problemList(proxy) {
            const section = document.createElement('section');
            section.className = 'proxy-health-detail-block';
            const problems = (this.data.active_problems || [])
                .filter((problem) => problem.host === proxy.host || problem.host === proxy.technical_name)
                .sort((a, b) => (b.relevant === 'Sim') - (a.relevant === 'Sim') || b.severity_num - a.severity_num);
            const relevant = problems.filter((problem) => problem.relevant === 'Sim').length;

            const title = document.createElement('h4');
            title.textContent = problems.length === 0
                ? 'Problemas ativos'
                : `Problemas ativos · ${problems.length}${relevant ? ` (${relevant} contam na nota)` : ''}`;
            section.append(title);

            if (problems.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'proxy-health-muted';
                empty.textContent = 'Nenhum problema ativo neste objeto.';
                section.append(empty);
                return section;
            }

            const list = document.createElement('ul');
            list.className = 'proxy-health-problems';
            problems.slice(0, 6).forEach((problem) => {
                const item = document.createElement('li');
                if (problem.relevant !== 'Sim') {
                    item.className = 'is-muted';
                }
                const dot = document.createElement('span');
                dot.className = `proxy-health-dot is-sev-${Math.max(0, Math.min(5, Number(problem.severity_num) || 0))}`;
                const name = document.createElement('span');
                name.className = 'proxy-health-problem-name';
                // O Zabbix prefixa o nome com "<host>: "; o objeto ja esta no contexto da linha.
                const prefix = `${proxy.host}: `;
                name.textContent = problem.name.startsWith(prefix) ? problem.name.slice(prefix.length) : problem.name;
                name.title = problem.name;
                const age = document.createElement('span');
                age.className = 'proxy-health-num';
                age.textContent = problem.age || problem.clock || '';
                item.append(dot, name, age);
                list.append(item);
            });
            section.append(list);

            if (proxy.hostid) {
                const url = new URL('zabbix.php', window.location.href);
                url.search = '';
                url.searchParams.set('action', 'problem.view');
                url.searchParams.set('filter_set', '1');
                url.searchParams.append('hostids[]', proxy.hostid);
                const link = document.createElement('a');
                link.className = 'proxy-health-link';
                link.href = url.toString();
                link.textContent = problems.length > 6 ? `Ver os ${problems.length} problemas no Zabbix` : 'Abrir em Problemas';
                section.append(link);
            }
            return section;
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

            const trendProxy = (this.data.proxies || []).find((row) => row.host === host);
            const trendByName = new Map((trendProxy?.trends || [])
                .filter((row) => row.group === 'process' || row.group === 'cache')
                .map((row) => [`${row.group}:${row.label}`, row]));
            const miniTrend = (group, name) => this.miniSparkline(trendByName.get(`${group}:${name}`));

            const configurableProcessRows = processConfig
                .filter((row) => row.status !== 'Sem parametro configuravel')
                .map((row) => [
                    row.process,
                    fmt(row.p95, 1, '%'),
                    fmt(row.avg30d, 1, '%'),
                    miniTrend('process', row.process),
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
                    fmt(row.p95, 1, '%'),
                    fmt(row.avg30d, 1, '%'),
                    miniTrend('process', row.process),
                    row.status
                ]);
            const otherConfigRows = this.data.config_items
                .filter((row) => row.host === host && !usedConfigKeys.has(row.key))
                .map((row) => [row.key || row.name, row.value ?? '—']);

            const sections = [
                {
                    id: 'process_config',
                    label: 'Processos x config',
                    table: this.detailTable('Pollers e processos com configuracao equivalente',
                    ['Parametro', 'P95', 'Media trends', '30 dias', 'Item de configuracao', 'Configurado', 'Recomendado', 'Status', 'Acao sugerida'],
                    configurableProcessRows
                    )
                },
                {
                    id: 'internal_processes',
                    label: 'Processos internos',
                    table: this.detailTable('Demais processos internos',
                    ['Parametro', 'P95', 'Media trends', '30 dias', 'Status'],
                    nonConfigurableProcessRows
                    )
                },
                {
                    id: 'caches',
                    label: 'Caches',
                    table: this.detailTable('Caches versus configuracao',
                    ['Cache', 'Uso P95', 'Media trends', '30 dias', 'Parametro', 'Configurado', 'Recomendado', 'Status', 'Acao sugerida'],
                    cacheConfig
                        .map((row) => [
                            row.cache, fmt(row.p95, 1, '%'), fmt(row.avg30d, 1, '%'),
                            miniTrend('cache', row.cache),
                            row.config_param || '—', row.config_value ?? '—',
                            humanBytes(row.recommended_bytes),
                            row.status, cacheAction(row)
                        ])
                    )
                },
                {
                    id: 'other_config',
                    label: 'Outras configs',
                    table: this.detailTable('Outras configuracoes coletadas',
                    ['Parametro', 'Configurado'],
                    otherConfigRows
                    )
                }
            ];

            const proxy = (this.data.proxies || []).find((row) => row.host === host);
            const trendAlerts = (proxy?.trend_alerts || []).length;
            sections.push({
                id: 'trends',
                label: trendAlerts > 0 ? `Tendencias (${trendAlerts})` : 'Tendencias',
                table: this.trendSection(proxy)
            });

            const active = sections.some((section) => section.id === this.detailTabs[host])
                ? this.detailTabs[host]
                : sections[0].id;
            const tabs = document.createElement('div');
            tabs.className = 'proxy-health-detail-tabs';
            sections.forEach((section) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'proxy-health-detail-tab';
                button.dataset.proxyHost = host;
                button.dataset.proxyDetailTab = section.id;
                button.setAttribute('aria-pressed', section.id === active ? 'true' : 'false');
                button.textContent = section.label;
                tabs.append(button);
            });
            wrapper.append(tabs);
            sections.forEach((section) => {
                section.table.classList.toggle('is-active', section.id === active);
                wrapper.append(section.table);
            });

            return wrapper;
        }

        trendAlertTitle(alert) {
            const cpus = alert.group === 'vm' && alert.unit === '';
            const limit = cpus ? 'do numero de CPUs' : `de ${trendValue(alert.card_limit, alert.unit)}`;
            if (alert.days !== null && alert.days !== undefined && alert.days <= 0) {
                return cpus ? `${alert.label}: ja acima do numero de CPUs` : `${alert.label}: ja em ${trendValue(alert.card_limit, alert.unit)}`;
            }
            return `${alert.label}: projetado para passar ${limit} ${daysLabel(alert.days)}`.trim();
        }

        trendCard(alert) {
            const card = document.createElement('div');
            card.className = 'proxy-health-trend-card';
            const icon = document.createElement('span');
            icon.className = 'proxy-health-trend-badge';
            icon.innerHTML = TREND_ICON;
            const body = document.createElement('div');
            const title = document.createElement('strong');
            title.textContent = this.trendAlertTitle(alert);
            const detail = document.createElement('span');
            detail.className = 'proxy-health-muted';
            const step = slopeLabel(alert.slope, alert.unit);
            detail.textContent = `Hoje ${trendValue(alert.current, alert.unit)}, ${step}. Tendencia dentro do horizonte configurado; nao desconta da nota.`;
            body.append(title, detail);
            card.append(icon, body);
            return card;
        }

        sparkline(row, statusClass, horizon) {
            const values = (row.series || []).map((v) => (v === null ? null : Number(v)));
            const known = values.filter((v) => v !== null);
            const svgNs = 'http://www.w3.org/2000/svg';
            const width = 180;
            const height = 26;
            const svg = document.createElementNS(svgNs, 'svg');
            svg.setAttribute('width', String(width));
            svg.setAttribute('height', String(height));
            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            svg.setAttribute('class', `proxy-health-sparkline ${statusClass}`);
            if (known.length < 2) {
                return svg;
            }

            const hasForecast = row.projection !== null && row.projection !== undefined && row.current !== null;
            const {threshold, ceiling} = trendLimits(row);
            const top = Math.max(...known, hasForecast ? Number(row.projection) : -Infinity);
            // Linhas de referencia so quando estao perto da serie; senao achatam o grafico.
            const showThreshold = threshold !== null && top >= threshold * 0.5;
            const showCeiling = ceiling !== null && top >= ceiling * 0.8;
            const range = [...known];
            if (hasForecast) {
                range.push(Number(row.current), Number(row.projection));
            }
            if (showThreshold) {
                range.push(threshold);
            }
            if (showCeiling) {
                range.push(ceiling);
            }
            let min = Math.min(...range);
            let max = Math.max(...range);
            // Escala minima de 10 p.p. em percentuais: variacao pequena nao pode parecer subida forte.
            const minSpan = row.unit === '%' ? 10 : 0;
            if (max - min < minSpan) {
                const pad = (minSpan - (max - min)) / 2;
                min = Math.max(0, min - pad);
                max = min + minSpan;
            }
            const span = max - min || 1;
            const y = (v) => (height - 3 - ((v - min) / span) * (height - 6)).toFixed(1);

            // Historico a esquerda; a projecao ocupa a fracao proporcional ao horizonte (no maximo metade).
            const count = values.length;
            const futureShare = hasForecast ? Math.min(0.5, horizon / Math.max(1, count - 1 + horizon)) : 0;
            const historyWidth = width * (1 - futureShare);
            const step = historyWidth / Math.max(1, count - 1);
            const line = (x1, y1, x2, y2, cls) => {
                const el = document.createElementNS(svgNs, 'line');
                el.setAttribute('x1', x1);
                el.setAttribute('y1', y1);
                el.setAttribute('x2', x2);
                el.setAttribute('y2', y2);
                el.setAttribute('class', cls);
                svg.append(el);
            };

            if (showCeiling) {
                line(0, y(ceiling), width, y(ceiling), 'is-ceiling-line');
            }
            if (showThreshold) {
                // Mesmo desenho das linhas de trigger dos graficos do Zabbix: tracejado amarelo sobre cinza.
                line(0, y(threshold), width, y(threshold), 'is-threshold-base');
                line(0, y(threshold), width, y(threshold), 'is-threshold-dash');
            }

            const history = document.createElementNS(svgNs, 'polyline');
            history.setAttribute('points', values
                .map((v, i) => (v === null ? null : `${(i * step).toFixed(1)},${y(v)}`))
                .filter(Boolean)
                .join(' '));
            history.setAttribute('class', 'is-history');
            svg.append(history);
            if (hasForecast) {
                line(historyWidth.toFixed(1), y(Number(row.current)), width, y(Number(row.projection)), 'is-forecast');
            }

            const title = document.createElementNS(svgNs, 'title');
            title.textContent = `Linha cheia: P95 diario dos ultimos ${count} dias. Tracejada: projecao para ${horizon} dias.`
                + (showThreshold ? ` Amarela: threshold ${trendValue(threshold, row.unit)}.` : '')
                + (showCeiling ? ` Vermelha: teto ${row.unit === '' ? '(numero de CPUs)' : trendValue(ceiling, row.unit)}.` : '');
            svg.append(title);
            return svg;
        }

        miniSparkline(row) {
            if (!row || (row.series || []).filter((v) => v !== null).length < 2) {
                return '—';
            }
            const status = trendStatus(row);
            const [statusClass, label] = TREND_STATUS[status] || TREND_STATUS.insufficient;
            const values = row.series.map((v) => (v === null ? null : Number(v)));
            const known = values.filter((v) => v !== null);
            const width = 90;
            const height = 18;
            let min = Math.min(...known);
            let max = Math.max(...known);
            if (max - min < 10) {
                min = Math.max(0, min - (10 - (max - min)) / 2);
                max = min + 10;
            }
            const step = width / Math.max(1, values.length - 1);
            const svgNs = 'http://www.w3.org/2000/svg';
            const svg = document.createElementNS(svgNs, 'svg');
            svg.setAttribute('width', String(width));
            svg.setAttribute('height', String(height));
            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            svg.setAttribute('class', `proxy-health-sparkline ${statusClass}`);
            const line = document.createElementNS(svgNs, 'polyline');
            line.setAttribute('class', 'is-history');
            line.setAttribute('points', values
                .map((v, i) => (v === null ? null : `${(i * step).toFixed(1)},${(height - 2 - ((v - min) / (max - min)) * (height - 4)).toFixed(1)}`))
                .filter(Boolean)
                .join(' '));
            const title = document.createElementNS(svgNs, 'title');
            title.textContent = `P95 diario dos ultimos ${values.length} dias · ${trendStatusLabel(row, status) || label}`;
            svg.append(line, title);
            return svg;
        }

        trendSection(proxy) {
            const section = document.createElement('section');
            section.className = 'proxy-health-detail-section proxy-health-trend-section';
            const settings = this.data.settings || {};
            const horizon = Number(settings.forecast_horizon || 30);
            const heading = document.createElement('h4');
            heading.textContent = `Tendencias de recursos · projecao para ${horizon} dias (nao desconta da nota)`;
            section.append(heading);

            const rows = proxy?.trends || [];
            if (rows.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'proxy-health-muted';
                empty.textContent = 'Sem series de trends suficientes para este objeto.';
                section.append(empty);
                return section;
            }

            const order = {ceiling: 0, threshold: 1, growing: 2, decreasing: 3, stable: 4, insufficient: 5};
            const table = document.createElement('table');
            table.className = 'proxy-health-detail-table proxy-health-trend-table';
            table.innerHTML = `<thead><tr><th>Recurso</th><th class="is-right">Hoje (P95)</th><th>Ultimos ${settings.trend_days || 30} dias · projecao</th>`
                + `<th class="is-right">Variacao</th><th class="is-right">Em ${horizon}d</th><th class="is-right">Threshold / teto</th><th>Situacao</th></tr></thead>`;
            const tbody = document.createElement('tbody');
            Object.entries(TREND_GROUPS).forEach(([group, groupLabel]) => {
                const allRows = rows.filter((row) => row.group === group)
                    .sort((a, b) => order[trendStatus(a)] - order[trendStatus(b)] || String(a.label).localeCompare(String(b.label)));
                if (allRows.length === 0) {
                    return;
                }
                // Processos e caches parados (P95 e projecao abaixo de 1%) nao dizem nada: ficam recolhidos.
                const idle = (row) => group !== 'vm' && group !== 'load' && row.unit === '%'
                    && ['stable', 'insufficient'].includes(row.status)
                    && Number(row.current || 0) < 1 && Number(row.projection || 0) < 1;
                const showIdle = this.trendIdleOpen?.has(`${proxy.host}:${group}`);
                const idleRows = allRows.filter(idle);
                const groupRows = showIdle ? allRows : allRows.filter((row) => !idle(row));
                const groupRow = document.createElement('tr');
                groupRow.className = 'proxy-health-trend-group';
                const groupCell = document.createElement('td');
                groupCell.colSpan = 7;
                groupCell.textContent = groupLabel;
                groupRow.append(groupCell);
                tbody.append(groupRow);

                groupRows.forEach((row) => {
                    const status = trendStatus(row);
                    const [statusClass] = TREND_STATUS[status] || TREND_STATUS.insufficient;
                    const {threshold, ceiling} = trendLimits(row);
                    const tr = document.createElement('tr');
                    const cells = [
                        row.label,
                        trendValue(row.current, row.unit),
                        null,
                        slopeLabel(row.slope, row.unit),
                        trendValue(row.projection, row.unit),
                        `${threshold === null ? '—' : trendValue(threshold, row.unit)} / `
                            + (ceiling === null ? '—' : (row.unit === '' ? '1 por nucleo' : trendValue(ceiling, row.unit))),
                        null
                    ];
                    cells.forEach((value, index) => {
                        const td = document.createElement('td');
                        if (index === 2) {
                            td.append(this.sparkline(row, statusClass, horizon));
                        }
                        else if (index === 6) {
                            const pill = document.createElement('span');
                            pill.className = `proxy-health-trend-pill ${statusClass}`;
                            pill.textContent = trendStatusLabel(row, status);
                            if (status === 'ceiling') {
                                pill.title = 'Projecao passa do limite fisico: gera card no detalhe e icone na linha do proxy.';
                            }
                            else if (status === 'threshold') {
                                pill.title = 'Projecao passa do threshold de trigger, mas nao do limite fisico: sem card.';
                            }
                            td.append(pill);
                        }
                        else {
                            td.textContent = value;
                            if ([1, 3, 4, 5].includes(index)) {
                                td.className = 'is-right proxy-health-num';
                            }
                        }
                        tr.append(td);
                    });
                    tbody.append(tr);
                });

                if (idleRows.length > 0) {
                    const tr = document.createElement('tr');
                    const td = document.createElement('td');
                    td.colSpan = 7;
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'proxy-health-link-button';
                    button.dataset.proxyTrendIdle = `${proxy.host}:${group}`;
                    button.textContent = showIdle
                        ? `Ocultar ${idleRows.length} ${group === 'process' ? 'processos' : 'caches'} ociosos e estaveis`
                        : `+ ${idleRows.length} ${group === 'process' ? 'processos' : 'caches'} ociosos e estaveis (abaixo de 1%)`;
                    td.append(button);
                    tr.append(td);
                    tbody.append(tr);
                }
            });
            table.append(tbody);
            section.append(table);

            const legend = document.createElement('p');
            legend.className = 'proxy-health-muted proxy-health-trend-legend';
            legend.textContent = 'Teto (vermelho): passa do limite fisico (100% ou numero de CPUs) no horizonte, gera card. '
                + 'Threshold (laranja): passa do threshold de trigger, sem card. Amarelo: crescendo · Verde: estavel · Azul: descendo. '
                + 'Grafico: linha cheia = P95 diario da janela de trends; tracejada = projecao (Theil-Sen, minimo de 14 dias); amarela tracejada = threshold.';
            section.append(legend);
            return section;
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
                        if (value instanceof Node) {
                            cell.append(value);
                        }
                        else {
                            cell.textContent = value ?? '—';
                        }
                        row.append(cell);
                    });
                    tbody.append(row);
                });
            }

            table.append(thead, tbody);
            section.append(table);
            return section;
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
                'secao', 'proxy', 'tipo', 'parametro', 'leitura_p95', 'media_trends',
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
                    ['VPS P95', fmt(proxy.vps_p95, 0)],
                    ['Unsupported %', pct(proxy.unsupported_pct)],
                    ['CPU P95', fmt(proxy.cpu_p95, 1, '%')],
                    ['Memoria total', fmt(proxy.memory_total_gb, 1, ' GB')],
                    ['Memoria P95', fmt(proxy.memory_p95, 1, '%')],
                    ['Memoria media trends', fmt(proxy.memory_avg, 1, '%')],
                    ['Disco P95', fmt(proxy.disk_p95, 1, '%')]
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
                    leitura_p95: fmt(row.p95, 1, '%'),
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
                leitura_p95: fmt(row.p95, 1, '%'),
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
                        'Proxy', 'Tipo', 'State', 'Score', 'Versao', 'VPS P95', 'Unsupported %',
                        'CPU P95', 'Mem total GB', 'Mem P95', 'Mem media trends',
                        'Disco P95', 'Resumo'
                    ],
                    rows: this.data.proxies.map((proxy) => [
                        proxy.host,
                        objectType(proxy),
                        proxy.state,
                        proxy.score,
                        proxy.version || '—',
                        fmt(proxy.vps_p95, 0),
                        pct(proxy.unsupported_pct),
                        fmt(proxy.cpu_p95, 1, '%'),
                        fmt(proxy.memory_total_gb, 1, ' GB'),
                        fmt(proxy.memory_p95, 1, '%'),
                        fmt(proxy.memory_avg, 1, '%'),
                        fmt(proxy.disk_p95, 1, '%'),
                        proxy.summary
                    ])
                },
                {
                    name: 'Processos Config',
                    headers: [
                        'Proxy', 'Parametro', 'P95', 'Media trends',
                        'Item de configuracao', 'Configurado', 'Recomendado',
                        'Status', 'Acao sugerida'
                    ],
                    rows: this.data.process_config
                        .filter((row) => row.status !== 'Sem parametro configuravel')
                        .map((row) => [
                            row.host,
                            row.process,
                            fmt(row.p95, 1, '%'),
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
                    headers: ['Proxy', 'Parametro', 'P95', 'Media trends', 'Status'],
                    rows: this.data.process_config
                        .filter((row) => row.status === 'Sem parametro configuravel')
                        .map((row) => [
                            row.host,
                            row.process,
                            fmt(row.p95, 1, '%'),
                            fmt(row.avg30d, 1, '%'),
                            row.status
                        ])
                },
                {
                    name: 'Caches',
                    headers: [
                        'Proxy', 'Cache', 'Uso P95', 'Media trends', 'Parametro',
                        'Configurado', 'Recomendado', 'Status', 'Acao sugerida'
                    ],
                    rows: this.data.cache_config.map((row) => [
                        row.host,
                        row.cache,
                        fmt(row.p95, 1, '%'),
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
