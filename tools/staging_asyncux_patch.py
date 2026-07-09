from pathlib import Path


def patch_base(base: Path) -> None:
    if not base.exists():
        print(f"skip_missing={base}")
        return

    controller = base / "actions" / "ProxyHealthView.php"
    s = controller.read_text()
    s = s.replace(
        "            'debug_profile' => 'string'\n        ]);",
        "            'debug_profile' => 'string',\n            'proxy_async' => 'string'\n        ]);"
    )
    old = """        try {
            $data = $this->collect($settings);
            $data['error'] = null;
            $this->profileStep($profile, 'collect.complete', [
                'proxies' => count($data['proxies']),
                'config_items' => count($data['config_items']),
                'process_config' => count($data['process_config']),
                'cache_config' => count($data['cache_config']),
                'active_problems' => count($data['active_problems']),
                'orphans' => count($data['orphans'])
            ]);
        }
        catch (Throwable $exception) {
"""
    new = """        $async = in_array($this->getInput('proxy_async', ''), ['1', 'true', 'Sim'], true);

        try {
            if ($async) {
                $data = $this->collect($settings);
                $data['error'] = null;
                $this->profileStep($profile, 'collect.complete', [
                    'proxies' => count($data['proxies']),
                    'config_items' => count($data['config_items']),
                    'process_config' => count($data['process_config']),
                    'cache_config' => count($data['cache_config']),
                    'active_problems' => count($data['active_problems']),
                    'orphans' => count($data['orphans'])
                ]);
            }
            else {
                $data = [
                    'error' => null,
                    'proxies' => [],
                    'config_items' => [],
                    'process_config' => [],
                    'cache_config' => [],
                    'orphans' => [],
                    'active_problems' => [],
                    'excluded_offline' => []
                ];
                $this->profileStep($profile, 'collect.deferred', [
                    'async' => '1'
                ]);
            }
        }
        catch (Throwable $exception) {
"""
    if old in s:
        s = s.replace(old, new)
    elif "collect.deferred" not in s:
        raise SystemExit(f"controller doAction block not found in {controller}")

    s = s.replace("array_chunk($numeric, 120)", "array_chunk($numeric, self::TREND_BATCH_SIZE)")
    controller.write_text(s)

    view = base / "views" / "proxy.health.view.php"
    s = view.read_text()
    if "data-proxy-health-async" not in s:
        s = s.replace(
            "        ->setAttribute('data-proxy-health-payload', $data['payload'])",
            "        ->setAttribute('data-proxy-health-payload', $data['payload'])\n"
            "        ->setAttribute('data-proxy-health-async', '1')"
        )
    view.write_text(s)

    js = base / "assets" / "js" / "proxy-health-page.js"
    s = js.read_text()
    old = """            const bytes = Uint8Array.from(atob(root.dataset.proxyHealthPayload), (character) =>
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
"""
    new = """            this.data = this.decodePayload(root.dataset.proxyHealthPayload);
            this.search = root.querySelector('#proxy-health-search');
            this.cards = root.querySelector('#proxy-health-cards');
            this.exportMenu = root.querySelector('[data-proxy-export-menu]');
            this.exportToggle = root.querySelector('[data-proxy-export-toggle]');
            this.expanded = new Set();
            this.loading = this.createLoadingState();

            root.addEventListener('click', (event) => this.onClick(event));
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
                <div class=\"proxy-health-loading-title\">Coletando assessment</div>
                <div class=\"proxy-health-loading-step\" data-proxy-loading-step>Preparando requisicao</div>
                <div class=\"proxy-health-loading-bar\"><span data-proxy-loading-bar style=\"width: 8%\"></span></div>
                <div class=\"proxy-health-loading-percent\" data-proxy-loading-percent>8%</div>
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

        async loadAssessment() {
            const progress = [
                ['Resolvendo host group e filtros', 15],
                ['Coletando hosts, itens e configuracoes', 35],
                ['Coletando trends de 30 dias', 55],
                ['Consolidando score e recomendacoes', 78]
            ];
            let marker = 0;
            const timer = window.setInterval(() => {
                if (marker < progress.length) {
                    this.setLoading(progress[marker][0], progress[marker][1]);
                    marker += 1;
                }
            }, 900);

            try {
                const url = new URL(window.location.href);
                url.searchParams.set('proxy_async', '1');
                const response = await fetch(url.toString(), {credentials: 'same-origin'});
                const html = await response.text();
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                this.setLoading('Processando payload recebido', 90);
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const freshRoot = doc.querySelector('[data-proxy-health-payload]');
                if (!freshRoot) {
                    throw new Error('Payload do assessment nao encontrado na resposta');
                }
                this.data = this.decodePayload(freshRoot.dataset.proxyHealthPayload);
                this.setLoading('Renderizando painel', 100);
                this.render();
                window.setTimeout(() => this.loading?.panel.remove(), 450);
            }
            catch (error) {
                this.setLoading(`Falha na coleta: ${error.message}`, 100);
                this.loading?.panel.classList.add('is-error');
            }
            finally {
                window.clearInterval(timer);
            }
        }
"""
    if old in s:
        s = s.replace(old, new)
    elif "loadAssessment" not in s:
        raise SystemExit(f"js constructor block not found in {js}")
    js.write_text(s)

    css = base / "assets" / "css" / "proxy-health-page.css"
    s = css.read_text()
    if ".proxy-health-loading" not in s:
        s += """

.proxy-health-loading {
    margin: 0 0 16px;
    padding: 12px 14px;
    border: 1px solid #3f4b52;
    background: #252b2e;
    color: #d8d8d8;
}

.proxy-health-loading-title {
    font-weight: 700;
    margin-bottom: 6px;
}

.proxy-health-loading-step {
    color: #b9c0c4;
    margin-bottom: 8px;
}

.proxy-health-loading-bar {
    height: 8px;
    background: #172025;
    overflow: hidden;
}

.proxy-health-loading-bar span {
    display: block;
    height: 100%;
    background: #1f8dd6;
    transition: width .25s ease;
}

.proxy-health-loading-percent {
    margin-top: 6px;
    font-size: 12px;
    color: #b9c0c4;
}

.proxy-health-loading.is-error {
    border-color: #b74a4a;
}
"""
    css.write_text(s)
    print(f"patched={base}")


for target in [
    Path("/usr/share/zabbix/modules/proxy_health_assessment"),
    Path("/usr/share/zabbix/ui/modules/proxy_health_assessment"),
]:
    patch_base(target)
