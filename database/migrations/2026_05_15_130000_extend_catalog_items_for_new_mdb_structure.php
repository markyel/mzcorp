<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расширение catalog_items под изменившуюся структуру MDB.
 *
 * Новая MDB-таблица отдаёт:
 *   - Бренды/Артикулы как `;`-списки (1:1 по индексу) вместо одного бренда/артикула;
 *   - Размеры как один склеенный «A=240;B=55;C=18» вместо отдельных колонок;
 *   - Узлы как `;`-список;
 *   - + новые поля: Размещение, ЦенаМин, Актуальность, СрокПоставки, Фото,
 *     Комментарий, Описание.
 *
 * Поля Ссылка и CRC из MDB сознательно НЕ копируем (нет потребителя).
 *
 * Backfill:
 *   - existing `brand`/`brand_article`/`unit_name` остаются и продолжают использоваться
 *     в CatalogResolutionService — после следующего импорта они будут перезаполнены
 *     primary-OEM-выбором из новых списков.
 *   - existing `size_a..size_f` остаются — заполняются парсером «Размеры».
 *   - `is_price_actual` дефолт true, чтобы старые строки не считались «цена не валидна».
 *
 * После применения миграции первый следующий импорт пометит ВСЕ строки как
 * rows_updated (source_hash расширен новыми полями) — это ожидаемо.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_items', 'brands')) {
                // Полный список брендов из «Бренды» (`;`-split), 1:1 по индексу с `articles`.
                // В сыром виде нужны для search/UI; primary OEM едет в скалярный `brand`.
                $table->jsonb('brands')->nullable()->after('brand_article_normalized');
            }
            if (! Schema::hasColumn('catalog_items', 'articles')) {
                $table->jsonb('articles')->nullable()->after('brands');
            }
            if (! Schema::hasColumn('catalog_items', 'units')) {
                // На случай multi-value «Узлы». Скалярный unit_name = первый non-empty.
                $table->jsonb('units')->nullable()->after('unit_name');
            }
            if (! Schema::hasColumn('catalog_items', 'placement')) {
                $table->string('placement', 64)->nullable()->after('units');
            }
            if (! Schema::hasColumn('catalog_items', 'price_min')) {
                // «ЦенаМин» — минимальная отпускная цена (со скидкой).
                $table->decimal('price_min', 12, 2)->nullable()->after('price');
            }
            if (! Schema::hasColumn('catalog_items', 'is_price_actual')) {
                // «Актуальность» = «цену можно транслировать клиенту».
                // НЕ заменяет is_active (тот про присутствие в snapshot).
                $table->boolean('is_price_actual')->default(true)->after('price_min');
            }
            if (! Schema::hasColumn('catalog_items', 'lead_time_days')) {
                // «СрокПоставки» в днях. smallint достаточно (до 32767).
                $table->smallInteger('lead_time_days')->nullable()->after('stock_available');
            }
            if (! Schema::hasColumn('catalog_items', 'photo_url')) {
                $table->string('photo_url', 500)->nullable()->after('lead_time_days');
            }
            if (! Schema::hasColumn('catalog_items', 'description')) {
                // «Описание» — публичный (клиентский) текст. Длинный, под TEXT.
                $table->text('description')->nullable()->after('photo_url');
            }
            if (! Schema::hasColumn('catalog_items', 'comment')) {
                // «Комментарий» — внутренний (для оператора), может быть очень длинный.
                $table->text('comment')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            foreach ([
                'brands', 'articles', 'units', 'placement',
                'price_min', 'is_price_actual', 'lead_time_days',
                'photo_url', 'description', 'comment',
            ] as $col) {
                if (Schema::hasColumn('catalog_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
