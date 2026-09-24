<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Чему именно посвящён материал внутри темы.
 *
 * Регулярная тема вроде «Советы по оформлению заявок» — это не один пост, а
 * серия: каждую публикацию берём новую категорию товара. Чтобы не повторяться
 * и не гадать по тексту, чему был посвящён прошлый выпуск, храним ключ рядом с
 * публикацией.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_publications') && ! Schema::hasColumn('media_publications', 'subject_key')) {
            Schema::table('media_publications', function (Blueprint $table) {
                $table->string('subject_key', 120)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media_publications') && Schema::hasColumn('media_publications', 'subject_key')) {
            Schema::table('media_publications', function (Blueprint $table) {
                $table->dropColumn('subject_key');
            });
        }
    }
};
