<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Models\WebhookConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookConfigController extends Controller
{
    private const VALID_EVENTS = ['message', 'message_deleted', 'message_reaction', 'connection'];

    public function index(Instance $instance): JsonResponse
    {
        $configs = WebhookConfig::where('instance_id', $instance->id)
            ->get()
            ->keyBy('event');

        $result = collect(self::VALID_EVENTS)->map(fn ($event) => [
            'event'  => $event,
            'url'    => $configs[$event]->url    ?? '',
            'active' => $configs[$event]->active ?? false,
            'secret' => $configs[$event]->secret ?? '',
        ]);

        return response()->json($result);
    }

    public function upsert(Instance $instance, Request $request): JsonResponse
    {
        $data = $request->validate([
            'event'  => ['required', 'in:' . implode(',', self::VALID_EVENTS)],
            'url'    => ['required', 'url', 'max:500'],
            'active' => ['boolean'],
            'secret' => ['nullable', 'string', 'max:255'],
        ]);

        WebhookConfig::updateOrCreate(
            ['instance_id' => $instance->id, 'event' => $data['event']],
            [
                'url'    => $data['url'],
                'active' => $data['active'] ?? true,
                'secret' => $data['secret'] ?? null,
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Instance $instance, string $event): JsonResponse
    {
        WebhookConfig::where('instance_id', $instance->id)
            ->where('event', $event)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
