<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Запрос расценки/наличия поставщику — тред «мы спрашиваем поставщика».
 * Помечается оператором из пойманного thread_reply; ответы поставщика в этом
 * треде ложатся как переписка (email_messages.supplier_inquiry_id), НЕ создавая
 * клиентских заявок. См. миграцию create_supplier_inquiries_table и
 * App\Services\Supplier\SupplierInquiryService.
 *
 * @property string $supplier_email
 * @property ?string $supplier_name
 * @property ?string $subject
 * @property ?string $thread_root_id
 * @property ?int $related_request_id
 * @property string $status
 * @property ?int $created_by_user_id
 * @property ?string $notes
 * @property int $reminders_sent
 * @property ?\Illuminate\Support\Carbon $last_reminder_at
 */
class SupplierInquiry extends Model
{
    /*
     * Запрос цены или просто переписка (11.11.2026). Ящики снабжения заводят ветку на каждое письмо
     * поставщика (ingestSupplierMessage): заказы, PI, счета, отгрузки, рассылки — 163 из 268 веток
     * раздела «Поставщики». Запросы цены, отправленные снабженцем со своего ящика, системных меток не
     * несут — их видно по теме: «Запрос 000372849», «Request … M02350», номер КП «000373308».
     * Шаблоны — POSIX (Postgres ~*) и PCRE одновременно: без \b, только классы и альтернативы.
     */
    public const PRICE_SUBJECT_RE = '(запрос|request|rfq|quot|price|цен[аыуе]|расценк|прайс|angebot|enquiry|inquiry|предложени)';

    /** Тема начинается с номера КП 1С: «000373308», «Re: 372897», «[372853] GAA177HR1». */
    public const NUMBER_SUBJECT_RE = '^\s*((re|fwd?|aw|ответ)\s*:\s*)*\[?0*[0-9]{5,9}\]?(\s|$)';

    public const ORDER_SUBJECT_RE = '(заказ|order|сч[её]т|invoice|proforma|оплат|отгруз|shipment|delivery|достав|сертификат|реализац|упд|накладн|tracking|трек)';

    /**
     * Наши запросы цены: отправленные системой (токен RFQ, автор, позиции) и ветки с темой запроса
     * или номером КП, кроме заказов/счетов/отгрузок. Остальное — переписка поставщика.
     */
    public function scopePriceRequests(\Illuminate\Database\Eloquent\Builder $q): void
    {
        $q->where(fn ($w) => $w->whereNotNull('rfq_token')
            ->orWhereNotNull('created_by_user_id')
            ->orWhereHas('items')
            ->orWhere(fn ($s) => $s
                ->where(fn ($p) => $p->whereNotNull('related_request_id')
                    ->orWhereRaw('subject ~* ?', [self::PRICE_SUBJECT_RE])
                    ->orWhereRaw('subject ~* ?', [self::NUMBER_SUBJECT_RE]))
                ->whereRaw('coalesce(subject, \'\') !~* ?', [self::ORDER_SUBJECT_RE])));
    }

    /** То же по одной ветке — для проверки и тестов (scopePriceRequests — для списка). */
    public function isPriceRequestThread(): bool
    {
        $hasItems = $this->relationLoaded('items') ? $this->items->isNotEmpty() : $this->items()->exists();
        if ($this->rfq_token !== null || $this->created_by_user_id !== null || $hasItems) {
            return true;
        }
        $subject = (string) $this->subject;
        $re = fn (string $p): bool => preg_match('/'.$p.'/iu', $subject) === 1;

        return ($this->related_request_id !== null || $re(self::PRICE_SUBJECT_RE) || $re(self::NUMBER_SUBJECT_RE))
            && ! $re(self::ORDER_SUBJECT_RE);
    }

    protected $fillable = [
        'supplier_email',
        'supplier_name',
        'subject',
        'thread_root_id',
        'related_request_id',
        'status',
        'created_by_user_id',
        'notes',
        // Уникальный токен RFQ ([RFQ-<token>] в теме) для детерминированного
        // матча ответа поставщика к этому инквайри.
        'rfq_token',
        // Авто-напоминания (Фаза 3.5).
        'reminders_sent',
        'last_reminder_at',
        // Интент последнего ответа без оффера: question_to_us | awaiting_supplier.
        'reply_state',
    ];

    protected function casts(): array
    {
        return [
            'last_reminder_at' => 'datetime',
            'reminders_sent' => 'integer',
        ];
    }

    /** Входящие письма поставщика (его ответы) — для lifecycle/напоминаний. */
    public function inboundMessages(): HasMany
    {
        return $this->hasMany(EmailMessage::class)->where('direction', 'inbound');
    }

    /**
     * Состояние ответа поставщика (Фаза 3.5): closed | answered | awaiting.
     * Использует загруженный inbound_messages_count, иначе считает запросом.
     */
    public function responseState(): string
    {
        if ($this->status === 'closed') {
            return 'closed';
        }
        $inbound = $this->inbound_messages_count
            ?? ($this->relationLoaded('inboundMessages')
                ? $this->inboundMessages->count()
                : $this->inboundMessages()->count());

        return $inbound > 0 ? 'answered' : 'awaiting';
    }

    /** Письма переписки с поставщиком (наш запрос + ответы поставщика). */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class)->orderBy('sent_at')->orderBy('id');
    }

    /** Запрошенные позиции (Фаза 3.2). */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierInquiryItem::class)->orderBy('id');
    }

    /** Предложения поставщика по позициям (Фаза 3.3). */
    public function offers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class)->orderByDesc('id');
    }

    /** Клиентская заявка, под которую сорсим (если связана). */
    public function relatedRequest(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'related_request_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
