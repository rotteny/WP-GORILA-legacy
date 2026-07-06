<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Models\Project;
use App\Services\ProjectFailoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    private const HTTP_TIMEOUT = 10;

    public function __construct(private readonly ProjectFailoverService $failover)
    {
    }

    public function index(): JsonResponse
    {
        $projects = Project::query()
            ->with(['instances', 'activeInstance'])
            ->orderBy('name')
            ->get();

        return response()->json($projects);
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json($project->load(['instances', 'activeInstance']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'lowercase', 'regex:/^[a-z0-9][a-z0-9_-]{0,30}$/', 'unique:projects,slug'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $project = Project::create($data);

        return response()->json($project, 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'name'                        => ['sometimes', 'string', 'max:255'],
            'responsible_email'           => ['sometimes', 'nullable', 'email', 'max:255'],
            'failover_webhook_url'        => ['sometimes', 'nullable', 'url', 'max:500'],
            // Aquecimento (Fase 1)
            'warming_enabled'             => ['sometimes', 'boolean'],
            'warming_config'              => ['sometimes', 'array'],
            'warming_config.intensity'    => ['sometimes', 'in:baixa,media,alta'],
            'warming_config.window_start' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'warming_config.window_end'   => ['sometimes', 'integer', 'min:0', 'max:23'],
        ]);

        $project->update($data);

        return response()->json($project->fresh(['instances', 'activeInstance']));
    }

    public function destroy(Project $project): Response
    {
        // O telefone vive dentro do projeto: ao excluir o projeto, apaga os telefones
        // (sessão no whatsapp-service + registro). Best-effort no Node — se estiver fora,
        // ainda assim remove os registros pra não deixar órfão.
        foreach ($project->instances as $instance) {
            $this->deleteNodeSession($instance->slug);
            $instance->delete();
        }

        $project->delete();

        return response()->noContent();
    }

    /**
     * Cria um telefone (instância) DENTRO do projeto: cria a sessão no whatsapp-service,
     * registra a instância já vinculada ao projeto e define sua prioridade de failover.
     * Se já existir um telefone SOLTO com esse slug (sobra de um projeto excluído), ele é
     * ADOTADO pelo projeto em vez de dar erro. O QR é lido depois em /p/{slug}/qr.
     */
    public function createInstance(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'slug'     => ['required', 'string', 'lowercase', 'regex:/^[a-z0-9][a-z0-9_-]{0,30}$/'],
            'name'     => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        $existing = Instance::where('slug', $data['slug'])->first();

        if ($existing && $existing->project_id === $project->id) {
            return response()->json(['ok' => false, 'error' => "Já existe um telefone '{$data['slug']}' neste projeto."], 409);
        }
        if ($existing && $existing->project_id !== null) {
            return response()->json(['ok' => false, 'error' => "O slug '{$data['slug']}' já está em uso por outro projeto."], 409);
        }

        // Garante a sessão no whatsapp-service. Pra telefone novo, falha de verdade barra.
        // Pra adoção (telefone já existia solto), a sessão provavelmente já existe — tolera.
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->post($this->nodeBaseUrl() . '/instances', [
                    'id'   => $data['slug'],
                    'name' => $data['name'],
                ]);
        } catch (\Throwable $e) {
            Log::error('Falha ao criar instância no whatsapp-service', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'whatsapp-service indisponível'], 502);
        }

        if (!$response->successful() && !$existing) {
            return response()->json($response->json() ?? ['ok' => false], $response->status());
        }

        // Prioridade default: próxima na fila do projeto.
        $priority = $data['priority'] ?? (($project->instances()->max('priority') ?? 0) + 1);

        if ($existing) {
            $existing->update(['project_id' => $project->id, 'priority' => $priority]);
        } else {
            Instance::create([
                'project_id' => $project->id,
                'slug'       => $data['slug'],
                'name'       => $data['name'],
                'status'     => 'INITIALIZING',
                'priority'   => $priority,
            ]);
        }

        return response()->json($project->fresh(['instances', 'activeInstance']), 201);
    }

    /**
     * Remove (apaga) o telefone do projeto: sessão no whatsapp-service + registro.
     * Best-effort no Node. Se era o ativo, o ponteiro zera sozinho (nullOnDelete na FK).
     */
    public function deleteInstance(Project $project, Instance $instance): JsonResponse
    {
        if ($instance->project_id !== $project->id) {
            return response()->json([
                'ok'    => false,
                'error' => "O telefone '{$instance->slug}' não pertence a este projeto.",
            ], 404);
        }

        $this->deleteNodeSession($instance->slug);
        $instance->delete();

        return response()->json($project->fresh(['instances', 'activeInstance']));
    }

    /** Apaga a sessão no whatsapp-service, ignorando indisponibilidade/404. */
    private function deleteNodeSession(string $slug): void
    {
        try {
            Http::timeout(self::HTTP_TIMEOUT)->acceptJson()
                ->delete($this->nodeBaseUrl() . '/instances/' . $slug);
        } catch (\Throwable $e) {
            Log::warning('Falha ao apagar sessão no whatsapp-service (seguindo)', [
                'slug' => $slug, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Atualiza campos por-telefone dentro do projeto. Hoje só o papel `warming_only`
     * (chip dedicado ao aquecimento).
     *
     * Edge case: ativar warming_only no telefone que É o ativo do projeto força um
     * failover imediato pro próximo chip de produção CONNECTED ANTES de setar a flag —
     * senão o projeto ficaria com um chip de aquecimento como ativo (que não pode enviar).
     * A confirmação com o usuário acontece na UI; aqui a troca é feita de fato.
     */
    public function updateInstance(Request $request, Project $project, Instance $instance): JsonResponse
    {
        if ($instance->project_id !== $project->id) {
            return response()->json([
                'ok'    => false,
                'error' => "O telefone '{$instance->slug}' não pertence a este projeto.",
            ], 404);
        }

        $data = $request->validate([
            'warming_only' => ['required', 'boolean'],
        ]);

        if ($data['warming_only'] && $project->active_instance_id === $instance->id) {
            $this->failover->failover($project, $instance, 'warming_only_toggle');
        }

        $instance->update(['warming_only' => $data['warming_only']]);

        return response()->json($project->fresh(['instances', 'activeInstance']));
    }

    /**
     * Promove manualmente um telefone a ativo do projeto ("tornar ativo").
     */
    public function promote(Project $project, Instance $instance): JsonResponse
    {
        try {
            $this->failover->promote($project, $instance);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json($project->fresh(['instances', 'activeInstance']));
    }

    private function nodeBaseUrl(): string
    {
        return config('services.whatsapp.url', env('WHATSAPP_SERVICE_URL', 'http://whatsapp-service:3000'));
    }
}
