<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * trgm GIN-индекс на email_attachments.filename — поиск «Почты» ищет письма по
 * имени вложения. Раньше это был коррелированный EXISTS в OR → COUNT paginate'а
 * шёл 3с. С индексом + переписью на `id IN (подзапрос)` — ~1.4с.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS email_attachments_filename_trgm ON email_attachments USING gin (filename gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_attachments_filename_trgm');
    }
};
