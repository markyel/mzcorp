<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-05-21 — фикс кейса M33763.
 *
 * Старая нормализация имени для trgm-индекса/SQL: regexp_replace удалял
 * `[\s\-_./,]` (включая пробелы) → имя «Компл. цепи T-135 на … L119,7»
 * склеивалось в одну строку «комплцепиt135на…l1197». Для word_similarity
 * вся эта простыня — одно «слово», короткие токены («t135», «l1197»)
 * тонули в её длине (≤0.5). AVG по 4 токенам падал до 0.33 → ниже порога
 * 0.6, позиция вылетала из выборки.
 *
 * Новый strip-set: `[\-_./,]` (БЕЗ `\s`). Внутрисловные разделители
 * стягиваются, пробелы между словами сохраняются:
 *   «Компл. цепи T-135 на … L119,7» → «компл цепи t135 на … l1197».
 * Теперь word_similarity('t135', …t135…) = 1.0.
 *
 * GIN-индекс пересоздаём под новое выражение, чтобы все 3 места в
 * CatalogEmbeddingService (codeTokenTopN ILIKE + trigramTopN SELECT/WHERE)
 * шли по индексу. Также дропаем мёртвый `catalog_items_name_nosep_trgm_idx`
 * (с суффиксом `_idx`), оставшийся от миграции 2026_05_18_150000.
 *
 * CONCURRENTLY обязателен на проде — иначе ACCESS EXCLUSIVE на
 * catalog_items на время построения. CONCURRENTLY требует запуска вне
 * транзакции → `$withinTransaction = false`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }

        // Мёртвый индекс из 2026_05_18_150000 (с суффиксом _idx)
        DB::statement('DROP INDEX IF EXISTS catalog_items_name_nosep_trgm_idx');

        // Старый dehyphenated с `\s` (склейка всей строки)
        DB::statement('DROP INDEX IF EXISTS idx_catalog_items_name_nosep_trgm');

        // Новый per-word: разделители стягиваются ВНУТРИ слова, пробелы сохраняются
        DB::statement(
            "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_catalog_items_name_perword_trgm "
            . "ON catalog_items USING gin (regexp_replace(lower(name), '[\\-_./,]', '', 'g') gin_trgm_ops)"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_catalog_items_name_perword_trgm');

        DB::statement(
            "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_catalog_items_name_nosep_trgm "
            . "ON catalog_items USING gin (regexp_replace(lower(name), '[\\s\\-_./,]', '', 'g') gin_trgm_ops)"
        );
    }
};
