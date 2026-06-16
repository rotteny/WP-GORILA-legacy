<?php

namespace App\Http\Controllers;

use App\Models\WhatsappSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    public function webhook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event'       => 'required|string',
            'status'      => 'nullable|string',
            'qr'          => 'nullable|string',
            'qr_data_url' => 'nullable|string',
            'timestamp'   => 'nullable|string',
            'payload'     => 'nullable',
        ]);

        if ($data['event'] === 'message') {
            Log::info('Mensagem recebida do WhatsApp', ['payload' => $data['payload'] ?? null]);
            return response()->json(['ok' => true]);
        }

        $setup = WhatsappSetup::query()->firstOrNew(['id' => 1]);
        $setup->status        = $data['status']      ?? $setup->status ?? 'INITIALIZING';
        $setup->qr_code       = $data['qr']          ?? null;
        $setup->qr_data_url   = $data['qr_data_url'] ?? null;
        $setup->last_event_at = now();
        $setup->save();

        return response()->json(['ok' => true, 'setup' => $setup]);
    }

    public function getStatus(): JsonResponse
    {
        $setup = WhatsappSetup::query()->find(1);

        return response()->json([
            'status'        => $setup->status        ?? 'INITIALIZING',
            'qr_code'       => $setup->qr_code       ?? null,
            'qr_data_url'   => $setup->qr_data_url   ?? null,
            'last_event_at' => $setup->last_event_at ?? null,
        ]);
    }

    public function reset(): JsonResponse
    {
        $baseUrl = config('services.whatsapp.url', env('WHATSAPP_SERVICE_URL', 'http://whatsapp-service:3000'));

        try {
            $response = Http::timeout(10)->acceptJson()->post($baseUrl . '/reset');

            WhatsappSetup::query()->updateOrCreate(
                ['id' => 1],
                [
                    'status'        => 'INITIALIZING',
                    'qr_code'       => null,
                    'qr_data_url'   => null,
                    'last_event_at' => now(),
                ],
            );

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('Falha ao resetar whatsapp-service', ['error' => $e->getMessage()]);
            return response()->json([
                'ok'    => false,
                'error' => 'whatsapp-service indisponível',
            ], 502);
        }
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'message' => 'required|string',
        ]);

        $baseUrl = config('services.whatsapp.url', env('WHATSAPP_SERVICE_URL', 'http://whatsapp-service:3000'));

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post($baseUrl . '/send-message', $data);

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('Falha ao chamar whatsapp-service', ['error' => $e->getMessage()]);
            return response()->json([
                'ok'    => false,
                'error' => 'whatsapp-service indisponível',
            ], 502);
        }
    }
}
