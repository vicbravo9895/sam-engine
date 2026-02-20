<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Pennant\Feature;

class FeatureFlagsController extends Controller
{
    /**
     * Feature flags available for toggle (must match AppServiceProvider::configureFeatureFlags).
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function flagDefinitions(): array
    {
        return [
            'ledger-v1' => [
                'label' => 'Ledger (domain_events)',
                'description' => 'Emisión de eventos al ledger para auditoría y timeline.',
            ],
            'alerts-v2' => [
                'label' => 'Alertas v2',
                'description' => 'Dual-write a signals/alerts; backfill y pipeline.',
            ],
            'notifications-v2' => [
                'label' => 'Notificaciones v2',
                'description' => 'Trazabilidad delivery/ack de notificaciones.',
            ],
            'metering-v1' => [
                'label' => 'Metering (usage_events)',
                'description' => 'Registro de uso para billing y dashboard Usage.',
            ],
            'attention-engine-v1' => [
                'label' => 'Attention Engine',
                'description' => 'SLA, ACK y escalación automática de alertas.',
            ],
        ];
    }

    public function index()
    {
        $flags = self::flagDefinitions();
        $companies = Company::orderBy('name')->get(['id', 'name', 'is_active']);

        $matrix = [];
        foreach ($companies as $company) {
            $matrix[$company->id] = [];
            foreach (array_keys($flags) as $flagName) {
                $matrix[$company->id][$flagName] = Feature::for($company)->active($flagName);
            }
        }

        return Inertia::render('super-admin/feature-flags/index', [
            'flags' => $flags,
            'companies' => $companies->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_active' => $c->is_active,
            ]),
            'matrix' => $matrix,
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $request->validate([
            'flag' => 'required|string|in:'.implode(',', array_keys(self::flagDefinitions())),
            'active' => 'required|boolean',
        ]);

        $flag = $request->input('flag');
        $active = $request->boolean('active');

        if ($active) {
            Feature::for($company)->activate($flag);
        } else {
            Feature::for($company)->deactivate($flag);
        }

        return back();
    }
}
