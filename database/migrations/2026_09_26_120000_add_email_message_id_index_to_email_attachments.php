<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс по email_attachments.email_message_id. Внешний ключ есть, индекса нет:
 * счётчик вложений в строке списка почты (withCount('attachments')) перебирал
 * всю таблицу на каждое письмо — 41 × 13 мс = 550 мс из 614 на открытие папки.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_attachments')) {
            DB::statement('CREATE INDEX IF NOT EXISTS email_attachments_email_message_id_index ON email_attachments (email_message_id)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS email_attachments_email_message_id_index');
    }
};
