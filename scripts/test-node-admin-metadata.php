<?php

declare(strict_types=1);

require '/www/vendor/autoload.php';

use App\Models\Server;
use App\Services\ServerTrafficResetService;
use Carbon\CarbonImmutable;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = new ServerTrafficResetService();
$server = new Server();
$server->traffic_reset_day = 31;

$nextInFebruary = $service->calculateNextResetAt(
    $server,
    CarbonImmutable::parse('2026-02-01 12:00:00', 'UTC')
);
$assert(
    $nextInFebruary?->format('Y-m-d H:i:s') === '2026-02-28 00:00:00',
    'Reset day 31 must use the final day of February'
);

$nextAfterFebruaryReset = $service->calculateNextResetAt(
    $server,
    CarbonImmutable::parse('2026-02-28 01:00:00', 'UTC')
);
$assert(
    $nextAfterFebruaryReset?->format('Y-m-d H:i:s') === '2026-03-31 00:00:00',
    'Next reset after February must be March 31'
);

$latestScheduled = $service->calculateLatestScheduledAt(
    $server,
    CarbonImmutable::parse('2026-02-28 01:00:00', 'UTC')
);
$assert(
    $latestScheduled?->format('Y-m-d H:i:s') === '2026-02-28 00:00:00',
    'Latest scheduled reset must resolve to February 28'
);

$checks = [
    '/www/app/Models/Server.php' => [
        "'traffic_reset_day' => 'integer'",
        "'last_traffic_reset_at' => 'integer'",
    ],
    '/www/app/Http/Requests/Admin/ServerSave.php' => [
        "'traffic_reset_day' => 'nullable|integer|min:1|max:31'",
        "'remark' => 'nullable|string|max:2000'",
    ],
    '/www/app/Http/Controllers/V2/Admin/Server/ManageController.php' => [
        'transfer_enable_gb',
        'next_traffic_reset_at',
        'normalizeAdministrativeMetadata',
    ],
    '/www/app/Console/Kernel.php' => [
        "reset:server-traffic",
    ],
    '/www/resources/views/admin.blade.php' => [
        '/assets/custom/admin-node-metadata.js',
        "filemtime(public_path('assets/custom/admin-node-metadata.js'))",
    ],
    '/www/public/assets/custom/admin-node-metadata.js' => [
        'traffic_reset_day',
        'isManagedUrl(url, "save")',
        'isManagedUrl(url, "getNodes")',
        'String(remarkInput?.value || "").trim()',
        'if (meta.textContent !== summary.short)',
        'function inheritNativeEditorTheme',
        'nativeControlClass',
        'font-family: inherit',
        'hsl(var(--muted-foreground',
    ],
    '/www/database/migrations/2026_07_14_000001_add_admin_metadata_to_servers.php' => [
        'traffic_reset_day',
        'last_traffic_reset_at',
        'remark',
    ],
];

foreach ($checks as $path => $needles) {
    $content = file_get_contents($path);
    $assert($content !== false, "Unable to read {$path}");

    foreach ($needles as $needle) {
        $assert(str_contains($content, $needle), "Missing '{$needle}' in {$path}");
    }
}

echo "Node admin metadata self-test passed: limit, reset day, remark, schedule, drawer UI, cache-safe rendering, and active-theme inheritance are present.\n";
