<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Серверная синхронизация пользовательских папок (личные ящики с владельцем):
 * imap_path — путь папки на IMAP-сервере (raw, как в LIST: MUTF-7, разделитель
 * сервера), imap_synced_at — когда папка последний раз подтверждена сервером.
 * NULL imap_path — папка живёт только в mzCorp (общие ящики, либо ещё не
 * создана на сервере).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_folders', function (Blueprint $table) {
            if (! Schema::hasColumn('mailbox_folders', 'imap_path')) {
                $table->string('imap_path', 500)->nullable()->after('name');
            }
            if (! Schema::hasColumn('mailbox_folders', 'imap_synced_at')) {
                $table->timestamp('imap_synced_at')->nullable()->after('imap_path');
            }
        });
        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->unique(['mailbox_id', 'imap_path'], 'mailbox_folders_mailbox_imap_path_unique');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->dropUnique('mailbox_folders_mailbox_imap_path_unique');
            if (Schema::hasColumn('mailbox_folders', 'imap_synced_at')) {
                $table->dropColumn('imap_synced_at');
            }
            if (Schema::hasColumn('mailbox_folders', 'imap_path')) {
                $table->dropColumn('imap_path');
            }
        });
    }
};
