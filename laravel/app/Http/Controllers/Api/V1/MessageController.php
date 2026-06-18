<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SendContactMessageRequest;
use App\Http\Requests\Api\V1\SendLocationMessageRequest;
use App\Http\Requests\Api\V1\SendMediaMessageRequest;
use App\Http\Requests\Api\V1\SendTextMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Instance;
use App\Models\Message;
use App\Services\WhatsAppMediaService;
use App\Services\WhatsAppSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MessageController extends Controller
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly WhatsAppSendService $sendService,
        private readonly WhatsAppMediaService $mediaService,
    ) {
    }

    public function index(Request $request, Instance $instance): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'direction' => 'nullable|in:in,out',
            'type' => 'nullable|in:text,image,video,audio,document,sticker,location,contact,unknown',
            'contact' => 'nullable|string|max:128',
            'since' => 'nullable|date',
            'until' => 'nullable|date',
            'cursor' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        $query = Message::query()
            ->with('instance')
            ->where('instance_id', $instance->id)
            ->orderByDesc('id')
            ->limit($limit + 1);

        if (!empty($validated['direction'])) {
            $query->where('direction', $validated['direction']);
        }
        if (!empty($validated['type'])) {
            $query->where('message_type', $validated['type']);
        }
        if (!empty($validated['contact'])) {
            $query->where('jid', 'ilike', '%' . $validated['contact'] . '%');
        }
        if (!empty($validated['since'])) {
            $query->where('created_at', '>=', $validated['since']);
        }
        if (!empty($validated['until'])) {
            $query->where('created_at', '<=', $validated['until']);
        }
        if (!empty($validated['cursor'])) {
            $query->where('id', '<', $validated['cursor']);
        }

        $messages = $query->get();
        $hasMore = $messages->count() > $limit;

        if ($hasMore) {
            $messages->pop();
        }

        $nextCursor = $hasMore && $messages->isNotEmpty() ? $messages->last()->id : null;

        return MessageResource::collection($messages)
            ->additional([
                'meta' => [
                    'limit' => $limit,
                    'has_more' => $hasMore,
                    'next_cursor' => $nextCursor,
                ],
            ]);
    }

    public function show(Instance $instance, Message $message): MessageResource
    {
        abort_if($message->instance_id !== $instance->id, 404);

        return new MessageResource($message->loadMissing('instance'));
    }

    public function storeText(SendTextMessageRequest $request, Instance $instance): JsonResponse
    {
        try {
            $message = $this->sendService->sendText(
                $instance,
                $request->recipientFields(),
                $request->validated()['message'],
                $request->clientMessageId(),
            );
        } catch (RuntimeException $e) {
            return $this->sendError($e);
        }

        return $this->sendAccepted($message->load('instance'), 'Mensagem de texto enfileirada.');
    }

    public function storeMedia(SendMediaMessageRequest $request, Instance $instance): JsonResponse
    {
        try {
            $validated = $request->validated();
            $message = $this->sendService->sendMedia(
                $instance,
                $request->recipientFields(),
                $request->file('file'),
                $validated['caption'] ?? null,
                $request->clientMessageId(),
            );
        } catch (RuntimeException $e) {
            return $this->sendError($e);
        }

        return $this->sendAccepted($message->load('instance'), 'Midia enfileirada.');
    }

    public function storeLocation(SendLocationMessageRequest $request, Instance $instance): JsonResponse
    {
        try {
            $validated = $request->validated();
            $message = $this->sendService->sendLocation(
                $instance,
                $request->recipientFields(),
                [
                    'latitude' => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                    'name' => $validated['name'] ?? null,
                    'address' => $validated['address'] ?? null,
                ],
                $request->clientMessageId(),
            );
        } catch (RuntimeException $e) {
            return $this->sendError($e);
        }

        return $this->sendAccepted($message->load('instance'), 'Localizacao enfileirada.');
    }

    public function storeContact(SendContactMessageRequest $request, Instance $instance): JsonResponse
    {
        try {
            $validated = $request->validated();
            $message = $this->sendService->sendContact(
                $instance,
                $request->recipientFields(),
                $validated['vcard'],
                $validated['display_name'] ?? null,
                $request->clientMessageId(),
            );
        } catch (RuntimeException $e) {
            return $this->sendError($e);
        }

        return $this->sendAccepted($message->load('instance'), 'Contato enfileirado.');
    }

    public function showMedia(Instance $instance, Message $message): SymfonyResponse
    {
        abort_if($message->instance_id !== $instance->id, 404);
        abort_unless($this->mediaService->isMediaType($message->message_type), 404);

        return $this->mediaService->serve($instance, $message);
    }

    /**
     * Resposta padronizada 202 Accepted — confirma que o envio foi
     * enfileirado e seguira no worker. Cliente acompanha via webhook
     * outbound `message.sent` ou polling do recurso.
     */
    private function sendAccepted(Message $message, string $msg): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $msg,
            'data' => new MessageResource($message),
        ], 202);
    }

    private function sendError(RuntimeException $e): JsonResponse
    {
        // Detalhe real fica no log; cliente recebe mensagem generica
        // para evitar vazamento de stack trace ou IPs internos.
        Log::error('Falha ao enviar mensagem v1', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);

        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 502;
        $publicMessage = match (true) {
            $status === 422 => 'Dados invalidos para envio.',
            $status === 409 => 'Instancia desconectada do WhatsApp.',
            $status >= 500 => 'Servico de mensagens indisponivel. Tente novamente em instantes.',
            default => 'Nao foi possivel enviar a mensagem.',
        };

        return response()->json([
            'status' => 'error',
            'message' => $publicMessage,
            'data' => [],
        ], $status);
    }
}
