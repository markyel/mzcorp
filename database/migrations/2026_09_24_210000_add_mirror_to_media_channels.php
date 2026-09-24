<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Канал-зеркало: площадка, которая забирает посты из другого нашего канала.
 *
 * Дзен так и устроен: своего API публикаций у него нет, зато канал привязывается
 * к телеграм-каналу, и посты приезжают туда сами. Значит, писать для Дзена
 * отдельный материал не нужно — нужно знать, что он повторяет Telegram, и
 * учитывать публикацию на обеих площадках.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_channels') && ! Schema::hasColumn('media_channels', 'mirror_of_channel_id')) {
            Schema::table('media_channels', function (Blueprint $table) {
                $table->foreignId('mirror_of_channel_id')->nullable()
                    ->constrained('media_channels')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media_channels') && Schema::hasColumn('media_channels', 'mirror_of_channel_id')) {
            Schema::table('media_channels', function (Blueprint $table) {
                $table->dropConstrainedForeignId('mirror_of_channel_id');
            });
        }
    }
};
