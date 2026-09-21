<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сохранённые тексты объявления позиции: заголовок, второй заголовок, текст.
 * См. миграции 2026_09_21_150000 и 2026_09_21_170000.
 *
 * Пустое поле означает «берём вариант правила» — модель могла не дать
 * пригодного варианта именно для этого поля, остальные при этом в силе.
 */
class DirectAdText extends Model
{
    public const SOURCE_RULE = 'rule';

    public const SOURCE_AI = 'ai';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_RULE => 'правило',
        self::SOURCE_AI => 'модель',
        self::SOURCE_MANUAL => 'вручную',
    ];

    /** Поля объявления, которые мы храним и правим. */
    public const FIELDS = ['title', 'title2', 'text'];

    protected $table = 'direct_ad_texts';

    protected $fillable = [
        'catalog_item_id', 'sku', 'title', 'title2', 'text',
        'source', 'tone', 'model', 'source_name', 'created_by_user_id',
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
        return self::SOURCES[$this->source] ?? (string) $this->source;
    }

    /** Есть ли вообще сохранённый текст — пустая запись равна её отсутствию. */
    public function hasAnything(): bool
    {
        foreach (self::FIELDS as $field) {
            if (trim((string) $this->{$field}) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Имя позиции в каталоге изменилось — тексты стоит пересобрать. */
    public function isStale(?string $currentName): bool
    {
        return $this->source_name !== null
            && $currentName !== null
            && trim($this->source_name) !== trim($currentName);
    }

    /** Написано прежним тоном — видно после смены настройки. */
    public function isOtherTone(string $currentTone): bool
    {
        return $this->source === self::SOURCE_AI
            && $this->tone !== null
            && $this->tone !== $currentTone;
    }
}
