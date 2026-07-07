<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ponytail: 1 row por cockpit (upsert). Perde histórico, mas o contrato só pede "último heartbeat".
    // Se quiser histórico, dropar o unique e converter em append-only + view de "latest per key".
    public function up(): void
    {
        Schema::create('cockpit_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->unique()->constrained('api_keys')->cascadeOnDelete();
            $table->string('hostname', 100);
            $table->json('devices_online');
            $table->timestamp('received_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cockpit_heartbeats');
    }
};
