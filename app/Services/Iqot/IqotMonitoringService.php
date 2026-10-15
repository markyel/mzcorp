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

    /** @param  array<string, mixed>  $p  позиция из GET …/prices */
    public function applyPrice(array $p): int
    {
        $best = (array) ($p['best_price'] ?? []);
        $summary = (array) ($p['summary'] ?? []);
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
        $num = fn ($v) => is_numeric($v) ? (float) $v : null;

        return IqotMonitoringItem::query()->where('client_ref', (string) ($p['client_ref'] ?? ''))->update([
            'last_priced_at' => isset($p['last_priced_at']) ? Carbon::parse($p['last_priced_at']) : null,
            'prices_changed_at' => isset($p['prices_changed_at']) ? Carbon::parse($p['prices_changed_at']) : null,
            'best_price' => $num($best['price'] ?? null),
            'best_source' => $best['price_source'] ?? null,
            'best_supplier_domain' => $best['supplier_domain'] ?? null,
            'best_url' => $best['url'] ?? null,
            'price_min' => $num($summary['min'] ?? null),
            'price_median' => $num($summary['median'] ?? null),
            'price_max' => $num($summary['max'] ?? null),
            'sources_priced' => (int) ($summary['sources_priced'] ?? 0),
            'has_kp' => collect($sources)->contains(fn ($s) => ! empty($s['flags']['kp'])),
            'sources' => json_encode($sources, JSON_UNESCAPED_UNICODE),
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
