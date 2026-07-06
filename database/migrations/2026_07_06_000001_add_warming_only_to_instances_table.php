<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            // Papel "warming-only": chip dedicado exclusivamente ao aquecimento. Nunca é
            // promovido a ativo do projeto nem escolhido como endpoint de envio externo —
            // só dá corpo às conversas simuladas do warming. default false = comportamento
            // atual (telefone de produção) para todas as instâncias existentes.
            $table->boolean('warming_only')->default(false)->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn('warming_only');
        });
    }
};
