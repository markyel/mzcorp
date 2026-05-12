<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * pgvector-эмбеддинг строки каталога. См. миграцию
 * `2026_05_12_220000_create_catalog_item_embeddings_table.php` и
 * `App\Services\Catalog\CatalogEmbeddingService`.
 *
 * Внимание: колонка `embedding` (vector(1536)) НЕ перечислена в `$fillable`
 * и не выбирается дефолтным `*` — мы хотим её только при матчинге,
 * не таскать по сети 6KiB на каждый ::all().
 */
class CatalogItemEmbedding extends Model
{
    protected $fillable = [
        'catalog_item_id',
        'source_hash',
        'source_text',
        'model_version',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
