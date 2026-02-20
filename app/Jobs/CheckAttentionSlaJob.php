<?php

namespace App\Jobs;

use App\Services\AttentionEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job that runs every minute to find overdue events
 * and trigger automatic escalation via the AttentionEngine.
 */
class CheckAttentionSlaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public $timeout = 60;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(AttentionEngine $engine): void
    {
        $escalated = $engine->checkAndEscalateOverdue();

        if ($escalated > 0) {
            Log::info('CheckAttentionSlaJob: completed', [
                'escalated_count' => $escalated,
            ]);
        }
    }
}
