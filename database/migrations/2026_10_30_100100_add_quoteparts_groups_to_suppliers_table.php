<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Какие группы товаров (Китай / Россия / Европа) поставщик видит в кабинете QuoteParts;
        // null — все. Копия того, что передано в QuoteParts.
        if (! Schema::hasColumn('suppliers', 'quoteparts_groups')) {
            Schema::table('suppliers', fn (Blueprint $table) => $table->jsonb('quoteparts_groups')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('suppliers', 'quoteparts_groups')) {
            Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('quoteparts_groups'));
        }
    }
};
