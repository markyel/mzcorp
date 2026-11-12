<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Менеджер «берёт чат бота на себя» (12.11.2026): пока он ведёт разговор, бот молчит; через
 * BotLiveChatService::IDLE_MINUTES тишины или по кнопке чат возвращается боту.
 * taken_by_user_id — кто ведёт, taken_at — с какого момента, taken_activity_at — последнее сообщение
 * любой из сторон (от него считается тишина).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messenger_chats', function (Blueprint $table) {
            if (! Schema::hasColumn('messenger_chats', 'taken_by_user_id')) {
                $table->foreignId('taken_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('messenger_chats', 'taken_at')) {
                $table->timestamp('taken_at')->nullable();
            }
            if (! Schema::hasColumn('messenger_chats', 'taken_activity_at')) {
                $table->timestamp('taken_activity_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('messenger_chats', function (Blueprint $table) {
            if (Schema::hasColumn('messenger_chats', 'taken_by_user_id')) {
                $table->dropConstrainedForeignId('taken_by_user_id');
            }
            foreach (['taken_at', 'taken_activity_at'] as $c) {
                if (Schema::hasColumn('messenger_chats', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
