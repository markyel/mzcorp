<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Статус обновления цен на заявке (Фаза 3.5). Отдельное поле рядом с воронкой
 * (RequestStatus), чтобы не ломать SLA/аналитику. Значения — App\Enums\
 * PriceRefreshState: awaiting (ждём поставщика / цена на обновлении),
 * actualized (все отслеживаемые цены актуализированы — можно делать КП),
 * refused (поставщики отказали). NULL — заявка не в процессе обновления цен.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('requests', 'price_refresh_state')) {
            Schema::table('requests', function (Blueprint $table) {
                $table->string('price_refresh_state', 16)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('requests', 'price_refresh_state')) {
            Schema::table('requests', function (Blueprint $table) {
                $table->dropColumn('price_refresh_state');
            });
        }
    }
};
