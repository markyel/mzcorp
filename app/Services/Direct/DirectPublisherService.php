<?php

namespace App\Services\Direct;

use App\Models\CatalogItem;
use App\Models\DirectOperation;
use App\Models\DirectPublishedAd;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

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

    /** Сколько фраз-дублей гасим за прогон — чтобы один вызов не разросся. */
    public const MAX_DEDUPE = 200;

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

            // Один сбой не должен ронять прогон: ошибка на позиции стоит нам
            // одной позиции, а не всей синхронизации (кейс 22.09: битый UTF-8
            // в заголовке прервал публикацию посреди списка).
            try {
                $result = $this->publishRow($row, $item, $record, $campaignId, $by, $groups);
            } catch (\Throwable $e) {
                Log::error('Direct: позиция не опубликована', [
                    'sku' => $row['sku'] ?? null, 'error' => $e->getMessage(),
                ]);
                $result = ['ok' => false, 'message' => ($row['sku'] ?? '?').': '.mb_substr($e->getMessage(), 0, 140)];
            }
            $result['ok'] ? $published++ : $failed++;
            if ($result['message'] !== '') {
                $messages[] = $result['message'];
            }
        }

        $tamed = $this->tameAutotargeting($campaignId, $by);
        if ($tamed > 0) {
            $messages[] = "Автотаргетингу сбита ставка до ".self::autotargetingBid()." ₽ в {$tamed} группах.";
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
            // Состояние и статус в Директе — разные вещи: объявление выключено
            // (State) и при этом черновик (Status). Путать их нельзя: на
            // модерацию мы отбираем по статусу.
            $record->state = 'OFF';
            $record->status = 'DRAFT';
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
        $text = trim(self::errorText($res).' '.self::resultErrors($res['result'] ?? null));
        // Пустая ошибка — сама по себе диагноз: запрос прошёл, а идентификатора
        // в ответе мы не нашли. Так и пишем, чтобы не гадать по «группа:».
        $record->last_error = mb_substr($step.': '.($text !== '' ? $text : 'запрос прошёл, но идентификатор в ответе не найден'), 0, 500);
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
     * Убрать объявление из кабинета: позицию сняли с рекламы, и висеть в
     * рабочем списке ей незачем.
     *
     * Прошедшее модерацию архивируем — статистика показов и кликов остаётся,
     * а из «всех, кроме архивных» объявление уходит. Черновик и отклонённое
     * архивировать нельзя и не за чем: удаляем вместе с группой, потому что
     * группа без объявления — мусор, который потом не опознать.
     *
     * @return array{ok: bool, message: string}
     */
    public function retire(DirectPublishedAd $record, ?User $by = null): array
    {
        $sku = (string) $record->sku;

        if ((string) $record->status === 'ACCEPTED') {
            // Архивировать можно только остановленное.
            if ($record->state !== 'SUSPENDED') {
                $this->call('ads', 'suspend', ['SelectionCriteria' => ['Ids' => [(int) $record->ad_id]]], $sku, $by);
            }
            $res = $this->call('ads', 'archive', ['SelectionCriteria' => ['Ids' => [(int) $record->ad_id]]], $sku, $by);
            $errors = trim(self::errorText($res).' '.self::resultErrors($res['result'] ?? null, false));
            if ($errors !== '') {
                return ['ok' => false, 'message' => $sku.': не архивируется — '.$errors];
            }

            $record->update(['state' => 'ARCHIVED', 'synced_at' => now()]);

            return ['ok' => true, 'message' => $sku.': объявление в архиве.'];
        }

        $res = $this->call('adgroups', 'delete', [
            'SelectionCriteria' => ['Ids' => [(int) $record->ad_group_id]],
        ], $sku, $by);
        $errors = trim(self::errorText($res).' '.self::resultErrors($res['result'] ?? null, false));
        if ($errors !== '') {
            return ['ok' => false, 'message' => $sku.': не удаляется — '.$errors];
        }

        $record->delete();

        return ['ok' => true, 'message' => $sku.': группа и объявление удалены.'];
    }

    /**
     * Переписать тексты уже созданного объявления и отправить его на проверку
     * заново. Нужно после отказа модерации: причину мы устранили в правилах,
     * но в Директе лежит прежний текст, и сам он не обновится.
     *
     * @param  array<string, mixed>  $row  строка плана
     * @return array{ok: bool, message: string}
     */
    public function updateAd(DirectPublishedAd $record, array $row, ?User $by = null): array
    {
        $res = $this->call('ads', 'update', [
            'Ads' => [[
                'Id' => (int) $record->ad_id,
                'TextAd' => array_filter([
                    'Title' => $row['title'],
                    'Title2' => $row['title2'],
                    'Text' => $row['text'],
                    'Href' => $row['url'],
                ], fn ($v) => $v !== null && $v !== ''),
            ]],
        ], $record->sku, $by);

        // Предупреждения — не отказ. «Комбинаторный баннер изменён через
        // устаревший API» и «Title2 не применён» значат, что правка легла, но
        // не полностью; считать это провалом — значит не сохранить снимок и
        // гонять обновление по кругу.
        $errors = trim(self::errorText($res).' '.self::resultErrors($res['result'] ?? null, false));
        if (! $res['ok'] || $errors !== '') {
            return ['ok' => false, 'message' => $record->sku.': '.($errors ?: 'обновление не прошло')];
        }
        $warnings = self::resultErrors($res['result'] ?? null);

        $record->fill([
            'title' => $row['title'],
            'title2' => $row['title2'],
            'text' => $row['text'],
            'status' => 'MODERATION',
            'status_note' => null,
            'moderated_at' => now(),
        ])->save();

        $this->moderate([(int) $record->ad_id], $by);

        return [
            'ok' => true,
            'message' => $record->sku.': текст переписан, отправлено на проверку заново.'
                .($warnings !== '' ? ' Директ отметил: '.$warnings : ''),
        ];
    }

    /**
     * Отправить объявления на модерацию.
     *
     * Единственная наша операция, которую видит Яндекс: до неё объявление —
     * черновик, после — предмет проверки, и каждая последующая правка текста
     * отправляет его на проверку заново. Поэтому кнопка отдельная и нажимает
     * её человек.
     *
     * @param  array<int, int>  $adIds
     * @return array{ok: bool, sent: int, message: string}
     */
    public function moderate(array $adIds, ?User $by = null): array
    {
        $adIds = array_values(array_filter(array_map('intval', $adIds)));
        if ($adIds === []) {
            return ['ok' => true, 'sent' => 0, 'message' => 'Нечего отправлять: черновиков нет.'];
        }

        $res = $this->call('ads', 'moderate', [
            'SelectionCriteria' => ['Ids' => $adIds],
        ], null, $by);

        if (! $res['ok']) {
            return ['ok' => false, 'sent' => 0, 'message' => self::errorText($res)];
        }

        // Директ отвечает поштучно: часть объявлений могла не уйти.
        $errors = self::resultErrors($res['result']);
        $sent = 0;
        foreach (Arr::get((array) $res['result'], 'ModerateResults', []) ?: [] as $row) {
            if (($row['Errors'] ?? []) === []) {
                $sent++;
            }
        }

        DirectPublishedAd::query()
            ->whereIn('ad_id', $adIds)
            ->update(['status' => 'MODERATION', 'moderated_at' => now()]);

        return [
            'ok' => true,
            'sent' => $sent,
            'message' => "Отправлено на модерацию: {$sent}".($errors !== '' ? '. Отказы: '.$errors : '.'),
        ];
    }

    /**
     * Сбить ставку автотаргетингу до минимума.
     *
     * Директ добавляет в каждую группу псевдофразу `---autotargeting` с нашей
     * же ставкой и останавливать её запрещает («Автотаргетинг не может быть
     * остановлен», код 8305). Показы по фразам, которые подбирает Яндекс, —
     * противоположность замыслу: мы платим за узкие запросы по артикулу.
     * Остаётся ставка: с минимальной автотаргетинг почти не выигрывает
     * аукционы, а расход остаётся на наших фразах.
     *
     * @return int сколько псевдофраз поправили
     */
    public function tameAutotargeting(int $campaignId, ?User $by = null): int
    {
        $bid = (int) round(self::autotargetingBid() * 1_000_000);

        $res = $this->call('keywords', 'get', [
            'SelectionCriteria' => ['CampaignIds' => [$campaignId]],
            'FieldNames' => ['Id', 'Keyword', 'Bid'],
        ], null, $by);

        $ids = [];
        foreach ($res['result']['Keywords'] ?? [] as $keyword) {
            if (str_contains((string) ($keyword['Keyword'] ?? ''), 'autotargeting')
                && (int) ($keyword['Bid'] ?? 0) > $bid) {
                $ids[] = (int) $keyword['Id'];
            }
        }
        if ($ids === []) {
            return 0;
        }

        $set = $this->call('bids', 'set', [
            'Bids' => array_map(fn ($id) => ['KeywordId' => $id, 'Bid' => $bid], $ids),
        ], null, $by);

        return $set['ok'] ? count($ids) : 0;
    }

    /**
     * Выключить фразы-дубли внутри кампании.
     *
     * По совпавшей фразе Директ показывает одно объявление рекламодателя
     * (правила показа, п. 3.8) — вторая копия фразы показов не добавляет, зато
     * отбирает их у первой и путает статистику. Дубли берутся из жизни: одна и
     * та же деталь встречается в каталоге в нескольких исполнениях с общим
     * кодом производителя. Оставляем копию в самой ранней группе — позиции
     * публикуются по очереди, и первая создана самой денежной. Гасим, а не
     * удаляем: позиция может уйти со склада, и тогда фраза вернётся к соседу.
     *
     * @return array{suspended: int, phrases: array<int, string>}
     */
    public function dedupeKeywords(int $campaignId, ?User $by = null): array
    {
        $res = $this->call('keywords', 'get', [
            'SelectionCriteria' => ['CampaignIds' => [$campaignId], 'States' => ['ON']],
            'FieldNames' => ['Id', 'Keyword', 'AdGroupId'],
        ], null, $by);

        $byPhrase = [];
        foreach ($res['result']['Keywords'] ?? [] as $keyword) {
            $phrase = mb_strtolower(trim((string) ($keyword['Keyword'] ?? '')));
            if ($phrase === '' || str_contains($phrase, 'autotargeting')) {
                continue;
            }
            $byPhrase[$phrase][] = (int) $keyword['Id'];
        }

        $ids = [];
        $phrases = [];
        foreach ($byPhrase as $phrase => $keywordIds) {
            if (count($keywordIds) < 2) {
                continue;
            }
            sort($keywordIds);
            array_shift($keywordIds);
            $ids = array_merge($ids, $keywordIds);
            $phrases[] = $phrase;
        }
        if ($ids === []) {
            return ['suspended' => 0, 'phrases' => []];
        }

        $ids = array_slice($ids, 0, self::MAX_DEDUPE);
        $off = $this->call('keywords', 'suspend', [
            'SelectionCriteria' => ['Ids' => $ids],
        ], null, $by);

        return $off['ok']
            ? ['suspended' => count($ids), 'phrases' => $phrases]
            : ['suspended' => 0, 'phrases' => []];
    }

    public static function autotargetingBid(): float
    {
        return (float) config('services.yandex_direct.autotargeting_bid', 0.3);
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
    public static function resultErrors(mixed $result, bool $withWarnings = true): string
    {
        $out = [];
        // Ключ зависит от метода: AddResults, SuspendResults, ResumeResults…
        foreach ((array) $result as $key => $rows) {
            if (! is_array($rows) || ! str_ends_with((string) $key, 'Results')) {
                continue;
            }
            foreach ($rows as $row) {
                $problems = (array) ($row['Errors'] ?? []);
                if ($withWarnings) {
                    $problems = array_merge($problems, (array) ($row['Warnings'] ?? []));
                }
                foreach ($problems as $err) {
                    $out[] = trim(($err['Message'] ?? '').' '.($err['Details'] ?? ''));
                }
            }
        }

        return implode('; ', array_filter(array_unique($out)));
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
