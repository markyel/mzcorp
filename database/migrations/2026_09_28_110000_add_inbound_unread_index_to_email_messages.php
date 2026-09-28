<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Узкий индекс по входящим — для бейджей непрочитанного в почте.
 *
 * После зеркала истории ящиков входящих стало ~715 тыс., и подсчёт
 * непрочитанного по ящикам (Mail\Client::unreadByMailbox) читал всю таблицу
 * вместе с телами писем: 0,8 с на каждом автообновлении у каждого, кто
 * держит почту открытой. Индекс несёт ровно поля, по которым фильтруют
 * бейджи, — подсчёт идёт по нему, не заходя в таблицу.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_inbound_badges_idx
            ON email_messages (mailbox_id, id)
            INCLUDE (folder, imap_uid, mailbox_folder_id, related_request_id)
            WHERE direction = 'inbound' AND is_draft = false
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_inbound_badges_idx');
    }
};
