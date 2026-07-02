<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mensagens de saída (enviadas pela API async) passam a viver na mesma tabela,
     * com `from_me = true` e um ciclo de status (queued → sent → failed). Como a
     * mensagem é enfileirada antes de existir no WhatsApp, `whatsapp_message_id`
     * fica nulo até o worker enviar de fato — por isso deixa de ser obrigatório.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Identificador público estável, devolvido no 202 e usado pelo consumidor
            // pra correlacionar (o whatsapp_message_id só existe depois do envio).
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('status', 20)->nullable()->after('type');   // queued|sent|failed (null = inbound)
            $table->string('to', 120)->nullable()->after('from');       // destino do envio (jid resolvido)
            $table->text('error')->nullable()->after('body');           // motivo da falha, se status=failed
            $table->timestamp('sent_at')->nullable()->after('received_at');

            $table->index('status');
        });

        Schema::table('messages', function (Blueprint $table) {
            // Saída enfileirada ainda não tem id do WhatsApp nem `from` (o remetente
            // somos nós) — ambos deixam de ser obrigatórios.
            $table->string('whatsapp_message_id')->nullable()->change();
            $table->string('from', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'status', 'to', 'error', 'sent_at']);
        });
    }
};
