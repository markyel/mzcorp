<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Фото позиции без водяного знака (раздел «Фото», белый фон): путь на диске local и когда обработано.
        // Отдельно от photo_url (1С, с водяным знаком сайта) — импорт каталога его не трогает.
        Schema::table('catalog_items', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_items', 'clean_photo_path')) {
                $table->string('clean_photo_path', 500)->nullable();
            }
            if (! Schema::hasColumn('catalog_items', 'clean_photo_at')) {
                $table->timestamp('clean_photo_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            foreach (['clean_photo_path', 'clean_photo_at'] as $column) {
                if (Schema::hasColumn('catalog_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
