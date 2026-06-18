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
 */
class SupplierInquiry extends Model
{
    protected $fillable = [
        'supplier_email',
        'supplier_name',
        'subject',
        'thread_root_id',
        'related_request_id',
        'status',
        'created_by_user_id',
        'notes',
    ];

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
