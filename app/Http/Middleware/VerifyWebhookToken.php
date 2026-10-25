<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.webhook.token');

        if (! is_string($expectedToken) || $expectedToken === '') {
            return response()->json([
                'message' => 'Webhook authentication is not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $providedToken = $request->header('X-Webhook-Token');

        if (! is_string($providedToken) || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'message' => 'Invalid webhook token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
