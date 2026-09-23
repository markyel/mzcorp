<?php

namespace App\Models;

use App\Enums\MediaProfileFacet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Вывод из отзывов конкурента: кандидат, а не готовая запись.
 *
 * Модель предлагает — человек решает. Принятое «чем мы лучше» становится
 * утверждением медиапрофиля, принятое «чем мы хуже» — записью обратной связи,
 * по которой нужно управленческое решение. Отклонённое остаётся здесь, чтобы
 * следующий разбор не предложил то же самое заново.
 *
 * @property string $kind
 * @property string $statement
 */
class CompetitorInsight extends Model
{
    public const KIND_ADVANTAGE = 'advantage';

    public const KIND_WEAKNESS = 'weakness';

    public const KINDS = [
        self::KIND_ADVANTAGE => 'Чем мы лучше',
        self::KIND_WEAKNESS => 'Чем мы хуже',
    ];

    protected $fillable = [
        'competitor_id', 'kind', 'statement', 'evidence', 'facet', 'topic',
        'status', 'media_profile_entry_id', 'client_feedback_id', 'model', 'created_by_user_id',
    ];

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function isAdvantage(): bool
    {
        return $this->kind === self::KIND_ADVANTAGE;
    }

    public function isNew(): bool
    {
        return $this->status === 'new';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function facetLabel(): ?string
    {
        return MediaProfileFacet::tryFrom((string) $this->facet)?->label();
    }
}
