<?php

namespace App\Console\Commands;

use App\Services\RepairTaskHousekeeper;
use Illuminate\Console\Command;

class ExpireStaleRepairTasksCommand extends Command
{
    protected $signature = 'agent:expire-stale';

    protected $description = 'Housekeeping de repair tasks — expira tasks dispatched órfãs ou que passaram do max de tentativas.';

    public function handle(RepairTaskHousekeeper $housekeeper): int
    {
        $result = $housekeeper->handleStaleTasks();

        $this->info(sprintf(
            'Housekeeping ok — expired=%d requeued=%d',
            $result['expired'],
            $result['requeued'],
        ));

        return self::SUCCESS;
    }
}
