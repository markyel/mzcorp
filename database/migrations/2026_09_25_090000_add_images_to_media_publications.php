<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Картинки публикации.
 *
 * Ассортиментные посты без фотографий читаются как прайс-лист: «поручень
 * резиновый Schindler» ничего не говорит человеку, который ищет деталь
 * глазами. Фото у нас есть в каталоге (photo_url), и для тем на каталожных
 * данных подбираются автоматически.
 *
 * Храним ссылки, а не файлы: картинка живёт на нашем же сайте, а площадки
 * (ВК, Telegram) забирают её при публикации.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_publications') && ! Schema::hasColumn('media_publications', 'image_urls')) {
            Schema::table('media_publications', function (Blueprint $table) {
                $table->jsonb('image_urls')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media_publications') && Schema::hasColumn('media_publications', 'image_urls')) {
            Schema::table('media_publications', function (Blueprint $table) {
                $table->dropColumn('image_urls');
            });
        }
    }
};
