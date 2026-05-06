<?php

namespace App\Models;

use App\Enums\MailRuleActionType;
use App\Enums\MailRuleMatchMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Правило маршрутизации входящего письма.
 *
 * Foundation §1.5. Применяется в порядке priority (asc), первое совпавшее
 * с is_terminal=true завершает цепочку.
 */
class MailRoutingRule extends Model
{
    protected $fillable = [
        'name',
        'priority',
        'is_active',
        'mailbox_scope',
        'match_mode',
        'match_criteria',
        'ai_match_type',
        'action_type',
        'forward_to_email',
        'label',
        'is_terminal',
        'created_by_user_id',
        'match_count',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'is_terminal' => 'bool',
            'mailbox_scope' => 'array',
            'match_criteria' => 'array',
            'match_mode' => MailRuleMatchMode::class,
            'action_type' => MailRuleActionType::class,
            'priority' => 'integer',
            'match_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function routedMails(): HasMany
    {
        return $this->hasMany(RoutedMail::class, 'rule_id');
    }

    /**
     * Применимо ли правило к данному ящику.
     * Если mailbox_scope не задан (NULL) — применимо ко всем.
     */
    public function appliesToMailbox(int $mailboxId): bool
    {
        $scope = $this->mailbox_scope;
        if (empty($scope)) {
            return true;
        }

        return in_array($mailboxId, array_map('intval', $scope), true);
    }
}
