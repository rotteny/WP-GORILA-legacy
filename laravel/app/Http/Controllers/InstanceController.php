<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstanceController extends Controller
{
    private const HTTP_TIMEOUT = 10;

    public function index(): JsonResponse
    {
        $this->syncFromNode();

        $instances = Instance::query()->orderBy('name')->get();

        return response()->json($instances);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'lowercase', 'regex:/^[a-z0-9][a-z0-9_-]{0,30}$/', 'unique:instances,slug'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->post($this->nodeBaseUrl() . '/instances', [
                    'id'   => $data['slug'],
                    'name' => $data['name'],
                ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao criar instância no whatsapp-service', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }

        if (!$response->successful()) {
            return response()->json($response->json() ?? ['ok' => false], $response->status());
        }

        $instance = Instance::create([
            'slug'   => $data['slug'],
            'name'   => $data['name'],
            'status' => 'INITIALIZING',
        ]);

        return response()->json($instance, 201);
    }

    public function destroy(Instance $instance): Response|JsonResponse
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->delete($this->nodeBaseUrl() . '/instances/' . $instance->slug);
        } catch (\Throwable $e) {
            Log::error('Falha ao deletar instância no whatsapp-service', [
                'slug'  => $instance->slug,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }

        if (!$response->successful() && $response->status() !== 404) {
            return response()->json($response->json() ?? ['ok' => false], $response->status());
        }

        $instance->delete();

        return response()->noContent();
    }

    private function syncFromNode(): void
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->get($this->nodeBaseUrl() . '/instances');

            if (!$response->successful()) {
                return;
            }

            $payload = $response->json();
            $items   = $payload['instances'] ?? [];

            $nodeSlugs = [];

            foreach ($items as $item) {
                if (empty($item['slug'])) {
                    continue;
                }
                $nodeSlugs[] = $item['slug'];

                $existing = Instance::query()->where('slug', $item['slug'])->first();

                $attributes = [
                    'status'        => $item['status']      ?? 'INITIALIZING',
                    'last_event_at' => $item['last_update'] ?? null,
                ];

                if (!$existing) {
                    $attributes['name'] = $item['name'] ?? $item['slug'];
                    Instance::create(['slug' => $item['slug']] + $attributes);
                } else {
                    $existing->fill($attributes)->save();
                }
            }

            // Remove órfãos: instâncias que existem no banco mas não no Node
            // (foram deletadas externamente ou perderam o auth_info).
            if (!empty($nodeSlugs)) {
                Instance::query()
                    ->whereNotIn('slug', $nodeSlugs)
                    ->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Não foi possível sincronizar instâncias com whatsapp-service', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function nodeBaseUrl(): string
    {
        return config('services.whatsapp.url', env('WHATSAPP_SERVICE_URL', 'http://whatsapp-service:3000'));
    }
}
