<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Liga/desliga o aquecimento automático das instâncias do projeto.
            $table->boolean('warming_enabled')->default(false)->after('failover_webhook_secret');

            // Config do aquecimento (intensity, window_start, window_end). null = usa os
            // defaults (ver Project::WARMING_DEFAULTS). O engine no Node lê via
            // GET /internal/warming-projects e interpreta a intensidade em delays.
            $table->json('warming_config')->nullable()->after('warming_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['warming_enabled', 'warming_config']);
        });
    }
};
