<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Разбор поискового запроса: наш он или чужой и что из него вычесть.
 *
 * @property string $query
 * @property string $verdict
 * @property ?string $phrase
 * @property ?string $decision
 */
class DirectQueryReview extends Model
{
    /** Запрос про наш товар — показ оправдан. */
    public const OURS = 'ours';

    /** Чужой мир: блоки питания, поручни для ванной, материнские платы. */
    public const FOREIGN = 'foreign';

    /** Модель не уверена — решает человек. */
    public const UNCLEAR = 'unclear';

    public const EXCLUDED = 'excluded';

    public const KEPT = 'kept';

    protected $fillable = [
        'query', 'campaign_id', 'verdict', 'phrase', 'reason', 'model',
        'decision', 'decided_at', 'decided_by_user_id', 'impressions', 'clicks',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'impressions' => 'integer',
        'clicks' => 'integer',
    ];

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** Ждёт решения человека. */
    public function isPending(): bool
    {
        return $this->decision === null;
    }

    public static function verdictLabel(string $verdict): string
    {
        return match ($verdict) {
            self::OURS => 'наш запрос',
            self::FOREIGN => 'чужой запрос',
            default => 'не уверена',
        };
    }
}
