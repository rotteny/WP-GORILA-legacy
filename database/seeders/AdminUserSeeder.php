<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('ADMIN_SEED_PASSWORD');

        if (! $password) {
            $this->command->error('ADMIN_SEED_PASSWORD nao definido no .env — seeder abortado.');
            return;
        }

        User::firstOrCreate(
            ['email' => 'admin@gorila.com'],
            [
                'name'     => 'Admin',
                'password' => Hash::make($password),
            ]
        );
    }
}
