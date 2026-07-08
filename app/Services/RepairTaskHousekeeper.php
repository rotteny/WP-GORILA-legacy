<?php

namespace App\Services;

use App\Models\RepairTask;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping de repair tasks:
 *  - tasks `dispatched` órfãs (sem retorno) voltam pra `pending` incrementando attempt;
 *  - tasks que estouraram o cap de tentativas são marcadas como `expired` (estado terminal).
 *
 * Extraído do AgentController pra permitir reuso via scheduled command
 * (`agent:expire-stale`) e o housekeeping inline no polling do cockpit.
 */
class RepairTaskHousekeeper
{
    /** Máximo tempo que uma task fica `dispatched` sem retorno antes de voltar a `pending`. */
    public const DISPATCH_TIMEOUT_MINUTES = 3;

    /** Cap de tentativas antes de virar `expired`. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Processa tasks `dispatched` cujo dispatch estourou o timeout.
     *
     * Ordem importa: primeiro promove pra `expired` quem já estourou o cap,
     * depois requeue o restante — senão nenhuma task chegaria ao estado terminal.
     *
     * @return array{expired: int, requeued: int}
     */
    public function handleStaleTasks(): array
    {
        $cutoff = now()->subMinutes(self::DISPATCH_TIMEOUT_MINUTES);

        $expired = RepairTask::where('status', 'dispatched')
            ->where('dispatched_at', '<', $cutoff)
            ->where('attempt', '>=', self::MAX_ATTEMPTS)
            ->update([
                'status'        => 'expired',
                'error_message' => 'max attempts exceeded',
                'result_at'     => now(),
            ]);

        $requeued = RepairTask::where('status', 'dispatched')
            ->where('dispatched_at', '<', $cutoff)
            ->update([
                'status'        => 'pending',
                'attempt'       => DB::raw('attempt + 1'),
                'dispatched_to' => null,
                'dispatched_at' => null,
            ]);

        return [
            'expired'  => $expired,
            'requeued' => $requeued,
        ];
    }
}
