<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reply_state — интент ПОСЛЕДНЕГО ответа поставщика, когда оффера нет:
 *   question_to_us    — поставщик задал НАМ вопрос (нужен наш ответ, мяч у нас);
 *   awaiting_supplier — принял/уточняет/ещё считает (ждём поставщика);
 *   null              — не классифицировано / есть оффер.
 * Ставит SupplierOfferParser из reply_intent LLM. Используется, чтобы в
 * «застрявших» разделить «ответьте поставщику» и «поставщик молчит».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_inquiries', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_inquiries', 'reply_state')) {
                $table->string('reply_state', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_inquiries', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_inquiries', 'reply_state')) {
                $table->dropColumn('reply_state');
            }
        });
    }
};
