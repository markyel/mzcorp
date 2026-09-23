<?php

namespace App\Models;

use App\Enums\MediaProfileFacet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Утверждение о компании, из которых складывается её рекламный образ.
 *
 * @property MediaProfileFacet $facet
 * @property string $statement
 * @property ?string $details
 * @property bool $is_strict
 */
class MediaProfileEntry extends Model
{
    protected $fillable = [
        'facet', 'statement', 'details', 'is_strict', 'is_active', 'position', 'created_by_user_id',
    ];

    protected $casts = [
        'facet' => MediaProfileFacet::class,
        'is_strict' => 'boolean',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Профиль текстом — то, что пойдёт в проверку материалов: обязательные
     * требования отдельно от пожеланий, иначе модель уравняет «нельзя» и
     * «хорошо бы».
     */
    public static function asBrief(): string
    {
        $lines = [];

        foreach (MediaProfileFacet::ordered() as $facet) {
            $entries = static::query()->active()->where('facet', $facet->value)
                ->orderBy('position')->orderBy('id')->get();
            if ($entries->isEmpty()) {
                continue;
            }

            $lines[] = $facet->label().':';
            foreach ($entries as $entry) {
                $lines[] = ($entry->is_strict ? '  ! ' : '  · ').$entry->statement
                    .($entry->details ? ' — '.$entry->details : '');
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }
}
