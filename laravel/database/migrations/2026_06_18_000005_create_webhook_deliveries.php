<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')
                ->constrained('webhook_endpoints')
                ->cascadeOnDelete();
            // message_id: nullOnDelete preserva audit trail se a mensagem for removida.
            // Cascade aqui apagaria historico de delivery — auditoria precisa sobreviver a remocao logica.
            $table->foreignId('message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();
            $table->string('event', 64);
            $table->jsonb('payload');
            $table->unsignedSmallInteger('attempt');
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body_excerpt')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'status']);
            $table->index('message_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
