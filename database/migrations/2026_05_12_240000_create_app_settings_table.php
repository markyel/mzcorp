<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: настройки приложения, редактируемые через UI «Настройки»
 * (Livewire admin страница). Используется как override поверх
 * config(): на каждый ключ — DB-значение, если установлено, иначе
 * fallback на .env / config defaults.
 *
 * Ключи именованы dot-нотацией, симметрично config(): например
 * `catalog.name_match.threshold`, `tax.vat_percent`. Сами имена
 * хранятся как строки.
 *
 * type-поле — для корректной десериализации:
 *   - string: value как есть;
 *   - int   : (int) value;
 *   - float : (float) value;
 *   - bool  : '1'/'0', 'true'/'false', '1'/'' ;
 *   - json  : json_decode для массивов/объектов.
 *
 * `updated_by_user_id` — кто последний раз менял (audit-trail без
 * отдельной audit-таблицы).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 191)->unique();
                $table->text('value')->nullable();
                $table->string('type', 16)->default('string');
                $table->text('description')->nullable();
                $table->foreignId('updated_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
