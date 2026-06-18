<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Флаги позиции для цикла обновления цен (Фаза 3.5):
 *   - price_refresh_watched  — позиция отслеживается (была сматчена + цена
 *                              неактуальна на момент отправки RFQ). По набору
 *                              watched-позиций реконсилер решает, обновились ли
 *                              цены по всей заявке.
 *   - possibly_discontinued  — «Возможно более не поставляется»: все ответы
 *                              поставщиков по позиции = отказ. Ставит реконсилер,
 *                              окончательное решение за менеджером (может снять).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('request_items', 'price_refresh_watched')) {
                $table->boolean('price_refresh_watched')->default(false)->index();
            }
            if (! Schema::hasColumn('request_items', 'possibly_discontinued')) {
                $table->boolean('possibly_discontinued')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            foreach (['price_refresh_watched', 'possibly_discontinued'] as $col) {
                if (Schema::hasColumn('request_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
