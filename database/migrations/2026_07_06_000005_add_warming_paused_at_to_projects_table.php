<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Pausa de segurança do aquecimento: setada quando um telefone do projeto
            // cai/é bloqueado durante o warming (possível sinal de padrão detectado).
            // Enquanto não-null, o engine ignora o projeto; reativação é MANUAL pelo painel.
            $table->timestamp('warming_paused_at')->nullable()->after('warming_config');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('warming_paused_at');
        });
    }
};
