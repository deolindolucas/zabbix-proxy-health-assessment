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

    class ProxyHealthPage {
        constructor(root) {
            this.root = root;
            const bytes = Uint8Array.from(atob(root.dataset.proxyHealthPayload), (character) =>
                character.charCodeAt(0)
            );
            this.data = JSON.parse(new TextDecoder('utf-8').decode(bytes));
            this.search = root.querySelector('#proxy-health-search');
            this.cards = root.querySelector('#proxy-health-cards');
            this.exportMenu = root.querySelector('[data-proxy-export-menu]');
            this.exportToggle = root.querySelector('[data-proxy-export-toggle]');
            this.expanded = new Set();

            root.addEventListener('click', (event) => this.onClick(event));
            document.addEventListener('click', (event) => this.onDocumentClick(event));
            this.search?.addEventListener('input', () => this.render());

            this.render();
        }

        onClick(event) {
            const tab = event.target.closest('[data-proxy-tab]');
            if (tab && this.root.contains(tab)) {
                this.selectTab(tab.dataset.proxyTab);
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

        filteredProxies() {
            const needle = normalize(this.search?.value ?? '');
            return this.data.proxies.filter((proxy) =>
                needle === ''
                || normalize(proxy.host).includes(needle)
                || normalize(proxy.technical_name).includes(needle)
            );
        }

        render() {
            const proxies = this.filteredProxies();
            this.renderKpis(proxies);
            this.renderCards(proxies);
            this.renderOverviewTable(proxies);
            this.renderConfigTables();
        }

        renderKpis(proxies) {
            const counts = {
                total: proxies.length,
                ok: proxies.filter((proxy) => proxy.state === 'OK').length,
                attention: proxies.filter((proxy) => proxy.state === 'Atencao').length,
                risk: proxies.filter((proxy) => proxy.state === 'Risco' || proxy.state === 'Critico').length
            };
            Object.entries(counts).forEach(([key, value]) => {
                const target = this.root.querySelector(`[data-proxy-kpi="${key}"]`);
                if (target) {
                    target.textContent = String(value);
                }
            });
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
                meta.textContent = `Versao ${proxy.version || '—'} · VPS ${fmt(proxy.vps_current, 0)} · Mem ${fmt(proxy.memory_total_gb, 1, ' GB')}`;

                const bars = document.createElement('div');
                bars.className = 'proxy-health-bars';
                [
                    ['CPU', proxy.cpu_current],
                    ['Memoria', proxy.memory_current],
                    ['Disco', proxy.disk_current]
                ].forEach(([label, value]) => bars.append(this.bar(label, value)));

                const summary = document.createElement('p');
                summary.textContent = proxy.summary;

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
                    ['Parametro', 'Leitura atual', 'Media 30d', 'Item de configuracao', 'Configurado', 'Recomendado', 'Status', 'Acao sugerida'],
                    configurableProcessRows
                ),
                this.detailTable('Demais processos internos',
                    ['Parametro', 'Leitura atual', 'Media 30d', 'Status'],
                    nonConfigurableProcessRows
                ),
                this.detailTable('Caches versus configuracao',
                    ['Cache', 'Uso atual', 'Media 30d', 'Parametro', 'Configurado', 'Recomendado', 'Status', 'Acao sugerida'],
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

            if (format === 'xls') {
                this.downloadFile(
                    `proxy_health_assessment_${stamp}.xls`,
                    this.spreadsheetXml(),
                    'application/vnd.ms-excel;charset=utf-8'
                );
                return;
            }

            if (format === 'xml') {
                this.downloadFile(
                    `proxy_health_assessment_${stamp}.xml`,
                    this.spreadsheetXml(),
                    'application/xml;charset=utf-8'
                );
                return;
            }

            if (format !== 'csv') {
                return;
            }

            const rows = this.exportRows();
            const headers = [
                'secao', 'proxy', 'parametro', 'leitura_atual', 'media_30d',
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
                    ['Memoria media 30d', fmt(proxy.memory_avg, 1, '%')],
                    ['Disco atual', fmt(proxy.disk_current, 1, '%')]
                ].forEach(([parameter, value]) => add({
                    secao: 'Overview',
                    proxy: proxy.host,
                    parametro: parameter,
                    valor: value,
                    status: proxy.state,
                    resumo: proxy.summary
                }));
                add({
                    secao: 'Overview',
                    proxy: proxy.host,
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
                    media_30d: fmt(row.avg30d, 1, '%'),
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
                media_30d: fmt(row.avg30d, 1, '%'),
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

            return rows;
        }

        spreadsheetSheets() {
            const usedByHost = this.usedConfigKeysByHost();

            return [
                {
                    name: 'Overview',
                    headers: [
                        'Proxy', 'State', 'Score', 'Versao', 'VPS atual', 'Unsupported %',
                        'CPU atual', 'Mem total GB', 'Mem atual', 'Mem media 30d',
                        'Disco atual', 'Resumo'
                    ],
                    rows: this.data.proxies.map((proxy) => [
                        proxy.host,
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
                        'Proxy', 'Parametro', 'Leitura atual', 'Media 30d',
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
                    headers: ['Proxy', 'Parametro', 'Leitura atual', 'Media 30d', 'Status'],
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
                        'Proxy', 'Cache', 'Uso atual', 'Media 30d', 'Parametro',
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
                }
            ];
        }

        spreadsheetXml() {
            const worksheets = this.spreadsheetSheets()
                .map((sheet) => this.worksheetXml(sheet.name, sheet.headers, sheet.rows))
                .join('');

            return [
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<?mso-application progid="Excel.Sheet"?>',
                '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"',
                ' xmlns:o="urn:schemas-microsoft-com:office:office"',
                ' xmlns:x="urn:schemas-microsoft-com:office:excel"',
                ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">',
                '<Styles>',
                '<Style ss:ID="header"><Font ss:Bold="1"/><Interior ss:Color="#D9EAF7" ss:Pattern="Solid"/></Style>',
                '</Styles>',
                worksheets,
                '</Workbook>'
            ].join('');
        }

        worksheetXml(name, headers, rows) {
            const headerRow = `<Row>${headers.map((header) =>
                `<Cell ss:StyleID="header"><Data ss:Type="String">${this.xmlEscape(header)}</Data></Cell>`
            ).join('')}</Row>`;
            const bodyRows = rows.map((row) =>
                `<Row>${row.map((value) =>
                    `<Cell><Data ss:Type="String">${this.xmlEscape(value)}</Data></Cell>`
                ).join('')}</Row>`
            ).join('');

            return [
                `<Worksheet ss:Name="${this.xmlEscape(this.sheetName(name))}">`,
                '<Table>',
                headerRow,
                bodyRows,
                '</Table>',
                '</Worksheet>'
            ].join('');
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

        xmlEscape(value) {
            return String(value ?? '')
                .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
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
                cell.colSpan = 12;
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
