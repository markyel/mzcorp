<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `mailbox_folder_states.folder` была varchar(32) — комментарий миграции
 * предполагал короткие special-use flag'ы («Inbox», «Sent»). Но реальный код
 * (`SyncMailboxFolderJob::getOrCreateState`) пишет туда `$folder->path` —
 * полный IMAP-путь, который для Yandex 360 c MUTF-7 закодированными
 * русскими названиями («Тендеры и площадки» → ~55 chars) и под-папками
 * («Archives|2026» → varchar(13), а вот «Trash|&BBAEQARFBDgEMg-» → 22)
 * запросто бьёт лимит.
 *
 * Симптом 2026-05-22: 62 failed_jobs за 2ч с
 * `SQLSTATE[22001]: value too long for type character varying(32)`.
 *
 * Расширяем до 255 — стандартный varchar Laravel, перекрывает любые
 * реальные IMAP-пути.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('mailbox_folder_states', 'folder')) {
            return;
        }
        // ALTER TYPE напрямую — Postgres не требует пересоздания индекса,
        // unique-constraint (mailbox_id, folder) тоже не страдает.
        DB::statement('ALTER TABLE mailbox_folder_states ALTER COLUMN folder TYPE VARCHAR(255)');
    }

    public function down(): void
    {
        // Не сужаем обратно — могут быть существующие пути длиннее 32.
    }
};
