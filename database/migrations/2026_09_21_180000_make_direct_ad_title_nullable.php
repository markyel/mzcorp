<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заголовок больше не обязателен.
 *
 * Поля объявления проверяются по отдельности, и отклонить модель может именно
 * заголовок, приняв при этом второй заголовок и текст. Тогда в записи лежат
 * только годные поля, а на месте заголовка работает правило — но NOT NULL из
 * первой миграции ронял такую запись с ошибкой 23502.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('direct_ad_texts')) {
            return;
        }

        Schema::table('direct_ad_texts', function (Blueprint $t) {
            $t->string('title', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Обратно в NOT NULL не возвращаем: записи без заголовка законны,
        // откат сломал бы их. Колонка остаётся nullable.
    }
};
