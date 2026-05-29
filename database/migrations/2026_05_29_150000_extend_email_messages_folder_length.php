<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `email_messages.folder` была varchar(32) с комментарием «Inbox | Sent» —
 * предполагались короткие special-use метки. Но persist пишет туда реальный
 * IMAP-путь папки, который для Yandex 360 приходит в MUTF-7 (RFC 3501):
 * кириллическая «Отправленные» → `&BB4EQgQ,BEAEMAQyBDsENQQ9BD0ESwQ1-`
 * (38 символов) — уже за пределами 32.
 *
 * Симптом 2026-05-29: `Failed to persist message` с
 * `SQLSTATE[22001]: value too long for type character varying(32)` на
 * outbound-письмах из папки «Отправленные» → письмо не сохраняется вовсе,
 * детектор КП/счетов теряет часть исходящих.
 *
 * Зеркало 2026_05_28_140000 (то же для mailbox_folder_states). Расширяем до
 * 255 — стандартный varchar Laravel, перекрывает любые реальные IMAP-пути.
 * ALTER TYPE по длине varchar Postgres делает без пересоздания индексов;
 * partial-unique `email_messages_unique_per_folder_not_draft` не страдает.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('email_messages', 'folder')) {
            return;
        }
        DB::statement('ALTER TABLE email_messages ALTER COLUMN folder TYPE VARCHAR(255)');
    }

    public function down(): void
    {
        // Не сужаем обратно — могут быть существующие пути длиннее 32.
    }
};
