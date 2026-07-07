<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->nullable()->constrained('instances')->nullOnDelete();
            $table->string('instance_slug', 60);
            // Ciphertext do Crypt::encryptString — pode passar de 255. Usar text.
            $table->text('pairing_code');
            // string em vez de enum pra portabilidade sqlite/pgsql. Valores: pending, dispatched, success, failed.
            $table->string('status', 20)->default('pending');
            $table->string('dispatched_to', 100)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('result_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('screenshot_ref', 200)->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_tasks');
    }
};
