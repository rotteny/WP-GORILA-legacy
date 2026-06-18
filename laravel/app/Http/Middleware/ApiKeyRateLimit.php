<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit por API key usando Redis como contador de janela fixa.
 *
 * Aceita o parametro `limit` no formato `maxAttempts,decayMinutes`
 * (ex: `60,1` = 60 req/min). Se nao informado, usa o default de
 * `whatsapp.rate_limit_per_minute` (default config: 60 req/min).
 *
 * Headers de resposta seguem a convencao de facto da industria:
 * - `X-RateLimit-Limit`     teto da janela atual
 * - `X-RateLimit-Remaining` quanto ainda pode usar
 * - `X-RateLimit-Reset`     timestamp Unix em que a janela reseta
 * - `Retry-After` (apenas em 429) segundos ate o reset
 *
 * Deve rodar **depois** do `api.key` — depende do attribute `api_key`
 * setado pelo middleware de autenticacao.
 */
class ApiKeyRateLimit
{
    private const DEFAULT_DECAY_MINUTES = 1;

    public function handle(Request $request, Closure $next, ?string $limit = null): Response
    {
        $apiKey = $request->attributes->get('api_key');
        if (!$apiKey instanceof ApiKey) {
            // Sem auth nao deveria chegar aqui; deixa passar pra nao mascarar
            // problemas de ordenacao de middleware em ambiente local.
            return $next($request);
        }

        [$maxAttempts, $decayMinutes] = $this->parseLimit($limit);

        $cacheKey = 'rate_limit:api_key:' . $apiKey->id;
        $current = (int) Redis::incr($cacheKey);

        if ($current === 1) {
            Redis::expire($cacheKey, $decayMinutes * 60);
        }

        $ttl = (int) Redis::ttl($cacheKey);
        if ($ttl < 0) {
            $ttl = $decayMinutes * 60;
        }

        if ($current > $maxAttempts) {
            return response()->json([
                'error' => 'rate limit exceeded',
                'code' => 'RATE_LIMITED',
                'retry_after' => $ttl,
            ], 429)
                ->header('Retry-After', (string) $ttl)
                ->header('X-RateLimit-Limit', (string) $maxAttempts)
                ->header('X-RateLimit-Remaining', '0')
                ->header('X-RateLimit-Reset', (string) now()->addSeconds($ttl)->timestamp);
        }

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $maxAttempts - $current));
        $response->headers->set('X-RateLimit-Reset', (string) now()->addSeconds($ttl)->timestamp);

        return $response;
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function parseLimit(?string $limit): array
    {
        if ($limit === null || $limit === '') {
            return [
                (int) config('whatsapp.rate_limit_per_minute', 60),
                self::DEFAULT_DECAY_MINUTES,
            ];
        }

        $parts = explode(',', $limit);
        $maxAttempts = (int) ($parts[0] ?? 60);
        $decayMinutes = (int) ($parts[1] ?? self::DEFAULT_DECAY_MINUTES);

        return [
            $maxAttempts > 0 ? $maxAttempts : 60,
            $decayMinutes > 0 ? $decayMinutes : self::DEFAULT_DECAY_MINUTES,
        ];
    }
}
