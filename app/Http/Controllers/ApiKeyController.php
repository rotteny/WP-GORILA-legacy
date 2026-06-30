<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiKeyController extends Controller
{
    /**
     * Lista todas as chaves de API. Nunca devolve o plaintext.
     */
    public function index(): JsonResponse
    {
        $keys = ApiKey::query()
            ->orderBy('instance_slug')
            ->orderByDesc('created_at')
            ->get([
                'id',
                'project_id',
                'instance_slug',
                'name',
                'key_prefix',
                'last_used_at',
                'revoked_at',
                'created_at',
            ]);

        return response()->json($keys);
    }

    /**
     * Cria uma nova chave de API.
     *
     * Esta é a ÚNICA resposta que contém o plaintext.
     * Cliente DEVE copiá-la imediatamente — depois disso, só o prefix fica visível.
     */
    public function store(Request $request): JsonResponse
    {
        // A chave é escopada a UMA instância OU a UM projeto (exatamente um).
        $data = $request->validate([
            'instance_slug' => ['required_without:project_id', 'nullable', 'string', Rule::exists('instances', 'slug')],
            'project_id'    => ['required_without:instance_slug', 'nullable', 'integer', Rule::exists('projects', 'id')],
            'name'          => ['required', 'string', 'max:100'],
        ]);

        if (!empty($data['instance_slug']) && !empty($data['project_id'])) {
            return response()->json([
                'ok'    => false,
                'error' => 'Informe instance_slug OU project_id, não os dois.',
            ], 422);
        }

        $generated = ApiKey::generate();

        $apiKey = ApiKey::create([
            'instance_slug' => $data['instance_slug'] ?? null,
            'project_id'    => $data['project_id'] ?? null,
            'name'          => $data['name'],
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);

        return response()->json([
            'id'            => $apiKey->id,
            'instance_slug' => $apiKey->instance_slug,
            'project_id'    => $apiKey->project_id,
            'name'          => $apiKey->name,
            'key_prefix'    => $apiKey->key_prefix,
            'plaintext'     => $generated['plaintext'],   // ⚠️ Só aparece aqui.
            'created_at'    => $apiKey->created_at,
        ], 201);
    }

    /**
     * Revoga (soft) uma chave de API.
     * A chave continua no banco pra auditoria mas não autentica mais.
     */
    public function destroy(ApiKey $apiKey): JsonResponse
    {
        if ($apiKey->revoked_at === null) {
            $apiKey->update(['revoked_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }
}
