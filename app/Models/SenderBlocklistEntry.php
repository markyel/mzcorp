<?php

namespace App\Models;

use App\Enums\BlocklistEntrySource;
use App\Enums\BlocklistEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись стоп-листа отправителей.
 *
 * Используется только сервисом `SenderBlocklistService` — прямое
 * чтение/запись через модель допустимо для UI/тестов, но в pipeline
 * мейла — только через сервис (он нормализует ввод и инкрементит
 * hit_count при матче).
 *
 * @property int $id
 * @property BlocklistEntryType $type
 * @property string $value
 * @property string $normalized_value
 * @property BlocklistEntrySource $source
 * @property string|null $comment
 * @property int|null $added_by_user_id
 * @property int|null $added_from_request_id
 * @property int $hit_count
 * @property \Illuminate\Support\Carbon|null $last_hit_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SenderBlocklistEntry extends Model
{
    protected $table = 'sender_blocklist';

    protected $fillable = [
        'type',
        'value',
        'normalized_value',
        'source',
        'comment',
        'added_by_user_id',
        'added_from_request_id',
        'hit_count',
        'last_hit_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => BlocklistEntryType::class,
            'source' => BlocklistEntrySource::class,
            'hit_count' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function addedFromRequest(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'added_from_request_id');
    }
}
