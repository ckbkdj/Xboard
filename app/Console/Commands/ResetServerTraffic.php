<?php

namespace App\Console\Commands;

use App\Services\ServerTrafficResetService;
use Illuminate\Console\Command;

class ResetServerTraffic extends Command
{
    protected $signature = 'reset:server-traffic';

    protected $description = 'Reset node traffic usage according to each node monthly reset day';

    public function __construct(
        private readonly ServerTrafficResetService $serverTrafficResetService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->serverTrafficResetService->resetDueServers();

        $this->info(sprintf(
            'Server traffic reset finished: processed=%d reset=%d skipped=%d errors=%d',
            $result['processed'],
            $result['reset'],
            $result['skipped'],
            $result['errors']
        ));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
