<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Денормализованный текстовый столбец для substring/fuzzy поиска по
     * multi-brand `brands[]` jsonb-массиву через pg_trgm GIN-индекс.
     *
     * Проблема: каталог хранит первый бренд в скалярном `brand` (выбран
     * через `pickPrimaryOem`), а полный `;`-список из MDB — в jsonb
     * `brands[]`. Все usages (`ItemCatalogLinkDialog` chip-фильтр,
     * `CatalogEmbeddingService` safety-gate, `CatalogComparisonService`
     * compare-таблица) читали только скалярный `brand` и теряли вторичные
     * бренды.
     *
     * Кейс M01231: `brand=Руспромаппаратура`, но
     * `brands=[Руспромаппаратура, OTIS, OTIS, OTIS, OTIS]` — это аналог
     * совместимый с Otis. Поиск по Otis-OEM (F0380CP3) находил позицию по
     * articles_search, но chip Brand=Otis отсекал её как «другой бренд».
     *
     * Решение симметрично `articles_search` (миграция 2026_05_18_160000):
     *  - `brands_search` text — UPPER(BRAND1|BRAND2|...);
     *  - PG-триггер BEFORE INSERT/UPDATE OF brands — auto-refresh;
     *  - GIN trgm индекс для substring lookup;
     *  - backfill для существующих rows.
     *
     * Нормализация ТОЛЬКО UPPER (без strip [\s\-_./] как у articles): бренды
     * это слова с пробелами/дефисами («ThyssenKrupp Elevator»). Strip-separators
     * сольёт слова в кашу, рискуя ложноположительными.
     */
    public function up(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }

        // 1) Столбец.
        if (! Schema::hasColumn('catalog_items', 'brands_search')) {
            Schema::table('catalog_items', function (Blueprint $table) {
                $table->text('brands_search')->nullable()->after('brands');
            });
        }

        // 2) Триггер-функция: пересчитывает brands_search из brands[].
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_items_refresh_brands_search()
            RETURNS trigger AS $$
            BEGIN
                NEW.brands_search := COALESCE(
                    (
                        SELECT string_agg(upper(b), '|')
                        FROM jsonb_array_elements_text(
                            CASE
                                WHEN NEW.brands IS NULL THEN '[]'::jsonb
                                ELSE NEW.brands
                            END
                        ) AS b
                        WHERE b IS NOT NULL AND b <> ''
                    ),
                    ''
                );
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // 3) Триггер на INSERT/UPDATE OF brands.
        DB::statement('DROP TRIGGER IF EXISTS catalog_items_brands_search_trg ON catalog_items');
        DB::statement(<<<'SQL'
            CREATE TRIGGER catalog_items_brands_search_trg
            BEFORE INSERT OR UPDATE OF brands ON catalog_items
            FOR EACH ROW
            EXECUTE FUNCTION catalog_items_refresh_brands_search()
        SQL);

        // 4) Backfill — заполняем brands_search для существующих rows. NULL
        //    brands также получают пустую строку (через CASE WHEN).
        DB::statement(<<<'SQL'
            UPDATE catalog_items
            SET brands_search = COALESCE(
                (
                    SELECT string_agg(upper(b), '|')
                    FROM jsonb_array_elements_text(
                        CASE WHEN brands IS NULL THEN '[]'::jsonb ELSE brands END
                    ) AS b
                    WHERE b IS NOT NULL AND b <> ''
                ),
                ''
            )
            WHERE brands_search IS NULL OR brands_search = ''
        SQL);

        // 5) GIN trgm индекс. Tolerant к отсутствию pg_trgm.
        try {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS catalog_items_brands_search_trgm_idx '
                . 'ON catalog_items USING gin (brands_search gin_trgm_ops)'
            );
        } catch (\Throwable $e) {
            logger()->warning(
                'brands_search trgm index failed: ' . $e->getMessage()
            );
        }
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS catalog_items_brands_search_trgm_idx');
        } catch (\Throwable) {
        }
        try {
            DB::statement('DROP TRIGGER IF EXISTS catalog_items_brands_search_trg ON catalog_items');
        } catch (\Throwable) {
        }
        try {
            DB::statement('DROP FUNCTION IF EXISTS catalog_items_refresh_brands_search()');
        } catch (\Throwable) {
        }
        if (Schema::hasColumn('catalog_items', 'brands_search')) {
            Schema::table('catalog_items', function (Blueprint $table) {
                $table->dropColumn('brands_search');
            });
        }
    }
};
