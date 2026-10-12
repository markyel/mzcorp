<?php

namespace App\Models;

use App\Enums\MessengerChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Клиент бота заявок в Telegram/MAX. state — шаг диалога и черновик заявки
 * (awaiting, pending_email, code_hash, draft…), см. BotConversationService.
 */
class MessengerChat extends Model
{
    protected $fillable = [
        'channel', 'external_user_id', 'chat_id', 'username', 'first_name', 'last_name',
        'email', 'email_verified_at', 'contact_name', 'phone', 'company_name', 'inn',
        'organization_id', 'state', 'messages_count', 'requests_count', 'last_message_at', 'blocked_at',
    ];

    protected $casts = [
        'channel' => MessengerChannel::class,
        'email_verified_at' => 'datetime',
        'last_message_at' => 'datetime',
        'blocked_at' => 'datetime',
        'state' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MessengerMessage::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(Request::class);
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null && (string) $this->email !== '';
    }

    public function stateGet(string $key, mixed $default = null): mixed
    {
        return data_get($this->state ?? [], $key, $default);
    }

    public function stateSet(string $key, mixed $value): void
    {
        $state = $this->state ?? [];
        data_set($state, $key, $value);
        $this->state = $state;
    }

    public function stateForget(string ...$keys): void
    {
        $state = $this->state ?? [];
        foreach ($keys as $key) {
            unset($state[$key]);
        }
        $this->state = $state;
    }

    public function displayName(): string
    {
        $name = trim((string) $this->contact_name);
        if ($name === '') {
            $name = trim($this->first_name.' '.$this->last_name);
        }

        return $name !== '' ? $name : ($this->username ? '@'.$this->username : $this->channel->label().' '.$this->external_user_id);
    }
}
