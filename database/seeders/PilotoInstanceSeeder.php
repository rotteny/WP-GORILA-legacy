<?php

namespace Database\Seeders;

use App\Models\Instance;
use Illuminate\Database\Seeder;

class PilotoInstanceSeeder extends Seeder
{
    public function run(): void
    {
        Instance::firstOrCreate(
            ['slug' => 'piloto'],
            [
                'name'   => 'Piloto Inicial',
                'status' => 'CONNECTED',
            ],
        );
    }
}
