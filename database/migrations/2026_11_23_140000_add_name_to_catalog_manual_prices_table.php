<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Название из ручной синхронизации (1С переименовала позицию) держится так же, как цена:
        // выгрузка mylift отдаёт старое, и плановый импорт откатил бы его через несколько часов.
        if (Schema::hasTable('catalog_manual_prices') && ! Schema::hasColumn('catalog_manual_prices', 'name')) {
            Schema::table('catalog_manual_prices', function (Blueprint $table) {
                $table->string('name', 500)->nullable()->after('sku');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('catalog_manual_prices') && Schema::hasColumn('catalog_manual_prices', 'name')) {
            Schema::table('catalog_manual_prices', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }
    }
};
