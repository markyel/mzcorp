<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.9 UI-переписка: drafts в email_messages.
 *
 * Draft = флаг на той же таблице, не отдельная сущность. Тред в Detail.php
 * собирается одним запросом; переход draft → sent — UPDATE is_draft=false.
 * Attachments переиспользуют существующий EmailAttachment.
 *
 * Дополнительно:
 *   - imap_uid делаем NULLABLE (drafts и не-yet-appended Sent имеют NULL).
 *   - Существующий unique (mailbox_id, folder, message_id) меняем на
 *     partial — только для is_draft=false. Drafts могут иметь временные
 *     message_id; при send Sender перепишет на финальный и снимет флаг.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('email_messages', 'is_draft')) {
                $table->boolean('is_draft')->default(false)->index();
            }
            if (! Schema::hasColumn('email_messages', 'draft_author_user_id')) {
                $table->foreignId('draft_author_user_id')->nullable()
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('email_messages', 'last_edited_at')) {
                $table->timestamp('last_edited_at')->nullable();
            }
        });

        // imap_uid NULLABLE — webklex после APPEND не всегда отдаёт UID,
        // ждём догоняющий Sent-sync.
        DB::statement('ALTER TABLE email_messages ALTER COLUMN imap_uid DROP NOT NULL');

        // Заменить full unique на partial (только для is_draft=false).
        // Drop старого индекса безопасен — он на (mailbox_id, folder, message_id).
        $hasOldUnique = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'email_messages' AND indexname = 'email_messages_unique_per_folder'"))->isNotEmpty();
        if ($hasOldUnique) {
            DB::statement('DROP INDEX email_messages_unique_per_folder');
        }

        $hasPartial = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'email_messages' AND indexname = 'email_messages_unique_per_folder_not_draft'"))->isNotEmpty();
        if (! $hasPartial) {
            DB::statement('CREATE UNIQUE INDEX email_messages_unique_per_folder_not_draft
                ON email_messages (mailbox_id, folder, message_id) WHERE is_draft = false');
        }
    }

    public function down(): void
    {
        // Partial unique → full unique. Возможны коллизии, но в down() важнее
        // не уронить откат: дропаем partial, создаём full unique best-effort.
        $hasPartial = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'email_messages' AND indexname = 'email_messages_unique_per_folder_not_draft'"))->isNotEmpty();
        if ($hasPartial) {
            DB::statement('DROP INDEX email_messages_unique_per_folder_not_draft');
        }

        $hasOldUnique = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'email_messages' AND indexname = 'email_messages_unique_per_folder'"))->isNotEmpty();
        if (! $hasOldUnique) {
            DB::statement('CREATE UNIQUE INDEX email_messages_unique_per_folder
                ON email_messages (mailbox_id, folder, message_id)');
        }

        Schema::table('email_messages', function (Blueprint $table) {
            if (Schema::hasColumn('email_messages', 'draft_author_user_id')) {
                $table->dropForeign(['draft_author_user_id']);
                $table->dropColumn('draft_author_user_id');
            }
            if (Schema::hasColumn('email_messages', 'is_draft')) {
                $table->dropColumn('is_draft');
            }
            if (Schema::hasColumn('email_messages', 'last_edited_at')) {
                $table->dropColumn('last_edited_at');
            }
        });

        // imap_uid вернуть NOT NULL только если нет NULL'ов.
        $hasNulls = (int) DB::scalar('SELECT COUNT(*) FROM email_messages WHERE imap_uid IS NULL') > 0;
        if (! $hasNulls) {
            DB::statement('ALTER TABLE email_messages ALTER COLUMN imap_uid SET NOT NULL');
        }
    }
};
