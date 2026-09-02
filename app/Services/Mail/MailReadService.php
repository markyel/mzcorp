<?php

namespace App\Services\Mail;

use App\Models\EmailMessageUserState;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Персональное состояние писем в разделе «Почта»: прочитано / флаг.
 *
 * НЕ трогает IMAP \Seen (CLAUDE.md §8) — всё живёт в email_message_user_states.
 * Отсутствие строки = непрочитано и без флага.
 */
class MailReadService
{
    /** Отметить письмо прочитанным (идемпотентно). */
    public function markRead(int $emailMessageId, User $user): void
    {
        $this->upsert($emailMessageId, $user->id, ['read_at' => now()], onlyIfNull: 'read_at');
    }

    /** Пакетная отметка прочитанным (при открытии треда). @param array<int,int> $ids */
    public function markManyRead(array $ids, User $user): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return;
        }
        $now = now();
        $rows = array_map(fn (int $id) => [
            'email_message_id' => $id,
            'user_id' => $user->id,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids);

        // upsert: не перетираем уже проставленный read_at (COALESCE).
        DB::table('email_message_user_states')->upsert(
            $rows,
            ['email_message_id', 'user_id'],
            ['read_at' => DB::raw('COALESCE(email_message_user_states.read_at, excluded.read_at)'), 'updated_at' => DB::raw('excluded.updated_at')],
        );
    }

    public function markUnread(int $emailMessageId, User $user): void
    {
        EmailMessageUserState::query()
            ->updateOrCreate(
                ['email_message_id' => $emailMessageId, 'user_id' => $user->id],
                ['read_at' => null],
            );
    }

    /** Переключить флаг (⚑). Возвращает новое состояние (true = помечено). */
    public function toggleFlag(int $emailMessageId, User $user): bool
    {
        $state = EmailMessageUserState::query()->firstOrNew([
            'email_message_id' => $emailMessageId,
            'user_id' => $user->id,
        ]);
        $state->flagged_at = $state->flagged_at ? null : now();
        $state->save();

        return $state->flagged_at !== null;
    }

    /**
     * Пакетная отметка прочитанным по (message_id значениям одного треда) не
     * нужна — треды дедупятся в UI. Здесь только по email_message id.
     */
    private function upsert(int $emailMessageId, int $userId, array $values, ?string $onlyIfNull = null): void
    {
        $state = EmailMessageUserState::query()->firstOrNew([
            'email_message_id' => $emailMessageId,
            'user_id' => $userId,
        ]);
        if ($onlyIfNull !== null && $state->{$onlyIfNull} !== null) {
            return;
        }
        $state->fill($values)->save();
    }
}
