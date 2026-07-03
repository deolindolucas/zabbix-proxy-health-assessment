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
    const normalize = (value) => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase();

    class ProxyHealthPage {
        constructor(root) {
            this.root = root;
            const bytes = Uint8Array.from(atob(root.dataset.proxyHealthPayload), (character) =>
                character.charCodeAt(0)
            );
            this.data = JSON.parse(new TextDecoder('utf-8').decode(bytes));
            this.search = root.querySelector('#proxy-health-search');
            this.cards = root.querySelector('#proxy-health-cards');
            this.expanded = new Set();

            root.addEventListener('click', (event) => this.onClick(event));
            this.search?.addEventListener('input', () => this.render());

            this.render();
        }

        onClick(event) {
            const tab = event.target.closest('[data-proxy-tab]');
            if (tab && this.root.contains(tab)) {
                this.selectTab(tab.dataset.proxyTab);
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
                meta.textContent = `Versao ${proxy.version || '—'} · VPS ${fmt(proxy.vps_current, 0)}`;

                const bars = document.createElement('div');
                bars.className = 'proxy-health-bars';
                [
                    ['CPU', proxy.cpu_current],
                    ['Memoria', proxy.memory_current],
                    ['Disco', proxy.disk_current],
                    ['Processos', proxy.process_current_max]
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

            wrapper.append(
                this.detailTable('Leituras de configuracao', ['Item', 'Key', 'Valor', 'Ultima coleta', 'Estado'],
                    this.data.config_items
                        .filter((row) => row.host === host)
                        .map((row) => [row.name, row.key, row.value ?? '—', row.lastclock || '—', row.state])
                ),
                this.detailTable('Processos versus configuracao',
                    ['Processo', 'Busy atual', 'Media 30d', 'Parametro', 'Configurado', 'Status'],
                    this.data.process_config
                        .filter((row) => row.host === host)
                        .map((row) => [
                            row.process, fmt(row.current, 1, '%'), fmt(row.avg30d, 1, '%'),
                            row.config_param || '—', row.config_value ?? '—', row.status
                        ])
                ),
                this.detailTable('Caches versus configuracao',
                    ['Cache', 'Uso atual', 'Media 30d', 'Parametro', 'Configurado', 'Status'],
                    this.data.cache_config
                        .filter((row) => row.host === host)
                        .map((row) => [
                            row.cache, fmt(row.current, 1, '%'), fmt(row.avg30d, 1, '%'),
                            row.config_param || '—', row.config_value ?? '—', row.status
                        ])
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
                fmt(proxy.process_current_max, 1, '%'),
                fmt(proxy.cpu_current, 1, '%'),
                fmt(proxy.memory_current, 1, '%'),
                fmt(proxy.disk_current, 1, '%'),
                proxy.summary
            ]);
        }

        renderConfigTables() {
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
