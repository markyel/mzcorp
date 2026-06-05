<?php

namespace App\Models;

use App\Models\Kb\EquipmentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Реплика строки корпоративного каталога (MDB → push API).
 *
 * См. миграцию `2026_05_12_180001_create_catalog_items_table.php` и
 * `App\Services\Catalog\CatalogImportService`.
 *
 * Источник истины — MDB на офисной машине. Любые UPDATE здесь
 * перезатираются следующим snapshot'ом. Никогда не правим вручную
 * из админки (если что-то не так — правим в MDB).
 */
class CatalogItem extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'name_en',
        'unit_name',
        // Полный список «Узлы» из MDB (`;`-split). Скалярный unit_name = первый non-empty.
        'units',
        'placement',
        'part_type',
        // FK на equipment_categories (Phase B): прямая привязка SKU к KB-категории
        // вместо substring-фильтрации по synonyms. Заполняется командой
        // kb:backfill-categories (rule-based + LLM fallback).
        'equipment_category_id',
        'brand',
        'brand_article',
        // Нормализованная форма (uppercase + удалены [\s\-_./]) для быстрого
        // article-match в use-case B (см. миграцию add_brand_article_normalized).
        'brand_article_normalized',
        // Полные списки «Бренды» и «Артикулы» (1:1 по индексу). brand/brand_article =
        // primary-OEM-выбор из этих списков (см. CatalogImportService::pickPrimaryOem).
        'brands',
        'articles',
        'form_factor',
        'size_a',
        'size_b',
        'size_c',
        'size_d',
        'size_e',
        'size_f',
        'weight',
        'price',
        // «ЦенаМин» — минимальная отпускная (со скидкой).
        'price_min',
        // «Актуальность» из MDB: можно ли транслировать цену клиенту.
        'is_price_actual',
        'stock_available',
        // «СрокПоставки» в днях.
        'lead_time_days',
        'photo_url',
        'description',
        'comment',
        'source_hash',
        'is_active',
        'last_imported_at',
        'last_import_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_price_actual' => 'boolean',
            'brands' => 'array',
            'articles' => 'array',
            'units' => 'array',
            'last_imported_at' => 'datetime',
            'size_a' => 'decimal:3',
            'size_b' => 'decimal:3',
            'size_c' => 'decimal:3',
            'size_d' => 'decimal:3',
            'size_e' => 'decimal:3',
            'size_f' => 'decimal:3',
            'weight' => 'decimal:3',
            'price' => 'decimal:2',
            'price_min' => 'decimal:2',
            'stock_available' => 'integer',
            'lead_time_days' => 'integer',
        ];
    }

    public function lastImport(): BelongsTo
    {
        return $this->belongsTo(CatalogImport::class, 'last_import_id');
    }

    public function equipmentCategory(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }

    /**
     * OEM-код для ВНЕШНИХ сервисов (IQOT и т.п.) — без нашего M-артикула.
     * brand_article иногда дублирует sku (M-код); такие значения отсекаем,
     * берём первый «настоящий» OEM из brand_article/articles[]. null = нет OEM.
     */
    public function oemForExternal(): ?string
    {
        foreach (array_merge([$this->brand_article], (array) ($this->articles ?? [])) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && ! self::isInternalCode($candidate, $this->sku)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * OEM-бренд для внешних сервисов (без значений-дублей M-артикула).
     */
    public function brandForExternal(): ?string
    {
        foreach (array_merge([$this->brand], (array) ($this->brands ?? [])) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && ! self::isInternalCode($candidate, $this->sku)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Является ли значение нашим внутренним M-артикулом (нельзя слать наружу/
     * показывать как OEM): равно sku (нормализованно) или похоже на M-код
     * (латинское «M####» / кириллическое «МЗ-…»).
     */
    public static function isInternalCode(string $value, ?string $sku): bool
    {
        $norm = static fn ($s) => mb_strtoupper(preg_replace('/[\s\-._]+/u', '', trim((string) $s)));
        $v = $norm($value);
        if ($v === '') {
            return true;
        }
        if ($sku !== null && $sku !== '' && $v === $norm($sku)) {
            return true;
        }
        if (preg_match('/^M\d{3,}$/iu', $value) === 1) {
            return true;
        }
        if (preg_match('/^МЗ\b/u', mb_strtoupper(trim($value))) === 1) {
            return true;
        }

        return false;
    }
}
