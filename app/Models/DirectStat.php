<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Строка статистики Директа за день: условие показа или поисковый запрос.
 *
 * @property string $kind
 * @property string $name
 * @property int $impressions
 * @property int $clicks
 * @property float $cost
 */
class DirectStat extends Model
{
    public const KIND_CRITERIA = 'criteria';

    public const KIND_QUERY = 'query';

    /** Итог по кампании за день — имя кампании лежит в name. */
    public const KIND_CAMPAIGN = 'campaign';

    public const TYPE_AUTOTARGETING = 'AUTOTARGETING';

    protected $fillable = [
        'date', 'kind', 'campaign_id', 'criteria_type', 'name', 'matched', 'sku',
        'impressions', 'clicks', 'cost',
    ];

    protected $casts = [
        'date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'cost' => 'float',
    ];

    /** Подбор Яндекса, а не наша фраза. */
    public function isAutotargeting(): bool
    {
        return str_contains(mb_strtoupper((string) $this->criteria_type), self::TYPE_AUTOTARGETING)
            || str_contains((string) $this->name, 'autotargeting');
    }
}
