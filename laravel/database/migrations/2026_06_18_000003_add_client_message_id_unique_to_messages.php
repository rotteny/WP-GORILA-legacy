<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Garante idempotencia real do `client_message_id` por instancia.
 *
 * Indice parcial (Postgres) ignora linhas com client_message_id NULL,
 * evitando falsa colisao quando o caller nao envia idempotency key.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS messages_instance_client_unique
            ON messages (instance_id, client_message_id)
            WHERE client_message_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_instance_client_unique');
    }
};
