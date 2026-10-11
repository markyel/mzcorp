<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Очередь повторной отправки черновика, когда SMTP недоступен (сбой
 * smtp.yandex.ru 2026-10-11: письма молча оставались черновиками).
 *
 *   send_status          — NULL: обычный черновик; queued: ждёт повтора;
 *                          failed: повторы исчерпаны / постоянная ошибка.
 *   send_attempts        — сколько раз пытались отправить.
 *   send_next_attempt_at — когда следующая попытка (только для queued).
 *   send_last_error      — текст последней ошибки SMTP.
 *
 * Колонки только на черновиках; отбор очереди идёт по индексу is_draft.
 */
return new class extends Migration
{
    private const COLUMNS = ['send_status', 'send_attempts', 'send_next_attempt_at', 'send_last_error'];

    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('email_messages', 'send_status')) {
                $table->string('send_status', 20)->nullable();
            }
            if (! Schema::hasColumn('email_messages', 'send_attempts')) {
                $table->unsignedSmallInteger('send_attempts')->nullable();
            }
            if (! Schema::hasColumn('email_messages', 'send_next_attempt_at')) {
                $table->timestamp('send_next_attempt_at')->nullable();
            }
            if (! Schema::hasColumn('email_messages', 'send_last_error')) {
                $table->text('send_last_error')->nullable();
            }
        });
    }

    public function down(): void
    {
        $existing = array_values(array_filter(
            self::COLUMNS,
            fn (string $c) => Schema::hasColumn('email_messages', $c),
        ));
        if ($existing !== []) {
            Schema::table('email_messages', function (Blueprint $table) use ($existing) {
                $table->dropColumn($existing);
            });
        }
    }
};
