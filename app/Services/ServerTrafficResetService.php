<?php

namespace App\Services;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServerTrafficResetService
{
    public function calculateNextResetAt(Server $server, ?CarbonInterface $from = null): ?CarbonImmutable
    {
        $day = $this->normalizeResetDay($server->traffic_reset_day);
        if ($day === null) {
            return null;
        }

        $moment = $this->toImmutable($from);
        $candidate = $this->scheduledAt($moment->startOfMonth(), $day);

        if ($candidate->lessThanOrEqualTo($moment)) {
            $candidate = $this->scheduledAt($moment->addMonthNoOverflow()->startOfMonth(), $day);
        }

        return $candidate;
    }

    public function calculateLatestScheduledAt(Server $server, ?CarbonInterface $from = null): ?CarbonImmutable
    {
        $day = $this->normalizeResetDay($server->traffic_reset_day);
        if ($day === null) {
            return null;
        }

        $moment = $this->toImmutable($from);
        $candidate = $this->scheduledAt($moment->startOfMonth(), $day);

        if ($candidate->greaterThan($moment)) {
            $candidate = $this->scheduledAt($moment->subMonthNoOverflow()->startOfMonth(), $day);
        }

        return $candidate;
    }

    /**
     * Reset every server whose most recent scheduled reset has not yet run.
     *
     * The query is deliberately independent from traffic usage so a zero-usage
     * server still receives a reset marker and cannot be processed repeatedly.
     */
    public function resetDueServers(?CarbonInterface $at = null): array
    {
        $now = $this->toImmutable($at);
        $result = [
            'processed' => 0,
            'reset' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        Server::query()
            ->whereNotNull('traffic_reset_day')
            ->whereBetween('traffic_reset_day', [1, 31])
            ->orderBy('id')
            ->chunkById(100, function ($servers) use ($now, &$result) {
                foreach ($servers as $candidate) {
                    $result['processed']++;

                    try {
                        $didReset = DB::transaction(function () use ($candidate, $now): bool {
                            /** @var Server|null $server */
                            $server = Server::query()
                                ->whereKey($candidate->id)
                                ->lockForUpdate()
                                ->first();

                            if (!$server) {
                                return false;
                            }

                            $dueAt = $this->calculateLatestScheduledAt($server, $now);
                            if (!$dueAt) {
                                return false;
                            }

                            $lastResetAt = $server->last_traffic_reset_at
                                ? CarbonImmutable::createFromTimestamp(
                                    (int) $server->last_traffic_reset_at,
                                    $now->getTimezone()
                                )
                                : null;

                            if ($lastResetAt && $lastResetAt->greaterThanOrEqualTo($dueAt)) {
                                return false;
                            }

                            $server->forceFill([
                                'u' => 0,
                                'd' => 0,
                                'last_traffic_reset_at' => $now->timestamp,
                            ])->saveQuietly();

                            Log::info('Server traffic reset automatically', [
                                'server_id' => $server->id,
                                'server_name' => $server->name,
                                'scheduled_at' => $dueAt->toDateTimeString(),
                                'reset_at' => $now->toDateTimeString(),
                            ]);

                            return true;
                        });

                        if ($didReset) {
                            $result['reset']++;
                        } else {
                            $result['skipped']++;
                        }
                    } catch (\Throwable $e) {
                        $result['errors']++;
                        Log::error('Automatic server traffic reset failed', [
                            'server_id' => $candidate->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $result;
    }

    private function scheduledAt(CarbonImmutable $month, int $day): CarbonImmutable
    {
        return $month
            ->startOfMonth()
            ->day(min($day, $month->daysInMonth))
            ->startOfDay();
    }

    private function normalizeResetDay(mixed $day): ?int
    {
        if ($day === null || $day === '') {
            return null;
        }

        $day = (int) $day;
        return $day >= 1 && $day <= 31 ? $day : null;
    }

    private function toImmutable(?CarbonInterface $moment): CarbonImmutable
    {
        if ($moment) {
            return CarbonImmutable::instance($moment);
        }

        return CarbonImmutable::now(config('app.timezone'));
    }
}
