<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «Закупаем здесь» и журнал решений для новой позиции без карточки каталога (T-… с M-артикулом):
        // выбор — по коду T-…, решение — по M-артикулу; после привязки к карточке — catalog_item_id.
        if (Schema::hasTable('catalog_item_supplier_choices')) {
            Schema::table('catalog_item_supplier_choices', function (Blueprint $table) {
                $table->unsignedBigInteger('catalog_item_id')->nullable()->change();
                if (! Schema::hasColumn('catalog_item_supplier_choices', 'draft_code')) {
                    $table->string('draft_code', 32)->nullable()->unique();
                }
            });
        }
        if (Schema::hasTable('price_purchase_decisions')) {
            Schema::table('price_purchase_decisions', function (Blueprint $table) {
                $table->unsignedBigInteger('catalog_item_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Строки без карточки каталога NOT NULL не переживут — обратно только колонку кода.
        if (Schema::hasTable('catalog_item_supplier_choices') && Schema::hasColumn('catalog_item_supplier_choices', 'draft_code')) {
            Schema::table('catalog_item_supplier_choices', function (Blueprint $table) {
                $table->dropUnique(['draft_code']);
                $table->dropColumn('draft_code');
            });
        }
    }
};
