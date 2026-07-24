<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Вложение к письму.
 *
 * file_path хранится относительно указанного диска (по умолчанию `local`).
 * На Phase 4 DocumentDetector читает PDF/XLSX через $attachment->contents().
 */
class EmailAttachment extends Model
{
    protected $fillable = [
        'email_message_id',
        'filename',
        'mime_type',
        'size_bytes',
        'content_id',
        'file_path',
        'disk',
        'is_inline',
        // Photo Classifier (2026-05-21): результаты Vision-классификации
        // по KB photo-slug'ам — kb_slot_candidates[].
        // См. App\Services\Kb\PhotoSlotClassifierService.
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_inline' => 'bool',
            'metadata' => 'array',
        ];
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    /**
     * Инлайн-картинка КРУПНЕЕ этого порога — вероятно реальное фото позиции
     * (клиент встроил в тело письма), а не подпись/логотип (те мелкие).
     */
    public const INLINE_PHOTO_MIN_BYTES = 50 * 1024;

    /**
     * Вложения, пригодные для ручной привязки фото к позиции
     * (диалог «Сменить фото»): картинки из письма.
     *
     * Inline-картинки (is_inline — подключены через cid: в HTML) обычно подписи/
     * логотипы/баннеры (кейс M-2026-2257: логотип «LiftCo» из подписи липнул к
     * позиции) — их дублирует каждое письмо треда. НО клиенты часто встраивают
     * и реальные фото товара прямо в тело письма — они тоже inline. Отсекаем
     * только МЕЛКИЕ инлайн (подписи/логотипы < INLINE_PHOTO_MIN_BYTES), крупные
     * инлайн-фото оставляем (кейс M-2026-9365: фото 200-350КБ ошибочно
     * скрывались как «в письмах нет image-вложений»).
     */
    public function scopeBindablePhotos(Builder $query): Builder
    {
        return $query
            ->where('mime_type', 'like', 'image/%')
            ->where(function (Builder $q) {
                $q->where('is_inline', false)
                    ->orWhere('size_bytes', '>=', self::INLINE_PHOTO_MIN_BYTES);
            });
    }

    /**
     * Прочитать содержимое файла из storage.
     */
    public function contents(): ?string
    {
        return Storage::disk($this->disk)->get($this->file_path);
    }
}
