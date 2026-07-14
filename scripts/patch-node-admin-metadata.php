<?php

declare(strict_types=1);

$root = rtrim($argv[1] ?? '/www', '/');

$read = static function (string $path): string {
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "Unable to read {$path}\n");
        exit(1);
    }
    return $content;
};

$write = static function (string $path, string $content): void {
    if (file_put_contents($path, $content) === false) {
        fwrite(STDERR, "Unable to write {$path}\n");
        exit(1);
    }
};

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

// -------------------------------------------------------------------------
// Server model casts
// -------------------------------------------------------------------------
$path = "{$root}/app/Models/Server.php";
$content = $read($path);
$content = $replaceExact(
    $content,
    "        'transfer_enable' => 'integer',\n" .
    "        'u' => 'integer',\n" .
    "        'd' => 'integer',\n" .
    "        'machine_id' => 'integer',",
    "        'transfer_enable' => 'integer',\n" .
    "        'traffic_reset_day' => 'integer',\n" .
    "        'last_traffic_reset_at' => 'integer',\n" .
    "        'u' => 'integer',\n" .
    "        'd' => 'integer',\n" .
    "        'machine_id' => 'integer',",
    'server metadata casts'
);
$write($path, $content);

// -------------------------------------------------------------------------
// Admin validation
// -------------------------------------------------------------------------
$path = "{$root}/app/Http/Requests/Admin/ServerSave.php";
$content = $read($path);
$content = $replaceExact(
    $content,
    "            'transfer_enable' => 'nullable|integer|min:0',",
    "            'transfer_enable' => 'nullable|integer|min:0',\n" .
    "            'traffic_reset_day' => 'nullable|integer|min:1|max:31',\n" .
    "            'remark' => 'nullable|string|max:2000',",
    'server metadata validation rules'
);
$content = $replaceExact(
    $content,
    "            'transfer_enable.integer' => '流量上限必须是整数',\n" .
    "            'transfer_enable.min' => '流量上限不能小于0',",
    "            'transfer_enable.integer' => '流量上限必须是整数',\n" .
    "            'transfer_enable.min' => '流量上限不能小于0',\n" .
    "            'traffic_reset_day.integer' => '重置日必须是整数',\n" .
    "            'traffic_reset_day.min' => '重置日不能小于1',\n" .
    "            'traffic_reset_day.max' => '重置日不能大于31',\n" .
    "            'remark.max' => '节点备注不能超过2000个字符',",
    'server metadata validation messages'
);
$write($path, $content);

// -------------------------------------------------------------------------
// Admin controller response/save/reset behavior
// -------------------------------------------------------------------------
$path = "{$root}/app/Http/Controllers/V2/Admin/Server/ManageController.php";
$content = $read($path);
$content = $replaceExact(
    $content,
    "use App\\Services\\ServerService;",
    "use App\\Services\\ServerService;\nuse App\\Services\\ServerTrafficResetService;",
    'server traffic reset service import'
);

$content = $replaceExact(
    $content,
    <<<'PHP'
    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers()->map(function ($item) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'] ?? [])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            return $item;
        });
        return $this->success($servers);
    }
PHP,
    <<<'PHP'
    public function getNodes(Request $request)
    {
        $trafficResetService = app(ServerTrafficResetService::class);

        $servers = ServerService::getAllServers()->map(function ($item) use ($trafficResetService) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'] ?? [])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            $item['transfer_enable_gb'] = (int) $item->transfer_enable > 0
                ? round((int) $item->transfer_enable / 1024 / 1024 / 1024, 3)
                : 0;
            $item['next_traffic_reset_at'] = $trafficResetService
                ->calculateNextResetAt($item)?->timestamp;

            return $item;
        });

        return $this->success($servers);
    }
PHP,
    'admin node metadata response'
);

$content = $replaceRegex(
    $content,
    '~    public function save\(ServerSave \$request\)\n    \{\n.*?\n    \}\n\n    public function update\(Request \$request\)~s',
    <<<'PHP'
    public function save(ServerSave $request)
    {
        $params = $request->validated();

        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }

            $params = $this->normalizeAdministrativeMetadata($server, $params);

            try {
                $server->update($params);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        $params = $this->normalizeAdministrativeMetadata(null, $params);

        try {
            Server::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }
    }

    private function normalizeAdministrativeMetadata(?Server $server, array $params): array
    {
        if (array_key_exists('transfer_enable', $params)) {
            $limit = (int) ($params['transfer_enable'] ?? 0);
            $params['transfer_enable'] = max(0, $limit);
        }

        if (array_key_exists('traffic_reset_day', $params)) {
            $newDay = $params['traffic_reset_day'] === null || $params['traffic_reset_day'] === ''
                ? null
                : (int) $params['traffic_reset_day'];
            $oldDay = $server?->traffic_reset_day
                ? (int) $server->traffic_reset_day
                : null;

            $params['traffic_reset_day'] = $newDay;

            // Changing the schedule starts a new cycle from now. This avoids an
            // immediate surprise reset when an administrator first enables it.
            if ($oldDay !== $newDay) {
                $params['last_traffic_reset_at'] = $newDay ? time() : null;
            }
        }

        if (array_key_exists('remark', $params)) {
            $remark = trim((string) ($params['remark'] ?? ''));
            $params['remark'] = $remark !== '' ? $remark : null;
        }

        return $params;
    }

    public function update(Request $request)
PHP,
    'admin node metadata save flow'
);

$content = $replaceExact(
    $content,
    "            \$server->u = 0;\n" .
    "            \$server->d = 0;\n" .
    "            \$server->save();",
    "            \$server->u = 0;\n" .
    "            \$server->d = 0;\n" .
    "            \$server->last_traffic_reset_at = time();\n" .
    "            \$server->save();",
    'manual node traffic reset marker'
);

$content = $replaceExact(
    $content,
    "            Server::whereIn('id', \$ids)->update([\n" .
    "                'u' => 0,\n" .
    "                'd' => 0,\n" .
    "            ]);",
    "            Server::whereIn('id', \$ids)->update([\n" .
    "                'u' => 0,\n" .
    "                'd' => 0,\n" .
    "                'last_traffic_reset_at' => time(),\n" .
    "            ]);",
    'batch node traffic reset marker'
);

$content = $replaceExact(
    $content,
    "        \$copiedServer->u = 0;\n" .
    "        \$copiedServer->d = 0;\n" .
    "        \$copiedServer->save();",
    "        \$copiedServer->u = 0;\n" .
    "        \$copiedServer->d = 0;\n" .
    "        \$copiedServer->last_traffic_reset_at = \$copiedServer->traffic_reset_day ? time() : null;\n" .
    "        \$copiedServer->save();",
    'copied node traffic reset anchor'
);
$write($path, $content);

// -------------------------------------------------------------------------
// Scheduler
// -------------------------------------------------------------------------
$path = "{$root}/app/Console/Kernel.php";
$content = $read($path);
$content = $replaceExact(
    $content,
    "        \$schedule->command('reset:traffic')->everyMinute()->onOneServer()->withoutOverlapping(10);\n" .
    "        \$schedule->command('reset:log')->daily()->onOneServer();",
    "        \$schedule->command('reset:traffic')->everyMinute()->onOneServer()->withoutOverlapping(10);\n" .
    "        \$schedule->command('reset:server-traffic')->hourlyAt(5)->onOneServer()->withoutOverlapping(30);\n" .
    "        \$schedule->command('reset:log')->daily()->onOneServer();",
    'server traffic reset schedule'
);
$write($path, $content);

// -------------------------------------------------------------------------
// Admin-only UI overlay
// -------------------------------------------------------------------------
$path = "{$root}/resources/views/admin.blade.php";
$content = $read($path);
$content = $replaceExact(
    $content,
    "  </script>\n  @php",
    "  </script>\n" .
    "  <script src=\"/assets/custom/admin-node-metadata.js?v={{ urlencode((string) \$version) }}\"></script>\n" .
    "  @php",
    'admin node metadata asset'
);
$write($path, $content);

echo "Patched administrator node metadata, monthly reset schedule, and admin UI.\n";
