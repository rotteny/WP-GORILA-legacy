<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();

            // Vínculo com a instância (slug). Cascade pra apagar chaves quando a instância sai.
            $table->string('instance_slug', 64)->index();

            // Nome descritivo da chave ("acca-homolog", "n8n-marketing", etc).
            $table->string('name', 100);

            // Prefixo curto da chave em plaintext, pra exibir na UI.
            // Ex: chave 'wpg_a1b2c3...' tem prefix 'wpg_a1b2c3'.
            $table->string('key_prefix', 16);

            // Hash bcrypt da chave completa. Plaintext só existe no momento da criação.
            $table->string('key_hash');

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->foreign('instance_slug')
                  ->references('slug')->on('instances')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
