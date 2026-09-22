<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Сколько раз в месяц фразу ищут в Яндексе (прогноз Директа).
 *
 * @property string $phrase
 * @property int $shows
 * @property int $clicks
 */
class DirectPhraseDemand extends Model
{
    protected $table = 'direct_phrase_demand';

    protected $fillable = ['phrase', 'shows', 'clicks', 'checked_at'];

    protected $casts = [
        'shows' => 'integer',
        'clicks' => 'integer',
        'checked_at' => 'datetime',
    ];
}
