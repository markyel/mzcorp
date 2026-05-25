<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SupportTicketAttachment extends Model
{
    protected $fillable = [
        'ticket_id',
        'message_id',
        'uploaded_by_user_id',
        'original_name',
        'mime_type',
        'size_bytes',
        'file_path',
        'disk',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function contents(): ?string
    {
        return Storage::disk($this->disk)->get($this->file_path);
    }

    public function humanSize(): string
    {
        $b = $this->size_bytes;
        if ($b < 1024) return $b . ' Б';
        if ($b < 1024 * 1024) return round($b / 1024, 1) . ' КБ';
        return round($b / 1024 / 1024, 2) . ' МБ';
    }
}
