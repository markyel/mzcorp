<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Список писем ящика по дате — индексом, а не сортировкой.
 *
 * Mail\Client показывает 40 последних писем папки: ORDER BY sent_at DESC
 * NULLS LAST, id DESC LIMIT 41. Пока в ящике было несколько сотен писем,
 * сортировка была бесплатной; после зеркала истории у менеджера 120 тыс.
 * писем, и каждый показ списка сортировал 40 тыс. строк через диск (0,45 с).
 * С индексом в том же порядке читаются первые 41 подходящие строки.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_mailbox_sent_at_idx
            ON email_messages (mailbox_id, sent_at DESC NULLS LAST, id DESC)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_mailbox_sent_at_idx');
    }
};
