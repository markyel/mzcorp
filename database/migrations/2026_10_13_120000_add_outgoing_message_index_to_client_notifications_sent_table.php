<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс по outgoing_email_message_id: список писем проверяет, не автоуведомление
 * ли наш ответ (стрелка «отвечено» в почте не ставится за автоответы).
 */
return new class extends Migration
{
    private const INDEX = 'client_notifications_sent_outgoing_email_message_id_index';

    public function up(): void
    {
        if (! Schema::hasTable('client_notifications_sent') || Schema::hasIndex('client_notifications_sent', self::INDEX)) {
            return;
        }

        Schema::table('client_notifications_sent', function (Blueprint $t) {
            $t->index('outgoing_email_message_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('client_notifications_sent') && Schema::hasIndex('client_notifications_sent', self::INDEX)) {
            Schema::table('client_notifications_sent', function (Blueprint $t) {
                $t->dropIndex(self::INDEX);
            });
        }
    }
};
