<?php

declare(strict_types=1);

require '/www/vendor/autoload.php';

use App\Protocols\ClashMeta;
use Symfony\Component\Yaml\Yaml;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$hysteriaServer = [
    'name' => 'TEST-HY2',
    'type' => 'hysteria',
    'host' => '192.0.2.10',
    'port' => 443,
    'protocol_settings' => [
        'version' => 2,
        'bandwidth' => ['up' => 50, 'down' => 200],
        'tls' => [
            'server_name' => 'example.com',
            'allow_insecure' => true,
        ],
        'obfs' => [
            'open' => true,
            'type' => 'salamander',
            'password' => 'test-obfs-password',
        ],
    ],
];

$proxies = [
    [
        'name' => 'TEST-SS',
        'type' => 'ss',
        'server' => '192.0.2.1',
        'port' => 8388,
        'cipher' => 'aes-128-gcm',
        'password' => 'ss-password',
        'udp' => true,
    ],
    [
        'name' => 'TEST-VMESS',
        'type' => 'vmess',
        'server' => '192.0.2.2',
        'port' => 443,
        'uuid' => '00000000-0000-0000-0000-000000000002',
        'alterId' => 0,
        'cipher' => 'auto',
        'tls' => true,
        'servername' => 'vmess.example.com',
        'network' => 'ws',
        'ws-opts' => [
            'path' => '/vmess',
            'headers' => ['Host' => 'vmess.example.com'],
        ],
    ],
    [
        'name' => 'TEST-VLESS',
        'type' => 'vless',
        'server' => '192.0.2.3',
        'port' => 443,
        'uuid' => '00000000-0000-0000-0000-000000000003',
        'tls' => true,
        'servername' => 'vless.example.com',
        'network' => 'grpc',
        'grpc-opts' => ['grpc-service-name' => 'vless-service'],
        'udp' => true,
    ],
    [
        'name' => 'TEST-TROJAN',
        'type' => 'trojan',
        'server' => '192.0.2.4',
        'port' => 443,
        'password' => 'trojan-password',
        'sni' => 'trojan.example.com',
        'skip-cert-verify' => false,
        'udp' => true,
    ],
    ClashMeta::buildHysteria('test-password', $hysteriaServer, []),
    [
        'name' => 'TEST-TUIC',
        'type' => 'tuic',
        'server' => '192.0.2.6',
        'port' => 443,
        'uuid' => '00000000-0000-0000-0000-000000000006',
        'password' => 'tuic-password',
        'sni' => 'tuic.example.com',
        'udp' => true,
    ],
    [
        'name' => 'TEST-ANYTLS',
        'type' => 'anytls',
        'server' => '192.0.2.7',
        'port' => 443,
        'password' => 'anytls-password',
        'sni' => 'anytls.example.com',
        'udp' => true,
    ],
    [
        'name' => 'TEST-SOCKS',
        'type' => 'socks5',
        'server' => '192.0.2.8',
        'port' => 1080,
        'username' => 'socks-user',
        'password' => 'socks-password',
        'udp' => true,
    ],
    [
        'name' => 'TEST-HTTP',
        'type' => 'http',
        'server' => '192.0.2.9',
        'port' => 8080,
        'username' => 'http-user',
        'password' => 'http-password',
    ],
    [
        'name' => 'TEST-MIERU',
        'type' => 'mieru',
        'server' => '192.0.2.11',
        'port' => 443,
        'username' => 'mieru-user',
        'password' => 'mieru-password',
        'transport' => 'TCP',
    ],
];

$config = [
    'proxies' => $proxies,
    'proxy-groups' => [[
        'name' => 'AUTO',
        'type' => 'select',
        'proxies' => array_column($proxies, 'name'),
    ]],
];

$yaml = Yaml::dump($config, 99, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
$yaml = preg_replace(
    '/^([ ]*)-\n\1  ([^ \-\n][^:\n]*:[^\n]*)$/m',
    '$1- $2',
    $yaml
) ?? $yaml;

$proxySections = preg_split('/^proxy-groups:\s*$/m', $yaml, 2);
$proxiesYaml = $proxySections[0] ?? $yaml;

$assert(!preg_match('/^\s*-\s*\{/m', $proxiesYaml), 'A proxy is still emitted as an inline flow mapping');
$assert(!preg_match('/:\s*\{[^\n]*\}/m', $proxiesYaml), 'A nested proxy map is still emitted inline');
$assert(!preg_match('/:\s*\[[^\n]*\]/m', $proxiesYaml), 'A nested proxy list is still emitted inline');
$assert(!preg_match('/^  -\s*$/m', $proxiesYaml), 'A proxy still uses a dash-only mapping line');

$blockCount = preg_match_all('/^  - name:/m', $proxiesYaml);
$assert(
    $blockCount === count($proxies),
    "Expected " . count($proxies) . " '- name:' proxy entries, got {$blockCount}\n{$proxiesYaml}"
);

foreach ($proxies as $proxy) {
    $name = (string) $proxy['name'];
    $type = (string) $proxy['type'];
    $assert(
        str_contains($proxiesYaml, "  - name: {$name}\n    type: {$type}\n"),
        "{$name} is not emitted as a conventional '- name:' block YAML proxy"
    );
}

$assert(str_contains($proxiesYaml, "    ws-opts:\n      path: /vmess\n      headers:\n        Host: vmess.example.com\n"), 'VMess nested WS options are not block YAML');
$assert(str_contains($proxiesYaml, "    grpc-opts:\n      grpc-service-name: vless-service\n"), 'VLESS nested gRPC options are not block YAML');
$assert(str_contains($proxiesYaml, "    alpn:\n      - h3\n"), 'Hysteria2 ALPN is not a block list');
$assert(str_contains($proxiesYaml, "    obfs: salamander\n"), 'Missing Hysteria2 salamander obfuscation');
$assert(str_contains($proxiesYaml, "    obfs-password: test-obfs-password\n"), 'Missing Hysteria2 obfuscation password');
$assert(str_contains($proxiesYaml, "    udp: true\n"), 'Missing UDP field');
$assert(str_contains($proxiesYaml, "    up: 50\n"), 'Missing Hysteria2 upload bandwidth');
$assert(str_contains($proxiesYaml, "    down: 200\n"), 'Missing Hysteria2 download bandwidth');

$parsed = Yaml::parse($yaml);
$parsedProxies = $parsed['proxies'] ?? [];
$assert(count($parsedProxies) === count($proxies), 'Parsed proxy count mismatch');
$assert(array_column($parsedProxies, 'type') === array_column($proxies, 'type'), 'Parsed proxy type order mismatch');

$source = file_get_contents('/www/app/Protocols/ClashMeta.php');
$assert($source !== false, 'Unable to read patched ClashMeta source');
$assert(
    str_contains($source, 'Yaml::dump($config, 99, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)'),
    'ClashMeta handle is not using all-protocol standard block YAML depth'
);
$assert(
    str_contains($source, 'preg_replace(') && str_contains($source, "'$1- $2'"),
    'ClashMeta handle is not normalizing mapping sequences to the conventional dash-key style'
);

$requiredBuilders = [
    'buildShadowsocks',
    'buildVmess',
    'buildVless',
    'buildTrojan',
    'buildHysteria',
    'buildTuic',
    'buildAnyTLS',
    'buildSocks5',
    'buildHttp',
    'buildMieru',
];
foreach ($requiredBuilders as $builder) {
    $assert(str_contains($source, "function {$builder}"), "Missing ClashMeta builder {$builder}");
}

echo "ClashMeta YAML self-test passed: every supported node type uses conventional '- name:' block YAML.\n";
