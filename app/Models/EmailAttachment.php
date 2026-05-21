<?php

namespace App\Models;

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
     * Прочитать содержимое файла из storage.
     */
    public function contents(): ?string
    {
        return Storage::disk($this->disk)->get($this->file_path);
    }
}
