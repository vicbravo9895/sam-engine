<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\UsageDailySummary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsageController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->input('period', 'mtd');
        [$from, $to] = $this->resolveDateRange($period, $request);

        $summaries = DB::table('usage_daily_summaries')
            ->join('companies', 'usage_daily_summaries.company_id', '=', 'companies.id')
            ->select(
                'companies.id as company_id',
                'companies.name as company_name',
                'usage_daily_summaries.meter',
                DB::raw('SUM(usage_daily_summaries.total_qty) as total_qty'),
            )
            ->whereBetween('usage_daily_summaries.date', [$from, $to])
            ->groupBy('companies.id', 'companies.name', 'usage_daily_summaries.meter')
            ->get();

        $grouped = $summaries->groupBy('company_id')->map(function ($rows) {
            $first = $rows->first();
            $meters = $rows->pluck('total_qty', 'meter')->toArray();

            return [
                'company_id' => $first->company_id,
                'company_name' => $first->company_name,
                'alerts_processed' => (float) ($meters['alerts_processed'] ?? 0),
                'alerts_revalidated' => (float) ($meters['alerts_revalidated'] ?? 0),
                'ai_tokens' => (float) ($meters['ai_tokens'] ?? 0),
                'notifications_sms' => (float) ($meters['notifications_sms'] ?? 0),
                'notifications_whatsapp' => (float) ($meters['notifications_whatsapp'] ?? 0),
                'notifications_call' => (float) ($meters['notifications_call'] ?? 0),
                'copilot_messages' => (float) ($meters['copilot_messages'] ?? 0),
                'copilot_tokens' => (float) ($meters['copilot_tokens'] ?? 0),
            ];
        })->values();

        $dailyRaw = DB::table('usage_daily_summaries')
            ->whereBetween('date', [$from, $to])
            ->select('date', 'meter', DB::raw('SUM(total_qty) as total_qty'))
            ->groupBy('date', 'meter')
            ->orderBy('date')
            ->get()
            ->groupBy('date');

        $dailySummaries = $dailyRaw->sortKeys()->map(function ($rows, $date) {
            $meters = $rows->pluck('total_qty', 'meter');
            $dateStr = $date instanceof Carbon ? $date->format('Y-m-d') : (string) $date;
            return [
                'date' => $dateStr,
                'alerts' => (float) (($meters['alerts_processed'] ?? 0) + ($meters['alerts_revalidated'] ?? 0)),
                'ai_tokens' => (float) ($meters['ai_tokens'] ?? 0),
                'notifications' => (float) (($meters['notifications_sms'] ?? 0) + ($meters['notifications_whatsapp'] ?? 0) + ($meters['notifications_call'] ?? 0)),
                'copilot' => (float) ($meters['copilot_messages'] ?? 0),
            ];
        })->values()->toArray();

        return Inertia::render('super-admin/usage/index', [
            'usage' => $grouped,
            'dailySummaries' => $dailySummaries,
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function show(Request $request, Company $company)
    {
        $period = $request->input('period', 'mtd');
        [$from, $to] = $this->resolveDateRange($period, $request);

        $daily = UsageDailySummary::where('company_id', $company->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(fn ($rows) => $rows->pluck('total_qty', 'meter'))
            ->toArray();

        $totals = UsageDailySummary::where('company_id', $company->id)
            ->whereBetween('date', [$from, $to])
            ->select('meter', DB::raw('SUM(total_qty) as total'))
            ->groupBy('meter')
            ->pluck('total', 'meter')
            ->toArray();

        return Inertia::render('super-admin/usage/show', [
            'company' => $company->only(['id', 'name']),
            'daily' => $daily,
            'totals' => $totals,
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $period = $request->input('period', 'mtd');
        [$from, $to] = $this->resolveDateRange($period, $request);

        $rows = DB::table('usage_daily_summaries')
            ->join('companies', 'usage_daily_summaries.company_id', '=', 'companies.id')
            ->select(
                'companies.name as company',
                'usage_daily_summaries.date',
                'usage_daily_summaries.meter',
                'usage_daily_summaries.total_qty',
            )
            ->whereBetween('usage_daily_summaries.date', [$from, $to])
            ->orderBy('companies.name')
            ->orderBy('usage_daily_summaries.date')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Company', 'Date', 'Meter', 'Quantity']);
            foreach ($rows as $row) {
                fputcsv($handle, [$row->company, $row->date, $row->meter, $row->total_qty]);
            }
            fclose($handle);
        }, "usage-{$from->format('Ymd')}-{$to->format('Ymd')}.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function resolveDateRange(string $period, Request $request): array
    {
        return match ($period) {
            'last30' => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
            'custom' => [
                Carbon::parse($request->input('from', now()->startOfMonth())),
                Carbon::parse($request->input('to', now())),
            ],
            default => [now()->startOfMonth(), now()->endOfDay()], // mtd
        };
    }
}
