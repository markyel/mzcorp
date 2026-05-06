<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UID-state на каждую (mailbox × folder) пару.
 *
 * Foundation §1 «Идемпотентность и устойчивость»:
 *   «сохранить state: last_uid_seen, uid_validity per mailbox per folder»
 *
 * Папки идентифицируем по IMAP special-use flags (\Inbox, \Sent), а не по
 * именам ("Входящие", "Отправленные") — Yandex использует русские имена.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mailbox_folder_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();

            $table->string('folder', 32)
                ->comment('IMAP special-use flag: \Inbox, \Sent (без обратного слэша в БД: "Inbox", "Sent")');

            $table->unsignedBigInteger('uid_validity')
                ->nullable()
                ->comment('UIDVALIDITY — при изменении делаем full resync');

            $table->unsignedBigInteger('last_uid_seen')
                ->default(0)
                ->comment('Максимальный UID, который мы уже распарсили');

            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('sync_count')->default(0)->comment('Сколько раз успешно синкали');

            $table->timestamps();

            $table->unique(['mailbox_id', 'folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_folder_states');
    }
};
