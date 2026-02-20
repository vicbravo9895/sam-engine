<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Twilio webhook signatures using HMAC-SHA1.
 *
 * Twilio signs every callback with X-Twilio-Signature, computed as:
 *   base64( HMAC-SHA1( URL + sorted POST params, AuthToken ) )
 *
 * Skipped in non-production environments when the auth token is missing
 * so local development keeps working without a real Twilio account.
 */
class VerifyTwilioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $authToken = config('services.twilio.token');

        if (empty($authToken)) {
            if (app()->environment('production')) {
                Log::error('VerifyTwilioSignature: auth token not configured in production');
                abort(500, 'Twilio auth token not configured');
            }

            return $next($request);
        }

        $signature = $request->header('X-Twilio-Signature');

        if (empty($signature)) {
            Log::warning('VerifyTwilioSignature: missing signature header', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response('Unauthorized', 401);
        }

        $url = $request->fullUrl();

        // Build sorted param string exactly as Twilio does
        $params = $request->post();
        ksort($params);

        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key . $value;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        if (!hash_equals($expected, $signature)) {
            Log::warning('VerifyTwilioSignature: invalid signature', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response('Unauthorized', 401);
        }

        return $next($request);
    }
}
