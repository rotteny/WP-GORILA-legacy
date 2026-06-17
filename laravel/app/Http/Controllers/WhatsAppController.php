<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    private const HTTP_TIMEOUT       = 10;
    private const HTTP_MEDIA_TIMEOUT = 60;

    public function webhook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance_id' => 'required|string',
            'event'       => 'required|string',
            'status'      => 'nullable|string',
            'qr'          => 'nullable|string',
            'qr_data_url' => 'nullable|string',
            'timestamp'   => 'nullable|string',
            'payload'     => 'nullable',
        ]);

        if ($data['event'] === 'message') {
            Log::info('Mensagem recebida do WhatsApp', [
                'instance_id' => $data['instance_id'],
                'payload'     => $data['payload'] ?? null,
            ]);
            return response()->json(['ok' => true]);
        }

        // O Laravel é a fonte autoritativa do `name` (legível pra UI).
        // Só seta o name na CRIAÇÃO; em updates, deixa o existente intacto.
        $attributes = [
            'status'        => $data['status']      ?? 'INITIALIZING',
            'qr_code'       => $data['qr']          ?? null,
            'qr_data_url'   => $data['qr_data_url'] ?? null,
            'last_event_at' => now(),
        ];

        $instance = Instance::firstOrNew(['slug' => $data['instance_id']]);
        if (!$instance->exists) {
            $instance->name = $data['instance_id']; // fallback se for criada via webhook
        }
        $instance->fill($attributes)->save();

        return response()->json(['ok' => true, 'instance' => $instance]);
    }

    public function getStatus(Instance $instance): JsonResponse
    {
        return $this->proxyGet('/instances/' . $instance->slug . '/status');
    }

    public function reset(Instance $instance): JsonResponse
    {
        $result = $this->proxyPost('/instances/' . $instance->slug . '/reset');

        $instance->update([
            'status'        => 'INITIALIZING',
            'qr_code'       => null,
            'qr_data_url'   => null,
            'last_event_at' => now(),
        ]);

        return $result;
    }

    public function sendMessage(Request $request, Instance $instance): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'message' => 'required|string',
        ]);

        return $this->proxyPost('/instances/' . $instance->slug . '/send-message', $data);
    }

    public function sendMedia(Request $request, Instance $instance): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'caption' => 'nullable|string|max:1024',
            'file'    => 'required|file|max:25600',
        ]);

        $file = $request->file('file');

        try {
            $response = Http::timeout(self::HTTP_MEDIA_TIMEOUT)
                ->attach(
                    'file',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName(),
                    ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
                )
                ->post($this->nodeBaseUrl() . '/instances/' . $instance->slug . '/send-media', array_filter([
                    'jid'     => $data['jid']     ?? null,
                    'number'  => $data['number']  ?? null,
                    'caption' => $data['caption'] ?? null,
                ]));

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar mídia pro whatsapp-service', [
                'slug'  => $instance->slug,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }
    }

    public function listChats(Instance $instance): JsonResponse
    {
        return $this->proxyGet('/instances/' . $instance->slug . '/chats');
    }

    public function chatMessages(Instance $instance, string $jid): JsonResponse
    {
        return $this->proxyGet('/instances/' . $instance->slug . '/chats/' . urlencode($jid) . '/messages');
    }

    public function media(Instance $instance, string $messageId): Response|JsonResponse
    {
        try {
            $response = Http::timeout(30)
                ->get($this->nodeBaseUrl() . '/instances/' . $instance->slug . '/media/' . urlencode($messageId));

            if (!$response->successful()) {
                return response()->json($response->json() ?? ['ok' => false], $response->status());
            }

            return response($response->body(), 200)
                ->withHeaders([
                    'Content-Type'  => $response->header('Content-Type') ?? 'application/octet-stream',
                    'Cache-Control' => 'public, max-age=3600',
                ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao proxiar mídia', [
                'slug'       => $instance->slug,
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }
    }

    private function proxyGet(string $path): JsonResponse
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)->acceptJson()->get($this->nodeBaseUrl() . $path);
            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('Falha ao proxiar GET ' . $path, ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }
    }

    private function proxyPost(string $path, array $data = []): JsonResponse
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->post($this->nodeBaseUrl() . $path, $data);
            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('Falha ao proxiar POST ' . $path, ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }
    }

    private function nodeBaseUrl(): string
    {
        return config('services.whatsapp.url', env('WHATSAPP_SERVICE_URL', 'http://whatsapp-service:3000'));
    }
}
