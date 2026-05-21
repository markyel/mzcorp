<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пересоздаём GIN-trgm индекс по dehyphenated name (Phase B / 2026-05-21).
 *
 * Старый индекс: `regexp_replace(lower(name), '[\\s\\-_./]', '', 'g')`
 * Новый:        `regexp_replace(lower(name), '[\\s\\-_./,]', '', 'g')`
 *
 * Добавили запятую в strip-набор: catalog name содержит десятичные с запятой
 * («L119,7»), pg_trgm word_similarity разбивает на «слова» по non-alnum →
 * query «L119,7» теряется. Унифицируем strip и в PHP query (lowerNoSep), и
 * в SQL filter/score.
 *
 * Имя индекса меняем чтобы не было коллизии с предыдущим.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }

        // Дропаем старый индекс (имя из миграции 2026_05_18_150000)
        DB::statement('DROP INDEX IF EXISTS idx_catalog_items_name_nosep_trgm');

        // Создаём новый с запятой в strip
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_catalog_items_name_nosep_trgm "
            . "ON catalog_items USING gin (regexp_replace(lower(name), '[\\s\\-_./,]', '', 'g') gin_trgm_ops)"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS idx_catalog_items_name_nosep_trgm');
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_catalog_items_name_nosep_trgm "
            . "ON catalog_items USING gin (regexp_replace(lower(name), '[\\s\\-_./]', '', 'g') gin_trgm_ops)"
        );
    }
};
