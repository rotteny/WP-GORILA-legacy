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

/**
 * @group Mensagens
 *
 * Endpoints de envio e consulta de mensagens. Envio e **sempre assincrono**:
 * a API responde **202 Accepted** com a mensagem ja persistida em status
 * `queued`; um worker dedicado envia para o WhatsApp e o cliente acompanha
 * o ciclo de vida via webhooks (`message.sent`, `message.delivered`, `message.read`).
 */
class MessageController extends Controller
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly WhatsAppSendService $sendService,
        private readonly WhatsAppMediaService $mediaService,
    ) {
    }

    /**
     * Listar mensagens
     *
     * Lista mensagens de uma instancia ordenadas da mais recente para a mais antiga,
     * com paginacao via cursor (id descendente). Suporta filtros por direcao, tipo,
     * intervalo de datas e contato (busca parcial em `jid`).
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     * @queryParam direction string Filtra por direcao da mensagem. Valores: `in` (recebida) ou `out` (enviada). Example: in
     * @queryParam type string Filtra por tipo. Valores: text, image, video, audio, document, sticker, location, contact, unknown. Example: text
     * @queryParam contact string Busca parcial no JID/numero do contato (case-insensitive). Example: 5511
     * @queryParam since string Data ISO 8601 — inclui apenas mensagens criadas a partir desta data. Example: 2026-06-01T00:00:00Z
     * @queryParam until string Data ISO 8601 — inclui apenas mensagens criadas ate esta data. Example: 2026-06-18T23:59:59Z
     * @queryParam cursor integer ID da ultima mensagem da pagina anterior (paginacao seek). Example: 12345
     * @queryParam limit integer Quantidade por pagina (1-200). Default: 50. Example: 50
     *
     * @response 200 scenario="sucesso" {
     *   "data": [
     *     {
     *       "id": 12345,
     *       "instance_slug": "gorila-vendas",
     *       "direction": "out",
     *       "status": "sent",
     *       "jid": "5511999999999@s.whatsapp.net",
     *       "from_me": true,
     *       "message_type": "text",
     *       "body": "Ola, tudo bem?",
     *       "sent_at": "2026-06-18T14:01:23+00:00",
     *       "delivered_at": "2026-06-18T14:01:25+00:00",
     *       "read_at": null
     *     }
     *   ],
     *   "meta": {"limit": 50, "has_more": true, "next_cursor": 12300}
     * }
     * @response 422 scenario="parametro invalido" {"message": "The direction field must be one of: in, out.", "errors": {"direction": ["The direction field must be one of: in, out."]}}
     */
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

    /**
     * Detalhar mensagem
     *
     * Retorna o detalhe de uma mensagem especifica, validando que ela pertence
     * a instancia informada.
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     * @urlParam message integer required ID interno da mensagem. Example: 12345
     *
     * @response 200 scenario="sucesso" {
     *   "data": {
     *     "id": 12345,
     *     "instance_slug": "gorila-vendas",
     *     "direction": "out",
     *     "status": "sent",
     *     "jid": "5511999999999@s.whatsapp.net",
     *     "from_me": true,
     *     "message_type": "text",
     *     "body": "Ola!",
     *     "sent_at": "2026-06-18T14:01:23+00:00"
     *   }
     * }
     * @response 404 scenario="mensagem nao pertence ao projeto" {"message": "Not Found"}
     */
    public function show(Instance $instance, Message $message): MessageResource
    {
        abort_if($message->instance_id !== $instance->id, 404);

        return new MessageResource($message->loadMissing('instance'));
    }

    /**
     * Enviar mensagem de texto
     *
     * Enfileira uma mensagem de texto para envio assincrono. A resposta e
     * **202 Accepted** com a mensagem ja persistida em status `queued`. O envio
     * real ocorre no worker, respeitando o throttle anti-ban da instancia.
     *
     * Identifique o destinatario por **um** dos campos: `to`, `jid` ou `number`.
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     *
     * @bodyParam to string Numero E.164 ou JID do destinatario (alternativa a `jid`/`number`). Example: 5511999999999
     * @bodyParam jid string JID completo (formato `<numero>@s.whatsapp.net`). Example: 5511999999999@s.whatsapp.net
     * @bodyParam number string Numero E.164 sem `+`. Example: 5511999999999
     * @bodyParam message string required Conteudo do texto (max. 4096 chars). Example: Ola, tudo bem?
     * @bodyParam client_message_id string Identificador idempotente do cliente (max. 128 chars). Example: ord-2026-06-18-001
     *
     * @response 202 scenario="enfileirada" {
     *   "status": "success",
     *   "message": "Mensagem de texto enfileirada.",
     *   "data": {
     *     "id": 12346,
     *     "instance_slug": "gorila-vendas",
     *     "direction": "out",
     *     "status": "queued",
     *     "jid": "5511999999999@s.whatsapp.net",
     *     "message_type": "text",
     *     "body": "Ola, tudo bem?",
     *     "client_message_id": "ord-2026-06-18-001"
     *   }
     * }
     * @response 422 scenario="payload invalido" {"message": "The message field is required.", "errors": {"message": ["The message field is required."]}}
     * @response 429 scenario="rate limit excedido" {"error": "rate limit exceeded", "code": "RATE_LIMITED", "retry_after": 42}
     * @response 502 scenario="Node indisponivel" {"status": "error", "message": "Servico de mensagens indisponivel. Tente novamente em instantes.", "data": []}
     */
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

    /**
     * Enviar midia
     *
     * Enfileira o envio de uma midia (imagem, video, audio ou documento) via
     * `multipart/form-data`. O Laravel valida MIME-type, persiste o arquivo em
     * disco temporario e despacha o envio para o worker. Tamanho maximo
     * configuravel via `WHATSAPP_MEDIA_MAX_MB` (default: 20 MB).
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     *
     * @bodyParam to string Destinatario (alternativa a `jid`/`number`). Example: 5511999999999
     * @bodyParam jid string JID completo. Example: 5511999999999@s.whatsapp.net
     * @bodyParam number string Numero E.164 sem `+`. Example: 5511999999999
     * @bodyParam file file required Arquivo binario (multipart). MIME permitidos: image/jpeg, image/png, image/webp, image/gif, video/mp4, audio/ogg, application/pdf, entre outros.
     * @bodyParam caption string Legenda opcional (max. 1024 chars). Example: Segue documento solicitado
     * @bodyParam client_message_id string Identificador idempotente do cliente. Example: doc-123
     *
     * @response 202 scenario="enfileirada" {
     *   "status": "success",
     *   "message": "Midia enfileirada.",
     *   "data": {
     *     "id": 12347,
     *     "direction": "out",
     *     "status": "queued",
     *     "message_type": "image",
     *     "media_mime": "image/jpeg",
     *     "body": "Segue documento solicitado"
     *   }
     * }
     * @response 422 scenario="MIME nao permitido" {"message": "The file failed to upload.", "errors": {"file": ["Tipo MIME 'application/x-msdownload' nao permitido."]}}
     */
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

    /**
     * Enviar localizacao
     *
     * Enfileira o envio de uma localizacao geografica. `name` e `address`
     * sao opcionais e aparecem na pre-visualizacao do WhatsApp.
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     *
     * @bodyParam to string Destinatario. Example: 5511999999999
     * @bodyParam jid string JID completo. Example: 5511999999999@s.whatsapp.net
     * @bodyParam number string Numero E.164 sem `+`. Example: 5511999999999
     * @bodyParam latitude numeric required Latitude (-90 a 90). Example: -23.55052
     * @bodyParam longitude numeric required Longitude (-180 a 180). Example: -46.633308
     * @bodyParam name string Titulo da localizacao (max. 255 chars). Example: Sede Gorila
     * @bodyParam address string Endereco completo (max. 512 chars). Example: Av. Paulista, 1000, Sao Paulo - SP
     * @bodyParam client_message_id string Identificador idempotente.
     *
     * @response 202 scenario="enfileirada" {
     *   "status": "success",
     *   "message": "Localizacao enfileirada.",
     *   "data": {"id": 12348, "message_type": "location", "status": "queued"}
     * }
     * @response 422 scenario="coordenadas invalidas" {"message": "The latitude field must be between -90 and 90.", "errors": {"latitude": ["The latitude field must be between -90 and 90."]}}
     */
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

    /**
     * Enviar contato (vCard)
     *
     * Enfileira o envio de um cartao de contato no formato vCard 3.0/4.0.
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     *
     * @bodyParam to string Destinatario. Example: 5511999999999
     * @bodyParam jid string JID completo. Example: 5511999999999@s.whatsapp.net
     * @bodyParam number string Numero E.164 sem `+`. Example: 5511999999999
     * @bodyParam vcard string required Conteudo vCard (max. 8192 chars). Example: "BEGIN:VCARD\nVERSION:3.0\nFN:Joao Silva\nTEL:+5511988887777\nEND:VCARD"
     * @bodyParam display_name string Nome visivel acima do cartao. Example: Joao Silva
     * @bodyParam client_message_id string Identificador idempotente.
     *
     * @response 202 scenario="enfileirada" {
     *   "status": "success",
     *   "message": "Contato enfileirado.",
     *   "data": {"id": 12349, "message_type": "contact", "status": "queued"}
     * }
     * @response 422 scenario="vcard ausente" {"message": "The vcard field is required.", "errors": {"vcard": ["The vcard field is required."]}}
     */
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

    /**
     * Baixar midia de uma mensagem
     *
     * Faz streaming do arquivo binario de uma mensagem do tipo midia.
     * Retorna 404 se a mensagem nao for de midia ou nao pertencer ao projeto.
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     * @urlParam message integer required ID interno da mensagem. Example: 12347
     *
     * @response 200 scenario="sucesso" Stream binario com Content-Type apropriado (image/jpeg, application/pdf, etc).
     * @response 404 scenario="mensagem nao e de midia" {"message": "Not Found"}
     */
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
