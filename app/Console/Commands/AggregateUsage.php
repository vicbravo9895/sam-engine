<?php

namespace App\Console\Commands;

use App\Models\UsageDailySummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateUsage extends Command
{
    protected $signature = 'sam:aggregate-usage
                            {--date= : Date to aggregate (YYYY-MM-DD, or "today"/"yesterday"), defaults to yesterday}';
    protected $description = 'Aggregate usage_events into daily summaries for billing';

    public function handle(): int
    {
        $dateOpt = $this->option('date');
        $date = match (strtolower($dateOpt ?? '')) {
            'today' => now()->toDateString(),
            'yesterday' => now()->subDay()->toDateString(),
            default => $dateOpt
                ? \Carbon\Carbon::parse($dateOpt)->toDateString()
                : now()->subDay()->toDateString(),
        };

        $this->info("Aggregating usage for {$date}...");

        $rows = DB::table('usage_events')
            ->select('company_id', 'meter', DB::raw('SUM(qty) as total_qty'))
            ->whereDate('occurred_at', $date)
            ->groupBy('company_id', 'meter')
            ->get();

        $upserted = 0;

        foreach ($rows as $row) {
            UsageDailySummary::updateOrCreate(
                [
                    'company_id' => $row->company_id,
                    'date' => $date,
                    'meter' => $row->meter,
                ],
                [
                    'total_qty' => $row->total_qty,
                    'computed_at' => now(),
                ],
            );
            $upserted++;
        }

        $this->info("Done: {$upserted} summaries upserted for {$date}.");

        return self::SUCCESS;
    }
}
