<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Для вопиющих писем — как стоило ответить: готовое письмо тем же фактам,
 * а не общий совет. Нужно для разбора с менеджером (заказчик, 29.09).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outbound_tone_reviews', 'better_reply')) {
            Schema::table('outbound_tone_reviews', function (Blueprint $table) {
                $table->text('better_reply')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('outbound_tone_reviews', 'better_reply')) {
            Schema::table('outbound_tone_reviews', function (Blueprint $table) {
                $table->dropColumn('better_reply');
            });
        }
    }
};
