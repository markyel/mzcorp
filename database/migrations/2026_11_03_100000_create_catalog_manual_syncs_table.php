<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ручная синхронизация каталога: секретарь загружает выгрузку 1С с актуализированными
        // ценами, видит разбор (было → стало) и применяет. Журнал загрузок.
        if (Schema::hasTable('catalog_manual_syncs')) {
            return;
        }
        Schema::create('catalog_manual_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('file_path');
            // parsed — разобран, ждёт «Применить»; applied — применён; cancelled; failed.
            $table->string('status', 16)->default('parsed')->index();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_matched')->default(0);
            $table->unsignedInteger('rows_changed')->default(0);
            $table->unsignedInteger('rows_unknown')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            // Разбор построчно: артикул, было/стало, статус строки.
            $table->jsonb('rows')->nullable();
            $table->foreignId('catalog_import_id')->nullable()->constrained('catalog_imports')->nullOnDelete();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_manual_syncs');
    }
};
