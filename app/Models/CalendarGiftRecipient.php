<?php

namespace App\Models;

use App\Enums\MessengerChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Получатель фирменного календаря — подписчик канала, записавшийся через бота (App\Services\CalendarGift). */
class CalendarGiftRecipient extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_NEW => 'записан', self::STATUS_SENT => 'отправлен', self::STATUS_CANCELLED => 'отменён'];

    protected $fillable = [
        'campaign', 'messenger_chat_id', 'channel', 'external_user_id', 'contact_name', 'company_name', 'phone', 'address',
        'organization_id', 'status', 'consent_at', 'subscribed', 'subscription_checked_at', 'sent_at', 'note',
    ];

    protected $casts = [
        'channel' => MessengerChannel::class,
        'consent_at' => 'datetime',
        'subscribed' => 'boolean',
        'subscription_checked_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(MessengerChat::class, 'messenger_chat_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
