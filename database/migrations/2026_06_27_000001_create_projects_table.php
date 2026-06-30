<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name');

            // Telefone ativo atual do projeto (a instância por onde sai a mensagem).
            // FK -> instances; nullOnDelete pra não derrubar o projeto se a instância sair.
            $table->foreignId('active_instance_id')
                  ->nullable()
                  ->constrained('instances')
                  ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
