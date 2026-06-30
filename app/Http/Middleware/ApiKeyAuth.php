<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica requests externas via chave de API.
 *
 * A chave deve vir em UMA dessas formas (a primeira que existir vence):
 *   - Header:  Authorization: Bearer wpg_xxx...
 *   - Header:  X-API-Key: wpg_xxx...
 *
 * A chave é verificada contra a tabela `api_keys` (key_prefix + hash bcrypt).
 * Além disso, a chave precisa estar vinculada ao escopo referenciado na rota:
 *   - rota com `{instance}`: a chave precisa ser daquela instância (instance_slug);
 *   - rota com `{project}`:  a chave precisa ser daquele projeto (project_id).
 * Isso evita usar a chave do projeto/instância A para falar com o B.
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $this->extractKey($request);

        if (!$plaintext) {
            return $this->unauthorized('Chave de API ausente. Use header Authorization: Bearer wpg_...');
        }

        // O prefix nos primeiros 12 chars (wpg_ + 8 chars) reduz a busca pra ~1 row.
        $prefix = substr($plaintext, 0, 12);

        $candidates = ApiKey::query()
            ->where('key_prefix', $prefix)
            ->whereNull('revoked_at')
            ->get();

        $apiKey = $candidates->first(fn (ApiKey $k) => $k->verify($plaintext));

        if (!$apiKey) {
            return $this->unauthorized('Chave de API inválida ou revogada.');
        }

        // Confere o escopo: chave precisa pertencer à instância da rota.
        $routeInstance = $request->route('instance');
        $routeSlug     = is_object($routeInstance) ? $routeInstance->slug : (string) $routeInstance;

        if ($routeSlug && $apiKey->instance_slug !== $routeSlug) {
            return $this->forbidden("Chave de API não autorizada para a instância '{$routeSlug}'.");
        }

        // Confere o escopo: chave precisa pertencer ao projeto da rota.
        $routeProject = $request->route('project');
        if ($routeProject) {
            $projectId = is_object($routeProject) ? $routeProject->id : null;
            $projectSlug = is_object($routeProject) ? $routeProject->slug : (string) $routeProject;

            if ($apiKey->project_id === null || $apiKey->project_id !== $projectId) {
                return $this->forbidden("Chave de API não autorizada para o projeto '{$projectSlug}'.");
            }
        }

        // Sucesso: atualiza last_used_at e injeta a chave no request.
        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    private function extractKey(Request $request): ?string
    {
        $auth = $request->bearerToken();
        if ($auth) {
            return $auth;
        }

        $header = $request->header('X-API-Key');
        return $header ?: null;
    }

    private function unauthorized(string $msg): Response
    {
        return response()->json(['ok' => false, 'error' => $msg], 401);
    }

    private function forbidden(string $msg): Response
    {
        return response()->json(['ok' => false, 'error' => $msg], 403);
    }
}
