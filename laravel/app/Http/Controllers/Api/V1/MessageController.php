<?php

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
    public function index(Request $request, Instance $instance): AnonymousResourceCollection
    {
        $messages = Message::query()
            ->where('instance_id', $instance->id)
            ->latest()
            ->limit(50)
            ->get();

        return MessageResource::collection($messages);
    }

    public function store(Request $request, Instance $instance): JsonResponse
    {
        return response()->json([
            'error' => 'Endpoint disponível no Chunk 3 da API',
            'code' => 'NOT_IMPLEMENTED',
        ], 501);
    }
}
