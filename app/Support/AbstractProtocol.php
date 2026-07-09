<?php

namespace App\Support;

use App\Models\Server;
use App\Services\Plugin\HookManager;

abstract class AbstractProtocol
{
    /**
     * @var array 用户信息
     */
    protected $user;

    /**
     * @var array 服务器信息
     */
    protected $servers;

    /**
     * @var string|null 客户端名称
     */
    protected $clientName;

    /**
     * @var string|null 客户端版本
     */
    protected $clientVersion;

    /**
     * @var string|null 原始 User-Agent
     */
    protected $userAgent;

    /**
     * @var array 协议标识
     */
    public $flags = [];

    /**
     * @var array 协议需求配置
     */
    protected $protocolRequirements = [];

    /**
     * @var array 允许的协议类型（白名单） 为空则不进行过滤
     */
    protected $allowedProtocols = [];

    /**
     * 运营商优选 VLESS CDN 入口。
     *
     * 仅用于订阅输出层复制普通 TLS VLESS CDN 节点，Reality / Hysteria / 直连 TCP 不参与。
     */
    private const CARRIER_PREFERRED_VLESS_SERVERS = [
        '联通优选网' => 'uniq.minghsui.com',
        '移动优选网' => 'cmcc.minghsui.com',
    ];

    /**
     * 存在主节点 network_settings 内部，给优选订阅项保存稳定 path，不输出给客户端或节点端。
     */
    private const CARRIER_PREFERRED_META_KEY = '_carrier_preferred';

    /**
     * 构造函数
     *
     * @param array $user 用户信息
     * @param array $servers 服务器信息
     * @param string|null $clientName 客户端名称
     * @param string|null $clientVersion 客户端版本
     * @param string|null $userAgent 原始 User-Agent
     */
    public function __construct($user, $servers, $clientName = null, $clientVersion = null, $userAgent = null)
    {
        $this->user = $user;
        $this->servers = $servers;
        $this->clientName = $clientName;
        $this->clientVersion = $clientVersion;
        $this->userAgent = $userAgent;
        $this->protocolRequirements = $this->normalizeProtocolRequirements($this->protocolRequirements);

        $filteredServers = HookManager::filter('protocol.servers.filtered', $this->filterServersByVersion());
        if ($filteredServers instanceof \Illuminate\Support\Collection) {
            $filteredServers = $filteredServers->values()->all();
        }
        if (!is_array($filteredServers)) {
            $filteredServers = is_array($this->servers) ? $this->servers : [];
        }

        // 运营商优选扩展只允许“增量复制节点”，任何异常都必须回退到原始节点列表，避免订阅/节点全空。
        try {
            $this->servers = $this->expandCarrierPreferredVlessServers($filteredServers);
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
            $this->servers = $filteredServers;
        }
    }

    /**
     * 获取协议标识
     *
     * @return array
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * 处理请求
     *
     * @return mixed
     */
    abstract public function handle();

    /**
     * 根据客户端版本过滤不兼容的服务器
     *
     * @return array
     */
    protected function filterServersByVersion()
    {
        $this->filterByAllowedProtocols();
        $hasGlobalConfig = isset($this->protocolRequirements['*']);
        $hasClientConfig = isset($this->protocolRequirements[$this->clientName]);

        if ((blank($this->clientName) || blank($this->clientVersion)) && !$hasGlobalConfig) {
            return $this->servers;
        }

        if (!$hasGlobalConfig && !$hasClientConfig) {
            return $this->servers;
        }

        return collect($this->servers)
            ->filter(fn($server) => $this->isCompatible($server))
            ->values()
            ->all();
    }

    /**
     * 检查服务器是否与当前客户端兼容
     *
     * @param array $server 服务器信息
     * @return bool
     */
    protected function isCompatible($server)
    {
        $serverType = $server['type'] ?? null;
        if (isset($this->protocolRequirements['*'][$serverType])) {
            $globalRequirements = $this->protocolRequirements['*'][$serverType];
            if (!$this->checkRequirements($globalRequirements, $server)) {
                return false;
            }
        }

        if (!isset($this->protocolRequirements[$this->clientName][$serverType])) {
            return true;
        }

        $requirements = $this->protocolRequirements[$this->clientName][$serverType];
        return $this->checkRequirements($requirements, $server);
    }

    /**
     * 检查版本要求
     *
     * @param array $requirements 要求配置
     * @param array $server 服务器信息
     * @return bool
     */
    private function checkRequirements(array $requirements, array $server): bool
    {
        foreach ($requirements as $field => $filterRule) {
            if (in_array($field, ['base_version', 'incompatible'])) {
                continue;
            }

            $actualValue = data_get($server, $field);

            if (is_array($filterRule) && isset($filterRule['whitelist'])) {
                $allowedValues = $filterRule['whitelist'];
                $strict = $filterRule['strict'] ?? false;
                // Normalize flat array ['tcp', 'ws'] to ['tcp' => '0.0.0', 'ws' => '0.0.0']
                if (!empty($allowedValues) && is_int(array_key_first($allowedValues))) {
                    $allowedValues = array_fill_keys($allowedValues, '0.0.0');
                }
                if ($strict) {
                    if ($actualValue === null) {
                        return false;
                    }
                    if (!is_string($actualValue) && !is_int($actualValue)) {
                        return false;
                    }
                    if (!isset($allowedValues[$actualValue])) {
                        return false;
                    }
                    $requiredVersion = $allowedValues[$actualValue];
                    if ($requiredVersion !== '0.0.0' && version_compare($this->clientVersion, $requiredVersion, '<')) {
                        return false;
                    }
                    continue;
                }
            } else {
                $allowedValues = $filterRule;
                $strict = false;
            }

            if ($actualValue === null) {
                continue;
            }
            if (!is_string($actualValue) && !is_int($actualValue)) {
                continue;
            }
            if (!isset($allowedValues[$actualValue])) {
                continue;
            }
            $requiredVersion = $allowedValues[$actualValue];
            if ($requiredVersion !== '0.0.0' && version_compare($this->clientVersion, $requiredVersion, '<')) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查当前客户端是否支持特定功能
     *
     * @param string $clientName 客户端名称
     * @param string $minVersion 最低版本要求
     * @param array $additionalConditions 额外条件检查
     * @return bool
     */
    protected function supportsFeature(string $clientName, string $minVersion, array $additionalConditions = []): bool
    {
        // 检查客户端名称
        if ($this->clientName !== $clientName) {
            return false;
        }

        // 检查版本号
        if (empty($this->clientVersion) || version_compare($this->clientVersion, $minVersion, '<')) {
            return false;
        }

        // 检查额外条件
        foreach ($additionalConditions as $condition) {
            if (!$condition) {
                return false;
            }
        }

        return true;
    }

    /**
     * 根据白名单过滤服务器
     *
     * @return void
     */
    protected function filterByAllowedProtocols(): void
    {
        if (!empty($this->allowedProtocols)) {
            $this->servers = collect($this->servers)
                ->filter(fn($server) => in_array($server['type'], $this->allowedProtocols))
                ->values()
                ->all();
        }
    }

    /**
     * 为普通 TLS VLESS CDN 节点自动增加运营商优选入口。
     *
     * 这个处理放在 AbstractProtocol 层，因此 ClashMeta、General、SingBox 等所有订阅输出都会自动继承。
     * 优选入口会使用自己的稳定随机 path；不会复用主节点 path。
     */
    protected function expandCarrierPreferredVlessServers(array $servers): array
    {
        $expanded = [];

        foreach ($servers as $server) {
            $expanded[] = $server;

            if (!is_array($server) || !$this->shouldExpandCarrierPreferredVless($server)) {
                continue;
            }

            $baseName = $this->carrierPreferredBaseName((string) ($server['name'] ?? 'VLESS'));

            foreach (self::CARRIER_PREFERRED_VLESS_SERVERS as $suffix => $host) {
                $clone = $this->buildCarrierPreferredVlessClone($server, $suffix, $host);
                $clone['name'] = $baseName . '|' . $suffix;
                $clone['host'] = $host;
                $expanded[] = $clone;
            }
        }

        return $expanded;
    }

    /**
     * 只扩展普通 TLS VLESS CDN 传输；Reality、Hysteria、已是优选入口的节点都跳过。
     */
    protected function shouldExpandCarrierPreferredVless(array $server): bool
    {
        if (($server['type'] ?? null) !== 'vless') {
            return false;
        }

        $name = (string) ($server['name'] ?? '');
        if (str_contains($name, '联通优选网') || str_contains($name, '移动优选网')) {
            return false;
        }

        $host = strtolower((string) ($server['host'] ?? ''));
        if ($host === '' || in_array($host, array_map('strtolower', array_values(self::CARRIER_PREFERRED_VLESS_SERVERS)), true)) {
            return false;
        }

        $protocolSettings = data_get($server, 'protocol_settings', []);
        $tlsMode = (int) data_get($protocolSettings, 'tls', 0);
        if ($tlsMode !== 1) {
            return false;
        }

        // Vision/Reality 类节点不做 CDN 优选扩展。
        if (data_get($protocolSettings, 'flow')) {
            return false;
        }

        $network = data_get($protocolSettings, 'network');
        return in_array($network, ['ws', 'grpc', 'h2', 'http', 'httpupgrade', 'xhttp'], true);
    }

    protected function carrierPreferredBaseName(string $name): string
    {
        $name = str_replace(' ', '|', $name);
        $name = str_replace(['联通优选网', '移动优选网', '联通', '移动'], '', $name);
        $name = preg_replace('/[|｜\s]+$/u', '', trim($name));

        return $name !== '' ? $name : 'VLESS';
    }

    protected function buildCarrierPreferredVlessClone(array $server, string $suffix, string $preferredHost): array
    {
        $clone = $server;
        $protocolSettings = data_get($clone, 'protocol_settings', []);
        if (!is_array($protocolSettings)) {
            return $clone;
        }

        $network = data_get($protocolSettings, 'network');
        if (!in_array($network, ['ws', 'httpupgrade'], true)) {
            return $clone;
        }

        $networkSettings = data_get($protocolSettings, 'network_settings', []);
        if (!is_array($networkSettings)) {
            $networkSettings = [];
        }

        $realHost = $this->resolveCarrierPreferredRealHost($server, $protocolSettings);
        $preferredPath = $this->resolveCarrierPreferredPath(
            $server,
            $suffix,
            $preferredHost,
            $protocolSettings,
            $networkSettings,
            $realHost
        );

        $networkSettings = $this->stripCarrierPreferredMeta($networkSettings);
        if ($network === 'httpupgrade') {
            $networkSettings['acceptProxyProtocol'] = (bool) data_get($networkSettings, 'acceptProxyProtocol', false);
            $networkSettings['path'] = $preferredPath;
            $networkSettings['host'] = $realHost;
        }

        if ($network === 'ws') {
            $headers = data_get($networkSettings, 'headers', []);
            if (!is_array($headers)) {
                $headers = [];
            }
            $headers['Host'] = $realHost;
            $networkSettings['path'] = $preferredPath;
            $networkSettings['headers'] = $headers;
        }

        $clone['protocol_settings']['network_settings'] = $networkSettings;

        return $clone;
    }

    protected function resolveCarrierPreferredPath(
        array $server,
        string $suffix,
        string $preferredHost,
        array $protocolSettings,
        array $networkSettings,
        string $realHost
    ): string {
        $key = $this->carrierPreferredKey($preferredHost);
        $sourceSignature = $this->carrierPreferredSourceSignature($server, $protocolSettings, $networkSettings, $realHost);
        $mainPath = data_get($networkSettings, 'path');
        $storedNetworkSettings = $this->loadCarrierPreferredStoredNetworkSettings($server, $networkSettings);
        $metaMap = $storedNetworkSettings[self::CARRIER_PREFERRED_META_KEY] ?? [];
        $meta = is_array($metaMap) ? ($metaMap[$key] ?? []) : [];
        $path = is_array($meta) ? ($meta['path'] ?? null) : null;

        $needsRefresh = !is_array($meta)
            || !$this->isStableHttpTransportPath($path)
            || ($this->isStableHttpTransportPath($mainPath) && $path === $mainPath)
            || (($meta['source_signature'] ?? null) !== $sourceSignature)
            || (($meta['host'] ?? null) !== $preferredHost);

        if ($needsRefresh) {
            $path = $this->generateHttpTransportPath($mainPath);
            $meta = [
                'suffix' => $suffix,
                'host' => $preferredHost,
                'path' => $path,
                'real_host' => $realHost,
                'source_signature' => $sourceSignature,
                'updated_at' => time(),
            ];
            $this->persistCarrierPreferredMeta($server, $key, $meta, $storedNetworkSettings);
        }

        return $path;
    }

    protected function loadCarrierPreferredStoredNetworkSettings(array $server, array $fallbackNetworkSettings): array
    {
        $id = $server['id'] ?? null;
        if (!$id) {
            return $fallbackNetworkSettings;
        }

        try {
            $model = Server::query()->find($id);
            if (!$model) {
                return $fallbackNetworkSettings;
            }

            $networkSettings = data_get($model->protocol_settings, 'network_settings', []);
            return is_array($networkSettings) ? $networkSettings : $fallbackNetworkSettings;
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
            return $fallbackNetworkSettings;
        }
    }

    protected function persistCarrierPreferredMeta(array $server, string $key, array $meta, array $storedNetworkSettings): void
    {
        $id = $server['id'] ?? null;
        if (!$id) {
            return;
        }

        try {
            $model = Server::query()->find($id);
            if (!$model) {
                return;
            }

            $protocolSettings = $model->protocol_settings;
            if (!is_array($protocolSettings)) {
                $protocolSettings = [];
            }

            $networkSettings = data_get($protocolSettings, 'network_settings', []);
            if (!is_array($networkSettings)) {
                $networkSettings = $storedNetworkSettings;
            }

            $metaMap = $networkSettings[self::CARRIER_PREFERRED_META_KEY] ?? [];
            if (!is_array($metaMap)) {
                $metaMap = [];
            }

            if (($metaMap[$key] ?? null) === $meta) {
                return;
            }

            $metaMap[$key] = $meta;
            $networkSettings[self::CARRIER_PREFERRED_META_KEY] = $metaMap;
            $protocolSettings['network_settings'] = $networkSettings;

            $model->protocol_settings = $protocolSettings;
            $model->saveQuietly();
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
        }
    }

    protected function carrierPreferredSourceSignature(array $server, array $protocolSettings, array $networkSettings, string $realHost): string
    {
        $payload = [
            'id' => $server['id'] ?? null,
            'type' => $server['type'] ?? null,
            'host' => $server['host'] ?? null,
            'network' => data_get($protocolSettings, 'network'),
            'tls' => data_get($protocolSettings, 'tls'),
            'real_host' => $realHost,
            'network_settings' => $this->stripCarrierPreferredMeta($networkSettings),
            'tls_settings' => data_get($protocolSettings, 'tls_settings'),
        ];

        return hash('sha256', json_encode($this->recursiveKeySort($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function carrierPreferredKey(string $preferredHost): string
    {
        return hash('sha1', strtolower($preferredHost));
    }

    protected function resolveCarrierPreferredRealHost(array $server, array $protocolSettings): string
    {
        $host = data_get($protocolSettings, 'tls_settings.server_name')
            ?: data_get($protocolSettings, 'server_name')
            ?: ($server['host'] ?? '');

        return trim((string) $host);
    }

    protected function stripCarrierPreferredMeta(array $networkSettings): array
    {
        unset($networkSettings[self::CARRIER_PREFERRED_META_KEY]);
        return $networkSettings;
    }

    protected function isStableHttpTransportPath(mixed $path): bool
    {
        if (!is_string($path)) {
            return false;
        }

        $path = trim($path);
        if ($path === '' || $path === '/') {
            return false;
        }

        return str_starts_with($path, '/') && !preg_match('/[\s?#]/', $path);
    }

    protected function generateHttpTransportPath(mixed $avoidPath = null): string
    {
        $avoidPath = is_string($avoidPath) ? $avoidPath : null;

        for ($i = 0; $i < 5; $i++) {
            try {
                $path = '/lv-' . bin2hex(random_bytes(5));
            } catch (\Throwable) {
                $path = '/lv-' . strtolower(str_replace('.', '', uniqid('', true)));
            }

            if ($path !== $avoidPath) {
                return $path;
            }
        }

        return '/lv-' . strtolower(str_replace('.', '', uniqid('', true)));
    }

    protected function recursiveKeySort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->recursiveKeySort($item);
        }

        ksort($value);
        return $value;
    }

    /**
     * 将平铺的协议需求转换为树形结构
     *
     * @param array $flat 平铺的协议需求
     * @return array 树形结构的协议需求
     */
    protected function normalizeProtocolRequirements(array $flat): array
    {
        $result = [];
        foreach ($flat as $key => $value) {
            if (!str_contains($key, '.')) {
                $result[$key] = $value;
                continue;
            }
            $segments = explode('.', $key, 3);
            if (count($segments) < 3) {
                $result[$segments[0]][$segments[1] ?? '*'][''] = $value;
                continue;
            }
            [$client, $type, $field] = $segments;
            $result[$client][$type][$field] = $value;
        }
        return $result;
    }
}
