<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Денормализованный текстовый столбец для быстрого fuzzy/substring поиска
     * по multi-OEM `articles[]` jsonb-массиву через pg_trgm GIN-индекс.
     *
     * Проблема: `articles` хранит список ОЕМ-артикулов как jsonb_array. Чтобы
     * найти позицию по «ЕИЛА.687255.008-04» (второй артикул в массиве у M16660),
     * приходилось делать `EXISTS (SELECT 1 FROM jsonb_array_elements_text(...))`
     * — это seq scan на 35K rows + парсинг jsonb + регексы → ~1500мс.
     *
     * Решение: один text-столбец `articles_search` с конкатенацией всех
     * нормализованных (uppercase + strip [\s\-_./]) артикулов через `|`.
     * GIN trgm индекс на нём даёт substring-lookup за десятки мс.
     *
     * Поддержка:
     *  - Backfill UPDATE для существующих rows.
     *  - PG trigger BEFORE INSERT/UPDATE — перестраивает articles_search
     *    при изменении articles. Это «источник правды», обход через
     *    Eloquent observer не нужен.
     *
     * Все шаги tolerant: pg_trgm может быть не доступен (Beget whitelist) —
     * тогда индекс не создастся, но столбец и триггер останутся. Сервис
     * fall-back'нется на старый jsonb-EXISTS путь.
     */
    public function up(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }

        // 1) Столбец.
        if (! Schema::hasColumn('catalog_items', 'articles_search')) {
            Schema::table('catalog_items', function (Blueprint $table) {
                $table->text('articles_search')->nullable()->after('articles');
            });
        }

        // 2) Триггер-функция: пересчитывает articles_search из articles.
        //    Обрабатывает NULL/non-array/null-element через coalesce + filter.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION catalog_items_refresh_articles_search()
            RETURNS trigger AS $$
            BEGIN
                NEW.articles_search := COALESCE(
                    (
                        SELECT string_agg(
                            upper(regexp_replace(a, '[\s\-_./]', '', 'g')),
                            '|'
                        )
                        FROM jsonb_array_elements_text(
                            CASE
                                WHEN NEW.articles IS NULL THEN '[]'::jsonb
                                ELSE NEW.articles
                            END
                        ) AS a
                        WHERE a IS NOT NULL AND a <> ''
                    ),
                    ''
                );
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // 3) Триггер на INSERT/UPDATE.
        DB::statement('DROP TRIGGER IF EXISTS catalog_items_articles_search_trg ON catalog_items');
        DB::statement(<<<'SQL'
            CREATE TRIGGER catalog_items_articles_search_trg
            BEFORE INSERT OR UPDATE OF articles ON catalog_items
            FOR EACH ROW
            EXECUTE FUNCTION catalog_items_refresh_articles_search()
        SQL);

        // 4) Backfill — обходим триггер, делая UPDATE на самих articles
        //    (триггер сработает и пересчитает). NULL-articles тоже получат
        //    пустую строку.
        DB::statement(<<<'SQL'
            UPDATE catalog_items
            SET articles_search = COALESCE(
                (
                    SELECT string_agg(
                        upper(regexp_replace(a, '[\s\-_./]', '', 'g')),
                        '|'
                    )
                    FROM jsonb_array_elements_text(
                        CASE WHEN articles IS NULL THEN '[]'::jsonb ELSE articles END
                    ) AS a
                    WHERE a IS NOT NULL AND a <> ''
                ),
                ''
            )
            WHERE articles_search IS NULL OR articles_search = ''
        SQL);

        // 5) GIN trgm индекс. Tolerant к отсутствию pg_trgm.
        try {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS catalog_items_articles_search_trgm_idx '
                . 'ON catalog_items USING gin (articles_search gin_trgm_ops)'
            );
        } catch (\Throwable $e) {
            logger()->warning(
                'articles_search trgm index failed: ' . $e->getMessage()
            );
        }
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS catalog_items_articles_search_trgm_idx');
        } catch (\Throwable) {
        }
        try {
            DB::statement('DROP TRIGGER IF EXISTS catalog_items_articles_search_trg ON catalog_items');
        } catch (\Throwable) {
        }
        try {
            DB::statement('DROP FUNCTION IF EXISTS catalog_items_refresh_articles_search()');
        } catch (\Throwable) {
        }
        if (Schema::hasColumn('catalog_items', 'articles_search')) {
            Schema::table('catalog_items', function (Blueprint $table) {
                $table->dropColumn('articles_search');
            });
        }
    }
};
