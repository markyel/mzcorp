<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('request_items', 'quoteparts_promoted_at')) {
            Schema::table('request_items', function (Blueprint $table) {
                // Предварительная позиция QuoteParts переведена в постоянную (позиция заявки
                // сопоставлена с каталогом — у товара появился M-артикул).
                $table->timestamp('quoteparts_promoted_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('request_items', 'quoteparts_promoted_at')) {
            Schema::table('request_items', fn (Blueprint $table) => $table->dropColumn('quoteparts_promoted_at'));
        }
    }
};
