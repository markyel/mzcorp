<?php

namespace App\Services\QuoteParts;

use App\Enums\Role;
use App\Livewire\QuoteParts\Index;
use App\Models\User;
use App\Notifications\QuotePartsInboundLetterNotification;
use Illuminate\Support\Facades\Cache;

/**
 * Снабжению — колокольчик о новом письме в очереди QuoteParts «Не разобрано» (вкладка «Письма без запроса»).
 * Вызывается из SyncQuotePartsRfqsJob (каждые 10 мин). Уже показанные письма помнит в кэше; автоответы
 * не объявляет — они висят в очереди, но звать ради них не стоит.
 */
class QuotePartsInboundLetterNotifier
{
    public const SEEN_CACHE = 'qp-unmatched-letters-notified';

    /** Сколько id помнить: очередь живая, старые письма из неё уходят. */
    private const SEEN_LIMIT = 500;

    public function __construct(private readonly QuotePartsClient $client) {}

    /** @return int сколько писем объявлено */
    public function notifyNew(): int
    {
        $letters = $this->client->inboundLetters();
        Cache::put(Index::LETTERS_CACHE, count($letters), 60);

        $seen = (array) Cache::get(self::SEEN_CACHE, []);
        $fresh = array_values(array_filter($letters, fn ($l) => ! in_array((int) $l['id'], $seen, true) && empty($l['auto_reply'])));
        $ids = array_map(fn ($l) => (int) $l['id'], $letters);
        Cache::forever(self::SEEN_CACHE, array_slice(array_values(array_unique(array_merge($seen, $ids))), -self::SEEN_LIMIT));

        if ($fresh === []) {
            return 0;
        }
        $staff = User::role(Role::Procurement->value)->whereNull('archived_at')->get();
        foreach ($fresh as $l) {
            $from = (string) (($l['from_name'] ?? '') ?: ($l['from_email'] ?? ''));
            foreach ($staff as $user) {
                $user->notify(new QuotePartsInboundLetterNotification((int) $l['id'], $from ?: null, $l['subject'] ?? null));
            }
        }

        return count($fresh);
    }
}
