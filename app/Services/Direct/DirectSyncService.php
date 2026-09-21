<?php

namespace App\Services\Direct;

use App\Models\DirectPublishedAd;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Collection;

/**
 * Автоматическое сопровождение рекламы: то, ради чего всё затевалось.
 *
 * Объявление живёт, пока позиция «в наличии и с актуальной ценой» — то есть
 * пока по заявке на неё мы можем сразу дать цену. Пропала с остатка, устарела
 * цена, сняли вручную — объявление выключается. Вернулась — включается.
 *
 * Правило безопасности одно и простое: ВЫКЛЮЧАТЬ можно всегда и без лимита —
 * это экономит деньги и в худшем случае мы теряем показы. ВКЛЮЧАТЬ и СОЗДАВАТЬ
 * — под потолком за прогон: ошибка в автомате, который тратит, должна упираться
 * в предел, а не в дневной бюджет.
 *
 * Режим «только предложения» (по умолчанию) ничего не меняет в Директе: прогон
 * говорит, что сделал бы. Первые дни живём так.
 */
class DirectSyncService
{
    public const SETTING_ENABLED = 'direct.sync_enabled';

    public const SETTING_DRY_RUN = 'direct.sync_dry_run';

    public const SETTING_LAST_RUN = 'direct.sync_last_run';

    /** Потолок «включающих» действий за прогон. */
    public const MAX_RESUMES = 25;

    public function __construct(
        private readonly DirectApiClient $api,
        private readonly DirectPublisherService $publisher,
        private readonly DirectCandidateService $candidates,
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

    /**
     * Прогон. $apply=null — брать режим из настроек.
     *
     * @return array{applied: bool, suspend: array<int, string>, resume: array<int, string>, states: int, errors: array<int, string>, checked: int}
     */
    public function run(?bool $apply = null, ?User $by = null): array
    {
        $apply ??= ! $this->dryRun();
        $report = ['applied' => $apply, 'suspend' => [], 'resume' => [], 'states' => 0, 'errors' => [], 'checked' => 0];

        $campaignId = $this->publisher->campaignId();
        if ($campaignId === null) {
            $report['errors'][] = 'Кампания ещё не создана — синхронизировать нечего.';

            return $report;
        }

        $published = DirectPublishedAd::query()->whereNotNull('ad_id')->get();
        $report['checked'] = $published->count();
        if ($published->isEmpty()) {
            return $report;
        }

        // Состояние из Директа: оно главнее нашего снимка — объявление могли
        // остановить руками в кабинете, и переспорить человека мы не должны.
        $states = $this->pullStates($campaignId, $by);
        $report['states'] = count($states);

        $eligible = $this->eligibleSkus();

        [$toSuspend, $toResume] = $this->decide($published, $states, $eligible);

        foreach ($toSuspend as $row) {
            $report['suspend'][] = $row['sku'].' — '.$row['reason'];
        }
        foreach ($toResume as $row) {
            $report['resume'][] = $row['sku'].' — снова в наличии';
        }

        if (! $apply) {
            return $report;
        }

        if ($toSuspend !== []) {
            $this->act('suspend', array_column($toSuspend, 'ad_id'), $report, $by);
        }
        if ($toResume !== []) {
            $this->act('resume', array_column(array_slice($toResume, 0, self::MAX_RESUMES), 'ad_id'), $report, $by);
        }

        $this->settings->set(self::SETTING_LAST_RUN, now()->toDateTimeString(), \App\Models\AppSetting::TYPE_STRING, $by?->id, 'Последний прогон синхронизации Директа');

        return $report;
    }

    /**
     * Что делать с каждым опубликованным объявлением.
     *
     * @param  Collection<int, DirectPublishedAd>  $published
     * @param  array<int, array<string, mixed>>  $states
     * @param  array<string, bool>  $eligible
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    public function decide(Collection $published, array $states, array $eligible): array
    {
        $suspend = [];
        $resume = [];

        foreach ($published as $ad) {
            $state = $states[(int) $ad->ad_id]['State'] ?? $ad->state;
            $ok = isset($eligible[$ad->sku]);

            if (! $ok && $state === 'ON') {
                $suspend[] = ['sku' => $ad->sku, 'ad_id' => (int) $ad->ad_id, 'reason' => 'нет остатка или цена неактуальна'];
            }
            // Включаем только то, что выключено нами: черновик в работу не
            // переводим — он ещё не проходил модерацию.
            if ($ok && $state === 'OFF' && ($states[(int) $ad->ad_id]['Status'] ?? '') === 'ACCEPTED') {
                $resume[] = ['sku' => $ad->sku, 'ad_id' => (int) $ad->ad_id];
            }
        }

        return [$suspend, $resume];
    }

    /**
     * Артикулы, которым реклама положена: остаток, актуальная цена, не сняты
     * вручную. Глубина — весь пул, а не ротация: выключать надо по факту
     * наличия, а не по месту в очереди.
     *
     * @return array<string, bool>
     */
    public function eligibleSkus(): array
    {
        $this->candidates->forget();

        return $this->candidates->queue(500)
            ->mapWithKeys(fn ($item) => [(string) $item->sku => true])
            ->all();
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
