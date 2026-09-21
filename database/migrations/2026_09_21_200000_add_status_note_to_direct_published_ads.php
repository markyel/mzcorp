<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пояснение модерации к объявлению (StatusClarification).
 *
 * Отказ без причины бесполезен: «Отклонено» не говорит, править заголовок,
 * текст или ссылку. Директ причину отдаёт — храним её рядом со статусом, иначе
 * придётся за каждым отказом ходить в кабинет.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('direct_published_ads') || Schema::hasColumn('direct_published_ads', 'status_note')) {
            return;
        }

        Schema::table('direct_published_ads', function (Blueprint $t) {
            $t->string('status_note', 1000)->nullable();
            $t->timestamp('moderated_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('direct_published_ads')) {
            return;
        }

        Schema::table('direct_published_ads', function (Blueprint $t) {
            foreach (['status_note', 'moderated_at'] as $column) {
                if (Schema::hasColumn('direct_published_ads', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
