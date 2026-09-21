<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сохранённый заголовок объявления позиции. См. миграцию
 * 2026_09_21_150000_create_direct_ad_titles_table.
 */
class DirectAdTitle extends Model
{
    public const SOURCE_RULE = 'rule';

    public const SOURCE_AI = 'ai';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_RULE => 'правило',
        self::SOURCE_AI => 'модель',
        self::SOURCE_MANUAL => 'вручную',
    ];

    protected $fillable = [
        'catalog_item_id', 'sku', 'title', 'source', 'model', 'source_name', 'created_by_user_id',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    /** Имя позиции в каталоге изменилось — заголовок стоит пересобрать. */
    public function isStale(?string $currentName): bool
    {
        return $this->source_name !== null
            && $currentName !== null
            && trim($this->source_name) !== trim($currentName);
    }
}
