<?php

namespace App\Models;

use App\Enums\MailDirection;
use App\Models\Scopes\ExcludeMailHistoryScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        // Пользовательская папка почтового клиента (MailboxFolder); NULL — входящие.
        'mailbox_folder_id',
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
        // Зеркало истории личного ящика — см. ExcludeMailHistoryScope, MailHistoryMirrorService.
        'is_history',
        'body_fetched_at',
        'history_has_attachments',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new ExcludeMailHistoryScope);
    }

    /**
     * Запрос вместе с письмами из истории ящика. Только для почтового клиента
     * и синка: всё прочее должно работать с живой перепиской.
     */
    public static function withHistory(): Builder
    {
        return static::query()->withoutGlobalScope(ExcludeMailHistoryScope::class);
    }

    /** Ссылки на письмо (вложения, inline-картинки) открываются и у архивных писем. */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withHistory()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    /** Письмо из истории, тело и вложения которого ещё не скачаны с сервера. */
    public function needsBodyFetch(): bool
    {
        return (bool) $this->is_history && $this->body_fetched_at === null;
    }

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
            // Phase 7.2: успешная классификация интента (для catch-up крона).
            'intent_classified_at' => 'datetime',
            // Phase 1.9
            'is_draft' => 'bool',
            'last_edited_at' => 'datetime',
            'is_history' => 'bool',
            'body_fetched_at' => 'datetime',
            'history_has_attachments' => 'bool',
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

    /**
     * Убрать из треда cross-mailbox копии, ОРИГИНАЛ которых в этом же треде.
     *
     * Копия — то же письмо, доставленное в личный ящик менеджера
     * (detected_artifacts.cross_mailbox_copy_of). Дважды показывать его не
     * надо, но и прятать копию, чей оригинал увели в другую заявку, нельзя:
     * тогда письма в переписке не остаётся вовсе (кейс M-2026-15292 — ответ
     * клиента породил новую заявку, оригинал ушёл туда, а копия здесь
     * пряталась как дубль).
     *
     * @param  \Illuminate\Support\Collection<int, self>  $thread
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function dropDuplicateCopies(\Illuminate\Support\Collection $thread): \Illuminate\Support\Collection
    {
        $presentIds = $thread->pluck('id')->flip();

        return $thread
            ->reject(function (self $message) use ($presentIds): bool {
                $originId = data_get($message->detected_artifacts, 'cross_mailbox_copy_of');

                return $originId !== null && $presentIds->has((int) $originId);
            })
            ->values();
    }

    /** Метки письма — их может быть несколько, в отличие от папки. */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(MailLabel::class, 'email_message_labels', 'email_message_id', 'mail_label_id')
            ->orderBy('mail_labels.sort_order')
            ->orderBy('mail_labels.name');
    }

    /** Назначение/прочитанность в разделе «Почта выбывших» (shared-mail). */
    public function sharedAssignment(): HasOne
    {
        return $this->hasOne(SharedMailAssignment::class);
    }

    /** Запрос поставщику, к которому прицеплена эта переписка (если есть). */
    public function supplierInquiry(): BelongsTo
    {
        return $this->belongsTo(SupplierInquiry::class);
    }

    /** Журнал решений маршрутизатора по письму (mail_decisions), новые первыми. */
    public function decisions(): HasMany
    {
        return $this->hasMany(MailDecision::class)->orderByDesc('id');
    }
}
