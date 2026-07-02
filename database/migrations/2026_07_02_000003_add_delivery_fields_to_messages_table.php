<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recibos de entrega/leitura do WhatsApp (evento `messages.update` do Baileys).
 * Guardam quando a mensagem de saída foi entregue no aparelho e quando foi lida —
 * a leitura implica entrega, então `read_at` nunca vem sem `delivered_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('read_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['delivered_at', 'read_at']);
        });
    }
};
