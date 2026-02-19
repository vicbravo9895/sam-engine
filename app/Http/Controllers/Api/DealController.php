<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'company_name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'fleet_size' => ['required', 'string', 'max:50'],
            'country' => ['required', 'string', 'max:100'],
            'challenges' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:100'],
        ]);

        $deal = Deal::create($validated);

        return response()->json([
            'success' => true,
            'deal_id' => $deal->id,
            'message' => 'Solicitud recibida exitosamente.',
        ], 201);
    }
}
