<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Контрагент из справочника 1С УНФ — код поставщика для выгрузки цен в 1С. */
class OnecCounterparty extends Model
{
    protected $fillable = ['code', 'name', 'name_normalized', 'full_name', 'inn', 'contract', 'seen_at'];

    protected $casts = ['seen_at' => 'datetime'];

    /** Название для сравнения: регистр, пробелы, кавычки и «ООО» в разных местах не важны. */
    public static function normalize(?string $name): string
    {
        $s = mb_strtolower(trim((string) $name));
        $s = str_replace(['«', '»', '"', '“', '”', '\''], '', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
