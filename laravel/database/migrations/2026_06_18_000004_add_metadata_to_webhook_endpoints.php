<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->timestamp('last_success_at')->nullable()->after('events');
            $table->timestamp('last_failure_at')->nullable()->after('last_success_at');
            $table->integer('consecutive_failures')->default(0)->after('last_failure_at');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn(['last_success_at', 'last_failure_at', 'consecutive_failures']);
        });
    }
};
