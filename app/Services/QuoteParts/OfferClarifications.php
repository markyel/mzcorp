<?php

namespace App\Services\QuoteParts;

use App\Models\QuotePartsOffer;
use App\Models\QuotePartsRfq;
use App\Services\Calendar\RussianWorkingDayService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Уточнение у поставщика по предложению («Актуализация цен»): пока ждём ответа — решение по
 * предложению не принимаем, позиция не «готова к актуализации».
 *
 * Спросили — наше письмо поставщику в ветке запроса после его предложения (из диалога, из
 * карточки заявки — всё равно) или отметка, поставленная при обращении к нему. Ждём, пока
 * поставщик не ответит (его письмо в ветке после вопроса или новое предложение по позиции),
 * но не дольше одного рабочего дня. Снять ожидание можно руками — «решить без ответа».
 */
class OfferClarifications
{
    public const WAIT_BUSINESS_DAYS = 1;

    public const WAITING = 'waiting';

    public const ANSWERED = 'answered';

    public const EXPIRED = 'expired';

    public function __construct(private readonly RussianWorkingDayService $calendar) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  строки вкладки (id, key, sku, at, rfq_remote_id, clarify_asked_at, clarify_cleared_at)
     * @return array<int, array{state:string, asked_at:Carbon, until:Carbon, answered_at:?Carbon}> по id предложения; без уточнения — нет ключа
     */
    public function states(Collection $rows, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $snapshots = QuotePartsRfq::query()->whereIn('remote_id', $rows->pluck('rfq_remote_id')->filter()->unique()->values())
            ->get(['remote_id', 'snapshot'])->keyBy('remote_id');

        $out = [];
        foreach ($rows as $r) {
            $thread = collect();
            if ($r['rfq_remote_id'] && ($rfq = $snapshots->get($r['rfq_remote_id']))) {
                $s = collect($rfq->snapshot['suppliers'] ?? [])->firstWhere('external_id', $r['key']);
                $thread = collect($s['thread'] ?? [])->filter(fn ($m) => ! empty($m['at']))
                    ->map(fn ($m) => $m + ['ts' => Carbon::parse($m['at'])]);
            }
            $asked = self::latest([
                $r['clarify_asked_at'] ?? null,
                $thread->where('direction', 'out')->filter(fn ($m) => $r['at'] && $m['ts']->gt($r['at']))->max('ts'),
            ]);
            if ($asked === null || (($r['clarify_cleared_at'] ?? null) && $r['clarify_cleared_at']->gte($asked))) {
                continue;
            }
            $answeredAt = $thread->where('direction', 'in')->filter(fn ($m) => empty($m['auto']) && $m['ts']->gt($asked))->min('ts')
                ?? QuotePartsOffer::query()->where('supplier_key', $r['key'])->where('sku', $r['sku'])->where('id', '!=', $r['id'])
                    ->where('remote_updated_at', '>', $asked)->min('remote_updated_at');
            $until = $this->waitUntil($asked);
            $out[$r['id']] = [
                'state' => $answeredAt !== null ? self::ANSWERED : ($now->lt($until) ? self::WAITING : self::EXPIRED),
                'asked_at' => $asked,
                'until' => $until,
                'answered_at' => $answeredAt !== null ? Carbon::parse($answeredAt) : null,
            ];
        }

        return $out;
    }

    /** Один рабочий день с вопроса (в то же время суток). */
    public function waitUntil(CarbonInterface $askedAt): Carbon
    {
        $day = $this->calendar->addBusinessDays($askedAt, self::WAIT_BUSINESS_DAYS);

        return Carbon::parse($day->toDateString().' '.$askedAt->format('H:i:s'), $askedAt->getTimezone());
    }

    /** @param  list<mixed>  $times */
    private static function latest(array $times): ?Carbon
    {
        $times = array_values(array_filter(array_map(fn ($t) => $t ? Carbon::parse($t) : null, $times)));

        return $times === [] ? null : collect($times)->max();
    }
}
