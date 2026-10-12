<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Рекламный блок в исходящих письмах клиентам.
 *
 * Картинка + заголовок + краткий текст + ссылка. Управляется директоратом и
 * админом (/dashboard/marketing-blocks). При отправке письма через MyLift
 * MarketingBlockService выбирает случайный из активных и вставляет под
 * подписью менеджера; id блока фиксируется в
 * `email_messages.detected_artifacts.marketing_block_id`.
 */
class MarketingBlock extends Model
{
    public const IMAGE_DISK = 'local';

    public const IMAGE_DIR = 'marketing';

    /** Текст ссылки-призыва под текстом блока, если свой не задан. Стрелка добавляется при рендере. */
    public const DEFAULT_LINK_TEXT = 'Подробнее';

    public const LINK_TEXT_MAX = 60;

    /** Сколько ссылок можно добавить к основной. */
    public const EXTRA_LINKS_MAX = 3;

    protected $fillable = [
        'title',
        'text',
        'url',
        'link_text',
        'extra_links',
        'image_path',
        'is_active',
        'impressions_count',
        'last_used_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'extra_links' => 'array',
            'impressions_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /** Текст ссылки для письма: свой или по умолчанию. */
    public function linkText(): string
    {
        $own = trim((string) ($this->link_text ?? ''));

        return $own !== '' ? $own : self::DEFAULT_LINK_TEXT;
    }

    /**
     * Дополнительные ссылки без пустых строк.
     *
     * @return list<array{url: string, text: string}>
     */
    public function extraLinks(): array
    {
        return self::normalizeLinks((array) ($this->extra_links ?? []));
    }

    /**
     * @param  array<int, mixed>  $links
     * @return list<array{url: string, text: string}>
     */
    public static function normalizeLinks(array $links): array
    {
        $out = [];
        foreach ($links as $l) {
            $url = trim((string) (is_array($l) ? ($l['url'] ?? '') : ''));
            $text = trim((string) (is_array($l) ? ($l['text'] ?? '') : ''));
            if ($url !== '') {
                $out[] = ['url' => $url, 'text' => $text !== '' ? $text : self::DEFAULT_LINK_TEXT];
            }
        }

        return array_slice($out, 0, self::EXTRA_LINKS_MAX);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** URL картинки для превью в CRM (за auth). В письме заменяется на cid:. */
    public function imageUrl(): ?string
    {
        if (! $this->image_path || ! $this->exists) {
            return null;
        }

        return route('marketing-blocks.image', ['block' => $this->id, 'v' => $this->updated_at?->timestamp]);
    }

    /** Абсолютный путь к файлу картинки — для CID-embed в MIME. null если файла нет. */
    public function imageLocalPath(): ?string
    {
        if (! $this->image_path) {
            return null;
        }
        $disk = Storage::disk(self::IMAGE_DISK);

        return $disk->exists($this->image_path) ? $disk->path($this->image_path) : null;
    }

    public function deleteImageFile(): void
    {
        if ($this->image_path) {
            Storage::disk(self::IMAGE_DISK)->delete($this->image_path);
        }
    }
}
