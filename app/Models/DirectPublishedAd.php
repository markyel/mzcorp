<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Соответствие «позиция каталога → объекты Директа».
 * См. миграцию 2026_09_21_191000_create_direct_published_ads_table.
 */
class DirectPublishedAd extends Model
{
    protected $fillable = [
        'catalog_item_id', 'sku', 'campaign_id', 'ad_group_id', 'ad_id', 'keyword_ids',
        'title', 'title2', 'text', 'keywords', 'state', 'status', 'last_error',
        'published_at', 'synced_at', 'published_by_user_id',
    ];

    protected $casts = [
        'keyword_ids' => 'array',
        'keywords' => 'array',
        'published_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /** Публикация доведена до конца — объявление и фразы созданы. */
    public function isComplete(): bool
    {
        return $this->ad_id !== null && $this->keyword_ids !== null && $this->keyword_ids !== [];
    }

    /**
     * Опубликованный текст разошёлся с тем, что сейчас в плане. Отправлять
     * такое обновление — значит заново проходить модерацию, поэтому решение
     * принимает человек, а не синхронизация.
     *
     * @param  array<string, mixed>  $plan
     */
    public function differsFrom(array $plan): bool
    {
        foreach (DirectAdText::FIELDS as $field) {
            if (trim((string) $this->{$field}) !== trim((string) ($plan[$field] ?? ''))) {
                return true;
            }
        }

        return false;
    }
}
