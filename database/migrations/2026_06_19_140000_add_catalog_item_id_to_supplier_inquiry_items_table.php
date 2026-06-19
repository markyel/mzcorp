<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позиция запроса поставщику может ссылаться на КАТАЛОЖНУЮ позицию (M-артикул),
 * а не только на request_item (Фаза 4B — снабжение). Для позиция-центричных RFQ
 * из раздела «Снабжение»: related_request_id у инквайри = null, items привязаны
 * к catalog_item. request_item_id остаётся nullable (для request-центричных RFQ
 * из карточки заявки — Фаза 3.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('supplier_inquiry_items', 'catalog_item_id')) {
            Schema::table('supplier_inquiry_items', function (Blueprint $table) {
                $table->foreignId('catalog_item_id')->nullable()->after('request_item_id')
                    ->constrained('catalog_items')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('supplier_inquiry_items', 'catalog_item_id')) {
            Schema::table('supplier_inquiry_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('catalog_item_id');
            });
        }
    }
};
