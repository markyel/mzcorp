<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Группа товаров (Китай, Россия, Европа, …): по группам поставщики видят позиции в QuoteParts.
 * Группа — она же регион поставщика 1С; exclude_if_code — позиция не попадает в группу
 * автоматически, если она есть в той группе (Европа — то, что не везём из Китая).
 */
class SupplyGroup extends Model
{
    protected $fillable = ['code', 'name', 'position', 'exclude_if_code'];

    /** @var Collection<int, self>|null на время запроса */
    private static ?Collection $memo = null;

    /** @return Collection<int, self> */
    public static function ordered(): Collection
    {
        return self::$memo ??= self::query()->orderBy('position')->orderBy('id')->get();
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return self::ordered()->pluck('code')->all();
    }

    public static function label(?string $code): ?string
    {
        return self::ordered()->firstWhere('code', $code)?->name;
    }

    public static function forget(): void
    {
        self::$memo = null;
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forget());
        static::deleted(fn () => self::forget());
    }
}
