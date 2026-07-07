<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Models\RepairTask;
use Illuminate\Console\Command;

class EnqueueRepairTaskCommand extends Command
{
    protected $signature = 'agent:enqueue-task {--slug= : Slug da instância} {--code= : Pairing code XXXX-XXXX} {--expires=300 : Segundos até expirar}';
    protected $description = 'Enfileira manualmente uma repair task pra testar o cockpit (dev/homolog).';

    public function handle(): int
    {
        $slug = $this->option('slug') ?: $this->ask('instance_slug');
        $code = $this->option('code') ?: $this->ask('pairing_code (XXXX-XXXX)');

        $instance = Instance::where('slug', $slug)->first();

        $task = RepairTask::create([
            'instance_id'   => $instance?->id,
            'instance_slug' => $slug,
            'pairing_code'  => $code,
            'status'        => 'pending',
            'expires_at'    => now()->addSeconds((int) $this->option('expires')),
        ]);

        $this->info("Task #{$task->id} pending para instância '{$slug}' (expira em {$this->option('expires')}s)");

        return self::SUCCESS;
    }
}
