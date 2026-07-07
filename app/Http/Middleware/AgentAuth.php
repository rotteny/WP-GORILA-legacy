<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica requests do repair-agent (cockpit).
 *
 * Reusa a tabela `api_keys` — chaves de cockpit se distinguem por terem `hmac_secret`
 * preenchido. Chaves comuns (sem hmac_secret) não passam nesses endpoints.
 *
 * Escopo por instância/projeto NÃO se aplica aqui: o cockpit é um consumidor
 * de tarefas de repair, e a fila é global. Se um dia tiver múltiplos tenants,
 * adicionar pivot `api_key_id ↔ instance_id`.
 */
class AgentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $prefix = substr($token, 0, 12);

        $candidates = ApiKey::query()
            ->where('key_prefix', $prefix)
            ->whereNull('revoked_at')
            ->whereNotNull('hmac_secret')
            ->get();

        $apiKey = $candidates->first(fn (ApiKey $k) => $k->verify($token));

        if (!$apiKey) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('cockpit_key', $apiKey);

        return $next($request);
    }
}
