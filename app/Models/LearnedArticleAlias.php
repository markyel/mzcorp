<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Выученная привязка «код клиента → каталожная позиция» (см. LearnedAliasService).
 * confirmations — сколько раз менеджеры подтвердили соответствие ручной привязкой.
 */
class LearnedArticleAlias extends Model
{
    protected $fillable = [
        'article_normalized',
        'catalog_item_id',
        'confirmations',
        'sample_article',
        'sample_name',
        'last_confirmed_at',
        'last_confirmed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'confirmations' => 'integer',
            'last_confirmed_at' => 'datetime',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
