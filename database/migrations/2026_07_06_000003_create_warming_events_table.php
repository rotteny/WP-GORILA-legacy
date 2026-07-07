<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warming_events', function (Blueprint $table) {
            // Histórico das mensagens de aquecimento trocadas entre instâncias de um
            // projeto. Registrado pelo engine no Node (POST /internal/warming-events),
            // consultável/filtrável por projeto e data.
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('sender_slug');
            $table->string('receiver_slug');
            $table->string('script_id')->nullable();
            $table->string('status')->default('sent'); // sent | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warming_events');
    }
};
