<?php

namespace App\Models;

use App\Enums\MailDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Письмо (входящее или исходящее).
 *
 * Уникальность: (mailbox_id, folder, message_id). Одно физическое письмо
 * может лежать в Inbox у одного ящика и в Sent у другого — это две записи.
 *
 * Поля ai_*, classified_at, related_request_id, detected_artifacts
 * заполняются на следующих фазах (1.6, 1.8, Phase 4).
 */
class EmailMessage extends Model
{
    protected $fillable = [
        'mailbox_id',
        'folder',
        'direction',
        'imap_uid',
        'message_id',
        'in_reply_to',
        'references_header',
        'subject',
        'from_email',
        'from_name',
        'to_recipients',
        'cc_recipients',
        'sent_at',
        'body_plain',
        'body_html',
        'raw_source',
        'headers',
        'imap_flags',
        'ai_classification',
        'ai_classification_confidence',
        'classified_at',
        'detected_artifacts',
        'related_request_id',
        // Модуль поставщиков: переписка с поставщиком привязана к запросу
        // (SupplierInquiry). Заполняется SupplierInquiryService.
        'supplier_inquiry_id',
        // Phase 1.8c — новая категоризация (LazyLift drop-in).
        'category',
        'category_confidence',
        'category_intent',
        'category_reasoning',
        'categorized_at',
        // Phase 1.9 — drafts для UI-переписки.
        'is_draft',
        'draft_author_user_id',
        'last_edited_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MailDirection::class,
            'imap_uid' => 'integer',
            'references_header' => 'array',
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'headers' => 'array',
            'imap_flags' => 'array',
            'detected_artifacts' => 'array',
            'sent_at' => 'datetime',
            'classified_at' => 'datetime',
            'ai_classification_confidence' => 'float',
            // Phase 1.8c
            'category_confidence' => 'float',
            'categorized_at' => 'datetime',
            // Phase 1.9
            'is_draft' => 'bool',
            'last_edited_at' => 'datetime',
        ];
    }

    /* ---------------- Phase 1.9 — drafts scopes ---------------- */

    /**
     * Только отправленные / inbound (не черновики). Используем при обычном
     * показе треда, где drafts не должны утечь между менеджерами.
     */
    public function scopeNotDraft(Builder $query): Builder
    {
        return $query->where('is_draft', false);
    }

    /**
     * Видимость для пользователя: всё не-draft + свои черновики.
     * Используется в Detail.php при выборке thread.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        $userId = $user?->id;

        return $query->where(function (Builder $q) use ($userId) {
            $q->where('is_draft', false);
            if ($userId !== null) {
                $q->orWhere('draft_author_user_id', $userId);
            }
        });
    }

    public function draftAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_author_user_id');
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    public function routedMails(): HasMany
    {
        return $this->hasMany(RoutedMail::class);
    }

    public function relatedRequest(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'related_request_id');
    }

    /** Запрос поставщику, к которому прицеплена эта переписка (если есть). */
    public function supplierInquiry(): BelongsTo
    {
        return $this->belongsTo(SupplierInquiry::class);
    }
}
