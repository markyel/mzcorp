<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Частичное КП», этап 3: когда у позиции без КП появилась цена (каталог 1С или ответ поставщика) — менеджеру
 * напоминание «можно дослать КП» (quotes:partial-price-ready). Отметка — чтобы напомнить по позиции один раз.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('request_items', 'quote_price_ready_at')) {
            Schema::table('request_items', function (Blueprint $table) {
                $table->timestamp('quote_price_ready_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('request_items', 'quote_price_ready_at')) {
            Schema::table('request_items', function (Blueprint $table) {
                $table->dropColumn('quote_price_ready_at');
            });
        }
    }
};
