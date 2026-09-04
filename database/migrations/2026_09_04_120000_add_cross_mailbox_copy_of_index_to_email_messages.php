<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Частичный индекс по выражению `(detected_artifacts->>'cross_mailbox_copy_of')::bigint`.
 *
 * Раздел «Почта» (Livewire\Mail\Client) прячет кросс-ящиковую копию письма
 * только если её оригинал ИЛИ более ранняя копия того же оригинала уже есть в
 * выборке ящиков. Второе условие — self-join по маркеру копии; без индекса это
 * seq-scan ~10k строк с маркером на КАЖДУЮ строку списка: замер на проде —
 * 2 040 мс на 41 строку против 170 мс с одним PK-условием. С индексом —
 * точечный lookup.
 *
 * Partial-предикат `~ '^[0-9]+$'` повторяется дословно в запросе клиента:
 * (а) планировщик видит совпадение и использует индекс, (б) каст ::bigint
 * никогда не упадёт на ненумерическом значении (сейчас таких 0 из 9 934,
 * маркер пишется только как `$message->id`, но защита бесплатная).
 *
 * CONCURRENTLY + без транзакции — чтобы не блокировать запись синка почты.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            "CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_cross_mailbox_copy_of_idx
             ON email_messages (((detected_artifacts->>'cross_mailbox_copy_of')::bigint))
             WHERE (detected_artifacts->>'cross_mailbox_copy_of') ~ '^[0-9]+$'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_cross_mailbox_copy_of_idx');
    }
};
