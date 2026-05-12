<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Кэш веб-фетча URL'ов из входящих писем.
 *
 * См. миграцию `2026_05_12_120000_create_inbound_url_fetches_table.php`
 * и `App\Services\Web\InboundUrlFetcherService`.
 */
class InboundUrlFetch extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_HTTP_ERROR = 'http_error';
    public const STATUS_SSRF_BLOCKED = 'ssrf_blocked';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_SIZE_EXCEEDED = 'size_exceeded';
    public const STATUS_WRONG_CONTENT_TYPE = 'wrong_content_type';
    public const STATUS_PARSE_ERROR = 'parse_error';
    public const STATUS_SKIPPED_BUDGET = 'skipped_budget';

    protected $fillable = [
        'url_hash',
        'url',
        'host',
        'status',
        'http_status',
        'content_type',
        'content_length',
        'extracted_text',
        'error_message',
        'fetched_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isFresh(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
}
