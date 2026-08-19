<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pg_trgm GIN-индексы для быстрого ILIKE '%...%' в поиске раздела «Почта»
 * (from_email / from_name / subject). Без них leading-wildcard ILIKE = seq-scan.
 * Тело писем (body_*) НЕ индексируем — оно ищется опционально (см. Mail\Index).
 *
 * CONCURRENTLY + без транзакции — чтобы не блокировать запись синка почты.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_from_email_trgm ON email_messages USING gin (from_email gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_from_name_trgm ON email_messages USING gin (from_name gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_subject_trgm ON email_messages USING gin (subject gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_from_email_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_from_name_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_subject_trgm');
    }
};
