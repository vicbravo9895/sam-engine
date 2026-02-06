<?php

namespace App\Jobs;

use App\Models\PendingWebhook;
use App\Models\Vehicle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reprocesa webhooks que no pudieron asociarse a un vehículo/empresa.
 * 
 * Se ejecuta via scheduler cada 5 minutos y también se dispara
 * al finalizar SyncVehicles para procesar inmediatamente.
 */
class ProcessPendingWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 120;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $pendingWebhooks = PendingWebhook::unresolved()
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        if ($pendingWebhooks->isEmpty()) {
            return;
        }

        Log::info('Processing pending webhooks', [
            'count' => $pendingWebhooks->count(),
        ]);

        $resolved = 0;
        $retried = 0;
        $exhausted = 0;

        foreach ($pendingWebhooks as $pending) {
            $vehicle = Vehicle::where('samsara_id', $pending->vehicle_samsara_id)
                ->whereNotNull('company_id')
                ->first();

            if (!$vehicle) {
                $pending->incrementAttempt();

                if ($pending->attempts >= $pending->max_attempts) {
                    $exhausted++;
                    Log::warning('Pending webhook exhausted max attempts', [
                        'id' => $pending->id,
                        'vehicle_samsara_id' => $pending->vehicle_samsara_id,
                        'attempts' => $pending->attempts,
                    ]);
                } else {
                    $retried++;
                }
                continue;
            }

            // Vehicle found -- re-process as a normal webhook
            try {
                $controller = app(\App\Http\Controllers\SamsaraWebhookController::class);
                $request = new \Illuminate\Http\Request();
                $request->replace($pending->raw_payload);

                $response = $controller->handle($request);
                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
                    $pending->markResolved("Vehículo encontrado (company_id: {$vehicle->company_id})");
                    $resolved++;
                } else {
                    $pending->incrementAttempt();
                    $retried++;
                    Log::warning('Pending webhook re-processing failed', [
                        'id' => $pending->id,
                        'status_code' => $statusCode,
                    ]);
                }
            } catch (\Exception $e) {
                $pending->incrementAttempt();
                $retried++;
                Log::error('Error re-processing pending webhook', [
                    'id' => $pending->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Pending webhooks batch completed', [
            'resolved' => $resolved,
            'retried' => $retried,
            'exhausted' => $exhausted,
        ]);
    }
}
