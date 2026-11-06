<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Адрес из переписки с поставщиком, который человек ещё не подтвердил как контакт поставщика. */
class SupplierContactCandidate extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const SOURCES = [
        'outbound_rfq' => 'наш запрос цены',
        'marked_thread' => 'тред помечен поставщиком',
        'close_lost' => 'заявка закрыта как переписка с поставщиком',
        'mail' => 'переписка',
    ];

    protected $fillable = ['email', 'name', 'source', 'context', 'suggested_organization_id', 'status',
        'created_by_user_id', 'decided_by_user_id', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function suggestedOrganization(): BelongsTo
    {
        return $this->belongsTo(SupplierOrganization::class, 'suggested_organization_id');
    }
}
