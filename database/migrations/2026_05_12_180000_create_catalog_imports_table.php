<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аудит выгрузок каталога (MDB → POST /api/catalog/import).
 *
 * Одна строка на каждый принятый snapshot:
 *  - кто прислал (источник по токену / IP),
 *  - mode = full | delta (пока только full — у MDB нет Modified-колонки),
 *  - сколько rows пришло, сколько created/updated/unchanged/soft_deleted,
 *  - длительность импорта,
 *  - errors[] (jsonb) — sku, по которому не прошла валидация / дубль в snapshot.
 *
 * Используется CatalogImportService и для диагностики через
 * `php artisan tinker --execute='\App\Models\CatalogImport::latest()->first()'`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_imports')) {
            Schema::create('catalog_imports', function (Blueprint $table) {
                $table->id();
                $table->string('mode', 16)->default('full');
                $table->string('source', 128)->nullable()->comment('source-tag из тела запроса (например, hostname офисной машины)');
                $table->string('client_ip', 64)->nullable();

                $table->unsignedInteger('rows_total')->default(0);
                $table->unsignedInteger('rows_created')->default(0);
                $table->unsignedInteger('rows_updated')->default(0);
                $table->unsignedInteger('rows_unchanged')->default(0);
                $table->unsignedInteger('rows_soft_deleted')->default(0);

                $table->unsignedInteger('duration_ms')->nullable();
                $table->jsonb('errors')->nullable();

                $table->timestamps();

                $table->index('created_at', 'catalog_imports_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_imports');
    }
};
