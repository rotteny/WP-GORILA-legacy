<?php

namespace App\Services;

use App\Exceptions\TargetUnavailableException;
use App\Models\ApiKey;
use App\Models\Instance;
use App\Models\Project;

/**
 * Resolve qual {@see Instance} (telefone) deve receber um envio, aplicando failover
 * de projeto quando necessário. Fonte única dessa lógica — em erro lança
 * {@see TargetUnavailableException} com o corpo/status a devolver ao consumidor.
 */
class InstanceResolver
{
    public function __construct(private ProjectFailoverService $failover)
    {
    }

    /**
     * Resolve o destino a partir do escopo da API key autenticada.
     */
    public function fromApiKey(?ApiKey $apiKey): Instance
    {
        if (! $apiKey) {
            throw new TargetUnavailableException(['ok' => false, 'error' => 'Chave de API ausente.'], 401);
        }

        if ($apiKey->project_id) {
            $project = Project::find($apiKey->project_id);
            if (! $project) {
                throw new TargetUnavailableException(['ok' => false, 'error' => 'Projeto da chave não encontrado.'], 404);
            }

            return $this->forProject($project);
        }

        if ($apiKey->instance_slug) {
            $instance = Instance::where('slug', $apiKey->instance_slug)->first();
            if (! $instance) {
                throw new TargetUnavailableException(['ok' => false, 'error' => 'Instância da chave não encontrada.'], 404);
            }

            return $this->requireConnected($instance);
        }

        throw new TargetUnavailableException(
            ['ok' => false, 'error' => 'Chave de API sem escopo (sem projeto nem instância).'],
            422
        );
    }

    /**
     * Resolve o telefone ativo do projeto, tentando failover se o ativo caiu.
     */
    public function forProject(Project $project): Instance
    {
        $instance = $project->activeInstance;

        if (! $instance || $instance->status !== 'CONNECTED') {
            $instance = $this->failover->failover($project, $instance, 'send_time');
        }

        if (! $instance) {
            throw new TargetUnavailableException([
                'ok'      => false,
                'error'   => "Projeto '{$project->slug}' não tem telefone conectado disponível.",
                'project' => $project->slug,
            ], 409);
        }

        return $instance;
    }

    /**
     * Garante que a instância está conectada antes de enviar por ela.
     */
    public function requireConnected(Instance $instance): Instance
    {
        if ($instance->status !== 'CONNECTED') {
            throw new TargetUnavailableException(
                ['ok' => false, 'error' => 'instância não conectada', 'status' => $instance->status],
                409
            );
        }

        return $instance;
    }
}
