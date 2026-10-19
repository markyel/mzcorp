<?php

namespace App\Services\QuoteParts;

use App\Models\QuotePartsRfq;
use App\Services\Calendar\RussianWorkingDayService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Достаточно ли предложений, чтобы актуализировать цену позиции в базе (вкладка «Актуализация
 * цен»): по запросам QuoteParts за окно — кого о позиции спросили, кто дал цену и когда спросили.
 *
 * Готово, если цену дали больше половины спрошенных поставщиков, или есть хотя бы одно
 * предложение и с первого запроса прошло больше двух рабочих дней.
 */
class PriceRefreshReadiness
{
    public const WINDOW_DAYS = 60;

    public const WAIT_BUSINESS_DAYS = 2;

    public function __construct(private readonly RussianWorkingDayService $calendar) {}

    /**
     * По артикулу (M-код): спрошенные и ответившие поставщики, отказавшиеся, когда спросили, запросы.
     *
     * @return array<string, array{asked: list<string>, offered: list<string>, declined: list<string>, requested_at: Carbon, rfqs: list<string>}>
     */
    public function stats(): array
    {
        $out = [];
        $rfqs = QuotePartsRfq::query()->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))->get(['id', 'tag', 'snapshot', 'created_at']);
        foreach ($rfqs as $rfq) {
            foreach ((array) ($rfq->snapshot['suppliers'] ?? []) as $s) {
                $key = (string) ($s['external_id'] ?? '');
                if ($key === '' || empty($s['sent'])) {
                    continue;
                }
                $sentAt = ! empty($s['sent_at']) ? Carbon::parse($s['sent_at']) : $rfq->created_at;
                foreach ((array) ($s['lines'] ?? []) as $line) {
                    $code = (string) ($line['code'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    $row = $out[$code] ?? ['asked' => [], 'offered' => [], 'declined' => [], 'requested_at' => $sentAt, 'rfqs' => []];
                    $row['asked'][$key] = true;
                    if (($line['status'] ?? '') === 'offered') {
                        $row['offered'][$key] = true;
                    } elseif (($line['status'] ?? '') === 'declined') {
                        $row['declined'][$key] = true;
                    }
                    if ($sentAt->lt($row['requested_at'])) {
                        $row['requested_at'] = $sentAt;
                    }
                    $row['rfqs'][(string) $rfq->tag] = true;
                    $out[$code] = $row;
                }
            }
        }

        return array_map(fn ($r) => [
            'asked' => array_keys($r['asked']),
            'offered' => array_keys($r['offered']),
            'declined' => array_keys($r['declined']),
            'requested_at' => $r['requested_at'],
            'rfqs' => array_values(array_filter(array_keys($r['rfqs']))),
        ], $out);
    }

    /** Когда истекают два рабочих дня с запроса (в то же время суток). */
    public function waitUntil(CarbonInterface $requestedAt): Carbon
    {
        $day = $this->calendar->addBusinessDays($requestedAt, self::WAIT_BUSINESS_DAYS);

        return Carbon::parse($day->toDateString().' '.$requestedAt->format('H:i:s'), $requestedAt->getTimezone());
    }

    /**
     * @param  array{asked: list<string>, offered: list<string>, requested_at: CarbonInterface}  $stat
     */
    public function isReady(array $stat, ?CarbonInterface $now = null): bool
    {
        $asked = count($stat['asked']);
        $offered = count($stat['offered']);
        if ($asked === 0 || $offered === 0) {
            return false;
        }

        return $offered * 2 > $asked || ($now ?? now())->gt($this->waitUntil($stat['requested_at']));
    }
}
