<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пользовательские папки почтового клиента (раздел «Почта»), с вложенностью.
 * Папка принадлежит ящику (общий ящик — папки общие для всех, кто его видит).
 * Письма раскладываются по папкам в mzCorp (email_messages.mailbox_folder_id);
 * на IMAP-сервере письмо остаётся в INBOX — Yandex не выполняет EXPUNGE после
 * UID MOVE (см. MailFolderRouter), поэтому физический перенос оставляет дубли.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mailbox_folders')) {
            return;
        }

        Schema::create('mailbox_folders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('mailbox_folders')->nullOnDelete();
            $t->string('name', 80);
            $t->unsignedSmallInteger('position')->default(0);
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['mailbox_id', 'parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_folders');
    }
};
