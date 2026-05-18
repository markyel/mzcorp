<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Меняем семантику soft-delete в каталоге: позиции которые отсутствуют
     * в новом snapshot MDB больше НЕ архивируются (is_active=false), а
     * помечаются как «нет в наличии и цена устарела» (stock_available=0,
     * is_price_actual=false). Это потому что:
     *
     *  - каталог = master data, история накапливается. Удалять позиции
     *    некорректно: менеджеры могут на них ссылаться, в исторических
     *    заявках они привязаны через catalog_item_id, нужны для текстового
     *    поиска;
     *  - снапшот MDB отражает «что сейчас доступно к продаже с актуальной
     *    ценой и stock», но не «что вообще существует».
     *
     * Этот фикс восстанавливает 768 ранее архивированных позиций как
     * «существуют, но недоступны». Старые матчи (catalog_item_id у
     * RequestItem) продолжают работать, в UI стоит просто чип
     * «нет в наличии» / «цена не актуальна».
     */
    public function up(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }
        if (! Schema::hasColumn('catalog_items', 'is_price_actual')) {
            // Миграция 2026_05_15_130000 уже должна была её добавить —
            // tolerant fallback на старые БД.
            Schema::table('catalog_items', function ($table) {
                $table->boolean('is_price_actual')->default(true);
            });
        }

        // Восстановим архивированные → пометим как «недоступны».
        $affected = DB::table('catalog_items')
            ->where('is_active', false)
            ->update([
                'is_active' => true,
                'stock_available' => 0,
                'is_price_actual' => false,
                'updated_at' => DB::raw('updated_at'), // не дёргать
            ]);

        logger()->info('reframe_soft_delete: restored archived catalog rows', [
            'restored' => $affected,
        ]);
    }

    public function down(): void
    {
        // no-op. Возврат к старой логике (массово is_active=false где
        // stock_available=0 AND is_price_actual=false) рискован — сделает
        // false-positive архивацию для позиций, у которых реально нет
        // остатка но импорт пришёл свежий.
    }
};
