<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Хэштеги темы — в конец поста в Telegram, ВКонтакте и MAX. Пусто — по типу темы (config).
        if (Schema::hasTable('media_topics') && ! Schema::hasColumn('media_topics', 'hashtags')) {
            Schema::table('media_topics', fn (Blueprint $table) => $table->string('hashtags', 500)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('media_topics', 'hashtags')) {
            Schema::table('media_topics', fn (Blueprint $table) => $table->dropColumn('hashtags'));
        }
    }
};
