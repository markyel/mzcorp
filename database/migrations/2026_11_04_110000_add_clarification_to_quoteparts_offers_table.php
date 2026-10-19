<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Уточнение у поставщика по предложению («Актуализация цен»): когда спросили, когда сняли
        // ожидание вручную. Пока ждём ответа (или 1 рабочий день) — решение по предложению не принимаем.
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'clarify_asked_at')) {
                $table->timestamp('clarify_asked_at')->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'clarify_cleared_at')) {
                $table->timestamp('clarify_cleared_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            foreach (['clarify_asked_at', 'clarify_cleared_at'] as $column) {
                if (Schema::hasColumn('quoteparts_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
