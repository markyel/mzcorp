<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цены закупки позиций каталога из 1С (выгрузка «Номенклатура (история
 * цен)»): у кого и почём покупали впервые и в последний раз. Вместе с нашими
 * запросами поставщикам даёт историю по позиции — кого спрашивали, кто давал
 * цену.
 *
 * Поставщик хранится как в 1С (там в названии бывают правила запроса: «ЗАПРОСЫ
 * ЧЕРЕЗ …», «ЦЕНЫ НА САЙТЕ»); supplier_id — наш реестр, если название
 * сопоставилось.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_supplier_prices')) {
            return;
        }
        Schema::create('catalog_supplier_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->string('sku', 32)->index();
            // first — первая закупка, last — последняя (колонки выгрузки 1С).
            $table->string('kind', 16);
            $table->string('supplier_name_1c', 500);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->date('priced_at')->nullable();
            $table->decimal('price', 14, 4);
            $table->string('currency', 8)->nullable();
            $table->string('source', 16)->default('1c');
            $table->string('import_file', 255)->nullable();
            $table->timestamps();

            $table->unique(['sku', 'kind', 'source']);
            $table->index('catalog_item_id');
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_supplier_prices');
    }
};
