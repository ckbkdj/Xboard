<?php

namespace App\Support;

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
     * 原始节点的 TLS SNI、WS Host、gRPC serviceName、path、uuid、port 等配置保持不变，只替换连接入口 host。
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
                $clone = $server;
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
