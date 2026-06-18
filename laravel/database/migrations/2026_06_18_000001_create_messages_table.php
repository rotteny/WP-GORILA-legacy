<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')
                ->constrained('instances')
                ->cascadeOnDelete();
            $table->string('direction', 8);
            $table->string('status', 16);
            $table->string('jid');
            $table->boolean('from_me');
            $table->string('message_type', 16);
            $table->text('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime')->nullable();
            $table->string('whatsapp_message_id')->nullable();
            $table->string('client_message_id')->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['instance_id', 'created_at']);
            $table->index(['instance_id', 'jid', 'created_at']);
            $table->unique(['instance_id', 'whatsapp_message_id'], 'messages_instance_wa_msg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
