<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWebhookRequest;
use App\Http\Resources\WebhookDeliveryResource;
use App\Http\Resources\WebhookResource;
use App\Models\Instance;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Webhooks
 *
 * Gerencia endpoints outbound para receber eventos da WP-GORILA.
 * Eventos suportados: `message.received`, `message.sent`, `message.delivered`,
 * `message.read`.
 *
 * Toda entrega e assinada via HMAC-SHA256 no header **`X-Gorila-Signature`**
 * (formato `sha256=<hex>`). O timestamp da entrega vai em **`X-Gorila-Timestamp`**.
 * Valide assinatura concatenando `timestamp + "." + body` e comparando com
 * o secret retornado **uma unica vez** na criacao do endpoint.
 */
class WebhookController extends Controller
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const SECRET_BYTES = 32;

    /**
     * Listar webhooks
     *
     * Lista endpoints outbound configurados para a instancia.
     * O campo `secret` **nao** e retornado — so e exibido na criacao.
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     *
     * @response 200 scenario="sucesso" {
     *   "data": [
     *     {
     *       "id": 7,
     *       "name": "ERP integration",
     *       "url": "https://erp.cliente.com/webhooks/whatsapp",
     *       "events": ["message.received", "message.sent"],
     *       "active": true,
     *       "created_at": "2026-06-15T10:00:00+00:00"
     *     }
     *   ]
     * }
     */
    public function index(Instance $instance): AnonymousResourceCollection
    {
        $endpoints = WebhookEndpoint::query()
            ->where('instance_id', $instance->id)
            ->orderByDesc('id')
            ->get();

        return WebhookResource::collection($endpoints);
    }

    /**
     * Criar webhook
     *
     * Registra um novo endpoint outbound. A resposta retorna o `secret`
     * **apenas nesta unica chamada** — guarde-o; o servidor armazena somente
     * uma copia interna usada para assinar entregas. Em producao, URLs `http://`
     * sao bloqueadas (exceto hosts na allowlist).
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     *
     * @bodyParam name string required Nome amigavel do endpoint (max. 128). Example: ERP integration
     * @bodyParam url string required URL HTTPS de destino. Example: https://erp.cliente.com/webhooks/whatsapp
     * @bodyParam events string[] required Eventos a receber. Valores: message.received, message.sent, message.delivered, message.read. Example: ["message.received", "message.sent"]
     *
     * @response 201 scenario="criado" {
     *   "status": "success",
     *   "message": "Webhook criado. Guarde o secret - nao sera exibido novamente.",
     *   "data": {
     *     "id": 7,
     *     "name": "ERP integration",
     *     "url": "https://erp.cliente.com/webhooks/whatsapp",
     *     "events": ["message.received", "message.sent"],
     *     "active": true,
     *     "secret": "a3f8b0c2d4e6...64chars"
     *   }
     * }
     * @response 422 scenario="URL invalida" {"message": "url precisa ser https (exceto hosts permitidos).", "errors": {"url": ["url precisa ser https (exceto hosts permitidos)."]}}
     */
    public function store(StoreWebhookRequest $request, Instance $instance): JsonResponse
    {
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));
        $validated = $request->validated();

        $endpoint = WebhookEndpoint::create([
            'instance_id' => $instance->id,
            'name' => $validated['name'],
            'url' => $validated['url'],
            'events' => $validated['events'],
            'secret' => $secret,
            'active' => true,
        ]);

        $data = (new WebhookResource($endpoint))->toArray($request);
        $data['secret'] = $secret;

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook criado. Guarde o secret — nao sera exibido novamente.',
            'data' => $data,
        ], 201);
    }

    /**
     * Detalhar webhook
     *
     * Retorna o detalhe de um endpoint. `secret` nunca e exposto aqui.
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     * @urlParam webhook integer required ID do endpoint. Example: 7
     *
     * @response 200 scenario="sucesso" {
     *   "status": "success",
     *   "message": "Webhook recuperado.",
     *   "data": {
     *     "id": 7,
     *     "name": "ERP integration",
     *     "url": "https://erp.cliente.com/webhooks/whatsapp",
     *     "events": ["message.received"],
     *     "active": true
     *   }
     * }
     * @response 404 scenario="webhook nao pertence a instancia" {"message": "Not Found"}
     */
    public function show(Instance $instance, WebhookEndpoint $webhook): JsonResponse
    {
        $this->assertOwnership($instance, $webhook);

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook recuperado.',
            'data' => new WebhookResource($webhook),
        ]);
    }

    /**
     * Remover webhook
     *
     * Remove permanentemente o endpoint outbound. Entregas pendentes em fila
     * sao descartadas. Para apenas desativar temporariamente, prefira atualizar
     * o campo `active`.
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     * @urlParam webhook integer required ID do endpoint. Example: 7
     *
     * @response 200 scenario="removido" {"status": "success", "message": "Webhook removido.", "data": []}
     * @response 404 scenario="webhook nao encontrado" {"message": "Not Found"}
     */
    public function destroy(Instance $instance, WebhookEndpoint $webhook): JsonResponse
    {
        $this->assertOwnership($instance, $webhook);

        $webhook->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook removido.',
            'data' => [],
        ]);
    }

    /**
     * Listar entregas de um webhook
     *
     * Retorna o historico de tentativas de entrega (deliveries) para o endpoint.
     * Util para debug e reconciliacao. Suporta filtros por status, evento e
     * paginacao via cursor.
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     * @urlParam webhook integer required ID do endpoint. Example: 7
     *
     * @queryParam status string Filtra por status. Valores: pending, succeeded, failed. Example: failed
     * @queryParam event string Filtra por nome do evento. Example: message.received
     * @queryParam cursor integer ID da ultima entrega da pagina anterior. Example: 5000
     * @queryParam limit integer Quantidade por pagina (1-200). Default: 50. Example: 50
     *
     * @response 200 scenario="sucesso" {
     *   "data": [
     *     {
     *       "id": 5001,
     *       "event": "message.received",
     *       "status": "succeeded",
     *       "attempts": 1,
     *       "response_status": 200,
     *       "delivered_at": "2026-06-18T14:01:25+00:00"
     *     }
     *   ],
     *   "meta": {"limit": 50, "has_more": false, "next_cursor": null}
     * }
     */
    public function deliveries(
        Request $request,
        Instance $instance,
        WebhookEndpoint $webhook,
    ): AnonymousResourceCollection {
        $this->assertOwnership($instance, $webhook);

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
            'cursor' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:pending,succeeded,failed',
            'event' => 'nullable|string|max:64',
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        $query = $webhook->deliveries()
            ->orderByDesc('id')
            ->limit($limit + 1);

        if (!empty($validated['cursor'])) {
            $query->where('id', '<', $validated['cursor']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['event'])) {
            $query->where('event', $validated['event']);
        }

        $deliveries = $query->get();
        $hasMore = $deliveries->count() > $limit;

        if ($hasMore) {
            $deliveries->pop();
        }

        $nextCursor = $hasMore && $deliveries->isNotEmpty() ? $deliveries->last()->id : null;

        return WebhookDeliveryResource::collection($deliveries)
            ->additional([
                'meta' => [
                    'limit' => $limit,
                    'has_more' => $hasMore,
                    'next_cursor' => $nextCursor,
                ],
            ]);
    }

    private function assertOwnership(Instance $instance, WebhookEndpoint $webhook): void
    {
        abort_if($webhook->instance_id !== $instance->id, 404);
    }
}
