<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageController extends Controller
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    public function index(Request $request, Instance $instance): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'direction' => 'nullable|in:in,out',
            'type'      => 'nullable|in:text,image,video,audio,document,sticker,location,contact,unknown',
            'contact'   => 'nullable|string|max:128',
            'since'     => 'nullable|date',
            'until'     => 'nullable|date',
            'cursor'    => 'nullable|integer|min:1',
            'limit'     => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        // +1 pra detectar se há próxima página sem fazer count() separado.
        $query = Message::query()
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
        $hasMore  = $messages->count() > $limit;

        if ($hasMore) {
            $messages->pop();
        }

        $nextCursor = $hasMore && $messages->isNotEmpty() ? $messages->last()->id : null;

        return MessageResource::collection($messages)
            ->additional([
                'meta' => [
                    'limit'       => $limit,
                    'has_more'    => $hasMore,
                    'next_cursor' => $nextCursor,
                ],
            ]);
    }

    public function show(Instance $instance, Message $message): MessageResource
    {
        abort_if($message->instance_id !== $instance->id, 404);

        return new MessageResource($message);
    }

    public function store(Request $request, Instance $instance): JsonResponse
    {
        return response()->json([
            'error' => 'Endpoint disponível no Chunk 3 da API',
            'code'  => 'NOT_IMPLEMENTED',
        ], 501);
    }
}
