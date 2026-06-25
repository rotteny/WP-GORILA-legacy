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
        $data = $request->validate([
            'instance_slug' => ['required', 'string', Rule::exists('instances', 'slug')],
            'name'          => ['required', 'string', 'max:100'],
        ]);

        $generated = ApiKey::generate();

        $apiKey = ApiKey::create([
            'instance_slug' => $data['instance_slug'],
            'name'          => $data['name'],
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
        ]);

        return response()->json([
            'id'            => $apiKey->id,
            'instance_slug' => $apiKey->instance_slug,
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
