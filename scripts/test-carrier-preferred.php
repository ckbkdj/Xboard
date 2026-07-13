<?php

declare(strict_types=1);

require '/www/vendor/autoload.php';

$container = new Illuminate\Container\Container();
$container->instance('app', $container);
$container->instance('hook.filters', []);
Illuminate\Support\Facades\Facade::setFacadeApplication($container);

$expand = static function (array $server): array {
    $protocol = new class([], [$server], 'general', '1.0.0', 'test') extends App\Support\AbstractProtocol {
        public function handle()
        {
            return null;
        }
    };

    $property = new ReflectionProperty(App\Support\AbstractProtocol::class, 'servers');
    $property->setAccessible(true);
    return $property->getValue($protocol);
};

$assertExpansion = static function (array $items, string $expectedNetwork, ?string $expectedTransportValue, string $transportKey): void {
    $expectedHosts = [
        'origin.example.com',
        'uniq.minghsui.com',
        'cmcc.minghsui.com',
        'ctex.minghsui.com',
    ];

    if (count($items) !== 4) {
        throw new RuntimeException('Expected 4 carrier entries, got ' . count($items));
    }

    $hosts = array_map(static fn(array $item): string => (string) ($item['host'] ?? ''), $items);
    if ($hosts !== $expectedHosts) {
        throw new RuntimeException('Unexpected hosts: ' . json_encode($hosts, JSON_UNESCAPED_SLASHES));
    }

    foreach ($items as $item) {
        $network = data_get($item, 'protocol_settings.network');
        if ($network !== $expectedNetwork) {
            throw new RuntimeException("Expected network {$expectedNetwork}, got {$network}");
        }

        $actual = data_get($item, "protocol_settings.network_settings.{$transportKey}");
        if ($actual !== $expectedTransportValue) {
            throw new RuntimeException("Transport setting {$transportKey} changed: " . var_export($actual, true));
        }
    }
};

$grpcItems = $expand([
    'id' => 900001,
    'name' => 'AUTO-GRPC',
    'type' => 'vless',
    'host' => 'origin.example.com',
    'port' => 443,
    'password' => '00000000-0000-0000-0000-000000000000',
    'protocol_settings' => [
        'tls' => 0,
        'flow' => null,
        'network' => 'grpc',
        'network_settings' => [
            'serviceName' => 'shared-grpc-service',
        ],
        'tls_settings' => [
            'server_name' => 'origin.example.com',
        ],
    ],
]);
$assertExpansion($grpcItems, 'grpc', 'shared-grpc-service', 'serviceName');

$upgradeItems = $expand([
    'id' => 900002,
    'name' => 'AUTO-HTTPUPGRADE',
    'type' => 'vless',
    'host' => 'origin.example.com',
    'port' => 443,
    'password' => '00000000-0000-0000-0000-000000000000',
    'protocol_settings' => [
        'tls' => 1,
        'flow' => null,
        'network' => 'httpupgrade',
        'network_settings' => [
            'path' => '/shared-path',
            'host' => 'origin.example.com',
            'acceptProxyProtocol' => false,
        ],
        'tls_settings' => [
            'server_name' => 'origin.example.com',
        ],
    ],
]);
$assertExpansion($upgradeItems, 'httpupgrade', '/shared-path', 'path');

echo "Carrier expansion self-test passed: automatic gRPC/HTTPUpgrade expansion with shared transport settings.\n";
