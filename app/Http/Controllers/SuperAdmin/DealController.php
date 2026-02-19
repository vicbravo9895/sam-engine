<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DealController extends Controller
{
    public function index(Request $request)
    {
        $query = Deal::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($country = $request->input('country')) {
            $query->where('country', $country);
        }

        $deals = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        $stats = [
            'total' => Deal::count(),
            'new' => Deal::where('status', Deal::STATUS_NEW)->count(),
            'contacted' => Deal::where('status', Deal::STATUS_CONTACTED)->count(),
            'qualified' => Deal::where('status', Deal::STATUS_QUALIFIED)->count(),
            'proposal' => Deal::where('status', Deal::STATUS_PROPOSAL)->count(),
            'won' => Deal::where('status', Deal::STATUS_WON)->count(),
            'lost' => Deal::where('status', Deal::STATUS_LOST)->count(),
        ];

        $countries = Deal::select('country')
            ->distinct()
            ->orderBy('country')
            ->pluck('country');

        return Inertia::render('super-admin/deals/index', [
            'deals' => $deals,
            'stats' => $stats,
            'countries' => $countries,
            'filters' => $request->only(['search', 'status', 'country']),
            'statuses' => Deal::STATUS_LABELS,
        ]);
    }

    public function show(Deal $deal)
    {
        return Inertia::render('super-admin/deals/show', [
            'deal' => $deal,
            'statuses' => Deal::STATUS_LABELS,
        ]);
    }

    public function updateStatus(Request $request, Deal $deal)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Deal::STATUSES)],
        ]);

        $newStatus = $validated['status'];

        $timestamps = [];
        if ($newStatus === Deal::STATUS_CONTACTED && !$deal->contacted_at) {
            $timestamps['contacted_at'] = now();
        }
        if ($newStatus === Deal::STATUS_QUALIFIED && !$deal->qualified_at) {
            $timestamps['qualified_at'] = now();
        }
        if (in_array($newStatus, [Deal::STATUS_WON, Deal::STATUS_LOST]) && !$deal->closed_at) {
            $timestamps['closed_at'] = now();
        }

        $deal->update(array_merge(['status' => $newStatus], $timestamps));

        return back()->with('success', 'Estado actualizado exitosamente.');
    }

    public function updateNotes(Request $request, Deal $deal)
    {
        $validated = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $deal->update($validated);

        return back()->with('success', 'Notas actualizadas exitosamente.');
    }

    public function destroy(Deal $deal)
    {
        $name = $deal->full_name;
        $deal->delete();

        return redirect()->route('super-admin.deals.index')
            ->with('success', "Deal de '{$name}' eliminado exitosamente.");
    }
}
