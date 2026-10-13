<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Прочитано ли менеджером сообщение клиента из Telegram/MAX и ушло ли о нём
 * письмо-сводка (bot:chat-digest). Уже пришедшие сообщения считаем
 * прочитанными — иначе первая сводка принесла бы всю историю.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messenger_messages') || Schema::hasColumn('messenger_messages', 'read_at')) {
            return;
        }

        Schema::table('messenger_messages', function (Blueprint $t) {
            $t->timestamp('read_at')->nullable()->after('meta');
            $t->timestamp('emailed_at')->nullable()->after('read_at');
            $t->index(['direction', 'read_at']);
        });

        DB::table('messenger_messages')->where('direction', 'in')->update(['read_at' => DB::raw('created_at'), 'emailed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (Schema::hasTable('messenger_messages') && Schema::hasColumn('messenger_messages', 'read_at')) {
            Schema::table('messenger_messages', function (Blueprint $t) {
                $t->dropIndex(['direction', 'read_at']);
                $t->dropColumn(['read_at', 'emailed_at']);
            });
        }
    }
};
