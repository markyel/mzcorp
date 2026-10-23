<?php

namespace App\Services\QuoteParts;

use App\Mail\QuotePartsAuthorMail;
use App\Models\QuotePartsRfq;
use App\Notifications\QuotePartsSupplierNotification;
use App\Services\Iqot\IqotCurrencyConverter;
use App\Services\Mail\SystemNotificationMailer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Набралась ли по запросу QuoteParts выборка цен, из которой автор может выбрать предложение, —
 * тогда автору один раз письмо и «колокольчик» (ready_notified_at).
 *
 * По позиции запроса правило то же, что у «Актуализации цен» (PriceRefreshReadiness): цену дали
 * больше половины спрошенных или есть цена и прошло два рабочих дня; ответили все — тоже готово.
 * Запрос готов, когда выбирать есть из чего хотя бы по одной позиции, а остальные позиции уже
 * не ждут: на них ответили все, или срок ожидания прошёл.
 */
class QuotePartsRfqReadiness
{
    public function __construct(private readonly PriceRefreshReadiness $rule) {}

    /**
     * Позиции запроса по снимку: кого спросили, кто дал цену, кто отказал, кто ещё не ответил.
     *
     * @return array<string, array{code: string, name: string, asked: list<string>, offered: list<string>, declined: list<string>, pending: list<string>, requested_at: Carbon, best: ?array{price: float, currency: string}}>
     */
    public function positions(QuotePartsRfq $rfq): array
    {
        $out = [];
        foreach ((array) ($rfq->snapshot['suppliers'] ?? []) as $s) {
            $key = (string) ($s['external_id'] ?? '');
            if ($key === '' || empty($s['sent'])) {
                continue;
            }
            $sentAt = ! empty($s['sent_at']) ? Carbon::parse($s['sent_at']) : Carbon::parse($rfq->created_at ?? now());
            foreach ((array) ($s['lines'] ?? []) as $line) {
                $code = trim((string) ($line['code'] ?? ''));
                $id = $code !== '' ? mb_strtoupper($code) : mb_strtolower(trim((string) ($line['name'] ?? '')));
                if ($id === '') {
                    continue;
                }
                $row = $out[$id] ?? ['code' => $code, 'name' => (string) ($line['name'] ?? ''), 'asked' => [], 'offered' => [], 'declined' => [], 'pending' => [], 'requested_at' => $sentAt, 'best' => null, 'best_rub' => null];
                $row['asked'][$key] = true;
                $status = (string) ($line['status'] ?? 'pending');
                if ($status === 'offered') {
                    $row['offered'][$key] = true;
                    foreach (($line['offers'] ?? []) ?: (empty($line['offer']) ? [] : [$line['offer']]) as $of) {
                        $tier = collect($of['tiers'] ?? [])->sortBy('min_qty')->first();
                        if (! isset($tier['price'])) {
                            continue;
                        }
                        $rub = IqotCurrencyConverter::toRub((float) $tier['price'], $of['currency'] ?? null);
                        if ($rub['known'] && ($row['best_rub'] === null || $rub['rub'] < $row['best_rub'])) {
                            $row['best_rub'] = $rub['rub'];
                            $row['best'] = ['price' => (float) $tier['price'], 'currency' => (string) ($of['currency'] ?? 'RUB')];
                        }
                    }
                } elseif ($status === 'declined') {
                    $row['declined'][$key] = true;
                } else {
                    $row['pending'][$key] = true;
                }
                if ($sentAt->lt($row['requested_at'])) {
                    $row['requested_at'] = $sentAt;
                }
                $out[$id] = $row;
            }
        }

        return array_map(fn ($r) => [
            'code' => $r['code'],
            'name' => $r['name'],
            'asked' => array_keys($r['asked']),
            'offered' => array_keys($r['offered']),
            'declined' => array_keys($r['declined']),
            'pending' => array_keys($r['pending']),
            'requested_at' => $r['requested_at'],
            'best' => $r['best'],
        ], $out);
    }

    /** Есть из чего выбрать: цена есть, и либо её дали достаточно поставщиков (или прошёл срок), либо ответили все. */
    public function choosable(array $position, ?CarbonInterface $now = null): bool
    {
        return $position['offered'] !== []
            && ($position['pending'] === [] || $this->rule->isReady($position, $now));
    }

    public function isReady(QuotePartsRfq $rfq, ?CarbonInterface $now = null): bool
    {
        $positions = $this->positions($rfq);
        if ($positions === []) {
            return false;
        }
        $now ??= now();
        $any = false;
        foreach ($positions as $p) {
            if ($this->choosable($p, $now)) {
                $any = true;

                continue;
            }
            // Позиция без выбора ещё ждёт ответов, и срок не вышел — рано.
            if ($p['pending'] !== [] && ! $now->gt($this->rule->waitUntil($p['requested_at']))) {
                return false;
            }
        }

        return $any;
    }

    /**
     * Запросы с автором, о готовности которых ещё не писали: готовым — письмо и «колокольчик».
     * $markOnly — только отметить готовые как уведомлённые (первый запуск: старые запросы не рассылаем).
     *
     * @param  Collection<int, QuotePartsRfq>|null  $rfqs
     * @return list<string> теги запросов, о которых сообщили (или отметили)
     */
    public function notifyReady(?Collection $rfqs = null, bool $markOnly = false, bool $dryRun = false): array
    {
        $rfqs ??= QuotePartsRfq::query()
            ->whereNull('ready_notified_at')
            ->whereNotNull('created_by_user_id')
            ->where('status', 'open')
            ->where('created_at', '>=', now()->subDays(PriceRefreshReadiness::WINDOW_DAYS))
            ->with(['createdBy', 'request'])
            ->get();

        $done = [];
        foreach ($rfqs as $rfq) {
            if ($rfq->ready_notified_at !== null || $rfq->created_by_user_id === null || $rfq->isSupplierQuestion() || ! $this->isReady($rfq)) {
                continue;
            }
            $done[] = (string) $rfq->tag;
            if ($dryRun) {
                continue;
            }
            // Отметка до отправки: сбой письма не должен превратиться в рассылку каждые 10 минут.
            $rfq->forceFill(['ready_notified_at' => now()])->save();
            if ($markOnly) {
                continue;
            }
            $user = $rfq->createdBy;
            if ($user === null || $user->archived_at !== null) {
                continue;
            }
            try {
                $user->notify(new QuotePartsSupplierNotification($rfq, 'prices.ready', null));
                if ($user->email) {
                    app(SystemNotificationMailer::class)->sendMailable($user->email, QuotePartsAuthorMail::ready($rfq, $this->positions($rfq)));
                }
            } catch (Throwable $e) {
                Log::warning('QuoteParts ready notification failed', ['rfq' => $rfq->id, 'error' => $e->getMessage()]);
            }
        }

        return $done;
    }
}
