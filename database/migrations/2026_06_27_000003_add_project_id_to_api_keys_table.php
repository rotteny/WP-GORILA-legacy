<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            // Escopo por projeto: quando preenchido, a chave envia pelo telefone ATIVO
            // do projeto (failover transparente). instance_slug continua valendo para
            // chaves presas a uma instância específica. Uma chave usa um escopo ou o outro.
            $table->foreignId('project_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('projects')
                  ->cascadeOnDelete();

            // instance_slug deixa de ser obrigatório (chaves de projeto não têm instância fixa).
            $table->string('instance_slug', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
            $table->string('instance_slug', 64)->nullable(false)->change();
        });
    }
};
