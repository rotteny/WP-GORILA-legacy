<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id');
            $table->string('whatsapp_message_id')->unique();
            $table->string('from', 100);
            $table->string('chat_type', 20)->default('private');
            $table->string('participant', 100)->nullable();
            $table->boolean('from_me')->default(false);
            $table->string('type', 30)->default('text');
            $table->text('body')->nullable();
            $table->string('sender_name', 255)->nullable();
            $table->string('sender_phone', 30)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index('instance_id');
            $table->index('from');
            $table->index('received_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
