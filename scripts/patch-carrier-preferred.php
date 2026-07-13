<?php

declare(strict_types=1);

$target = $argv[1] ?? null;
if (!$target || !is_file($target)) {
    fwrite(STDERR, "Target file not found: {$target}\n");
    exit(1);
}

$content = file_get_contents($target);
if ($content === false) {
    fwrite(STDERR, "Unable to read target file: {$target}\n");
    exit(1);
}

$replaceExact = static function (string $search, string $replace, string $label) use (&$content): void {
    $count = substr_count($content, $search);
    if ($count !== 1) {
        fwrite(STDERR, "Patch '{$label}' expected 1 match, got {$count}\n");
        exit(1);
    }
    $content = str_replace($search, $replace, $content);
};

$replaceExact(
    "    private const CARRIER_PREFERRED_VLESS_SERVERS = [\n" .
    "        '联通优选网' => 'uniq.minghsui.com',\n" .
    "        '移动优选网' => 'cmcc.minghsui.com',\n" .
    "    ];",
    "    private const CARRIER_PREFERRED_VLESS_SERVERS = [\n" .
    "        '联通优选网' => 'uniq.minghsui.com',\n" .
    "        '移动优选网' => 'cmcc.minghsui.com',\n" .
    "        '电信优选网' => 'ctex.minghsui.com',\n" .
    "    ];",
    'carrier hosts'
);

$replaceExact(
    '     * 优选入口会使用自己的稳定随机 path；不会复用主节点 path。',
    '     * 联通、移动、电信优选入口只替换连接地址，path/serviceName 等传输参数与原始节点完全一致。',
    'carrier comment'
);

$replaceExact(
    "        if (str_contains(\$name, '联通优选网') || str_contains(\$name, '移动优选网')) {",
    "        if (str_contains(\$name, '联通优选网') || str_contains(\$name, '移动优选网') || str_contains(\$name, '电信优选网')) {",
    'carrier duplicate guard'
);

$replaceExact(
    "        \$name = str_replace(['联通优选网', '移动优选网', '联通', '移动'], '', \$name);",
    "        \$name = str_replace(['联通优选网', '移动优选网', '电信优选网', '联通', '移动', '电信'], '', \$name);",
    'carrier base name cleanup'
);

$pattern = '~    protected function buildCarrierPreferredVlessClone\(array \$server, string \$suffix, string \$preferredHost\): array\n    \{\n.*?\n    \}\n\n    protected function resolveCarrierPreferredPath\(~s';

$replacement = <<<'PHP'
    protected function buildCarrierPreferredVlessClone(array $server, string $suffix, string $preferredHost): array
    {
        $clone = $server;
        $protocolSettings = data_get($clone, 'protocol_settings', []);
        if (!is_array($protocolSettings)) {
            return $clone;
        }

        $network = data_get($protocolSettings, 'network');
        $networkSettings = data_get($protocolSettings, 'network_settings', []);
        if (!is_array($networkSettings)) {
            return $clone;
        }

        // 清理旧版本保存的优选随机 path 元数据；所有优选入口统一沿用原始节点传输参数。
        $networkSettings = $this->stripCarrierPreferredMeta($networkSettings);
        $realHost = $this->resolveCarrierPreferredRealHost($server, $protocolSettings);

        if ($network === 'httpupgrade') {
            $networkSettings['acceptProxyProtocol'] = (bool) data_get($networkSettings, 'acceptProxyProtocol', false);
            $networkSettings['host'] = $realHost;
            // path 保持原始节点值，不再单独随机。
        }

        if ($network === 'ws') {
            $headers = data_get($networkSettings, 'headers', []);
            if (!is_array($headers)) {
                $headers = [];
            }
            $headers['Host'] = $realHost;
            $networkSettings['headers'] = $headers;
            // path 保持原始节点值，不再单独随机。
        }

        // grpc/h2/http/xhttp 的 serviceName/path/host 等字段直接完整继承原始节点。
        $clone['protocol_settings']['network_settings'] = $networkSettings;

        return $clone;
    }

    protected function resolveCarrierPreferredPath(
PHP;

$patched = preg_replace($pattern, $replacement, $content, 1, $count);
if ($patched === null || $count !== 1) {
    fwrite(STDERR, "Patch 'carrier clone method' expected 1 match, got {$count}\n");
    exit(1);
}
$content = $patched;

if (file_put_contents($target, $content) === false) {
    fwrite(STDERR, "Unable to write patched file: {$target}\n");
    exit(1);
}

echo "Patched carrier preferred entries: Unicom, Mobile, Telecom; shared original transport path.\n";
