<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\CockpitHeartbeat;
use App\Models\RepairTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints consumidos pelo daemon Python `repair-agent/` rodando no PC cockpit.
 * Contrato completo em docs/repair-agent-python-plan.md.
 */
class AgentController extends Controller
{
    /** Máximo tempo que uma task fica `dispatched` sem retorno antes de voltar a `pending`. */
    private const DISPATCH_TIMEOUT_MINUTES = 3;

    /** Após N falhas seguidas, escalar (por ora só loga; ver ponytail comment). */
    private const FAILURE_ESCALATION_THRESHOLD = 3;

    /**
     * GET /api/whatsapp/agent/tasks
     *
     * Retorna 204 se não há task pendente. Senão, 200 com JSON assinado via HMAC.
     */
    public function tasks(Request $request)
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('cockpit_key');

        // Housekeeping: tasks dispatched há muito tempo voltam pra pendente.
        // ponytail: feito inline em vez de cron. Trocar por scheduled job se virar hot.
        RepairTask::where('status', 'dispatched')
            ->where('dispatched_at', '<', now()->subMinutes(self::DISPATCH_TIMEOUT_MINUTES))
            ->update([
                'status'         => 'pending',
                'attempt'        => DB::raw('attempt + 1'),
                'dispatched_to'  => null,
                'dispatched_at'  => null,
            ]);

        $task = DB::transaction(function () use ($apiKey) {
            $query = RepairTask::query()
                ->where('status', 'pending')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('id');

            // Postgres suporta SKIP LOCKED (evita starvation entre múltiplos cockpits).
            // SQLite não — cai no lockForUpdate padrão (que na prática é no-op em sqlite).
            $query = DB::connection()->getDriverName() === 'pgsql'
                ? $query->lock('FOR UPDATE SKIP LOCKED')
                : $query->lockForUpdate();

            $task = $query->first();
            if (!$task) {
                return null;
            }

            $task->update([
                'status'         => 'dispatched',
                'dispatched_to'  => $apiKey->hostname_hint ?: "key-{$apiKey->id}",
                'dispatched_at'  => now(),
            ]);

            return $task;
        });

        if (!$task) {
            return response()->noContent();
        }

        $payload = [
            'id'            => $task->id,
            'instance_slug' => $task->instance_slug,
            'pairing_code'  => $task->pairing_code, // decriptado pelo accessor do model
        ];

        // json_encode sem flags que o daemon Python não replicaria — bytes exatos importam pro HMAC.
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $apiKey->hmac_secret);

        return response($body, 200, [
            'Content-Type'    => 'application/json',
            'X-Wpg-Signature' => $signature,
        ]);
    }

    /**
     * POST /api/whatsapp/agent/result
     */
    public function result(Request $request)
    {
        $data = $request->validate([
            'task_id'    => 'required|integer|exists:repair_tasks,id',
            'status'     => 'required|in:success,failed',
            'error'      => 'nullable|string|max:2000',
            'screenshot' => 'nullable|string|max:200',
        ]);

        $task = RepairTask::findOrFail($data['task_id']);

        $task->update([
            'status'         => $data['status'],
            'result_at'      => now(),
            'error_message'  => $data['error'] ?? null,
            'screenshot_ref' => $data['screenshot'] ?? null,
        ]);

        if ($data['status'] === 'failed') {
            // ponytail: só loga. Hook aqui p/ escalar quando `attempt >= threshold`
            // (email, PagerDuty, WhatsApp interno, etc).
            if ($task->attempt >= self::FAILURE_ESCALATION_THRESHOLD) {
                Log::warning('repair.task.failure_threshold', [
                    'task_id'       => $task->id,
                    'instance_slug' => $task->instance_slug,
                    'attempt'       => $task->attempt,
                    'error'         => $data['error'] ?? null,
                ]);
            }
        }

        // ponytail: sucesso deveria notificar filas de mensagens seguradas.
        // Hook aqui: event(new SessionRepaired($task->instance_slug)) quando o listener existir.

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/whatsapp/agent/heartbeat
     */
    public function heartbeat(Request $request)
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('cockpit_key');

        $data = $request->validate([
            'hostname'         => 'required|string|max:100',
            'devices_online'   => 'required|array',
            'devices_online.*' => 'string|max:100',
        ]);

        CockpitHeartbeat::updateOrCreate(
            ['api_key_id' => $apiKey->id],
            [
                'hostname'       => $data['hostname'],
                'devices_online' => $data['devices_online'],
                'received_at'    => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }
}
