<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trgm GIN-индексы на (to_recipients::text) / (cc_recipients::text) — поиск
 * «Почты» ищет по получателям через jsonb→text ILIKE; без индекса эта ветка OR
 * тянула весь запрос в seq-scan (≈1.3с даже при индексе на body_plain). С ними
 * реальные слова ищутся ~0.03–0.16с. CONCURRENTLY — без блокировки.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_to_recip_trgm ON email_messages USING gin ((to_recipients::text) gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_cc_recip_trgm ON email_messages USING gin ((cc_recipients::text) gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_to_recip_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_cc_recip_trgm');
    }
};
