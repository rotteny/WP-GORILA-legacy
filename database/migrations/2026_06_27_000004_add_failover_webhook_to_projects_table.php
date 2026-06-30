<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // URL avisada quando o telefone ativo do projeto troca por failover (ou cai sem backup).
            $table->string('failover_webhook_url', 500)->nullable()->after('active_instance_id');
            // Segredo opcional para assinar o aviso (HMAC SHA256), como nos webhook_configs.
            $table->string('failover_webhook_secret', 255)->nullable()->after('failover_webhook_url');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['failover_webhook_url', 'failover_webhook_secret']);
        });
    }
};
