<?php

namespace App\Providers;

use App\Models\ApiKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Rate limit da API pública: 60 req/min POR CHAVE de API (não por IP — várias
     * chaves podem sair do mesmo IP, e um IP não deve derrubar o do outro). A chave
     * já foi resolvida pelo middleware `api-key`, que roda antes do `throttle`; se por
     * algum motivo não houver chave, cai pro IP como rede de segurança.
     *
     * O middleware ThrottleRequests do Laravel injeta sozinho os headers
     * `X-RateLimit-Limit` / `X-RateLimit-Remaining` e, no 429, `Retry-After` +
     * `X-RateLimit-Reset` — não precisamos montá-los à mão.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api-key', function (Request $request) {
            // O ThrottleRequests tem prioridade e pode rodar ANTES do middleware
            // `api-key` — então nem sempre a chave já foi resolvida em `attributes`.
            // Pra ser robusto à ordem, derivamos o balde direto do token (1 token =
            // 1 chave), usando a chave resolvida quando existir e o IP como último caso.
            $apiKey = $request->attributes->get('api_key');
            $token  = $request->bearerToken() ?: $request->header('X-API-Key');

            $by = match (true) {
                $apiKey instanceof ApiKey => 'key:' . $apiKey->id,
                (bool) $token             => 'token:' . sha1($token),
                default                   => 'ip:' . $request->ip(),
            };

            return Limit::perMinute(60)->by($by)->response(function (Request $request, array $headers) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'Limite de requisições excedido (60/min). Tente novamente em instantes.',
                ], 429, $headers);
            });
        });
    }
}
