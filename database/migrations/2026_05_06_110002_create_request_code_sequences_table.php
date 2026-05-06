<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Хранилище счётчика для internal_code заявок.
 *
 * Формат кода: M-{year}-{N}. Каждый год N стартует с 1.
 * Атомарность гарантируется UPDATE ... RETURNING last_value через DB lock.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('request_code_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_code_sequences');
    }
};
