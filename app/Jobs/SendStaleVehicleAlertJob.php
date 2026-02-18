<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\StaleVehicleAlert;
use App\Models\VehicleStat;
use App\Services\ContactResolver;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendStaleVehicleAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [10, 30, 60];

    public function __construct(
        public int $companyId,
        public VehicleStat $vehicleStat,
        public array $config,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(TwilioService $twilioService): void
    {
        $company = Company::find($this->companyId);
        if (!$company) {
            Log::warning('SendStaleVehicleAlert: Company not found', ['company_id' => $this->companyId]);
            return;
        }

        $stat = $this->vehicleStat;
        $vehicleName = $stat->vehicle_name ?? 'Unidad desconocida';
        $lastSyncedAt = $stat->synced_at;
        $timezone = $company->timezone ?? 'America/Mexico_City';

        $minutesAgo = $lastSyncedAt
            ? (int) $lastSyncedAt->diffInMinutes(now())
            : null;

        $timeLabel = $lastSyncedAt
            ? $lastSyncedAt->setTimezone($timezone)->format('d/m/Y H:i')
            : 'nunca';

        $durationLabel = $minutesAgo !== null
            ? ($minutesAgo >= 60
                ? floor($minutesAgo / 60) . 'h ' . ($minutesAgo % 60) . 'min'
                : "{$minutesAgo} minutos")
            : 'tiempo desconocido';

        $messageText = "Alerta de flota: El vehículo {$vehicleName} no ha reportado desde {$timeLabel} ({$durationLabel} sin actividad). Se requiere verificación.";

        $channels = $this->filterChannels($company, $this->config['channels'] ?? ['whatsapp', 'sms']);
        $recipientTypes = $this->config['recipients'] ?? ['monitoring_team', 'supervisor'];

        if (empty($channels)) {
            Log::info('SendStaleVehicleAlert: No enabled channels', [
                'company_id' => $this->companyId,
                'vehicle_id' => $stat->samsara_vehicle_id,
            ]);
            return;
        }

        $resolvedContacts = app(ContactResolver::class)->resolve(
            $stat->samsara_vehicle_id,
            null,
            $this->companyId,
        );

        $recipients = [];
        foreach ($resolvedContacts as $type => $contactData) {
            if ($contactData && in_array($type, $recipientTypes, true)) {
                $recipients[] = array_merge($contactData, ['recipient_type' => $type]);
            }
        }

        if (empty($recipients)) {
            foreach ($resolvedContacts as $type => $contactData) {
                if ($contactData) {
                    $recipients[] = array_merge($contactData, ['recipient_type' => $type]);
                }
            }
        }

        if (empty($recipients)) {
            Log::warning('SendStaleVehicleAlert: No recipients found', [
                'company_id' => $this->companyId,
                'vehicle_id' => $stat->samsara_vehicle_id,
            ]);
            return;
        }

        $results = $this->sendNotifications($twilioService, $channels, $recipients, $messageText);

        $successfulChannels = collect($results)->where('success', true)->pluck('channel')->unique()->values()->toArray();
        $notifiedTypes = collect($results)->where('success', true)->pluck('recipient_type')->unique()->values()->toArray();

        StaleVehicleAlert::create([
            'company_id' => $this->companyId,
            'samsara_vehicle_id' => $stat->samsara_vehicle_id,
            'vehicle_name' => $vehicleName,
            'last_stat_at' => $lastSyncedAt ?? now(),
            'alerted_at' => now(),
            'channels_used' => $successfulChannels,
            'recipients_notified' => $notifiedTypes,
        ]);

        Log::info('SendStaleVehicleAlert: Completed', [
            'company_id' => $this->companyId,
            'vehicle_id' => $stat->samsara_vehicle_id,
            'vehicle_name' => $vehicleName,
            'channels_used' => $successfulChannels,
            'recipients_notified' => $notifiedTypes,
            'total_sent' => count($results),
            'successful' => collect($results)->where('success', true)->count(),
        ]);
    }

    private function sendNotifications(
        TwilioService $twilioService,
        array $channels,
        array $recipients,
        string $messageText,
    ): array {
        $results = [];

        usort($recipients, fn ($a, $b) => ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999));

        foreach (['call', 'whatsapp', 'sms'] as $channel) {
            if (!in_array($channel, $channels)) {
                continue;
            }

            foreach ($recipients as $recipient) {
                $phone = $recipient['phone'] ?? null;
                $whatsapp = $recipient['whatsapp'] ?? null;
                $result = null;

                if ($channel === 'call' && $phone) {
                    $response = $twilioService->makeCall($phone, mb_substr($messageText, 0, 200));
                    $result = [
                        'channel' => 'call',
                        'to' => $phone,
                        'recipient_type' => $recipient['recipient_type'] ?? 'unknown',
                        'success' => $response['success'] ?? false,
                    ];
                } elseif ($channel === 'whatsapp' && ($whatsapp || $phone)) {
                    $target = $whatsapp ?: $phone;
                    $response = $twilioService->sendWhatsappTemplate(
                        to: $target,
                        templateSid: TwilioService::TEMPLATE_FLEET_ALERT,
                        variables: [
                            '1' => 'Vehículo sin reportar',
                            '2' => $this->vehicleStat->vehicle_name ?? 'Unidad desconocida',
                            '3' => 'N/A',
                            '4' => now()->setTimezone($this->getTimezone())->format('d/m/Y H:i'),
                            '5' => $messageText,
                        ],
                    );
                    $result = [
                        'channel' => 'whatsapp',
                        'to' => $target,
                        'recipient_type' => $recipient['recipient_type'] ?? 'unknown',
                        'success' => $response['success'] ?? false,
                    ];
                } elseif ($channel === 'sms' && $phone) {
                    $response = $twilioService->sendSms($phone, $messageText);
                    $result = [
                        'channel' => 'sms',
                        'to' => $phone,
                        'recipient_type' => $recipient['recipient_type'] ?? 'unknown',
                        'success' => $response['success'] ?? false,
                    ];
                }

                if ($result) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    private function filterChannels(Company $company, array $channels): array
    {
        return array_values(array_filter(
            $channels,
            fn (string $ch) => $company->isNotificationChannelEnabled($ch),
        ));
    }

    private function getTimezone(): string
    {
        return Company::find($this->companyId)?->timezone ?? 'America/Mexico_City';
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendStaleVehicleAlert: Failed permanently', [
            'company_id' => $this->companyId,
            'vehicle_id' => $this->vehicleStat->samsara_vehicle_id ?? 'unknown',
            'error' => $exception->getMessage(),
        ]);
    }
}
