<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trgm GIN-индекс на email_messages.body_plain — быстрый ILIKE '%...%' по
 * содержимому письма в поиске раздела «Почта». body_plain заполняется для ВСЕХ
 * писем (для html-only — html→text, см. MessagePersister + backfill), поэтому
 * покрывает весь контент; body_html (906МБ) отдельно не индексируем/ищем.
 *
 * ~101МБ индекс, строится ~50с на 79k строк. CONCURRENTLY — без блокировки синка.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS email_messages_body_plain_trgm ON email_messages USING gin (body_plain gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_messages_body_plain_trgm');
    }
};
