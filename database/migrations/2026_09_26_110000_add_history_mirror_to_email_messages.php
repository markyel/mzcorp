<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Зеркало истории личных ящиков менеджеров.
 *
 * До сих пор синк забирал только письма, пришедшие после подключения ящика, а
 * в пользовательских папках Яндекса лишь переселял уже известные: у Агрызкова
 * во «Входящих (локально)» на сервере 101 тыс. писем, у нас — 86 в списке.
 * Заказчик (2026-09-26): «полная синхронизация ящиков, в т.ч. папок».
 *
 * История заводится шапками (отправитель, получатели, тема, дата, флаги) —
 * тело и вложения подтягиваются с сервера при открытии письма: полный импорт
 * миллиона писем занял бы ~150 ГБ базы и ~300 ГБ файлов.
 *
 *   is_history              — письмо из истории ящика: мимо конвейера, заявок,
 *                             детекторов и отчётов (глобальный scope модели);
 *   body_fetched_at         — когда тело и вложения скачаны (для истории);
 *   history_has_attachments — по Content-Type шапки: скрепка в списке до скачивания.
 *
 * mailbox_folder_states:
 *   history_low_uid         — ниже этого UID история папки ещё не пройдена
 *                             (идём от свежих к старым; NULL — не начинали);
 *   history_completed_at    — вся история папки заведена;
 *   history_imported        — сколько писем заведено.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_messages') && ! Schema::hasColumn('email_messages', 'is_history')) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->boolean('is_history')->default(false);
                $table->timestamp('body_fetched_at')->nullable();
                $table->boolean('history_has_attachments')->nullable();
            });
        }

        // Поиск уже известного письма по Message-ID (без учёта регистра) на
        // каждую пачку шапок; без индекса это перебор всех писем ящика.
        // Сборка треда (SharedMailService::threadFor) ищет по message_id,
        // in_reply_to и вхождению в references_header по всей таблице — сейчас
        // это перебор 117 тыс. строк, с историей стало бы 1,2 млн на каждое
        // открытие письма.
        if (Schema::hasTable('email_messages')) {
            DB::statement('CREATE INDEX IF NOT EXISTS email_messages_mailbox_lower_mid_idx ON email_messages (mailbox_id, lower(message_id))');
            DB::statement('CREATE INDEX IF NOT EXISTS email_messages_message_id_idx ON email_messages (message_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS email_messages_in_reply_to_idx ON email_messages (in_reply_to)');
            DB::statement('CREATE INDEX IF NOT EXISTS email_messages_references_gin ON email_messages USING gin (references_header jsonb_path_ops)');
        }

        if (Schema::hasTable('mailbox_folder_states') && ! Schema::hasColumn('mailbox_folder_states', 'history_low_uid')) {
            Schema::table('mailbox_folder_states', function (Blueprint $table) {
                $table->unsignedBigInteger('history_low_uid')->nullable();
                $table->timestamp('history_completed_at')->nullable();
                $table->unsignedInteger('history_imported')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['email_messages_mailbox_lower_mid_idx', 'email_messages_message_id_idx', 'email_messages_in_reply_to_idx', 'email_messages_references_gin'] as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }
        if (Schema::hasTable('email_messages') && Schema::hasColumn('email_messages', 'is_history')) {
            Schema::table('email_messages', function (Blueprint $table) {
                $table->dropColumn(['is_history', 'body_fetched_at', 'history_has_attachments']);
            });
        }
        if (Schema::hasTable('mailbox_folder_states') && Schema::hasColumn('mailbox_folder_states', 'history_low_uid')) {
            Schema::table('mailbox_folder_states', function (Blueprint $table) {
                $table->dropColumn(['history_low_uid', 'history_completed_at', 'history_imported']);
            });
        }
    }
};
