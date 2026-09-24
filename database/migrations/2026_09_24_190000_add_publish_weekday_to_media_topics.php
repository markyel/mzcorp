<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * День недели публикации темы.
 *
 * Четыре еженедельные темы, заведённые в один день, дальше так и ходят одной
 * пачкой: раз в неделю лента получает четыре поста подряд и шесть дней тишины.
 * День недели закрепляем за темой — тогда после каждой публикации следующий
 * срок встаёт на «свой» день, а не просто на +7.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_topics') && ! Schema::hasColumn('media_topics', 'publish_weekday')) {
            Schema::table('media_topics', function (Blueprint $table) {
                // 1 — понедельник … 7 — воскресенье (ISO). NULL — день не важен.
                $table->unsignedTinyInteger('publish_weekday')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media_topics') && Schema::hasColumn('media_topics', 'publish_weekday')) {
            Schema::table('media_topics', function (Blueprint $table) {
                $table->dropColumn('publish_weekday');
            });
        }
    }
};
