<?php
declare(strict_types=1);

$options = getopt('', ['api-url:', 'template::', 'templateid::', 'out::']);
$api_url = $options['api-url'] ?? '';
$templates = [];
foreach ((array) ($options['template'] ?? []) as $template) {
    $templates[] = $template;
}
foreach ((array) ($options['templateid'] ?? []) as $templateid) {
    $templates[] = '#'.$templateid;
}
$token = getenv('ZBX_TOKEN') ?: '';

if ($api_url === '' || $token === '' || !$templates) {
    fwrite(STDERR, "Usage: ZBX_TOKEN=... php export_template_keys.php --api-url URL --template NAME [--templateid ID]\n");
    exit(2);
}

$rpc_id = 1;
$call = static function (string $method, array $params = []) use ($api_url, $token, &$rpc_id): array {
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
        'id' => $rpc_id++
    ], JSON_UNESCAPED_SLASHES);

    $curl = curl_init($api_url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json-rpc',
            'Authorization: Bearer '.$token
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $body = curl_exec($curl);
    if ($body === false) {
        throw new RuntimeException(curl_error($curl));
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response: '.$body);
    }
    if (isset($decoded['error'])) {
        throw new RuntimeException($method.': '.json_encode($decoded['error'], JSON_UNESCAPED_UNICODE));
    }
    return $decoded['result'] ?? [];
};

$resolved = [];
foreach ($templates as $template) {
    if (str_starts_with($template, '#')) {
        $result = $call('template.get', [
            'output' => ['templateid', 'host', 'name'],
            'templateids' => [substr($template, 1)],
            'limit' => 1
        ]);
    }
    else {
        $result = $call('template.get', [
            'output' => ['templateid', 'host', 'name'],
            'filter' => ['host' => [$template]],
            'limit' => 1
        ]);
        if (!$result) {
            $result = $call('template.get', [
                'output' => ['templateid', 'host', 'name'],
                'filter' => ['name' => [$template]],
                'limit' => 1
            ]);
        }
        if (!$result) {
            $result = $call('template.get', [
                'output' => ['templateid', 'host', 'name'],
                'search' => ['host' => $template, 'name' => $template],
                'searchByAny' => true,
                'limit' => 10
            ]);
        }
    }

    foreach ($result as $row) {
        $resolved[$row['templateid']] = $row;
    }
}

$items_by_template = [];
foreach ($resolved as $templateid => $template) {
    $items = $call('item.get', [
        'output' => ['itemid', 'hostid', 'name', 'key_', 'type', 'value_type', 'status'],
        'templateids' => [$templateid],
        'inherited' => true,
        'sortfield' => 'key_'
    ]);

    $items_by_template[$templateid] = [
        'template' => $template,
        'item_count' => count($items),
        'keys' => array_values(array_map(static fn(array $item): array => [
            'name' => $item['name'],
            'key' => $item['key_'],
            'value_type' => $item['value_type'],
            'status' => $item['status']
        ], $items))
    ];
}

$output = [
    'generated_at' => gmdate('c'),
    'templates' => array_values($items_by_template)
];

$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (($options['out'] ?? '') !== '') {
    file_put_contents($options['out'], $json);
}
echo $json.PHP_EOL;
