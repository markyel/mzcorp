<?php

namespace App\Services\Direct;

use App\Models\CatalogItem;
use App\Models\DirectOperation;
use App\Models\DirectPublishedAd;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Arr;

/**
 * Создание структуры в Директе: кампания-контейнер, группа на позицию,
 * объявление и фразы.
 *
 * Два правила, от которых здесь всё зависит.
 *
 * 1. Ничего не показывается само. Кампания создаётся остановленной, объявления
 *    уходят черновиками: `ads.add` не отправляет на модерацию — это отдельный
 *    вызов, и делает его человек кнопкой. Значит ошибка публикации стоит нам
 *    времени, а не денег и не репутации у модератора.
 * 2. Одна позиция — одна группа, и соответствие пишется в БД. Без таблицы
 *    вторая публикация наплодит дублей, а отключать по остатку будет нечем:
 *    в Директе нашего артикула нет, там числовые идентификаторы.
 */
class DirectPublisherService
{
    /** Ключ настройки с идентификатором кампании-контейнера. */
    public const SETTING_CAMPAIGN_ID = 'direct.campaign_id';

    public function __construct(
        private readonly DirectApiClient $api,
        private readonly SettingsService $settings,
        private readonly DirectAdPlanService $plan,
    ) {}

    /** Идентификатор нашей кампании, если она уже создана. */
    public function campaignId(): ?int
    {
        $id = (int) $this->settings->get(self::SETTING_CAMPAIGN_ID, 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Найти кампанию-контейнер в аккаунте или создать её остановленной.
     *
     * @return array{ok: bool, id: int|null, created: bool, message: string}
     */
    public function ensureCampaign(?User $by = null): array
    {
        $name = DirectAdPlanService::campaignName();

        // Сначала ищем: кампания могла быть создана раньше или руками в вебе.
        $found = $this->call('campaigns', 'get', [
            'SelectionCriteria' => (object) [],
            'FieldNames' => ['Id', 'Name', 'State', 'Status'],
        ], null, $by);

        if (! $found['ok']) {
            return ['ok' => false, 'id' => null, 'created' => false, 'message' => self::errorText($found)];
        }

        foreach ($found['result']['Campaigns'] ?? [] as $campaign) {
            if (trim((string) ($campaign['Name'] ?? '')) === $name) {
                $id = (int) $campaign['Id'];
                $this->rememberCampaign($id, $by);

                return [
                    'ok' => true, 'id' => $id, 'created' => false,
                    'message' => "Кампания «{$name}» уже есть в аккаунте (#{$id}), взял её.",
                ];
            }
        }

        $added = $this->call('campaigns', 'add', [
            'Campaigns' => [$this->campaignPayload($name)],
        ], null, $by);

        if (! $added['ok']) {
            return ['ok' => false, 'id' => null, 'created' => false, 'message' => self::errorText($added)];
        }

        $id = (int) (Arr::get($added['result'], 'AddResults.0.Id') ?? 0);
        if ($id <= 0) {
            return [
                'ok' => false, 'id' => null, 'created' => false,
                'message' => 'Директ не вернул идентификатор кампании: '.self::resultErrors($added['result']),
            ];
        }

        $this->rememberCampaign($id, $by);
        // Свежая кампания и так не показывается, но состояние фиксируем явно:
        // в аккаунте с автоматическими правилами «и так» — плохая опора.
        $this->call('campaigns', 'suspend', ['SelectionCriteria' => ['Ids' => [$id]]], null, $by);

        return [
            'ok' => true, 'id' => $id, 'created' => true,
            'message' => "Кампания «{$name}» создана (#{$id}) и остановлена.",
        ];
    }

    /**
     * Опубликовать позиции плана: группа + объявление-черновик + фразы.
     *
     * @param  iterable<array<string, mixed>>  $rows  строки плана
     * @return array{published: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function publish(iterable $rows, ?User $by = null, int $limit = 10): array
    {
        $campaignId = $this->campaignId();
        if ($campaignId === null) {
            return ['published' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => ['Сначала создайте кампанию.']];
        }

        $published = 0;
        $skipped = 0;
        $failed = 0;
        $messages = [];
        // Один запрос на весь прогон: что в кампании уже есть.
        $groups = $this->existingGroups($campaignId, $by);

        foreach ($rows as $row) {
            if ($published + $failed >= $limit) {
                break;
            }
            if (! ($row['in_rotation'] ?? false)) {
                continue;
            }
            if (($row['keywords'] ?? []) === []) {
                $skipped++;
                $messages[] = $row['sku'].': нет фраз — не публикую.';

                continue;
            }

            $item = CatalogItem::query()->where('sku', $row['sku'])->first(['id', 'sku']);
            if ($item === null) {
                $skipped++;

                continue;
            }

            $record = DirectPublishedAd::firstOrNew(['catalog_item_id' => $item->id]);
            if ($record->exists && $record->isComplete()) {
                $skipped++;

                continue;
            }

            $result = $this->publishRow($row, $item, $record, $campaignId, $by, $groups);
            $result['ok'] ? $published++ : $failed++;
            if ($result['message'] !== '') {
                $messages[] = $result['message'];
            }
        }

        return ['published' => $published, 'skipped' => $skipped, 'failed' => $failed, 'messages' => $messages];
    }

    /**
     * Одна позиция. Шаги идут по порядку и запоминаются по мере успеха:
     * оборвались на фразах — группа и объявление уже записаны, повтор доделает
     * остаток, а не создаст второй комплект.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $groups  артикул → уже существующая группа
     * @return array{ok: bool, message: string}
     */
    private function publishRow(
        array $row,
        CatalogItem $item,
        DirectPublishedAd $record,
        int $campaignId,
        ?User $by,
        array $groups = [],
    ): array {
        $sku = (string) $row['sku'];
        $record->fill([
            'sku' => $sku,
            'campaign_id' => $campaignId,
            'title' => $row['title'],
            'title2' => $row['title2'],
            'text' => $row['text'],
            'keywords' => $row['keywords'],
            'published_by_user_id' => $by?->id,
        ]);

        // Группа могла остаться в аккаунте от прерванного прогона — берём её,
        // а не создаём вторую: дубли в Директе чистить дорого и вручную.
        if ($record->ad_group_id === null && isset($groups[$sku])) {
            $record->ad_group_id = $groups[$sku];
            $record->save();
        }

        if ($record->ad_group_id === null) {
            $res = $this->call('adgroups', 'add', [
                'AdGroups' => [[
                    'Name' => mb_substr((string) $row['group'], 0, 255),
                    'CampaignId' => $campaignId,
                    'RegionIds' => self::regionIds(),
                ]],
            ], $sku, $by);

            $groupId = self::addedId($res['result'] ?? null, 'AdGroupId');
            if (! $res['ok'] || $groupId <= 0) {
                return $this->failRow($record, $sku, 'группа', $res);
            }
            $record->ad_group_id = $groupId;
            $record->save();
        }

        if ($record->ad_id === null) {
            $res = $this->call('ads', 'add', [
                'Ads' => [[
                    'AdGroupId' => $record->ad_group_id,
                    'TextAd' => array_filter([
                        'Title' => $row['title'],
                        'Title2' => $row['title2'],
                        'Text' => $row['text'],
                        'Href' => $row['url'],
                        'Mobile' => 'NO',
                    ], fn ($v) => $v !== null && $v !== ''),
                ]],
            ], $sku, $by);

            $adId = self::addedId($res['result'] ?? null);
            if (! $res['ok'] || $adId <= 0) {
                return $this->failRow($record, $sku, 'объявление', $res);
            }
            $record->ad_id = $adId;
            $record->state = 'DRAFT';
            $record->save();
        }

        if ($record->keyword_ids === null || $record->keyword_ids === []) {
            $bid = (int) round(self::defaultBid() * 1_000_000);
            $res = $this->call('keywords', 'add', [
                'Keywords' => array_map(fn ($phrase) => [
                    'AdGroupId' => $record->ad_group_id,
                    'Keyword' => $phrase,
                    'Bid' => $bid,
                ], array_values((array) $row['keywords'])),
            ], $sku, $by);

            $ids = array_values(array_filter(array_map(
                fn ($r) => (int) ($r['Id'] ?? 0),
                Arr::get($res['result'] ?? [], 'AddResults', []) ?: [],
            )));

            if (! $res['ok'] || $ids === []) {
                return $this->failRow($record, $sku, 'фразы', $res);
            }
            $record->keyword_ids = $ids;
        }

        $record->last_error = null;
        $record->published_at = now();
        $record->save();

        return ['ok' => true, 'message' => "{$sku}: группа #{$record->ad_group_id}, объявление #{$record->ad_id}, фраз ".count($record->keyword_ids ?? [])."."];
    }

    /**
     * @param  array<string, mixed>  $res
     * @return array{ok: false, message: string}
     */
    private function failRow(DirectPublishedAd $record, string $sku, string $step, array $res): array
    {
        $text = self::errorText($res).' '.self::resultErrors($res['result'] ?? null);
        $record->last_error = mb_substr(trim($step.': '.$text), 0, 500);
        $record->save();

        return ['ok' => false, 'message' => "{$sku}: {$record->last_error}"];
    }

    /**
     * Вызов API с записью в журнал. Журнал — не отладка, а бухгалтерия:
     * по нему видно, кто и что создал в аккаунте и во сколько баллов обошлось.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function call(string $service, string $method, array $params, ?string $sku = null, ?User $by = null): array
    {
        $res = $this->api->call($service, $method, $params);

        DirectOperation::create([
            'service' => $service,
            'method' => $method,
            'sku' => $sku,
            'ok' => (bool) $res['ok'],
            // Ответ режем: нам нужен след операции, а не копия выдачи Директа.
            'request' => self::trim($params),
            'response' => self::trim($res['result'] ?? $res['error']),
            'units_spent' => $res['units']['spent'] ?? null,
            'units_rest' => $res['units']['rest'] ?? null,
            'error_code' => $res['error']['code'] ?? null,
            'error_message' => $res['error'] !== null
                ? mb_substr(trim(($res['error']['message'] ?? '').' '.($res['error']['detail'] ?? '')), 0, 500)
                : null,
            'user_id' => $by?->id,
        ]);

        return $res;
    }

    /** @return array<string, mixed> */
    private function campaignPayload(string $name): array
    {
        $cfg = (array) config('services.yandex_direct');

        return [
            'Name' => mb_substr($name, 0, 255),
            'StartDate' => now()->toDateString(),
            'TextCampaign' => [
                'BiddingStrategy' => [
                    // Ручные ставки на поиске и никакой сети: смысл затеи —
                    // дешёвый клик по узкой фразе, РСЯ сюда не вписывается.
                    'Search' => ['BiddingStrategyType' => (string) ($cfg['search_strategy'] ?? 'HIGHEST_POSITION')],
                    'Network' => ['BiddingStrategyType' => 'SERVING_OFF'],
                ],
                'Settings' => [
                    ['Option' => 'ADD_METRICA_TAG', 'Value' => 'YES'],
                    ['Option' => 'ADD_OPENSTAT_TAG', 'Value' => 'NO'],
                ],
            ],
            'DailyBudget' => [
                'Amount' => (int) round((float) ($cfg['daily_budget'] ?? 300) * 1_000_000),
                'Mode' => 'STANDARD',
            ],
        ];
    }

    private function rememberCampaign(int $id, ?User $by): void
    {
        $this->settings->set(
            self::SETTING_CAMPAIGN_ID,
            (string) $id,
            \App\Models\AppSetting::TYPE_INT,
            $by?->id,
            'Идентификатор кампании-контейнера в Яндекс.Директе',
        );
    }

    /**
     * Идентификатор созданного объекта. Директ кладёт его в `Id` — и для
     * объявления, и для группы, и для фразы; «AdGroupId» в ответе adgroups.add
     * нет, хотя напрашивается. На этом мы уже потеряли десять групп: разбор
     * считал успешный ответ ошибкой, а объекты в аккаунте остались.
     */
    public static function addedId(mixed $result, ?string $alias = null): int
    {
        $row = Arr::get((array) $result, 'AddResults.0', []);
        $id = (int) ($row['Id'] ?? 0);
        if ($id <= 0 && $alias !== null) {
            $id = (int) ($row[$alias] ?? 0);
        }

        return $id;
    }

    /**
     * Группы нашей кампании: артикул → идентификатор. Имя группы начинается с
     * артикула — по нему и опознаём своё.
     *
     * @return array<string, int>
     */
    public function existingGroups(int $campaignId, ?User $by = null): array
    {
        $res = $this->call('adgroups', 'get', [
            'SelectionCriteria' => ['CampaignIds' => [$campaignId]],
            'FieldNames' => ['Id', 'Name'],
        ], null, $by);

        $out = [];
        foreach ($res['result']['AdGroups'] ?? [] as $group) {
            $name = trim((string) ($group['Name'] ?? ''));
            $sku = strtok($name, ' ');
            if ($sku !== false && $sku !== '' && (int) ($group['Id'] ?? 0) > 0) {
                $out[$sku] = (int) $group['Id'];
            }
        }

        return $out;
    }

    /** @return array<int, int> */
    public static function regionIds(): array
    {
        $raw = (string) config('services.yandex_direct.region_ids', '225');

        return array_values(array_filter(array_map(
            fn ($v) => (int) trim($v),
            explode(',', $raw),
        ), fn ($v) => $v > 0)) ?: [225];
    }

    public static function defaultBid(): float
    {
        return (float) config('services.yandex_direct.default_bid', 3);
    }

    /** @param  array<string, mixed>  $res */
    public static function errorText(array $res): string
    {
        if ($res['error'] === null) {
            return '';
        }

        return trim(($res['error']['message'] ?? 'ошибка').' '.($res['error']['detail'] ?? ''));
    }

    /**
     * Ошибки отдельных объектов: Директ отвечает 200 и кладёт их в AddResults,
     * поэтому «ок» на уровне запроса ещё ничего не значит.
     */
    public static function resultErrors(mixed $result): string
    {
        $out = [];
        foreach (Arr::get((array) $result, 'AddResults', []) ?: [] as $row) {
            foreach (($row['Errors'] ?? []) + ($row['Warnings'] ?? []) as $err) {
                $out[] = trim(($err['Message'] ?? '').' '.($err['Details'] ?? ''));
            }
        }

        return implode('; ', array_filter($out));
    }

    /** Обрезать структуру для журнала — без простыней в БД. */
    private static function trim(mixed $value): mixed
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($json === false || mb_strlen($json) <= 4000) {
            return $value;
        }

        return ['truncated' => mb_substr($json, 0, 4000)];
    }
}
