<?php

namespace App\Services\Direct;

use App\Models\AppSetting;
use App\Models\DirectAdText;
use App\Models\DirectPublishedAd;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Collection;

/**
 * Конвейер рекламы по складу: то, ради чего всё затевалось.
 *
 * Объявление живёт, пока позиция «в наличии и с актуальной ценой» — тот же
 * критерий, по которому заявка получает автоматическое КП. Пропала с остатка,
 * устарела цена, сняли вручную — объявление гаснет. Вернулась — зажигается.
 *
 * Показываем `direct.ads_limit` объявлений, но готовим `direct.bench_size`:
 * скамейка запасных — написанные, созданные и ПРОШЕДШИЕ МОДЕРАЦИЮ объявления,
 * которые ждут выключенными. Модерацию можно проходить заранее, и тогда замена
 * выпавшей позиции — это включение готового объявления, а не путь с нуля.
 *
 * Правило безопасности: ВЫКЛЮЧАТЬ можно всегда и без лимита — это экономит.
 * Всё, что создаёт, тратит или показывает, идёт под потолком за прогон: ошибка
 * автомата должна упираться в предел, а не в дневной бюджет.
 */
class DirectSyncService
{
    public const SETTING_ENABLED = 'direct.sync_enabled';

    public const SETTING_DRY_RUN = 'direct.sync_dry_run';

    public const SETTING_LAST_RUN = 'direct.sync_last_run';

    /** Сколько объявлений держим готовыми (показываем — direct.ads_limit). */
    public const SETTING_BENCH = 'direct.bench_size';

    public const DEFAULT_BENCH = 25;

    public const MAX_BENCH = 500;

    /** Потолки «дорогих» действий за прогон. */
    public const MAX_RESUMES = 25;

    public const MAX_TEXTS = 10;

    public const MAX_PUBLISH = 10;

    public const MAX_MODERATE = 25;

    public function __construct(
        private readonly DirectPublisherService $publisher,
        private readonly DirectCandidateService $candidates,
        private readonly DirectAdPlanService $plan,
        private readonly DirectAdTextService $texts,
        private readonly SettingsService $settings,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING_ENABLED, false);
    }

    /** По умолчанию — только предложения: автомат в рекламном кабинете заводят осторожно. */
    public function dryRun(): bool
    {
        return (bool) $this->settings->get(self::SETTING_DRY_RUN, true);
    }

    public function adsLimit(): int
    {
        return \App\Livewire\Direct\Index::clamp((int) $this->settings->get(
            \App\Livewire\Direct\Index::SETTING_ADS_LIMIT,
            \App\Livewire\Direct\Index::DEFAULT_ADS_LIMIT,
        ));
    }

    public function benchSize(): int
    {
        $bench = (int) $this->settings->get(self::SETTING_BENCH, self::DEFAULT_BENCH);

        return max($this->adsLimit(), min(self::MAX_BENCH, $bench));
    }

    /**
     * Прогон. $apply=null — брать режим из настроек.
     *
     * @return array{applied: bool, checked: int, states: int, suspend: array<int, string>, resume: array<int, string>, texts: array<int, string>, published: array<int, string>, moderated: array<int, string>, attention: array<int, string>, errors: array<int, string>}
     */
    public function run(?bool $apply = null, ?User $by = null): array
    {
        $apply ??= ! $this->dryRun();
        $report = [
            'applied' => $apply, 'checked' => 0, 'states' => 0,
            'suspend' => [], 'resume' => [], 'texts' => [], 'published' => [],
            'moderated' => [], 'attention' => [], 'errors' => [],
        ];

        $campaignId = $this->publisher->campaignId();
        if ($campaignId === null) {
            $report['errors'][] = 'Кампания ещё не создана — синхронизировать нечего.';

            return $report;
        }

        // Состояние из Директа главнее нашего снимка: объявление могли
        // остановить руками в кабинете, и переспорить человека мы не должны.
        $states = $this->pullStates($campaignId, $by);
        $report['states'] = count($states);

        $this->candidates->forget();
        $bench = $this->benchSize();
        $queue = $this->candidates->queue($bench)->take($bench);
        $queueSkus = $queue->pluck('sku')->map(fn ($s) => (string) $s)->all();

        $published = DirectPublishedAd::query()->whereNotNull('ad_id')->get();
        $report['checked'] = $published->count();

        // 1. Показ: гасим лишнее, зажигаем готовое.
        [$suspend, $resume] = $this->decide($queueSkus, $published, $states, $this->adsLimit());
        foreach ($suspend as $row) {
            $report['suspend'][] = $row['sku'].' — '.$row['reason'];
        }
        foreach ($resume as $row) {
            $report['resume'][] = $row['sku'].' — из резерва в показ';
        }

        // 2. Скамейка: у кого нет текстов, объявлений, модерации.
        $plan = $this->plan->plan($this->adsLimit(), $bench);
        $byKey = $published->keyBy('sku');

        $needTexts = $plan->filter(fn ($row) => $row['source'] === DirectAdText::SOURCE_RULE)
            ->take(self::MAX_TEXTS);
        $needPublish = $plan->filter(fn ($row) => $row['keywords'] !== [] && ! ($byKey[$row['sku']] ?? null)?->isComplete())
            ->take(self::MAX_PUBLISH);
        $needModeration = $published->filter(fn ($ad) => $ad->isDraft() && in_array($ad->sku, $queueSkus, true))
            ->take(self::MAX_MODERATE);

        $report['texts'] = $needTexts->pluck('sku')->all();
        $report['published'] = $needPublish->pluck('sku')->all();
        $report['moderated'] = $needModeration->pluck('sku')->values()->all();
        // Отклонённое не переотправляем автоматом: причина никуда не делась,
        // второй заход даст тот же отказ и раздражение модератора.
        $report['attention'] = $published->filter(fn ($ad) => $ad->isRejected())
            ->map(fn ($ad) => $ad->sku.' — '.($ad->status_note ?: 'отклонено модерацией'))
            ->values()->all();

        if (! $apply) {
            return $report;
        }

        if ($suspend !== []) {
            $this->act('suspend', array_column($suspend, 'ad_id'), $report, $by);
        }
        if ($resume !== []) {
            $this->act('resume', array_column(array_slice($resume, 0, self::MAX_RESUMES), 'ad_id'), $report, $by);
        }

        foreach ($needTexts as $row) {
            $item = $queue->firstWhere('sku', $row['sku']);
            if ($item !== null) {
                $this->texts->generate($item, $by, DirectAdPlanService::currentTone());
            }
        }
        if ($needPublish->isNotEmpty()) {
            $res = $this->publisher->publish($needPublish->map(function ($row) {
                // Публикуем и резерв — он для того и нужен, чтобы лежать готовым.
                $row['in_rotation'] = true;

                return $row;
            }), $by, self::MAX_PUBLISH);
            $report['errors'] = array_merge($report['errors'], array_filter(
                $res['messages'],
                fn ($m) => str_contains($m, ':') && ! str_contains($m, 'группа #'),
            ));
        }
        if ($needModeration->isNotEmpty()) {
            $res = $this->publisher->moderate($needModeration->pluck('ad_id')->all(), $by);
            if (! $res['ok']) {
                $report['errors'][] = $res['message'];
            }
        }

        $this->settings->set(
            self::SETTING_LAST_RUN,
            now()->toDateTimeString(),
            AppSetting::TYPE_STRING,
            $by?->id,
            'Последний прогон синхронизации Директа',
        );

        return $report;
    }

    /**
     * Кому показываться, а кому погаснуть.
     *
     * Показываем первые $adsLimit позиций очереди, у которых объявление уже
     * принято модерацией. Всё остальное, что горит, — гасим: либо позиция
     * выпала из наличия, либо её вытеснили более денежные.
     *
     * @param  array<int, string>  $queueSkus  очередь по порядку
     * @param  Collection<int, DirectPublishedAd>  $published
     * @param  array<int, array<string, mixed>>  $states
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    public function decide(array $queueSkus, Collection $published, array $states, int $adsLimit): array
    {
        $byKey = $published->keyBy('sku');
        $state = fn (DirectPublishedAd $ad) => $states[(int) $ad->ad_id]['State'] ?? $ad->state;
        $status = fn (DirectPublishedAd $ad) => $states[(int) $ad->ad_id]['Status'] ?? $ad->status;

        // Кто достоин показа: по порядку очереди, только принятые модерацией.
        $target = [];
        foreach ($queueSkus as $sku) {
            if (count($target) >= $adsLimit) {
                break;
            }
            $ad = $byKey[$sku] ?? null;
            if ($ad !== null && $ad->ad_id !== null && $status($ad) === 'ACCEPTED') {
                $target[$sku] = $ad;
            }
        }

        $suspend = [];
        $resume = [];

        foreach ($published as $ad) {
            $isTarget = isset($target[$ad->sku]);
            $now = $state($ad);

            if (! $isTarget && $now === 'ON') {
                $suspend[] = [
                    'sku' => $ad->sku,
                    'ad_id' => (int) $ad->ad_id,
                    'reason' => in_array($ad->sku, $queueSkus, true)
                        ? 'вытеснена более денежными позициями'
                        : 'нет остатка или цена неактуальна',
                ];
            }
            if ($isTarget && $now === 'OFF') {
                $resume[] = ['sku' => $ad->sku, 'ad_id' => (int) $ad->ad_id];
            }
        }

        return [$suspend, $resume];
    }

    /**
     * Состояние наших объявлений глазами Директа.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pullStates(int $campaignId, ?User $by = null): array
    {
        $res = $this->publisher->call('ads', 'get', [
            'SelectionCriteria' => ['CampaignIds' => [$campaignId]],
            'FieldNames' => ['Id', 'AdGroupId', 'State', 'Status', 'StatusClarification'],
        ], null, $by);

        $out = [];
        foreach ($res['result']['Ads'] ?? [] as $ad) {
            $id = (int) ($ad['Id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[$id] = $ad;

            DirectPublishedAd::query()->where('ad_id', $id)->update([
                'state' => $ad['State'] ?? null,
                'status' => $ad['Status'] ?? null,
                // Причина отказа: без неё «Отклонено» не говорит, что править.
                'status_note' => mb_substr((string) ($ad['StatusClarification'] ?? ''), 0, 1000) ?: null,
                'synced_at' => now(),
            ]);
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $adIds
     * @param  array<string, mixed>  $report
     */
    private function act(string $method, array $adIds, array &$report, ?User $by): void
    {
        if ($adIds === []) {
            return;
        }

        $res = $this->publisher->call('ads', $method, [
            'SelectionCriteria' => ['Ids' => array_values($adIds)],
        ], null, $by);

        if (! $res['ok']) {
            $report['errors'][] = $method.': '.DirectPublisherService::errorText($res);

            return;
        }

        $errors = DirectPublisherService::resultErrors($res['result']);
        if ($errors !== '') {
            $report['errors'][] = $method.': '.$errors;
        }
    }
}
