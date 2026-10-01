<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Структура статьи для страницы /news и RSS-ленты: разделы, позиции с фото,
 * ценами и ссылками. Текст поста в body остаётся — по нему работает редактор
 * в разделе «Медиаплан», а страницу и ленту собираем из этой структуры.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('media_publications', 'article')) {
            Schema::table('media_publications', function (Blueprint $table) {
                $table->jsonb('article')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('media_publications', 'article')) {
            Schema::table('media_publications', function (Blueprint $table) {
                $table->dropColumn('article');
            });
        }
    }
};
