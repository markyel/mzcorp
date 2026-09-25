<?php

namespace App\Models;

use App\Enums\OrganizationLinkStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сомнительная привязка контрагента к адресу заказчика, ждущая решения.
 * См. OrganizationLinkGuard.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $client_contact_id
 * @property string $source
 * @property ?int $request_id
 * @property ?int $outbound_quote_id
 * @property ?int $quotation_id
 * @property ?string $document_type
 * @property ?string $document_number
 * @property ?array $known_emails
 * @property OrganizationLinkStatus $status
 */
class OrganizationLinkRequest extends Model
{
    public const SOURCE_OUTBOUND_QUOTE = 'outbound_quote';

    public const SOURCE_QUOTATION = 'quotation';

    public const SOURCE_WEB_FORM = 'web_form';

    protected $fillable = [
        'organization_id',
        'client_contact_id',
        'source',
        'request_id',
        'outbound_quote_id',
        'quotation_id',
        'document_type',
        'document_number',
        'known_emails',
        'status',
        'notified_user_id',
        'notified_at',
        'decided_by_user_id',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'known_emails' => 'array',
            'status' => OrganizationLinkStatus::class,
            'notified_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class, 'client_contact_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function notifiedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === OrganizationLinkStatus::Pending;
    }

    /** «КП» / «счёт» — для текста письма и страницы. */
    public function documentLabel(): string
    {
        return match ($this->document_type) {
            'outbound_invoice' => 'счёт',
            null, '' => 'документ',
            default => 'КП',
        };
    }
}
