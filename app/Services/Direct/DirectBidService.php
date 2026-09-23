<?php

namespace App\Services\Direct;

use App\Models\User;
use App\Services\Settings\SettingsService;

/**
 * Ставки фраз по данным аукциона.
 *
 * Единая ставка на все фразы не работает: у каждой свой порог входа. Замер
 * 22.09.2026 по нашим 300 фразам — нижняя ступень аукциона от 7 до 36 ₽
 * (медиана 12), поэтому выставленные при создании 3 ₽ не давали показов
 * вообще: мы не участвовали в торгах.
 *
 * Стратегия прежняя — дешёвый клик по узкой фразе, поэтому берём САМУЮ
 * дешёвую ступень, дающую трафик, а не «первое место». Потолок ставки
 * обязателен: аукцион по редкой позиции иногда просит сотни рублей, и платить
 * их за один клик по детали за тысячу бессмысленно.
 */
class DirectBidService
{
    public const SETTING_CAP = 'direct.bid_cap';

    public const SETTING_TARGET_VOLUME = 'direct.traffic_target';

    /** Потолок ставки по умолчанию — 25 ₽ за клик. */
    public const DEFAULT_CAP = 25.0;

    /** Целевой объём трафика: 0 = самая дешёвая ступень аукциона. */
    public const DEFAULT_TARGET = 0;

    /** За раз ставим не больше — bids.set принимает до 10 000, но баллы. */
    public const MAX_PER_RUN = 500;

    /** Сколько строк отдаёт `keywordbids.get` за один вызов. */
    public const PAGE = 500;

    /** Предохранитель от бесконечного обхода страниц. */
    public const MAX_TOTAL = 10000;

    public function __construct(
        private readonly DirectPublisherService $publisher,
        private readonly SettingsService $settings,
    ) {}

    public function cap(): float
    {
        return max(1.0, (float) $this->settings->get(self::SETTING_CAP, self::DEFAULT_CAP));
    }

    public function targetVolume(): int
    {
        return max(0, (int) $this->settings->get(self::SETTING_TARGET_VOLUME, self::DEFAULT_TARGET));
    }

    /**
     * Что аукцион просит за наши фразы и что мы поставили бы.
     *
     * @return array{rows: array<int, array<string, mixed>>, cap: float, target: int, error: ?string}
     */
    public function plan(?User $by = null): array
    {
        $campaignId = $this->publisher->campaignId();
        if ($campaignId === null) {
            return ['rows' => [], 'cap' => $this->cap(), 'target' => $this->targetVolume(), 'error' => 'Кампания ещё не создана.'];
        }

        // Страницами: Директ отдаёт не больше 500 строк за вызов, а фраз в
        // кампании уже больше (каждая группа добавляет свою псевдофразу
        // автотаргетинга). Без обхода страниц фразы из хвоста никогда не
        // попадали в план — 72 из них так и остались со стартовыми 3 ₽,
        // то есть ниже входа в аукцион, и показов не давали.
        $bids = [];
        $offset = 0;
        do {
            $res = $this->publisher->call('keywordbids', 'get', [
                'SelectionCriteria' => ['CampaignIds' => [$campaignId]],
                'FieldNames' => ['KeywordId', 'AdGroupId', 'ServingStatus'],
                'SearchFieldNames' => ['Bid', 'AuctionBids'],
                'Page' => ['Limit' => self::PAGE, 'Offset' => $offset],
            ], null, $by);

            if (! $res['ok']) {
                return ['rows' => [], 'cap' => $this->cap(), 'target' => $this->targetVolume(), 'error' => DirectPublisherService::errorText($res)];
            }

            $page = $res['result']['KeywordBids'] ?? [];
            $bids = array_merge($bids, $page);
            // LimitedBy присылают, только пока есть что дочитывать.
            $offset = (int) ($res['result']['LimitedBy'] ?? 0);
        } while ($offset > 0 && $page !== [] && count($bids) < self::MAX_TOTAL);

        $cap = $this->cap();
        $target = $this->targetVolume();
        $rows = [];

        foreach ($bids as $k) {
            $tiers = self::tiers($k['Search']['AuctionBids']['AuctionBidItems'] ?? []);
            if ($tiers === []) {
                continue;
            }
            $current = (float) (($k['Search']['Bid'] ?? 0) / 1_000_000);
            $wanted = self::pickBid($tiers, $target, $cap);

            $rows[] = [
                'keyword_id' => (int) $k['KeywordId'],
                'ad_group_id' => (int) ($k['AdGroupId'] ?? 0),
                'serving' => (string) ($k['ServingStatus'] ?? ''),
                'current' => $current,
                // Вход в аукцион — самая дешёвая ступень.
                'entry' => (float) reset($tiers),
                'wanted' => $wanted,
                'changes' => abs($wanted - $current) > 0.005,
            ];
        }

        return ['rows' => $rows, 'cap' => $cap, 'target' => $target, 'error' => null];
    }

    /**
     * Выставить рассчитанные ставки.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{set: int, message: string}
     */
    public function apply(array $rows, ?User $by = null): array
    {
        $changes = array_values(array_filter($rows, fn ($r) => $r['changes']));
        if ($changes === []) {
            return ['set' => 0, 'message' => 'Ставки уже соответствуют аукциону.'];
        }

        $res = $this->publisher->call('bids', 'set', [
            'Bids' => array_map(fn ($r) => [
                'KeywordId' => $r['keyword_id'],
                'Bid' => (int) round($r['wanted'] * 1_000_000),
            ], array_slice($changes, 0, self::MAX_PER_RUN)),
        ], null, $by);

        if (! $res['ok']) {
            return ['set' => 0, 'message' => DirectPublisherService::errorText($res)];
        }

        $errors = DirectPublisherService::resultErrors($res['result'], false);

        return [
            'set' => count($changes),
            'message' => 'Ставки обновлены: '.count($changes).($errors !== '' ? '. Отказы: '.$errors : '.'),
        ];
    }

    /**
     * Ступени аукциона: объём трафика → ставка в рублях, от дешёвой к дорогой.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, float>
     */
    public static function tiers(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $volume = (int) ($item['TrafficVolume'] ?? 0);
            $bid = (float) (($item['Bid'] ?? 0) / 1_000_000);
            if ($volume > 0 && $bid > 0) {
                $out[$volume] = $bid;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Ставка: самая дешёвая ступень, дающая нужный объём трафика, но не выше
     * потолка. Если даже вход в аукцион дороже потолка — ставим потолок:
     * показов не будет, зато видно, что фраза нам не по карману, и решение
     * остаётся за человеком.
     *
     * @param  array<int, float>  $tiers  объём → ставка, по возрастанию объёма
     */
    public static function pickBid(array $tiers, int $targetVolume, float $cap): float
    {
        if ($tiers === []) {
            return $cap;
        }

        $chosen = null;
        foreach ($tiers as $volume => $bid) {
            if ($volume >= $targetVolume) {
                $chosen = $bid;
                break;
            }
        }
        $chosen ??= end($tiers);

        return round(min($chosen, $cap), 2);
    }
}
