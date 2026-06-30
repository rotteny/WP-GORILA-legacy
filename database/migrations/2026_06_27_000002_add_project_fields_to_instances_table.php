<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            // Projeto dono deste telefone. Exclusivo: uma instância pertence a no máximo
            // um projeto. nullOnDelete: apagar o projeto solta as instâncias (não as apaga).
            $table->foreignId('project_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('projects')
                  ->nullOnDelete();

            // Ordem de failover dentro do projeto (menor = preferido). 1=tik1, 2=tik2...
            $table->unsignedInteger('priority')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn(['project_id', 'priority']);
        });
    }
};
