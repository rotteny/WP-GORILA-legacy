<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('whatsapp_setups');
    }

    public function down(): void
    {
        // Migration anterior (create_whatsapp_setups_table) recria a tabela em rollback completo.
    }
};
