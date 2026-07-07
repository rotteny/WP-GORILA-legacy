<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            // Momento em que o chip começou a aquecer (definido ao criar a instância
            // num projeto com warming ligado). Enquanto dentro da rampa de 14 dias,
            // o chip participa do warming com volume reduzido e não assume tráfego
            // real. null = chip antigo/estabelecido (tratado como 100%, sem rampa).
            $table->timestamp('warming_started_at')->nullable()->after('warming_only');

            // Override manual "Pular aquecimento": força 100% imediatamente.
            $table->boolean('warming_skip_ramp')->default(false)->after('warming_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn(['warming_started_at', 'warming_skip_ramp']);
        });
    }
};
