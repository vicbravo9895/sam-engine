<?php

namespace App\Http\Controllers;

use App\Business\Engine\AIEngineClient;
use Illuminate\Http\Request;

class SamsaraWebhookController extends Controller
{
    public function __construct(private AIEngineClient $aiEngineClient)
    {
    }

    public function handle(Request $request)
    {
        $payload = $request->all();

        $response = $this->aiEngineClient->callAIEvaluator(
            payload: $payload
        );

        $data = $response["responses"];

        return response()->json([
            'status' => 'success',
            'data' => $data
        ]);
    }
}
