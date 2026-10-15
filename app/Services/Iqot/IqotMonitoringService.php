<?php

namespace App\Services\Iqot;

use App\Models\AppSetting;
use App\Models\CatalogItem;
use App\Models\IqotMonitoringItem;
use App\Services\Procurement\PriceRefreshCandidates;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Мониторинг цен IQOT в режиме «Гибрид» (вместо заявок на КП по нескольку десятков позиций).
 *
 * На мониторинге — все позиции «Снабжение → К актуализации» с OEM-артикулом (без OEM IQOT
 * искать нечего: артикул производителя — главный ключ поиска), плюс добавленные вручную из
 * каталога. Позиция ушла из списка (цена актуализирована, спрос ушёл из окна) — снимаем с
 * мониторинга (active=false), вернулась — включаем снова. Наш M-артикул — только client_ref.
 *
 * Своя программа IQOT (iqot.monitoring_program_id): на ключе есть и чужие программы (пилот
 * LazyLift) — их не трогаем. Отправляются только изменившиеся позиции (хэш снимка). Цены
 * забираются GET …/prices: первый раз целиком, дальше updated_since (не чаще раза в час).
 */
class IqotMonitoringService
{
    public const BATCH = 1000;

    public function __construct(
        private readonly IqotApiService $api,
        private readonly SettingsService $settings,
        private readonly PriceRefreshCandidates $candidates,
    ) {}

    public function enabled(): bool
    {
        return (bool) app_setting('iqot.enabled', config('services.iqot.enabled', false))
            && app_setting('iqot.mode', config('services.iqot.mode', 'monitoring')) === 'monitoring'
            && $this->api->isConfigured();
    }

    public function programId(): ?string
    {
        $id = trim((string) app_setting('iqot.monitoring_program_id', ''));

        return $id !== '' ? $id : null;
    }

    /** Своя программа в режиме hybrid: создать при первом запуске, переключить режим, если сменили. */
    public function ensureProgram(): array
    {
        $id = $this->programId();
        if ($id === null) {
            $program = $this->api->createMonitoringProgram([
                'name' => (string) config('services.iqot.monitoring.name'),
                'price_mode' => 'hybrid',
                'target_freshness_days' => (int) config('services.iqot.monitoring.target_freshness_days'),
                'site_refresh_days' => (int) config('services.iqot.monitoring.site_refresh_days'),
            ]);
            $this->settings->set('iqot.monitoring_program_id', (string) $program['program_id'], AppSetting::TYPE_STRING);
            Log::info('IQOT monitoring: program created', ['program_id' => $program['program_id']]);

            return $program;
        }

        $program = $this->api->monitoringProgram($id);
        if (($program['price_mode'] ?? null) !== 'hybrid') {
            $program = $this->api->updateMonitoringProgram($id, ['price_mode' => 'hybrid']);
        }

        return $program;
    }

    /**
     * Что должно стоять на мониторинге: catalog_item_id => источник.
     *
     * @return array{wanted: array<int, string>, no_oem: list<int>}
     */
    public function wanted(): array
    {
        $refresh = array_column($this->candidates->list(), 'cid');
        $manual = IqotMonitoringItem::query()->where('source', IqotMonitoringItem::SOURCE_MANUAL)->where('active', true)->pluck('catalog_item_id')->all();

        $wanted = [];
        $noOem = [];
        CatalogItem::query()->whereIn('id', array_unique([...$refresh, ...$manual]))
            ->get(['id', 'sku', 'name', 'brand', 'brands', 'brand_article', 'articles', 'part_type'])
            ->each(function (CatalogItem $c) use (&$wanted, &$noOem, $manual) {
                if ($c->oemForExternal() === null) {
                    $noOem[] = $c->id;

                    return;
                }
                $wanted[$c->id] = in_array($c->id, $manual, true) ? IqotMonitoringItem::SOURCE_MANUAL : IqotMonitoringItem::SOURCE_REFRESH;
            });

        return ['wanted' => $wanted, 'no_oem' => $noOem];
    }

    /**
     * Позиция для IQOT: client_ref — наш M-артикул, article — OEM, категория — как в заявках
     * (тот же код: соответствия категорий и типов товаров IQOT переиспользуются).
     *
     * @return array<string, mixed>
     */
    public function buildItem(CatalogItem $c): array
    {
        // «(ЗАМЕНЕНО на M…)» — наша пометка, поставщику она ничего не говорит.
        $name = trim((string) preg_replace('/^\s*\(\s*заменен[оа][^)]*\)\s*/iu', '', (string) $c->name)) ?: (string) $c->name;
        $item = [
            'client_ref' => (string) $c->sku,
            'name' => mb_substr($name, 0, 1000),
            'article' => mb_substr((string) $c->oemForExternal(), 0, 255),
            'unit' => 'шт',
        ];
        if (($brand = $c->brandForExternal()) !== null) {
            $item['brand'] = mb_substr($brand, 0, 255);
        }
        $root = trim((string) app_setting('iqot.root_category', config('services.iqot.root_category', '')));
        $path = array_values(array_filter([$root, trim((string) $c->part_type)], fn ($s) => $s !== ''));
        if ($path !== []) {
            $item['client_category'] = ['code' => mb_substr(end($path), 0, 128), 'path' => $path];
        }

        return $item;
    }

    /**
     * Привести мониторинг к списку: новые и изменившиеся — загрузить, ушедшие — снять.
     *
     * @return array{wanted:int, no_oem:int, sent:int, activated:int, deactivated:int, unchanged:int, imports:list<string>}
     */
    public function syncItems(bool $dryRun = false): array
    {
        ['wanted' => $wanted, 'no_oem' => $noOem] = $this->wanted();
        $rows = IqotMonitoringItem::query()->get()->keyBy('catalog_item_id');
        $catalog = CatalogItem::query()->whereIn('id', array_keys($wanted))
            ->get(['id', 'sku', 'name', 'brand', 'brands', 'brand_article', 'articles', 'part_type'])->keyBy('id');

        $send = [];        // client_ref => [item, catalog_item_id, source]
        $stats = ['wanted' => count($wanted), 'no_oem' => count($noOem), 'sent' => 0, 'activated' => 0, 'deactivated' => 0, 'unchanged' => 0, 'imports' => []];
        foreach ($wanted as $cid => $source) {
            $item = $this->buildItem($catalog[$cid]) + ['active' => true];
            $hash = sha1(json_encode($item, JSON_UNESCAPED_UNICODE));
            $row = $rows->get($cid);
            if ($row !== null && $row->active && $row->payload_hash === $hash && $row->import_status !== 'failed') {
                $stats['unchanged']++;

                continue;
            }
            if ($row !== null && ! $row->active) {
                $stats['activated']++;
            }
            $send[$item['client_ref']] = ['item' => $item, 'hash' => $hash, 'cid' => $cid, 'source' => $source];
        }
        // Ушли из списка (и не добавлены вручную) — снять с мониторинга.
        foreach ($rows as $cid => $row) {
            if ($row->active && ! isset($wanted[$cid])) {
                $item = ['client_ref' => $row->client_ref, 'name' => (string) ($row->payload['name'] ?? $row->client_ref), 'active' => false];
                $send[$row->client_ref] = ['item' => $item, 'hash' => sha1(json_encode($item, JSON_UNESCAPED_UNICODE)), 'cid' => $cid, 'source' => $row->source, 'off' => true];
                $stats['deactivated']++;
            }
        }
        $stats['sent'] = count($send);
        if ($dryRun || $send === []) {
            return $stats;
        }

        $programId = $this->programId() ?? throw new RuntimeException('Программа мониторинга IQOT не создана.');
        foreach (array_chunk($send, self::BATCH, true) as $chunk) {
            $items = array_values(array_map(fn ($s) => $s['item'], $chunk));
            $key = 'mzcorp-mon-'.substr(sha1($programId.json_encode($items, JSON_UNESCAPED_UNICODE)), 0, 40);
            $import = $this->api->upsertMonitoringItems($programId, $items, $key);
            $stats['imports'][] = (string) ($import['import_id'] ?? '');
            foreach ($chunk as $ref => $s) {
                $off = ! empty($s['off']);
                IqotMonitoringItem::query()->updateOrCreate(['catalog_item_id' => $s['cid']], [
                    'client_ref' => $ref,
                    'source' => $s['source'],
                    'active' => ! $off,
                    'payload' => $off ? (($rows->get($s['cid'])?->payload) ?? $s['item']) : $s['item'],
                    'payload_hash' => $off ? null : $s['hash'],
                    'sent_at' => now(),
                    'import_id' => $import['import_id'] ?? null,
                    'import_status' => $import['status'] ?? 'queued',
                    'import_error' => null,
                    'deactivated_at' => $off ? now() : null,
                ]);
            }
        }

        return $stats;
    }

    /** Добавить позицию на мониторинг вручную (из каталога). Без OEM — нельзя. */
    public function addManual(CatalogItem $c): IqotMonitoringItem
    {
        if ($c->oemForExternal() === null) {
            throw new RuntimeException('У позиции нет OEM-артикула производителя — IQOT нечего искать.');
        }
        $row = IqotMonitoringItem::query()->firstOrNew(['catalog_item_id' => $c->id]);
        $row->fill(['client_ref' => (string) $c->sku, 'source' => IqotMonitoringItem::SOURCE_MANUAL, 'active' => true, 'payload_hash' => null, 'deactivated_at' => null])->save();

        return $row;
    }

    /** Ход импортов: ошибки по строкам (например, plan_limit_exceeded). */
    public function syncImports(): int
    {
        $programId = $this->programId();
        if ($programId === null) {
            return 0;
        }
        $done = 0;
        $open = IqotMonitoringItem::query()->whereIn('import_status', ['queued', 'processing'])->whereNotNull('import_id')->distinct()->pluck('import_id');
        foreach ($open as $importId) {
            $import = $this->api->monitoringImport($programId, (string) $importId);
            $status = (string) ($import['status'] ?? 'queued');
            IqotMonitoringItem::query()->where('import_id', $importId)->update(['import_status' => $status]);
            foreach ((array) ($import['errors'] ?? []) as $err) {
                if (! empty($err['client_ref'])) {
                    IqotMonitoringItem::query()->where('client_ref', $err['client_ref'])
                        ->update(['import_status' => 'failed', 'import_error' => mb_substr((string) ($err['error'] ?? 'error'), 0, 255)]);
                }
            }
            $done += in_array($status, ['done', 'failed'], true) ? 1 : 0;
        }

        return $done;
    }

    /** Сопоставление позиций с товарами IQOT (matched / ambiguous / rejected). */
    public function syncMatches(): int
    {
        $programId = $this->programId();
        if ($programId === null) {
            return 0;
        }
        $n = 0;
        $cursor = null;
        do {
            $page = $this->api->monitoringItems($programId, null, $cursor, 1000);
            foreach ((array) ($page['items'] ?? []) as $it) {
                $n += IqotMonitoringItem::query()->where('client_ref', (string) ($it['client_ref'] ?? ''))->update([
                    'match_status' => $it['match_status'] ?? null,
                    'match_note' => $it['match_note'] ?? null,
                    'last_priced_at' => isset($it['last_priced_at']) ? Carbon::parse($it['last_priced_at']) : null,
                ]);
            }
            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor !== null);

        return $n;
    }

    /** Цены: первый раз целиком, дальше только изменившиеся (updated_since = server_time прошлого обхода). */
    public function syncPrices(bool $full = false): int
    {
        $programId = $this->programId();
        if ($programId === null) {
            return 0;
        }
        $since = $full ? null : (trim((string) app_setting('iqot.monitoring_prices_since', '')) ?: null);
        $serverTime = null;
        $cursor = null;
        $n = 0;
        do {
            $page = $this->api->monitoringPrices($programId, $since, $cursor, 500);
            $serverTime ??= $page['server_time'] ?? null;
            foreach ((array) ($page['items'] ?? []) as $p) {
                $n += $this->applyPrice($p);
            }
            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor !== null);

        if ($serverTime !== null) {
            $this->settings->set('iqot.monitoring_prices_since', (string) $serverTime, AppSetting::TYPE_STRING);
        }

        return $n;
    }

    /**
     * Источники, которые показываем и по которым считаем сводку: только подтверждённые цены и
     * по одному на поставщика.
     *  - отбрасываем «на перепроверке» (status=suspicious: резкий скачок цены), источники без цены
     *    сайта и без КП, оптовые уровни и не рубли — так же сопоставимые цены считает и IQOT;
     *  - цена источника — цена сайта, а если её нет («по запросу») — цена КП;
     *  - сравниваем и сортируем БЕЗ НДС, как в разделе IQOT: цена «с НДС» делится на 1+ставка
     *    (tax.vat_percent). НДС не указан — считаем, что цена с НДС (розница сайтов), и помечаем;
     *  - несколько страниц одного поставщика (домен) — одна строка с его лучшей ценой; признаки
     *    «КП» и «подтверждена по почте» берём с любой из его страниц.
     *
     * @param  list<array<string, mixed>>  $sources  нормализованные источники (см. applyPrice)
     * @return list<array<string, mixed>> по возрастанию цены без НДС; поля value (цена как есть), vat (true/false/null), net (без НДС)
     */
    public static function cleanSources(array $sources, ?float $vatPercent = null): array
    {
        $rate = ($vatPercent ?? (float) app_setting('tax.vat_percent', config('services.tax.vat_percent', 22))) / 100;
        $byDomain = [];
        foreach ($sources as $s) {
            if (($s['status'] ?? 'active') !== 'active' || ($s['price_kind'] ?? null) === 'wholesale_tier') {
                continue;
            }
            $kpPrice = is_numeric($s['kp']['price'] ?? null) && (float) $s['kp']['price'] > 0 && strtoupper((string) ($s['kp']['currency'] ?? 'RUB')) === 'RUB'
                ? (float) $s['kp']['price'] : null;
            $sitePrice = is_numeric($s['price'] ?? null) && (float) $s['price'] > 0 && strtoupper((string) ($s['currency'] ?? 'RUB')) === 'RUB'
                ? (float) $s['price'] : null;
            $value = $sitePrice ?? $kpPrice;
            if ($value === null) {
                continue;
            }
            $vat = $sitePrice !== null ? ($s['vat'] ?? null) : ($s['kp']['vat_included'] ?? null);
            $s['value'] = $value;
            $s['vat'] = $vat === null ? null : (bool) $vat;
            $s['net'] = round($vat !== false && $rate > 0 ? $value / (1 + $rate) : $value, 2);
            $domain = mb_strtolower(trim((string) ($s['domain'] ?? ''))) ?: 'url:'.($s['url'] ?? count($byDomain));
            $prev = $byDomain[$domain] ?? null;
            $flags = (array) ($s['flags'] ?? []);
            if ($prev !== null) {
                $flags['kp'] = ! empty($flags['kp']) || ! empty($prev['flags']['kp']);
                $flags['email_confirmed'] = ! empty($flags['email_confirmed']) || ! empty($prev['flags']['email_confirmed']);
                $s['kp'] ??= $prev['kp'] ?? null;
                if ($prev['net'] <= $s['net']) {
                    $prev['flags'] = $flags;
                    $prev['kp'] ??= $s['kp'];
                    $prev['pages'] = ($prev['pages'] ?? 1) + 1;
                    $byDomain[$domain] = $prev;

                    continue;
                }
                $s['pages'] = ($prev['pages'] ?? 1) + 1;
            }
            $s['flags'] = $flags;
            $byDomain[$domain] = $s;
        }
        $out = array_values($byDomain);
        usort($out, fn ($a, $b) => $a['net'] <=> $b['net']);

        return $out;
    }

    /** @param  array<string, mixed>  $p  позиция из GET …/prices */
    public function applyPrice(array $p): int
    {
        $sources = array_values(array_map(fn ($s) => [
            'type' => $s['source_type'] ?? null,
            'domain' => $s['supplier_domain'] ?? null,
            'url' => $s['url'] ?? null,
            'price' => $s['price'] ?? null,
            'currency' => $s['currency'] ?? 'RUB',
            'price_kind' => $s['price_kind'] ?? null,
            'vat' => $s['vat_included'] ?? null,
            'availability' => $s['availability'] ?? null,
            'observed_at' => $s['observed_at'] ?? null,
            'status' => $s['status'] ?? null,
            'flags' => (array) ($s['flags'] ?? []),
            'email_confirmed_at' => $s['email_confirmed_at'] ?? null,
            'kp' => $s['kp'] ?? null,
            'trust' => $s['site_trust_level'] ?? null,
        ], (array) ($p['sources'] ?? [])));
        // Сводку считаем сами — по очищенным источникам (без перепроверки и дублей поставщика).
        $clean = self::cleanSources($sources);
        // Сводка — без НДС.
        $values = array_column($clean, 'net');
        $best = $clean[0] ?? null;
        $median = null;
        if ($values !== []) {
            $mid = intdiv(count($values), 2);
            $median = count($values) % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return IqotMonitoringItem::query()->where('client_ref', (string) ($p['client_ref'] ?? ''))->update([
            'last_priced_at' => isset($p['last_priced_at']) ? Carbon::parse($p['last_priced_at']) : null,
            'prices_changed_at' => isset($p['prices_changed_at']) ? Carbon::parse($p['prices_changed_at']) : null,
            'best_price' => $best['net'] ?? null,
            'best_source' => $best === null ? null : (is_numeric($best['price'] ?? null) && (float) $best['price'] > 0 ? 'web' : 'kp'),
            'best_supplier_domain' => $best['domain'] ?? null,
            'best_url' => $best['url'] ?? null,
            'price_min' => $values !== [] ? min($values) : null,
            'price_median' => $median,
            'price_max' => $values !== [] ? max($values) : null,
            'sources_priced' => count($clean),
            'has_kp' => collect($clean)->contains(fn ($s) => ! empty($s['flags']['kp'])),
            'sources' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'prices_synced_at' => now(),
        ]);
    }

    /**
     * Полный проход: программа, позиции, импорты, сопоставление, цены. Сбой одного шага не валит остальные.
     *
     * @return array<string, mixed>
     */
    public function run(bool $dryRun = false, bool $fullPrices = false): array
    {
        if (! $this->enabled()) {
            return ['skipped' => 'disabled'];
        }
        $out = [];
        try {
            if ($dryRun) {
                return ['items' => $this->syncItems(true)];
            }
            $program = $this->ensureProgram();
            $out['items'] = $this->syncItems();
            // Почтовая работа идёт только у активной программы: после первой загрузки — запустить.
            if (($program['status'] ?? null) === 'draft' && IqotMonitoringItem::query()->where('active', true)->exists()) {
                $program = $this->api->updateMonitoringProgram((string) $this->programId(), ['status' => 'active']);
            }
            $out['program'] = ['status' => $program['status'] ?? null, 'items' => $program['items'] ?? null];
        } catch (Throwable $e) {
            Log::error('IQOT monitoring: items sync failed', ['error' => $e->getMessage()]);
            $out['error'] = $e->getMessage();

            return $out;
        }
        foreach (['imports' => fn () => $this->syncImports(), 'matches' => fn () => $this->syncMatches(), 'prices' => fn () => $this->syncPrices($fullPrices)] as $step => $fn) {
            try {
                $out[$step] = $fn();
            } catch (Throwable $e) {
                Log::warning('IQOT monitoring: '.$step.' failed', ['error' => $e->getMessage()]);
                $out[$step] = 'error: '.mb_substr($e->getMessage(), 0, 200);
            }
        }

        return $out;
    }
}
