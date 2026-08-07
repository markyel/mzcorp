<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Индекс на email_messages.supplier_inquiry_id — колонка добавлялась без
 * индекса, из-за чего withCount/whereHas по переписке инквайри (панель «Мои
 * запросы поставщикам») сканировали всю таблицу (~9с на странице «Снабжение»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            if (Schema::hasColumn('email_messages', 'supplier_inquiry_id')
                && ! $this->indexExists('email_messages', 'email_messages_supplier_inquiry_id_index')) {
                $table->index('supplier_inquiry_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            if ($this->indexExists('email_messages', 'email_messages_supplier_inquiry_id_index')) {
                $table->dropIndex(['supplier_inquiry_id']);
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('pg_indexes')->where('tablename', $table)->where('indexname', $index)->exists();
    }
};
