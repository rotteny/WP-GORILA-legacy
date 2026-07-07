<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Models\Project;
use App\Models\WarmingEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aquecimento (warming) — Fase 1.
 *
 * Dois endpoints INTERNOS (sem autenticação por API key; ficam na rede Docker,
 * chamados só pelo whatsapp-service):
 *   - projects()   GET  /internal/warming-projects  → projetos com warming ligado +
 *                       config efetiva + instâncias CONNECTED (slug + warming_only).
 *   - storeEvent() POST /internal/warming-events     → grava o registro que o Node manda.
 *
 * E um endpoint AUTENTICADO (painel) pra consultar o histórico por projeto/data:
 *   - events()     GET  /projects/{project}/warming-events
 */
class WarmingController extends Controller
{
    /**
     * Lista os projetos elegíveis ao aquecimento: warming ligado e com pelo menos
     * uma instância CONNECTED. O Node usa essa lista pra escolher pares e roda o
     * loop de envio; re-valida o status ao vivo antes de cada mensagem.
     */
    public function projects(): JsonResponse
    {
        $projects = Project::query()
            ->where('warming_enabled', true)
            ->with(['instances' => fn ($q) => $q->where('status', 'CONNECTED')])
            ->get()
            ->map(fn (Project $p) => [
                'slug'      => $p->slug,
                'config'    => $p->effectiveWarmingConfig(),
                'instances' => $p->instances->map(fn ($i) => [
                    'slug'          => $i->slug,
                    'warming_only'  => (bool) $i->warming_only,
                    // Fração da rampa (0.2..1.0): o Node reduz o volume do chip novo.
                    'ramp_fraction' => $i->warmingRamp()['fraction'],
                ])->values(),
            ])
            // Precisa de >= 2 instâncias conectadas pra ter par de conversa.
            ->filter(fn ($p) => count($p['instances']) >= 2)
            ->values();

        return response()->json(['projects' => $projects]);
    }

    /**
     * Grava um evento de aquecimento reportado pelo Node (fire-and-forget do lado dele).
     */
    public function storeEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project'   => ['required', 'string'],
            'sender'    => ['required', 'string'],
            'receiver'  => ['required', 'string'],
            'script_id' => ['nullable', 'string'],
            'status'    => ['required', 'in:sent,failed'],
            'error'     => ['nullable', 'string'],
        ]);

        $project = Project::where('slug', $data['project'])->first();
        if (! $project) {
            return response()->json(['ok' => false, 'error' => 'projeto não encontrado'], 404);
        }

        WarmingEvent::create([
            'project_id'    => $project->id,
            'sender_slug'   => $data['sender'],
            'receiver_slug' => $data['receiver'],
            'script_id'     => $data['script_id'] ?? null,
            'status'        => $data['status'],
            'error'         => $data['error'] ?? null,
            'sent_at'       => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    /**
     * Histórico de aquecimento do projeto, filtrável por data (?date=YYYY-MM-DD) e
     * paginável (?limit=, padrão 100, máx 500). Mais recentes primeiro.
     */
    public function events(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'date'  => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $events = $project->warmingEvents()
            ->when($data['date'] ?? null, fn ($q, $date) => $q->whereDate('sent_at', $date))
            ->orderByDesc('sent_at')
            ->limit($data['limit'] ?? 100)
            ->get();

        return response()->json(['project' => $project->slug, 'events' => $events]);
    }

    /**
     * Estado da rampa de aquecimento de uma instância (em que fase da rampa está).
     * Consulta pelo painel.
     */
    public function status(Instance $instance): JsonResponse
    {
        $ramp = $instance->warmingRamp();

        return response()->json([
            'slug'               => $instance->slug,
            'warming_only'       => (bool) $instance->warming_only,
            'warming_started_at' => $instance->warming_started_at,
            'warming_skip_ramp'  => (bool) $instance->warming_skip_ramp,
            'ramping'            => $ramp['ramping'],
            'day'                => $ramp['day'],
            'total_days'         => $ramp['total_days'],
            'fraction'           => $ramp['fraction'],
        ]);
    }
}
