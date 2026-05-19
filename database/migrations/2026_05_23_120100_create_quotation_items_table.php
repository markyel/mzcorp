<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позиции КП. Каждая привязана к (RequestItem, CatalogItem) паре —
 * КП-позиция = «что предлагаем из каталога в ответ на позицию заявки».
 *
 * Snapshot всех каталог-полей: catalog_unit_price / catalog_price_min /
 * catalog_lead_time_days / snapshot_name / snapshot_sku / snapshot_brand /
 * snapshot_photo_url. Это нужно для двух целей:
 *  1) Цена в КП не должна меняться когда обновляется catalog_items.price
 *     (catalog:sync-from-url 6 раз в день). Клиент видит зафиксированную
 *     цену.
 *  2) Каталог-позиция может быть soft-deleted потом — КП должен рендериться
 *     даже если catalog_item ушёл из каталога.
 *
 * Расчёт final_unit_price (см. QuotationService::computeFinalUnitPrice):
 *   final = MAX(catalog_unit_price × (1 - effective_discount/100),
 *               catalog_price_min)
 *
 * Защита от продажи ниже минимальной цены каталога: даже если менеджер
 * поставил большую скидку, цена не опускается ниже catalog_price_min.
 *
 * effective_discount: per-item `discount_percent` IF NOT NULL,
 * иначе берётся `quotations.discount_percent` (общая по КП).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('quotation_items')) {
            Schema::create('quotation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
                $table->unsignedSmallInteger('position');

                // Откуда позиция пришла (для backref в UI). Nullable: позицию
                // можно добавить руками без привязки к RequestItem.
                $table->foreignId('request_item_id')->nullable()
                    ->constrained('request_items')->nullOnDelete();
                // Привязка к каталогу. Nullable: на MVP скипаем несматченные
                // (см. /MEMORY/«пункт 1 решения»), но поле остаётся nullable
                // на случай ручного добавления позиции без каталога.
                $table->foreignId('catalog_item_id')->nullable()
                    ->constrained('catalog_items')->nullOnDelete();

                // SNAPSHOT каталог-данных на момент создания/refresh позиции.
                // Меняются только через явный `QuotationService::refreshPrices()`
                // в draft-режиме. Для sent/accepted версий — immutable.
                $table->decimal('catalog_unit_price', 14, 2)->default(0);
                $table->decimal('catalog_price_min', 14, 2)->nullable();
                $table->unsignedSmallInteger('catalog_lead_time_days')->nullable();
                $table->boolean('catalog_in_stock')->default(true);

                $table->text('snapshot_name'); // полное наименование для PDF
                $table->string('snapshot_sku', 64)->nullable();
                $table->string('snapshot_brand', 100)->nullable();
                $table->string('snapshot_brand_article', 100)->nullable();
                $table->text('snapshot_photo_url')->nullable();

                // Количество в КП (default = RequestItem.parsed_qty).
                $table->decimal('qty', 12, 3);
                $table->string('unit', 16)->default('шт');

                // Per-item override общей скидки. NULL = используем quotations.discount_percent.
                $table->decimal('discount_percent', 5, 2)->nullable();

                // Computed (заполняет QuotationService::recalcTotals):
                //   final_unit_price = MAX(catalog_unit_price × (1 - effective_discount/100), catalog_price_min)
                //   line_total = final_unit_price × qty
                //   vat_amount  = line_total - (line_total / (1 + vat_rate/100))   // НДС в т.ч.
                $table->decimal('final_unit_price', 14, 2)->default(0);
                $table->decimal('line_total', 14, 2)->default(0);
                $table->decimal('vat_amount', 14, 2)->default(0);

                // Срок поставки для PDF: «Под заказ N нед» (auto из catalog_lead_time_days/7)
                // или ручной текст («1-2 недели», «со склада»).
                $table->string('delivery_text', 64)->nullable();

                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['quotation_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};
