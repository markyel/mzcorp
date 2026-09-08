<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Письмо в пользовательской папке почтового клиента (NULL — во «Входящих» /
 * своей системной папке). При удалении папки письма возвращаются во входящие.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_messages') || Schema::hasColumn('email_messages', 'mailbox_folder_id')) {
            return;
        }

        Schema::table('email_messages', function (Blueprint $t) {
            $t->foreignId('mailbox_folder_id')->nullable()->after('folder')
                ->constrained('mailbox_folders')->nullOnDelete();
            $t->index(['mailbox_folder_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_messages') && Schema::hasColumn('email_messages', 'mailbox_folder_id')) {
            Schema::table('email_messages', function (Blueprint $t) {
                $t->dropConstrainedForeignId('mailbox_folder_id');
            });
        }
    }
};
