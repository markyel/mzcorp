<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Внесистемный поставщик (решение 2026-10-21): в QuoteParts его не приглашаем, но запросы цен идут
 * через QuoteParts — нашим же письмом с ящика orders@, ответы разбирает платформа. Отметка вручную.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('suppliers', 'quoteparts_offsystem')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->boolean('quoteparts_offsystem')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('suppliers', 'quoteparts_offsystem')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropColumn('quoteparts_offsystem');
            });
        }
    }
};
