<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ai_engine' => [
        'url' => env('AI_SERVICE_BASE_URL', 'http://ai-service:8000'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        // GPT-4o Hybrid Strategy:
        // - standard: gpt-4o-mini (rápido, económico) para consultas normales
        // - advanced: gpt-4o (máxima calidad) para reportes complejos
        'standard_model' => env('OPENAI_STANDARD_MODEL', 'gpt-4o-mini'),
        'advanced_model' => env('OPENAI_ADVANCED_MODEL', 'gpt-4o'),
    ],

    'samsara' => [
        'api_token' => env('SAMSARA_API_TOKEN'),
        'base_url' => env('SAMSARA_BASE_URL', 'https://api.samsara.com'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_PHONE_NUMBER'),
        'whatsapp' => env('TWILIO_WHATSAPP_NUMBER'),
        'callback_url' => env('TWILIO_CALLBACK_URL'),
    ],

];
