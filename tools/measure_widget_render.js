const fs = require('fs');
const path = require('path');
const {chromium} = require('playwright');

const root = path.resolve(__dirname, '..');
const payloadPath = process.env.PAYLOAD_PATH || path.join(root, 'outputs', 'profile_proxy_payload.json');
const widgetJsPath = path.join(root, 'zabbix_modules', 'proxy_health_assessment', 'assets', 'js', 'proxy-health-page.js');

const payload = fs.readFileSync(payloadPath, 'utf8');
const widgetJs = fs.readFileSync(widgetJsPath, 'utf8');
const encodedPayload = Buffer.from(payload, 'utf8').toString('base64');

const tableNames = [
  'overview',
  'host_health',
  'active_problems',
  'orphan_problems',
  'process_config',
  'runtime_processes',
  'cache_config',
  'config_items'
];

const panes = ['overview', 'config', 'rules']
  .map((name, index) => `<section data-proxy-pane="${name}"${index === 0 ? '' : ' hidden'}></section>`)
  .join('');

const tables = tableNames
  .map((name) => `<table><tbody data-proxy-table="${name}"></tbody></table>`)
  .join('');

const html = `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Proxy Health Render Benchmark</title>
</head>
<body>
  <div id="proxy-health-assessment" data-proxy-health-payload="${encodedPayload}">
    <input id="proxy-health-search" />
    <button data-proxy-clear-search></button>
    <button data-proxy-export-toggle></button>
    <div data-proxy-export-menu></div>
    <button data-proxy-tab="overview"></button>
    <button data-proxy-tab="config"></button>
    <button data-proxy-tab="rules"></button>
    <div data-proxy-kpi="total"></div>
    <div data-proxy-kpi="ok"></div>
    <div data-proxy-kpi="attention"></div>
    <div data-proxy-kpi="risk"></div>
    <div data-proxy-kpi="critical"></div>
    <div id="proxy-health-cards"></div>
    ${panes}
    ${tables}
  </div>
</body>
</html>`;

(async () => {
  const launchOptions = {headless: true};
  if (process.env.CHROME_PATH) {
    launchOptions.executablePath = process.env.CHROME_PATH;
  }
  const browser = await chromium.launch(launchOptions);
  const page = await browser.newPage({viewport: {width: 1920, height: 1080}});
  await page.setContent(html, {waitUntil: 'domcontentloaded'});

  const started = Date.now();
  await page.addScriptTag({content: widgetJs});
  await page.waitForFunction(() => {
    const payload = JSON.parse(atob(document.getElementById('proxy-health-assessment').dataset.proxyHealthPayload));
    return document.querySelectorAll('#proxy-health-cards article').length === payload.proxies.length;
  }, {timeout: 30000});
  const renderMs = Date.now() - started;

  const firstExpandStarted = Date.now();
  await page.locator('[data-proxy-expand]').first().click();
  await page.waitForSelector('.proxy-health-card-details');
  const firstExpandMs = Date.now() - firstExpandStarted;

  const expandAllStarted = Date.now();
  const totalButtons = await page.locator('[data-proxy-expand]').count();
  for (let i = 1; i < totalButtons; i += 1) {
    await page.locator('[data-proxy-expand]').nth(i).click();
  }
  await page.waitForFunction((expected) =>
    document.querySelectorAll('.proxy-health-card-details').length === expected,
    totalButtons,
    {timeout: 30000}
  );
  const expandAllMs = Date.now() - expandAllStarted;

  const result = await page.evaluate(() => {
    const count = (selector) => document.querySelectorAll(selector).length;
    return {
      cards: count('#proxy-health-cards article'),
      expandedDetails: count('.proxy-health-card-details'),
      detailRows: count('.proxy-health-detail-table tbody tr'),
      overviewRows: count('[data-proxy-table="overview"] tr'),
      hostHealthRows: count('[data-proxy-table="host_health"] tr'),
      activeProblemRows: count('[data-proxy-table="active_problems"] tr'),
      orphanProblemRows: count('[data-proxy-table="orphan_problems"] tr'),
      processConfigRows: count('[data-proxy-table="process_config"] tr'),
      runtimeProcessRows: count('[data-proxy-table="runtime_processes"] tr'),
      cacheConfigRows: count('[data-proxy-table="cache_config"] tr'),
      configItemRows: count('[data-proxy-table="config_items"] tr')
    };
  });

  await browser.close();
  console.log(JSON.stringify({renderMs, firstExpandMs, expandAllMs, ...result}, null, 2));
})();
