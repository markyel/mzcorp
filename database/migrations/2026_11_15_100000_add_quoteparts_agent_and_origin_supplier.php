<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Агенты QuoteParts (26.10.2026): UniSystem не поставщик, а агент, который ищет поставщиков для Моего Зипа.
 * suppliers.quoteparts_agent — копия признака связи в QuoteParts (у всех адресов организации);
 * quoteparts_offers.origin_supplier — реальный поставщик за ценой агента (Sicor, Donati…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'quoteparts_agent')) {
                $table->boolean('quoteparts_agent')->default(false);
            }
        });

        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'origin_supplier')) {
                $table->string('origin_supplier', 150)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (Schema::hasColumn('quoteparts_offers', 'origin_supplier')) {
                $table->dropColumn('origin_supplier');
            }
        });

        Schema::table('suppliers', function (Blueprint $table) {
            if (Schema::hasColumn('suppliers', 'quoteparts_agent')) {
                $table->dropColumn('quoteparts_agent');
            }
        });
    }
};
