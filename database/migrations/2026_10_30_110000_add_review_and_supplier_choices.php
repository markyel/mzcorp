<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Отклонение от запрошенного (аналог, копия, восстановленное, замена) разбирает снабжение:
        // pending → accepted (принято к закупке по этой позиции) | rejected. Оригинал — null.
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'review_status')) {
                $table->string('review_status', 16)->nullable()->index();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'reviewed_by_user_id')) {
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
        });

        // У кого закупаем позицию. Выбор остаётся при новых ценах, пока его не поменяют.
        // supplier_key — как в QuoteParts: org:{id} / sup:{id}; поставщик без карточки — email:{адрес}.
        if (! Schema::hasTable('catalog_item_supplier_choices')) {
            Schema::create('catalog_item_supplier_choices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catalog_item_id')->unique()->constrained('catalog_items')->cascadeOnDelete();
                $table->string('supplier_key', 191);
                $table->string('supplier_name')->nullable();
                $table->string('source', 16)->nullable();
                $table->unsignedBigInteger('offer_ref')->nullable();
                $table->decimal('price', 14, 4)->nullable();
                $table->string('currency', 8)->nullable();
                $table->foreignId('chosen_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('chosen_at')->nullable();
                $table->timestamps();

                $table->index('supplier_key');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_item_supplier_choices');
        foreach (['reviewed_at', 'review_status'] as $column) {
            if (Schema::hasColumn('quoteparts_offers', $column)) {
                Schema::table('quoteparts_offers', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        if (Schema::hasColumn('quoteparts_offers', 'reviewed_by_user_id')) {
            Schema::table('quoteparts_offers', fn (Blueprint $table) => $table->dropConstrainedForeignId('reviewed_by_user_id'));
        }
    }
};
