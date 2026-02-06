<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Show Reverb and VITE_REVERB env vars as seen by the app (e.g. inside Docker).
 * Use from inside the container: php artisan env:reverb
 * Secrets are masked. Use --show-keys to see key prefix (for debugging).
 */
class ShowReverbEnvCommand extends Command
{
    protected $signature = 'env:reverb
                            {--show-keys : Show first 8 chars of APP_KEY and REVERB keys (avoid in logs)}';

    protected $description = 'Show Reverb/VITE env vars as seen by this process (for debugging Docker/Dokploy)';

    public function handle(): int
    {
        $mask = ! $this->option('show-keys');

        $vars = [
            'REVERB_APP_ID' => env('REVERB_APP_ID'),
            'REVERB_APP_KEY' => $this->mask(env('REVERB_APP_KEY'), 8, $mask),
            'REVERB_APP_SECRET' => $this->mask(env('REVERB_APP_SECRET'), 8, $mask),
            'REVERB_HOST' => env('REVERB_HOST'),
            'REVERB_PORT' => env('REVERB_PORT'),
            'REVERB_SCHEME' => env('REVERB_SCHEME'),
            'REVERB_SERVER_HOST' => env('REVERB_SERVER_HOST'),
            'REVERB_SERVER_PORT' => env('REVERB_SERVER_PORT'),
            'REVERB_SERVER_SCHEME' => env('REVERB_SERVER_SCHEME'),
            'BROADCAST_CONNECTION' => config('broadcasting.default'),
            // VITE_* are only used at frontend build time; here we show what the container has (for build debugging)
            'VITE_REVERB_APP_KEY' => $this->mask(env('VITE_REVERB_APP_KEY'), 8, $mask),
            'VITE_REVERB_HOST' => env('VITE_REVERB_HOST'),
            'VITE_REVERB_PORT' => env('VITE_REVERB_PORT'),
            'VITE_REVERB_SCHEME' => env('VITE_REVERB_SCHEME'),
        ];

        $this->line('');
        $this->info('Reverb / VITE env as seen by this container:');
        $this->line(str_repeat('-', 60));

        foreach ($vars as $key => $value) {
            $display = $value === null || $value === '' ? '<empty>' : $value;
            $this->line(sprintf('  %-24s = %s', $key, $display));
        }

        $this->line(str_repeat('-', 60));
        $this->line('');
        $this->comment('Note: Frontend WebSocket URL is baked at BUILD time from VITE_REVERB_*.');
        $this->comment('To see what the browser uses (baked in JS), inside container run:');
        $this->comment('  grep -l "localhost\\|sam-local-key" public/build/assets/*.js 2>/dev/null || true');
        $this->line('');

        return self::SUCCESS;
    }

    private function mask(?string $value, int $visible, bool $doMask): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (! $doMask) {
            return strlen($value) > $visible ? substr($value, 0, $visible) . '...' : $value;
        }
        return str_repeat('*', min(strlen($value), 24));
    }
}
