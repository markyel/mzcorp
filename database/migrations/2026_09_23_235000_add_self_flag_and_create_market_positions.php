<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Своя карточка среди конкурентов и сводка по рынку.
 *
 * Чтобы видеть позицию, себя надо считать по той же линейке: тот же рейтинг,
 * те же отзывы, те же слова клиентов. Поэтому мы — такая же карточка, только
 * с флагом `is_self`: её не разбирают на «чем мы лучше», она служит точкой
 * отсчёта.
 *
 * Сводка хранится версиями: позиция меняется, и через квартал важно знать,
 * из каких цифр и отзывов был сделан прошлый вывод.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('competitors') && ! Schema::hasColumn('competitors', 'is_self')) {
            Schema::table('competitors', function (Blueprint $table) {
                $table->boolean('is_self')->default(false)->index();
            });
        }

        if (! Schema::hasTable('market_positions')) {
            Schema::create('market_positions', function (Blueprint $table) {
                $table->id();
                // Связный текст сводки — его читает человек.
                $table->text('body');
                // Из чего собрана: карточки, отзывы, профиль на момент сборки.
                $table->text('snapshot')->nullable();
                $table->unsignedInteger('competitors_count')->default(0);
                $table->unsignedInteger('reviews_count')->default(0);
                $table->string('model', 64)->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('market_positions');

        if (Schema::hasTable('competitors') && Schema::hasColumn('competitors', 'is_self')) {
            Schema::table('competitors', function (Blueprint $table) {
                $table->dropColumn('is_self');
            });
        }
    }
};
