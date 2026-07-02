<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log de cada TENTATIVA de entrega de webhook de saída (não uma linha por webhook,
     * mas uma por POST feito). Serve pra auditoria/reprocesso: qual URL, qual evento,
     * status HTTP, corpo da resposta e em qual tentativa (1..6) parou.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            // Config de origem; nullable + nullOnDelete pra não perder o histórico se o
            // consumidor apagar o webhook depois.
            $table->foreignId('webhook_config_id')->nullable()->constrained()->nullOnDelete();
            $table->string('instance_id');           // slug da instância
            $table->string('event', 50);             // evento externo: message.received, message.sent, ...
            $table->string('url', 500);
            $table->unsignedTinyInteger('attempt')->default(1); // 1..6
            $table->boolean('success')->default(false);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('instance_id');
            $table->index('event');
            $table->index(['success', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
