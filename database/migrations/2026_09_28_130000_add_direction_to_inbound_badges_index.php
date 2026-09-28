<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Бейджевый индекс входящих — с direction в INCLUDE.
 *
 * Фильтр «письмо ещё есть на сервере» (Mail\Client::hideGoneFromServer)
 * написан через OR с direction, и без этого поля в индексе PostgreSQL не мог
 * посчитать бейдж по одному индексу — шёл в таблицу (0,4 с на ящик в 120 тыс.
 * писем). С direction в INCLUDE тот же подсчёт — index-only, ~0,1 с.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_inbound_badges2_idx
            ON email_messages (mailbox_id, id)
            INCLUDE (folder, imap_uid, mailbox_folder_id, related_request_id, direction)
            WHERE direction = 'inbound' AND is_draft = false
        SQL);
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_inbound_badges_idx');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_inbound_badges_idx
            ON email_messages (mailbox_id, id)
            INCLUDE (folder, imap_uid, mailbox_folder_id, related_request_id)
            WHERE direction = 'inbound' AND is_draft = false
        SQL);
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_inbound_badges2_idx');
    }
};
