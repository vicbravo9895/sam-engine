<?php

namespace App\Business\Engine;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;


class AIEngineClient
{

    private $baseUrl;


    public function __construct()
    {
        $this->baseUrl = env('AI_SERVICE_BASE_URL');
    }

    public function callAIEvaluator(array $payload)
    {
        try {
            $response = Http::timeout(30)
                ->baseUrl($this->baseUrl)
                ->post('/ai-agent', [
                    'user_id' => Str::uuid()->toString(),
                    'session_id' => Str::uuid()->toString(),
                    'query' => json_encode($payload),
                ]);

            return $response->json();
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'status_code' => $e->getCode(),
            ];
        }
    }
}