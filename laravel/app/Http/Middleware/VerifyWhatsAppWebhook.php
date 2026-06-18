<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida assinatura HMAC-SHA256 enviada pelo whatsapp-service (Node).
 *
 * Se WEBHOOK_SECRET nao estiver configurado, falha-fechado (rejeita
 * em producao). Em local, basta deixar o WEBHOOK_SECRET vazio dos dois
 * lados para o Node nao assinar e o middleware permitir passagem.
 */
class VerifyWhatsAppWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('whatsapp.webhook_secret', '');

        if ($secret === '') {
            if (app()->environment('production')) {
                Log::error('Webhook recebido sem WEBHOOK_SECRET configurado em producao');
                abort(503, 'Webhook indisponivel');
            }
            return $next($request);
        }

        $signature = (string) $request->header('X-WhatsApp-Signature', '');
        if ($signature === '') {
            Log::warning('Webhook recebido sem assinatura', [
                'ip' => $request->ip(),
            ]);
            abort(401, 'Assinatura ausente');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (!hash_equals($expected, $signature)) {
            Log::warning('Webhook com assinatura invalida', [
                'ip' => $request->ip(),
            ]);
            abort(401, 'Assinatura invalida');
        }

        return $next($request);
    }
}
