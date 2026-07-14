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

$server = [
    'name' => 'TEST-HY2',
    'type' => 'hysteria',
    'host' => '192.0.2.10',
    'port' => 443,
    'protocol_settings' => [
        'version' => 2,
        'bandwidth' => [
            'up' => 50,
            'down' => 200,
        ],
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

$proxy = ClashMeta::buildHysteria('test-password', $server, []);
$config = ['proxies' => [$proxy]];
$yaml = Yaml::dump($config, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

$assert(!str_contains($yaml, '- {'), 'Proxy must not be emitted as an inline flow mapping');
$assert(str_contains($yaml, "  - name: TEST-HY2\n"), 'Missing block-style proxy list item');
$assert(str_contains($yaml, "    type: hysteria2\n"), 'Missing Hysteria2 type');
$assert(str_contains($yaml, "    password: test-password\n"), 'Missing Hysteria2 password');
$assert(str_contains($yaml, "    alpn:\n      - h3\n"), 'Missing block-style H3 ALPN list');
$assert(str_contains($yaml, "    obfs: salamander\n"), 'Missing salamander obfuscation type');
$assert(str_contains($yaml, "    obfs-password: test-obfs-password\n"), 'Missing obfuscation password');
$assert(str_contains($yaml, "    udp: true\n"), 'Missing UDP field');
$assert(str_contains($yaml, "    up: 50\n"), 'Missing upload bandwidth');
$assert(str_contains($yaml, "    down: 200\n"), 'Missing download bandwidth');

$parsed = Yaml::parse($yaml);
$item = $parsed['proxies'][0] ?? [];
$assert(($item['type'] ?? null) === 'hysteria2', 'Parsed proxy type mismatch');
$assert(($item['alpn'] ?? null) === ['h3'], 'Parsed ALPN mismatch');
$assert(($item['udp'] ?? null) === true, 'Parsed UDP value mismatch');
$assert(($item['skip-cert-verify'] ?? null) === true, 'Parsed certificate flag mismatch');

$source = file_get_contents('/www/app/Protocols/ClashMeta.php');
$assert($source !== false, 'Unable to read patched ClashMeta source');
$assert(
    str_contains($source, 'Yaml::dump($config, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)'),
    'ClashMeta handle is not using standard block YAML depth'
);

echo "ClashMeta YAML self-test passed: Hysteria2 is emitted as standard block YAML.\n";
