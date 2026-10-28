<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Файл к поручению: шаблон страницы, логотип, текст — уходит исполнителю в архиве.
 */
class MarketingAssignmentFile extends Model
{
    public const DISK = 'local';

    public const DIR = 'marketing/assignments';

    protected $fillable = [
        'marketing_assignment_id', 'filename', 'path', 'mime', 'size', 'note', 'uploaded_by_user_id',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(MarketingAssignment::class, 'marketing_assignment_id');
    }

    public function fileExists(): bool
    {
        return Storage::disk(self::DISK)->exists($this->path);
    }

    public function contents(): ?string
    {
        return $this->fileExists() ? Storage::disk(self::DISK)->get($this->path) : null;
    }

    public function deleteFile(): void
    {
        if ($this->path !== '' && Storage::disk(self::DISK)->exists($this->path)) {
            Storage::disk(self::DISK)->delete($this->path);
        }
    }

    public function sizeLabel(): string
    {
        $kb = $this->size / 1024;

        return $kb >= 1024 ? number_format($kb / 1024, 1, ',', ' ').' МБ' : max(1, (int) round($kb)).' КБ';
    }
}
