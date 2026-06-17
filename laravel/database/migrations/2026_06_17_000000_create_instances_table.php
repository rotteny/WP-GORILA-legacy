<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instances', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name');
            $table->string('status')->default('INITIALIZING');
            $table->text('qr_code')->nullable();
            $table->text('qr_data_url')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instances');
    }
};
