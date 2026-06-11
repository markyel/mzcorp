<?php

namespace App\Models;

use App\Enums\DetectorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Snapshot исходящего КП/счёта (Foundation §7, см. миграцию
 * create_outbound_quotes_table).
 *
 * Заполняется `ParseOutboundQuoteJob` после OutboundDocumentDetector /
 * OutboundDocumentClassifier подтвердил тип документа.
 */
class OutboundQuote extends Model
{
    public const STATUS_PARSING = 'parsing';
    public const STATUS_PARSED = 'parsed';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_FAILED = 'failed';

    public const SOURCE_ATTACHMENT = 'attachment';
    public const SOURCE_BODY = 'body';

    protected $fillable = [
        'request_id',
        'email_message_id',
        'email_attachment_id',
        'source',
        'document_type',
        'document_number',
        'document_date',
        'valid_until',
        'currency',
        'subtotal',
        'vat_amount',
        'total_amount',
        'vat_rate',
        'prices_include_vat',
        'status',
        'parse_error',
        'ai_raw_response',
        'payload',
        'parsed_at',
        'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DetectorType::class,
            'document_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'prices_include_vat' => 'boolean',
            'ai_raw_response' => 'array',
            'payload' => 'array',
            'parsed_at' => 'datetime',
            'matched_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(EmailAttachment::class, 'email_attachment_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OutboundQuoteItem::class)->orderBy('position');
    }

    public function isParsed(): bool
    {
        return in_array($this->status, [self::STATUS_PARSED, self::STATUS_MATCHED], true);
    }

    public function matchedCount(): int
    {
        return $this->items->whereNotNull('matched_request_item_id')->count();
    }

    /**
     * Синтезированное имя файла для UI: «КП №355534 от 18.05.2026.pdf».
     *
     * Реальный `email_attachments.filename` может быть битым: Yandex для
     * некоторых писем отдаёт filename в нестандартной MIME-форме, decoder
     * получает мусор (cyrillic «Д» + ASCII gibberish с кучей `?`). CLI
     * `mail:redecode-attachment-names` помогает только когда в filename
     * всё ещё есть raw `=?UTF-8?B?...?=` паттерн — для уже-decoded-в-мусор
     * пересохранить оригинал нельзя (raw bytes потеряны).
     *
     * Этот helper возвращает синтезированное имя на основе распарсенных
     * метаданных документа. Используется в табах «КП» и «Файлы».
     */
    public function displayFilename(): string
    {
        $isInvoice = $this->document_type === \App\Enums\DetectorType::OutboundInvoice;
        $label = $isInvoice ? 'Счёт' : 'КП';

        $parts = [$label];
        if ($this->document_number !== null && $this->document_number !== '') {
            $parts[] = '№'.$this->document_number;
        }
        if ($this->document_date !== null) {
            $parts[] = 'от '.$this->document_date->format('d.m.Y');
        }

        $ext = $this->guessExtension();

        return implode(' ', $parts).($ext !== null ? '.'.$ext : '');
    }

    /**
     * Расширение файла из file_path или mime_type. Используется в displayFilename().
     */
    private function guessExtension(): ?string
    {
        $att = $this->relationLoaded('attachment') ? $this->attachment : null;
        if ($att !== null && $att->file_path) {
            $ext = strtolower((string) pathinfo($att->file_path, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, ['pdf', 'xlsx', 'xls', 'docx', 'doc'], true)) {
                return $ext;
            }
        }
        $mime = $att?->mime_type;
        if ($mime) {
            return match (true) {
                str_contains($mime, 'pdf') => 'pdf',
                str_contains($mime, 'spreadsheetml') || str_contains($mime, 'excel') => 'xlsx',
                str_contains($mime, 'wordprocessingml') || str_contains($mime, 'msword') => 'docx',
                default => null,
            };
        }

        return null;
    }

    /**
     * Heuristic «filename выглядит как mojibake»: считаем долю `?`, backtick'ов
     * и control-chars. Чистое имя обычно их почти не содержит.
     */
    public static function filenameLooksGarbled(?string $filename): bool
    {
        if ($filename === null || $filename === '') {
            return true;
        }
        $len = mb_strlen($filename);
        if ($len < 4) {
            return true;
        }
        $suspicious = preg_match_all('/[?`\x00-\x1F]/u', $filename) ?: 0;

        return $suspicious / max(1, $len) > 0.20;
    }
}
