<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Вопиющее» — узкий отбор среди замечаний: хамство, панибратство,
 * высокомерие, отсылка к конкурентам. Сухие отказы («не предложим», «можем
 * только такой») — вопрос формулировки, их разбирают иначе (заказчик, 29.09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_tone_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('outbound_tone_reviews', 'egregious')) {
                $table->boolean('egregious')->nullable()->index();
            }
            if (! Schema::hasColumn('outbound_tone_reviews', 'egregious_reason')) {
                $table->text('egregious_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('outbound_tone_reviews', function (Blueprint $table) {
            foreach (['egregious', 'egregious_reason'] as $col) {
                if (Schema::hasColumn('outbound_tone_reviews', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
