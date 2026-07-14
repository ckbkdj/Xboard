<?php

declare(strict_types=1);

$root = rtrim($argv[1] ?? '/www', '/');
$path = "{$root}/app/Protocols/ClashMeta.php";
$content = file_get_contents($path);

if ($content === false) {
    fwrite(STDERR, "Unable to read {$path}\n");
    exit(1);
}

$replaceExact = static function (string $content, string $search, string $replace, string $label): string {
    $count = substr_count($content, $search);
    if ($count !== 1) {
        fwrite(STDERR, "Patch '{$label}' expected 1 match, got {$count}\n");
        exit(1);
    }
    return str_replace($search, $replace, $content);
};

$replaceRegex = static function (string $content, string $pattern, string $replace, string $label): string {
    $patched = preg_replace($pattern, $replace, $content, 1, $count);
    if ($patched === null || $count !== 1) {
        fwrite(STDERR, "Patch '{$label}' expected 1 match, got {$count}\n");
        exit(1);
    }
    return $patched;
};

// This serializer is shared by every ClashMeta proxy type. The previous inline
// depth of 2 compressed every node into a JSON-like flow mapping. A very high
// inline depth keeps VLESS, VMess, Trojan, Shadowsocks, Hysteria, Hysteria2,
// TUIC, AnyTLS, SOCKS, HTTP, Mieru and all nested option maps/lists in normal
// readable block YAML.
$content = $replaceExact(
    $content,
    "        \$yaml = Yaml::dump(\$config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);",
    "        \$yaml = Yaml::dump(\$config, 99, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);",
    'all-protocol standard block YAML output'
);

// Keep Hysteria2 fields ordered and complete. This changes field content only;
// block-style formatting for every protocol is controlled globally above.
$method = <<<'PHP'
    public static function buildHysteria($password, $server, $user)
    {
        $protocolSettings = data_get($server, 'protocol_settings', []);
        $version = (int) data_get($protocolSettings, 'version', 2);
        $name = (string) data_get($server, 'name', 'Hysteria');
        $host = data_get($server, 'host');
        $port = (int) data_get($server, 'port');

        if ($version === 2) {
            $alpn = data_get(
                $protocolSettings,
                'tls.alpn',
                data_get($protocolSettings, 'alpn', ['h3'])
            );

            if (is_string($alpn)) {
                $alpn = array_values(array_filter(array_map(
                    static fn($value) => trim($value),
                    explode(',', $alpn)
                )));
            }
            if (!is_array($alpn) || $alpn === []) {
                $alpn = ['h3'];
            }

            $array = [
                'name' => $name,
                'type' => 'hysteria2',
                'server' => $host,
                'port' => $port,
                'password' => $password,
            ];

            if ($sni = data_get($protocolSettings, 'tls.server_name')) {
                $array['sni'] = $sni;
            }
            $array['skip-cert-verify'] = (bool) data_get(
                $protocolSettings,
                'tls.allow_insecure',
                false
            );
            $array['alpn'] = array_values($alpn);

            if (data_get($protocolSettings, 'obfs.open')) {
                $array['obfs'] = data_get($protocolSettings, 'obfs.type', 'salamander');
                $array['obfs-password'] = data_get($protocolSettings, 'obfs.password');
            }

            $array['udp'] = true;

            $up = data_get($protocolSettings, 'bandwidth.up');
            if ($up !== null && $up !== '') {
                $array['up'] = (int) $up;
            }
            $down = data_get($protocolSettings, 'bandwidth.down');
            if ($down !== null && $down !== '') {
                $array['down'] = (int) $down;
            }

            if (!empty($server['ports'])) {
                $array['ports'] = $server['ports'];
            }
            if ($hopInterval = data_get($protocolSettings, 'hop_interval')) {
                $array['hop-interval'] = (int) $hopInterval;
            }

            return $array;
        }

        $array = [
            'name' => $name,
            'type' => 'hysteria',
            'server' => $host,
            'port' => $port,
            'auth_str' => $password,
        ];

        if ($sni = data_get($protocolSettings, 'tls.server_name')) {
            $array['sni'] = $sni;
        }
        $array['skip-cert-verify'] = (bool) data_get(
            $protocolSettings,
            'tls.allow_insecure',
            false
        );

        $up = data_get($protocolSettings, 'bandwidth.up');
        if ($up !== null && $up !== '') {
            $array['up'] = (int) $up;
        }
        $down = data_get($protocolSettings, 'bandwidth.down');
        if ($down !== null && $down !== '') {
            $array['down'] = (int) $down;
        }

        $array['protocol'] = 'udp';
        if (data_get($protocolSettings, 'obfs.open')) {
            $array['obfs'] = data_get($protocolSettings, 'obfs.password');
        }
        $array['fast-open'] = true;
        $array['disable_mtu_discovery'] = true;

        if (!empty($server['ports'])) {
            $array['ports'] = $server['ports'];
        }
        if ($hopInterval = data_get($protocolSettings, 'hop_interval')) {
            $array['hop-interval'] = (int) $hopInterval;
        }

        return $array;
    }

    public static function buildTuic
PHP;

$content = $replaceRegex(
    $content,
    '~    public static function buildHysteria\(\$password, \$server, \$user\)\n    \{.*?\n    \}\n\n    public static function buildTuic~s',
    $method,
    'standard Hysteria2 proxy fields'
);

if (file_put_contents($path, $content) === false) {
    fwrite(STDERR, "Unable to write {$path}\n");
    exit(1);
}

echo "Patched ClashMeta: every protocol uses standard block YAML; Hysteria2 fields normalized.\n";
