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

$replaceRegex = static function (string $pattern, string $replace, string $label) use (&$content): void {
    $patched = preg_replace($pattern, $replace, $content, 1, $count);
    if ($patched === null || $count !== 1) {
        fwrite(STDERR, "Patch '{$label}' expected 1 match, got {$count}\n");
        exit(1);
    }
    $content = $patched;
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
    "        foreach (\$servers as \$server) {\n" .
    "            \$expanded[] = \$server;\n\n" .
    "            if (!is_array(\$server) || !\$this->shouldExpandCarrierPreferredVless(\$server)) {\n" .
    "                continue;\n" .
    "            }",
    "        foreach (\$servers as \$server) {\n" .
    "            if (!is_array(\$server)) {\n" .
    "                \$expanded[] = \$server;\n" .
    "                continue;\n" .
    "            }\n\n" .
    "            // 面板若误把主节点地址填成某个优选域名，则使用 TLS SNI 恢复真实原始域名。\n" .
    "            \$server = \$this->normalizeCarrierPreferredSourceServer(\$server);\n" .
    "            \$expanded[] = \$server;\n\n" .
    "            if (!\$this->shouldExpandCarrierPreferredVless(\$server)) {\n" .
    "                continue;\n" .
    "            }",
    'carrier source normalization'
);

$replaceExact(
    "    /**\n" .
    "     * 只扩展普通 TLS VLESS CDN 传输；Reality、Hysteria、已是优选入口的节点都跳过。\n" .
    "     */\n" .
    "    protected function shouldExpandCarrierPreferredVless(array \$server): bool",
    "    /**\n" .
    "     * 如果数据库中的主节点 host 已经被填成优选域名，则从 TLS SNI 恢复真实入口。\n" .
    "     */\n" .
    "    protected function normalizeCarrierPreferredSourceServer(array \$server): array\n" .
    "    {\n" .
    "        \$preferredHosts = array_map('strtolower', array_values(self::CARRIER_PREFERRED_VLESS_SERVERS));\n" .
    "        \$currentHost = strtolower(trim((string) (\$server['host'] ?? '')));\n" .
    "        if (!in_array(\$currentHost, \$preferredHosts, true)) {\n" .
    "            return \$server;\n" .
    "        }\n\n" .
    "        \$protocolSettings = data_get(\$server, 'protocol_settings', []);\n" .
    "        if (!is_array(\$protocolSettings)) {\n" .
    "            return \$server;\n" .
    "        }\n\n" .
    "        \$realHost = \$this->resolveCarrierPreferredRealHost(\$server, \$protocolSettings);\n" .
    "        if (\$realHost !== '' && !in_array(strtolower(\$realHost), \$preferredHosts, true)) {\n" .
    "            \$server['host'] = \$realHost;\n" .
    "        }\n\n" .
    "        return \$server;\n" .
    "    }\n\n" .
    "    /**\n" .
    "     * 根据真实协议字段自动扩展 VLESS CDN 节点；不依赖节点名称，也不要求管理员手工创建副本。\n" .
    "     */\n" .
    "    protected function shouldExpandCarrierPreferredVless(array \$server): bool",
    'carrier source helper'
);

$shouldExpandPattern = '~    protected function shouldExpandCarrierPreferredVless\(array \$server\): bool\n    \{\n.*?\n    \}\n\n    protected function carrierPreferredBaseName~s';
$shouldExpandReplacement = <<<'PHP'
    protected function shouldExpandCarrierPreferredVless(array $server): bool
    {
        if (($server['type'] ?? null) !== 'vless') {
            return false;
        }

        $protocolSettings = data_get($server, 'protocol_settings', []);
        if (!is_array($protocolSettings)) {
            return false;
        }

        // Reality/XTLS Vision 不走 CDN 优选；普通 TLS 或无 TLS 的 CDN 传输都按实际 network 自动扩展。
        if ((int) data_get($protocolSettings, 'tls', 0) === 2) {
            return false;
        }
        if (trim((string) data_get($protocolSettings, 'flow', '')) !== '') {
            return false;
        }

        $network = strtolower(trim((string) data_get($protocolSettings, 'network', '')));
        return in_array($network, ['ws', 'grpc', 'h2', 'http', 'httpupgrade', 'xhttp'], true);
    }

    protected function carrierPreferredBaseName
PHP;
$replaceRegex($shouldExpandPattern, $shouldExpandReplacement, 'automatic VLESS transport eligibility');

$replaceExact(
    "        \$name = str_replace(['联通优选网', '移动优选网', '联通', '移动'], '', \$name);",
    "        \$name = str_replace(['联通优选网', '移动优选网', '电信优选网', '联通', '移动', '电信'], '', \$name);",
    'carrier base name cleanup'
);

$clonePattern = '~    protected function buildCarrierPreferredVlessClone\(array \$server, string \$suffix, string \$preferredHost\): array\n    \{\n.*?\n    \}\n\n    protected function resolveCarrierPreferredPath\(~s';
$cloneReplacement = <<<'PHP'
    protected function buildCarrierPreferredVlessClone(array $server, string $suffix, string $preferredHost): array
    {
        $clone = $server;
        $protocolSettings = data_get($clone, 'protocol_settings', []);
        if (!is_array($protocolSettings)) {
            return $clone;
        }

        $network = strtolower(trim((string) data_get($protocolSettings, 'network', '')));
        $networkSettings = data_get($protocolSettings, 'network_settings', []);
        if (!is_array($networkSettings)) {
            $networkSettings = [];
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
$replaceRegex($clonePattern, $cloneReplacement, 'carrier clone method');

if (file_put_contents($target, $content) === false) {
    fwrite(STDERR, "Unable to write patched file: {$target}\n");
    exit(1);
}

echo "Patched carrier preferred entries: automatic VLESS transport detection; origin + Unicom + Mobile + Telecom; shared original transport settings.\n";
