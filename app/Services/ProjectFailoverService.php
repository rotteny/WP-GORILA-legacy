<?php

namespace App\Services;

use App\Mail\FailoverAlert;
use App\Models\Instance;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Gerencia qual telefone (instância) é o ativo de um projeto.
 *
 * - promote(): troca manual do telefone ativo (silenciosa — é ação do usuário).
 * - failover(): troca involuntária — escolhe o próximo telefone CONNECTED por
 *   prioridade, promove e DISPARA o aviso (webhook do projeto + log). Quando não
 *   há backup, dispara um alerta de "sem telefone disponível".
 *
 * O failover é acionado de dois lugares: proativamente no webhook de LOGGED_OUT
 * (WhatsAppController::webhook) e, como rede de segurança, na hora do envio
 * (WhatsAppController::resolveActiveInstance).
 */
class ProjectFailoverService
{
    /**
     * Define manualmente o telefone ativo do projeto. Não dispara aviso.
     *
     * @throws \InvalidArgumentException se a instância não pertencer ao projeto.
     */
    public function promote(Project $project, Instance $instance): Project
    {
        if ($instance->project_id !== $project->id) {
            throw new \InvalidArgumentException(
                "A instância '{$instance->slug}' não pertence ao projeto '{$project->slug}'."
            );
        }

        // Chip warming-only existe só pra dar corpo ao aquecimento; nunca pode ser o
        // telefone ativo do projeto (por onde saem as mensagens reais).
        if ($instance->warming_only) {
            throw new \InvalidArgumentException(
                'Não é possível promover uma instância dedicada a aquecimento.'
            );
        }

        return DB::transaction(function () use ($project, $instance) {
            $project->forceFill(['active_instance_id' => $instance->id])->save();

            return $project->refresh();
        });
    }

    /**
     * Promove o próximo telefone CONNECTED do projeto (menor priority primeiro),
     * ignorando a instância que caiu, e avisa. Retorna a instância promovida, ou
     * null se não houver backup (caso em que dispara um alerta).
     *
     * @param string $reason Motivo do failover (ex.: 'logged_out', 'send_time').
     */
    public function failover(Project $project, ?Instance $failed = null, string $reason = 'unknown'): ?Instance
    {
        // Candidatos: CONNECTED, não warming-only, diferentes do que caiu, por prioridade.
        $candidates = fn () => $project->instances()
            ->where('status', 'CONNECTED')
            ->where('warming_only', false) // chip de aquecimento nunca vira ativo
            ->when($failed, fn ($q) => $q->where('id', '!=', $failed->id))
            ->orderBy('priority');

        // Prefere um chip que já completou a rampa; só cai num chip ainda em
        // aquecimento se não houver nenhum pronto (melhor enviar do que ficar sem).
        $next = $candidates()->fullyRamped()->first() ?? $candidates()->first();

        if (!$next) {
            $this->notify($project, $failed, null, $reason);
            Log::warning('Failover sem backup disponível', [
                'project' => $project->slug,
                'failed'  => $failed?->slug,
                'reason'  => $reason,
            ]);

            return null;
        }

        $this->promote($project, $next);

        $this->notify($project, $failed, $next, $reason);
        Log::info('Failover: telefone ativo trocado', [
            'project' => $project->slug,
            'from'    => $failed?->slug,
            'to'      => $next->slug,
            'reason'  => $reason,
        ]);

        return $next;
    }

    /**
     * Avisa sobre o failover. Canais (cada um best-effort, independente):
     *   - Email ao responsável do projeto (responsible_email) — canal principal;
     *   - Webhook do projeto (failover_webhook_url) — para integrações externas.
     * to=null significa "sem telefone disponível" (alerta).
     */
    private function notify(Project $project, ?Instance $from, ?Instance $to, string $reason): void
    {
        $this->notifyByEmail($project, $from, $to, $reason);
        $this->notifyByWebhook($project, $from, $to, $reason);
    }

    /** Email ao responsável do projeto, se configurado. */
    private function notifyByEmail(Project $project, ?Instance $from, ?Instance $to, string $reason): void
    {
        if (!$project->responsible_email) {
            return;
        }

        try {
            Mail::to($project->responsible_email)
                ->send(new FailoverAlert($project, $from?->slug, $to?->slug, $reason));
        } catch (\Throwable $e) {
            Log::warning('Failover: falha ao enviar email ao responsável', [
                'project' => $project->slug,
                'email'   => $project->responsible_email,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** Webhook do projeto, se configurado. */
    private function notifyByWebhook(Project $project, ?Instance $from, ?Instance $to, string $reason): void
    {
        if (!$project->failover_webhook_url) {
            return;
        }

        $body = [
            'event'      => 'failover',
            'project'    => $project->slug,
            'from'       => $from?->slug,
            'to'         => $to?->slug,
            'reason'     => $reason,
            'available'  => $to !== null,
            'occurred_at' => now()->toISOString(),
        ];

        $request = Http::timeout(10)->acceptJson();

        if ($project->failover_webhook_secret) {
            $signature = hash_hmac('sha256', json_encode($body), $project->failover_webhook_secret);
            $request   = $request->withHeaders(['X-Hub-Signature-256' => 'sha256=' . $signature]);
        }

        try {
            $request->post($project->failover_webhook_url, $body);
        } catch (\Throwable $e) {
            Log::warning('Failover: falha ao enviar aviso', [
                'project' => $project->slug,
                'url'     => $project->failover_webhook_url,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
