<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('request_items', 'quoteparts_code')) {
            Schema::table('request_items', function (Blueprint $table) {
                // Временный код предварительной позиции в QuoteParts (T-XXXXXX) — новый товар без
                // M-артикула, отправленный поставщикам через платформу.
                $table->string('quoteparts_code', 64)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('request_items', 'quoteparts_code')) {
            Schema::table('request_items', function (Blueprint $table) {
                $table->dropIndex(['quoteparts_code']);
                $table->dropColumn('quoteparts_code');
            });
        }
    }
};
