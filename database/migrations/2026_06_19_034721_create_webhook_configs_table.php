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
        Schema::create('webhook_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained()->cascadeOnDelete();
            $table->string('event', 50); // 'message','message_deleted','message_reaction','connection'
            $table->string('url', 500);
            $table->boolean('active')->default(true);
            $table->string('secret', 255)->nullable();
            $table->timestamps();
            $table->unique(['instance_id', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_configs');
    }
};
