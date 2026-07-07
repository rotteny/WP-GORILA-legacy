<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            // Presença de hmac_secret marca a chave como cockpit (repair-agent).
            $table->string('hmac_secret', 128)->nullable()->after('key_hash');
            $table->string('hostname_hint', 100)->nullable()->after('hmac_secret');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['hmac_secret', 'hostname_hint']);
        });
    }
};
