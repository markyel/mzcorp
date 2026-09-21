<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Храним не только заголовок, а всё объявление целиком: заголовок, второй
 * заголовок и текст.
 *
 * Причина та же, по которой заголовок вообще сохраняется: каждое изменение
 * текста отправляет объявление на повторную модерацию. Значит тексты надо
 * готовить и вычитывать ДО публикации, а не править у работающих объявлений —
 * поэтому таблица заполняется для всей очереди, а не только для тех позиций,
 * что сейчас в ротации.
 *
 * tone — каким тоном написано (см. DirectAdTone): сменили тон в настройках,
 * видно, какие объявления ещё написаны прежним.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('direct_ad_titles') && ! Schema::hasTable('direct_ad_texts')) {
            Schema::rename('direct_ad_titles', 'direct_ad_texts');
        }
        if (! Schema::hasTable('direct_ad_texts')) {
            return;
        }

        Schema::table('direct_ad_texts', function (Blueprint $t) {
            if (! Schema::hasColumn('direct_ad_texts', 'title2')) {
                $t->string('title2', 60)->nullable();
            }
            if (! Schema::hasColumn('direct_ad_texts', 'text')) {
                $t->string('text', 200)->nullable();
            }
            if (! Schema::hasColumn('direct_ad_texts', 'tone')) {
                $t->string('tone', 24)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('direct_ad_texts')) {
            return;
        }

        Schema::table('direct_ad_texts', function (Blueprint $t) {
            foreach (['title2', 'text', 'tone'] as $column) {
                if (Schema::hasColumn('direct_ad_texts', $column)) {
                    $t->dropColumn($column);
                }
            }
        });

        if (! Schema::hasTable('direct_ad_titles')) {
            Schema::rename('direct_ad_texts', 'direct_ad_titles');
        }
    }
};
