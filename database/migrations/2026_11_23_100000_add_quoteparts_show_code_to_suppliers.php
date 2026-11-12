<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Показывать поставщику наш M-артикул (12.11.2026): копия признака связи в QuoteParts (show_internal_code) у всех
 * адресов организации. Системному код показывает QuoteParts (кабинет, письма платформы), внесистемному — письмо
 * mzCorp: M-артикул в начале названия позиции.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'quoteparts_show_code')) {
                $table->boolean('quoteparts_show_code')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (Schema::hasColumn('suppliers', 'quoteparts_show_code')) {
                $table->dropColumn('quoteparts_show_code');
            }
        });
    }
};
