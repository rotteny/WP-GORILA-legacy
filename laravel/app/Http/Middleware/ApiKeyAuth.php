<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->header('X-API-Key');

        if (empty($raw)) {
            return response()->json([
                'error' => 'missing API key',
                'code' => 'AUTH_MISSING',
            ], 401);
        }

        $apiKey = ApiKey::findActiveByRaw($raw);

        if ($apiKey === null) {
            return response()->json([
                'error' => 'invalid API key',
                'code' => 'AUTH_INVALID',
            ], 401);
        }

        $apiKey->markUsed();

        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
