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

class WebhookController extends Controller
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const SECRET_BYTES = 32;

    public function index(Instance $instance): AnonymousResourceCollection
    {
        $endpoints = WebhookEndpoint::query()
            ->where('instance_id', $instance->id)
            ->orderByDesc('id')
            ->get();

        return WebhookResource::collection($endpoints);
    }

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

    public function show(Instance $instance, WebhookEndpoint $webhook): JsonResponse
    {
        $this->assertOwnership($instance, $webhook);

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook recuperado.',
            'data' => new WebhookResource($webhook),
        ]);
    }

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
