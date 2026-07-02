<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mídia de saída assíncrona: como o worker roda DEPOIS da resposta 202, os bytes do
 * upload original já se foram. Guardamos o arquivo em storage e referenciamos aqui
 * (caminho + mime + nome) pro job ler e enviar depois. O arquivo é apagado no envio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('media_path')->nullable()->after('body');   // caminho no disk 'local'
            $table->string('media_mime', 150)->nullable()->after('media_path');
            $table->string('media_name')->nullable()->after('media_mime');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['media_path', 'media_mime', 'media_name']);
        });
    }
};
