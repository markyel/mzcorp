<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Публикация в канал: доступы, режим и след публикации.
 *
 * Токен площадки лежит рядом с каналом и шифруется, как креды в разделе
 * «Доступы» (Crypt::encryptString над json). Автопубликация — отдельный
 * тумблер на канал: сгенерировать черновик безопасно всегда, а разместить
 * без человека — сознательное решение по каждому каналу.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_channels')) {
            Schema::table('media_channels', function (Blueprint $table) {
                if (! Schema::hasColumn('media_channels', 'encrypted_secrets')) {
                    // {"access_token": "...", "owner_id": "-123", "chat_id": "@channel"}
                    $table->text('encrypted_secrets')->nullable();
                }
                if (! Schema::hasColumn('media_channels', 'auto_publish')) {
                    $table->boolean('auto_publish')->default(false)->index();
                }
                if (! Schema::hasColumn('media_channels', 'last_posted_at')) {
                    $table->timestamp('last_posted_at')->nullable();
                }
                if (! Schema::hasColumn('media_channels', 'last_error')) {
                    $table->string('last_error', 500)->nullable();
                }
            });
        }

        if (Schema::hasTable('media_publications') && ! Schema::hasColumn('media_publications', 'external_id')) {
            Schema::table('media_publications', function (Blueprint $table) {
                // id записи на площадке (post_id ВК, message_id Telegram).
                $table->string('external_id', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media_publications') && Schema::hasColumn('media_publications', 'external_id')) {
            Schema::table('media_publications', function (Blueprint $table) {
                $table->dropColumn('external_id');
            });
        }

        if (Schema::hasTable('media_channels')) {
            Schema::table('media_channels', function (Blueprint $table) {
                foreach (['encrypted_secrets', 'auto_publish', 'last_posted_at', 'last_error'] as $col) {
                    if (Schema::hasColumn('media_channels', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
