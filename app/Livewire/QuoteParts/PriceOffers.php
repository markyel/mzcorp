<?php

namespace App\Livewire\QuoteParts;

use App\Livewire\Concerns\AnswersQuotePartsSuppliers;
use App\Livewire\Concerns\FiltersByRfqAuthor;
use App\Models\CatalogItem;
use App\Models\PricePosition;
use App\Models\QuotePartsOffer;
use App\Models\QuotePartsRfq;
use App\Services\Iqot\IqotCurrencyConverter;
use App\Services\Procurement\SupplierChoiceService;
use App\Services\QuoteParts\PriceActualizationService;
use App\Services\QuoteParts\PriceRefreshReadiness;
use App\Services\QuoteParts\QuotePartsPriceService;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Support\Collection;
use App\Services\QuoteParts\OfferClarifications;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Вкладка «Актуализация цен» раздела QuoteParts: свежие предложения поставщиков из QuoteParts
 * (форма и письмо по запросу, кабинет, прайс) рядом с ценами каталога — закупочной и продажной.
 *
 * Позиция — главная единица, предложения — её строки. Разделы: «Новое предложение по
 * обработанной позиции» (сбор цен завершён, а предложение пришло), «Готово к актуализации»
 * (PriceRefreshReadiness), «Ждём ответа и отказы». Выбор поставщика, список выгрузки 1С,
 * выгрузка и завершение сбора цен — PriceActualizationService. Позицию можно раскрыть — полная
 * карточка каталога.
 */
class PriceOffers extends Component
{
    use AnswersQuotePartsSuppliers;
    use FiltersByRfqAuthor;
    use WithFileUploads;

    public const DAYS = [3, 7, 30, 90];

    /** Оформление одно — позиция и её предложения; «по запросам» — тот же список, упорядоченный по заявкам. */
    public const VIEWS = ['item' => 'По позициям', 'request' => 'По запросам'];

    /** Фильтр «Готово к актуализации» по решению. */
    public const DECISIONS = ['all' => 'Все', 'open' => 'Без решения', 'buy' => 'Закупаем', 'ignore' => 'Игнор'];

    #[Url(as: 'pd', except: 7)]
    public int $days = 7;

    #[Url(as: 'pv', except: 'item')]
    public string $view = 'item';

    #[Url(as: 'pf', except: 'all')]
    public string $decision = 'all';

    /** Поиск вкладки: артикул, позиция, поставщик, заявка, QP. */
    #[Url(as: 'pq', except: '')]
    public string $q = '';

    /** Итог последнего действия выгрузки — в нижней панели. */
    public ?string $exportNote = null;

    /** Показывать и заменённые / истёкшие предложения. */
    #[Url(as: 'pa', except: false)]
    public bool $withStale = false;


    /** Открытая переписка по отказу: id строки отказа (declines). */
    public ?string $declineDialog = null;

    /** @var list<int> раскрытые карточки позиций каталога */
    public array $openItems = [];

    /** Предложение, по которому открыт диалог с поставщиком (уточнить цену). */
    public ?int $dialog = null;

    /** «Закупаем здесь»: это предложение — закупочное для позиции (выбор снабжения, идёт в выгрузку 1С). Другое выбранное снимается. */
    public function choose(int $offerId, PriceActualizationService $service): void
    {
        $o = QuotePartsOffer::query()->findOrFail($offerId);
        try {
            $service->choose($o, auth()->user());
        } catch (\DomainException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }
        $this->exportNote = null;
        $this->forget();
    }

    /** «+ В выгрузку»: позиция с выбранным предложением — в список выгрузки 1С. */
    public function addToExport(string $sku, PriceActualizationService $service): void
    {
        $it = $this->screenItems()->firstWhere('sku', $sku);
        if ($it === null || ! $it['cid']) {
            return;
        }
        try {
            $service->add($sku, (int) $it['cid'], self::liveOffers($it), auth()->user());
        } catch (\DomainException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }
        $this->exportNote = null;
        $this->forget();
    }

    public function removeFromExport(string $sku, PriceActualizationService $service): void
    {
        $service->remove($sku);
        $this->exportNote = null;
        $this->forget();
    }

    /** «+ Добавить все с выбранным поставщиком». */
    public function addAllChosen(PriceActualizationService $service): void
    {
        foreach ($this->bar['addable'] as $it) {
            $service->add($it['sku'], (int) $it['cid'], self::liveOffers($it), auth()->user());
        }
        $this->exportNote = null;
        $this->forget();
    }

    /**
     * «Выгрузить в 1С» — весь список, сколько угодно раз: позиции остаются на экране, сбор цен
     * продолжается. «Выгрузить и завершить сбор цен» — весь список, и позиции уходят с экрана: работа
     * по ним закончена до нового предложения. Файл сразу скачивается.
     */
    public function exportList(bool $finish, PriceActualizationService $service)
    {
        // В списке бывает и завершённая позиция: вернулась с новым предложением и выгружается снова.
        $list = PricePosition::query()->whereNotNull('in_export_at')->get();
        if ($list->isEmpty()) {
            return null;
        }
        try {
            $export = $service->export($list, $finish, auth()->user());
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', type: 'error', message: 'Не удалось сформировать выгрузку: '.mb_substr($e->getMessage(), 0, 300));

            return null;
        }
        $this->exportNote = $finish
            ? 'Завершено: '.$list->count().' поз. выгружено и убрано с экрана. Вернутся, если придёт новое предложение.'
            : 'Выгружено в 1С: '.$list->count().' поз. Сбор цен по ним продолжается.';
        $this->forget();

        return \Illuminate\Support\Facades\Storage::disk(config('procurement.price_export.disk', 'local'))->download($export->file_path, basename($export->file_path));
    }

    /** Действующие предложения позиции на экране — моделями. */
    private static function liveOffers(array $it): Collection
    {
        return QuotePartsOffer::query()->whereIn('id', $it['offers']->reject(fn ($r) => $r['stale'])->pluck('id'))->get();
    }

    /** Сбросить посчитанное: решения, список выгрузки, разделы. */
    private function forget(): void
    {
        unset($this->rows, $this->readyRows, $this->readyItems, $this->items, $this->groups, $this->choices, $this->bestIds,
            $this->positions, $this->newItems, $this->readyList, $this->waitItems, $this->bar, $this->clarifications);
    }

    public function clearChoice(int $catalogItemId, SupplierChoiceService $choices): void
    {
        $choices->clear($catalogItemId);
        // Поставщик снят — в выгрузке позиции без него делать нечего.
        PricePosition::query()->where('catalog_item_id', $catalogItemId)
            ->update(['in_export_at' => null, 'in_export_by_user_id' => null, 'exported_at' => null]);
        $this->forget();
    }

    /** Комментарий к предложению в выгрузке 1С: поправка снабжения (null — снова собирается сам). */
    public function saveComment(int $offerId, ?string $text, QuotePartsPriceService $prices): void
    {
        $prices->setExportComment(QuotePartsOffer::query()->findOrFail($offerId), $text, auth()->user());
        $this->forget();
    }

    /** «Игнорировать» / «Вернуть»: предложение не о том — его цена не идёт в выгрузку 1С, в «лучшее» и в закупку. */
    public function ignore(int $offerId, bool $ignore, QuotePartsPriceService $prices): void
    {
        $prices->ignore(QuotePartsOffer::query()->findOrFail($offerId), $ignore, auth()->user());
        $this->forget();
        $this->dispatch('toast', type: 'success', message: $ignore ? 'Игнорируем: цена не пойдёт в выгрузку 1С.' : 'Предложение снова учитывается.');
    }

    /** Отклонение от оригинала (аналог, замена…): учитывать как цену позиции (и в выгрузке) или нет. */
    public function review(int $offerId, bool $accept, QuotePartsPriceService $prices): void
    {
        $prices->review(QuotePartsOffer::query()->findOrFail($offerId), $accept, auth()->user());
        $this->forget();
        $this->dispatch('toast', type: 'success', message: $accept ? 'Учитываем: цена идёт как цена позиции, в выгрузке — с примечанием.' : 'Не учитываем это предложение.');
    }

    /** Диалог с поставщиком по предложению: переписка запроса QuoteParts или обращения. */
    public function openDialog(int $offerId, QuotePartsRfqService $rfqs): void
    {
        $this->dialog = $this->dialog === $offerId ? null : $offerId;
        if ($this->dialog !== null && ($rfq = $this->dialogRfq) !== null) {
            try {
                $rfqs->refresh($rfq);
            } catch (Throwable) {
                // Покажем последний снимок.
            }
            unset($this->dialogRfq);
        }
    }

    /** Переписка с поставщиком по запросу, на который он ответил отказом. */
    public function openDeclineDialog(string $id, QuotePartsRfqService $rfqs): void
    {
        $this->declineDialog = $this->declineDialog === $id ? null : $id;
        if ($this->declineDialog !== null && ($rfq = $this->declineRfq) !== null) {
            try {
                $rfqs->refresh($rfq);
            } catch (Throwable) {
                // Покажем последний снимок.
            }
            unset($this->declineRfq);
        }
    }

    #[Computed]
    public function declineRfq(): ?QuotePartsRfq
    {
        $row = $this->declineDialog ? $this->allDeclines->firstWhere('id', $this->declineDialog) : null;

        return $row ? QuotePartsRfq::query()->where('remote_id', $row['rfq_remote_id'])->first() : null;
    }

    /**
     * Отказы поставщиков по позициям запросов QuoteParts за окно запросов (из снимков запросов):
     * в mzCorp их нет среди предложений, а снабжению важно видеть, кто уже отказал и почему.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function allDeclines(): Collection
    {
        $rfqs = $this->windowRfqs;
        $names = $this->supplierNames;

        $rows = collect();
        foreach ($rfqs as $rfq) {
            foreach ((array) ($rfq->snapshot['suppliers'] ?? []) as $s) {
                $key = (string) ($s['external_id'] ?? '');
                $at = $s['answered_at'] ?? $s['last_reply_at'] ?? $s['sent_at'] ?? null;
                foreach ((array) ($s['lines'] ?? []) as $line) {
                    if (($line['status'] ?? '') !== 'declined' || empty($line['code'])) {
                        continue;
                    }
                    $rows->push([
                        'id' => 'd'.$rfq->remote_id.'-'.$key.'-'.$line['code'],
                        'decline' => true,
                        'at' => $at ? \Illuminate\Support\Carbon::parse($at) : $rfq->synced_at,
                        'request_id' => $rfq->request_id,
                        'request' => $rfq->request,
                        'rfq_tag' => $rfq->tag,
                        'rfq_remote_id' => $rfq->remote_id,
                        'sku' => (string) $line['code'],
                        'line_name' => $line['name'] ?? null,
                        'supplier' => (string) ($names[$key] ?? $key),
                        'key' => $key,
                        'reason' => $line['decline_reason'] ?? null,
                    ]);
                }
            }
        }

        $catalog = CatalogItem::query()->whereIn('sku', $rows->pluck('sku')->unique())
            ->get(['id', 'sku', 'name', 'price', 'purchase_price', 'is_price_actual'])->keyBy('sku');
        $rows = $rows->map(function ($r) use ($catalog) {
            $ci = $catalog->get($r['sku']);

            return $r + [
                'cid' => $ci?->id,
                'name' => $ci?->name ?? $r['line_name'],
                'purchase' => $ci?->purchase_price !== null ? (float) $ci->purchase_price : null,
                'sale' => $ci?->price !== null ? (float) $ci->price : null,
                'actual' => (bool) ($ci?->is_price_actual ?? false),
            ];
        });

        if (($term = trim($this->q)) !== '') {
            $rows = $rows->filter(fn ($r) => mb_stripos(implode(' ', [
                $r['sku'], $r['name'], $r['supplier'], $r['rfq_tag'], $r['request']?->internal_code, $r['reason'],
            ]), $term) !== false);
        }

        return $rows->sortByDesc(fn ($r) => $r['at']?->getTimestamp() ?? 0)->values();
    }

    /** Запросы QuoteParts за окно запросов. */
    #[Computed]
    public function windowRfqs(): Collection
    {
        return QuotePartsRfq::query()->with('request:id,internal_code,client_name,subject,assigned_user_id', 'request.assignedUser:id,name')
            ->where('created_at', '>=', now()->subDays(PriceRefreshReadiness::WINDOW_DAYS))->get();
    }

    /** @return array<string, string> ключ поставщика => название */
    #[Computed]
    public function supplierNames(): array
    {
        return collect(app(QuotePartsRfqService::class)->supplierNames($this->windowRfqs))->all();
    }

    /** Отказы за выбранный период. */
    #[Computed]
    public function declines(): Collection
    {
        $since = now()->subDays(in_array($this->days, self::DAYS, true) ? $this->days : 7);

        return $this->allDeclines->filter(fn ($r) => $r['at'] && $r['at']->gte($since))->values();
    }

    #[Computed]
    public function dialogOffer(): ?QuotePartsOffer
    {
        return $this->dialog ? QuotePartsOffer::query()->find($this->dialog) : null;
    }

    #[Computed]
    public function dialogRfq(): ?QuotePartsRfq
    {
        $remote = $this->dialogOffer?->rfq_remote_id;

        return $remote ? QuotePartsRfq::query()->where('remote_id', $remote)->first() : null;
    }

    /** Поставщик предложения без запроса — для обращений через QuoteParts. */
    #[Computed]
    public function dialogSupplier(): ?\App\Models\Supplier
    {
        $key = (string) ($this->dialogOffer?->supplier_key ?? '');
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');

        return match ($kind) {
            'org' => \App\Models\Supplier::query()->where('supplier_organization_id', (int) $id)->orderByRaw('quoteparts_link_id is null')->orderBy('id')->first(),
            'sup' => \App\Models\Supplier::query()->find((int) $id),
            default => null,
        };
    }

    public function refreshQuotePartsRfq(int $id, QuotePartsRfqService $rfqs): void
    {
        try {
            $rfqs->refresh(QuotePartsRfq::query()->findOrFail($id));
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 200));
        }
        unset($this->dialogRfq, $this->declineRfq);
    }

    protected function quotePartsRfqForAnswer(int $id): QuotePartsRfq
    {
        return QuotePartsRfq::query()->findOrFail($id);
    }

    /** Написали поставщику из диалога по предложению — предложение ждёт его ответа. */
    protected function afterQuotePartsAnswer(): void
    {
        $this->markAsked();
        unset($this->dialogRfq);
    }

    #[On('quoteparts-conversation-sent')]
    public function onConversationSent(string $supplierKey = ''): void
    {
        if ($this->dialogOffer?->supplier_key === $supplierKey) {
            $this->markAsked();
        }
    }

    private function markAsked(): void
    {
        if ($o = $this->dialogOffer) {
            $o->forceFill(['clarify_asked_at' => now()])->save();
            unset($this->dialogOffer, $this->rows, $this->readyRows, $this->clarifications, $this->readyItems, $this->items, $this->groups);
        }
    }

    /**
     * Сколько писем в ветке запроса с поставщиком предложения (наш запрос, его ответы, наши
     * уточнения) — для кнопки «переписка» в строке.
     *
     * @return array<int, int> id предложения => писем
     */
    #[Computed]
    public function threadCounts(): array
    {
        $rows = $this->rows->merge($this->readyRows)->merge($this->newItems->flatMap(fn ($i) => $i['offers']))->unique('id');
        $snapshots = QuotePartsRfq::query()->whereIn('remote_id', $rows->pluck('rfq_remote_id')->filter()->unique()->values())
            ->get(['remote_id', 'snapshot'])->keyBy('remote_id');
        $out = [];
        foreach ($rows as $r) {
            $s = $r['rfq_remote_id'] ? collect($snapshots->get($r['rfq_remote_id'])?->snapshot['suppliers'] ?? [])->firstWhere('external_id', $r['key']) : null;
            if ($s !== null) {
                $out[$r['id']] = count($s['letters'] ?? []) + count($s['thread'] ?? []);
            }
        }

        return $out;
    }

    /** «Решить без ответа»: снять ожидание уточнения — решение по предложению можно принимать. */
    public function clearClarification(int $offerId): void
    {
        QuotePartsOffer::query()->whereKey($offerId)->update(['clarify_cleared_at' => now()]);
        unset($this->rows, $this->readyRows, $this->clarifications, $this->readyItems, $this->items, $this->groups);
    }

    /**
     * Уточнения по предложениям на экране: ждём ответа / ответил / не ответил в срок.
     *
     * @return array<int, array{state:string, asked_at:\Illuminate\Support\Carbon, until:\Illuminate\Support\Carbon, answered_at:?\Illuminate\Support\Carbon}>
     */
    #[Computed]
    public function clarifications(): array
    {
        return app(OfferClarifications::class)->states($this->rows->merge($this->readyRows)->merge($this->newItems->flatMap(fn ($i) => $i['offers']))->unique('id')->values());
    }

    /**
     * Лучшее предложение каждой позиции на экране: самое дешёвое в рублях среди действующих и
     * годных к закупке (оригинал или отклонение, которое решили учитывать).
     *
     * @return list<int>
     */
    #[Computed]
    public function bestIds(): array
    {
        return $this->rows->merge($this->readyItems->flatMap(fn ($i) => $i['offers']))
            ->filter(fn ($r) => $r['sku'] && $r['rub'] !== null && ! $r['stale'] && $r['counts'])
            ->groupBy('sku')
            // Выделять есть из чего, когда предложений на позицию хотя бы два.
            ->filter(fn ($g) => $g->pluck('id')->unique()->count() > 1)
            ->map(fn ($g) => $g->sortBy('rub')->first()['id'])
            ->values()->all();
    }

    /** Выбранные поставщики позиций на экране (catalog_item_id => выбор). */
    #[Computed]
    public function choices(): Collection
    {
        $ids = $this->rows->pluck('cid')->merge($this->readyItems->pluck('cid'))->merge($this->newItems->pluck('cid'))->filter()->unique()->values()->all();

        return app(SupplierChoiceService::class)->forItems($ids);
    }

    /** Последняя закупка из 1С (цена, поставщик, дата) по позициям на экране. */
    #[Computed]
    public function lastPurchases(): Collection
    {
        $ids = $this->rows->pluck('cid')->merge($this->readyItems->pluck('cid'))->merge($this->newItems->pluck('cid'))->filter()->unique()->values()->all();

        return $ids === [] ? collect() : \App\Models\CatalogSupplierPrice::query()->whereIn('catalog_item_id', $ids)->where('kind', 'last')
            ->orderByDesc('priced_at')->get()->unique('catalog_item_id')->keyBy('catalog_item_id');
    }

    public function toggleItem(int $catalogItemId): void
    {
        $this->openItems = in_array($catalogItemId, $this->openItems, true)
            ? array_values(array_diff($this->openItems, [$catalogItemId]))
            : [...$this->openItems, $catalogItemId];
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function rows(): Collection
    {
        $since = now()->subDays(in_array($this->days, self::DAYS, true) ? $this->days : 7);

        return $this->query()
            ->where(fn ($q) => $q->where('remote_updated_at', '>=', $since)->orWhere('created_at', '>=', $since))
            ->limit(500)
            ->get()
            ->pipe(fn (Collection $offers) => self::rowsOf($offers));
    }

    /**
     * Строки предложений; новому товару без M-артикула (предварительная позиция T-…) — его
     * название из заявки: в каталоге его ещё нет, а без названия строка ни о чём (21.10, T-VZQ4BU).
     *
     * @param  Collection<int, QuotePartsOffer>  $offers
     * @return Collection<int, array<string, mixed>>
     */
    private static function rowsOf(Collection $offers): Collection
    {
        $draftCodes = $offers->filter(fn (QuotePartsOffer $o) => $o->catalogItem === null && $o->sku)->pluck('sku')->unique()->values();
        $draftNames = $draftCodes->isEmpty() ? collect() : \App\Models\RequestItem::query()
            ->whereIn('quoteparts_code', $draftCodes)->whereNotNull('parsed_name')
            ->orderBy('id')->get(['quoteparts_code', 'parsed_name'])->unique('quoteparts_code')->pluck('parsed_name', 'quoteparts_code');

        // toBase: пустая выборка иначе остаётся Eloquent-коллекцией, и её merge() со строками-массивами падает (getKey() on array).
        return $offers->toBase()->map(fn (QuotePartsOffer $o) => self::row($o, $draftNames->get((string) $o->sku)))->values();
    }

    private function query()
    {
        $term = trim($this->q);

        return QuotePartsOffer::query()
            ->with(['catalogItem:id,sku,name,brand,price,purchase_price,is_price_actual', 'request:id,internal_code,client_name,subject,assigned_user_id', 'request.assignedUser:id,name'])
            ->when(! $this->withStale, fn ($q) => $q->where('superseded', false)->whereIn('status', ['active', 'expiring']))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('sku', 'ilike', '%'.$term.'%')
                ->orWhere('supplier_name', 'ilike', '%'.$term.'%')
                ->orWhere('part_number', 'ilike', '%'.$term.'%')
                ->orWhere('rfq_tag', 'ilike', '%'.$term.'%')
                ->orWhereHas('catalogItem', fn ($c) => $c->where('name', 'ilike', '%'.$term.'%'))
                ->orWhereHas('request', fn ($r) => $r->where('internal_code', 'ilike', '%'.$term.'%'))))
            ->orderByDesc('remote_updated_at');
    }

    /** Кого о позиции спросили и кто ответил — по запросам QuoteParts (артикул => сводка). */
    #[Computed]
    public function readiness(): array
    {
        return app(PriceRefreshReadiness::class)->stats();
    }

    /**
     * Позиции, по которым предложений достаточно для актуализации цены: со всеми их действующими
     * предложениями за окно запросов, не только за выбранный период.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function readyItems(): Collection
    {
        // Ждём ответа на уточнение — позиция остаётся здесь, её решение — «запрошено уточнение».
        $skus = $this->readyRows->pluck('sku')->filter()->unique()->all();

        return $this->itemsFrom($this->readyRows, $this->allDeclines->whereIn('sku', $skus))->values();
    }

    /**
     * Предложения позиций, где предложений уже достаточно (PriceRefreshReadiness), за окно запросов.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function readyRows(): Collection
    {
        $checker = app(PriceRefreshReadiness::class);
        $ready = array_keys(array_filter($this->readiness, fn ($st) => $checker->isReady($st)));
        if ($ready === []) {
            return collect();
        }

        return $this->query()->whereIn('sku', $ready)
            ->where('created_at', '>=', now()->subDays(PriceRefreshReadiness::WINDOW_DAYS))
            ->get()->pipe(fn (Collection $offers) => self::rowsOf($offers));
    }

    /** @return Collection<int, array<string, mixed>> позиция и все предложения на неё */
    #[Computed]
    public function items(): Collection
    {
        $ready = $this->readyItems->pluck('sku')->all();
        $rows = $this->rows->merge($this->readyRows->reject(fn ($r) => in_array($r['sku'], $ready, true)))->unique('id');

        return $this->itemsFrom($rows->reject(fn ($r) => in_array($r['sku'], $ready, true)), $this->declines->reject(fn ($r) => in_array($r['sku'], $ready, true)))->values();
    }

    /**
     * Позиции из предложений и отказов: позиция, где поставщики только отказали, тоже в списке.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, array<string, mixed>>|null  $declines
     * @return Collection<int, array<string, mixed>>
     */
    private function itemsFrom(Collection $rows, ?Collection $declines = null): Collection
    {
        $checker = app(PriceRefreshReadiness::class);
        $declinesBySku = ($declines ?? collect())->groupBy('sku');
        $bySku = $rows->groupBy(fn ($r) => $r['sku'] ?: 'offer-'.$r['id']);
        foreach ($declinesBySku->keys() as $sku) {
            if (! $bySku->has($sku)) {
                $bySku->put($sku, collect());
            }
        }

        return $bySku
            ->map(function (Collection $offers, string $sku) use ($checker, $declinesBySku) {
                $itemDeclines = $declinesBySku->get($sku, collect())->values();
                $first = $offers->first() ?? $itemDeclines->first();
                $st = $this->readiness[$sku] ?? null;
                // Сначала оригиналы, потом копии и аналоги; внутри — дешевле выше.
                $sorted = $offers->sortBy(fn ($r) => [($r['kind'] ?? 'original') === 'original' ? 0 : 1, $r['rub'] ?? PHP_FLOAT_MAX])->values();

                return [
                    'sku' => $first['sku'],
                    'cid' => $first['cid'],
                    'name' => $first['name'],
                    'purchase' => $first['purchase'],
                    'sale' => $first['sale'],
                    'actual' => $first['actual'],
                    'offers' => $sorted,
                    'declines' => $itemDeclines,
                    'best' => $sorted->first(fn ($r) => $r['rub'] !== null && ! $r['stale'] && $r['counts']),
                    'asked' => $st ? count($st['asked']) : null,
                    'offered' => $st ? count($st['offered']) : null,
                    'declined' => $st ? count($st['declined']) : null,
                    'requested_at' => $st['requested_at'] ?? null,
                    'wait_until' => $st ? $checker->waitUntil($st['requested_at']) : null,
                    'rfqs' => $st['rfqs'] ?? [],
                    // Спросили, а он ни цены, ни отказа: строка «ждём ответ».
                    'waiting' => $st ? array_values(array_map(fn ($k) => $this->supplierNames[$k] ?? $k,
                        array_diff($st['asked'], $st['offered'], $st['declined'], $offers->pluck('key')->filter()->all()))) : [],
                    'at' => $offers->merge($itemDeclines)->max('at'),
                ];
            })
            ->sortByDesc(fn ($i) => $i['at']?->getTimestamp() ?? 0);
    }

    /**
     * Группы по заявкам: свежий ответ — выше, «Без заявки» — последней. Внутри — позиции с их
     * предложениями, как в «Готово к актуализации» (те позиции здесь не повторяются).
     *
     * @return Collection<int, array{key:string, request:?\App\Models\Request, rfqs:list<string>, rows:Collection, items:Collection, at:mixed}>
     */
    #[Computed]
    public function groups(): Collection
    {
        $ready = $this->readyItems->pluck('sku')->all();
        $groupKey = fn ($r) => $r['request_id'] ? 'r'.$r['request_id'] : 'none';
        $offers = $this->rows->reject(fn ($r) => in_array($r['sku'], $ready, true))->groupBy($groupKey);
        $declines = $this->declines->reject(fn ($r) => in_array($r['sku'], $ready, true))->groupBy($groupKey);

        return $offers->keys()->merge($declines->keys())->unique()->values()
            ->map(function (string $key) use ($offers, $declines) {
                $rows = $offers->get($key, collect());
                $refused = $declines->get($key, collect());
                $all = $rows->merge($refused);

                return [
                    'key' => $key,
                    'request' => $all->first()['request'],
                    'rfqs' => $all->pluck('rfq_tag')->filter()->unique()->values()->all(),
                    'rows' => $rows,
                    'declines' => $refused,
                    'items' => $this->itemsFrom($rows, $refused)->values(),
                    'at' => $all->max('at'),
                ];
            })
            ->sortBy(fn ($g) => [$g['key'] === 'none' ? 1 : 0, -($g['at']?->getTimestamp() ?? 0)])
            ->values();
    }

    /** Позиции в работе снабжения (список выгрузки, выгружено, завершено): артикул => позиция. */
    #[Computed]
    public function positions(): Collection
    {
        return PricePosition::query()->get()->keyBy('sku');
    }

    /** Артикулы, по которым сбор цен завершён. */
    private function closedSkus(): array
    {
        return $this->positions->filter(fn (PricePosition $p) => $p->closed_at !== null)->keys()->all();
    }

    /**
     * «Новое предложение по обработанной позиции»: сбор цен завершён, а после этого пришло
     * предложение. Новые — отмечены «новое».
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function newItems(): Collection
    {
        $closed = $this->positions->filter(fn (PricePosition $p) => $p->closed_at !== null);
        if ($closed->isEmpty()) {
            return collect();
        }
        $rows = $this->query()->whereIn('sku', $closed->keys()->all())
            ->where('created_at', '>=', now()->subDays(PriceRefreshReadiness::WINDOW_DAYS))
            ->get()->pipe(fn (Collection $offers) => self::rowsOf($offers));

        return $this->byAuthor($this->itemsFrom($rows)
            ->filter(fn ($it) => $it['offers']->contains(fn ($r) => $r['created'] && $r['created']->gt($closed[$it['sku']]->closed_at)))
            ->values());
    }

    /**
     * Кто запрашивал позицию у поставщиков: артикул => авторы запросов QuoteParts за окно.
     *
     * @return array<string, list<int>>
     */
    #[Computed]
    public function skuAuthors(): array
    {
        $out = [];
        foreach ($this->windowRfqs as $rfq) {
            if ($rfq->created_by_user_id === null) {
                continue;
            }
            foreach ((array) ($rfq->snapshot['suppliers'] ?? []) as $s) {
                foreach ((array) ($s['lines'] ?? []) as $line) {
                    if (! empty($line['code'])) {
                        $out[(string) $line['code']][(int) $rfq->created_by_user_id] = true;
                    }
                }
            }
        }

        return array_map('array_keys', $out);
    }

    /** Фильтр «Чьи запросы»: позиции, которые запрашивал выбранный сотрудник (без запроса — только во «Всех»). */
    private function byAuthor(Collection $items): Collection
    {
        $id = $this->authorId();

        return $id === null ? $items : $items->filter(fn ($it) => in_array($id, $this->skuAuthors[(string) $it['sku']] ?? [], true))->values();
    }

    /** «Готово к актуализации» без завершённых позиций; фильтр по решению, порядок вида. */
    #[Computed]
    public function readyList(): Collection
    {
        $closed = $this->closedSkus();

        return $this->byAuthor($this->readyItems->reject(fn ($it) => in_array($it['sku'], $closed, true))->values());
    }

    /** «Ждём ответа и отказы»: остальные позиции периода — мало цен, ждём или все отказали. */
    #[Computed]
    public function waitItems(): Collection
    {
        $closed = $this->closedSkus();

        return $this->sorted($this->byAuthor($this->items->reject(fn ($it) => in_array($it['sku'], $closed, true))->values()));
    }

    /** Все позиции на экране: новое, готово, ждём. */
    public function screenItems(): Collection
    {
        return $this->newItems->merge($this->readyList)->merge($this->waitItems);
    }

    /** Вид «по запросам»: позиции одной заявки рядом. */
    public function sorted(Collection $items): Collection
    {
        if ($this->view !== 'request') {
            return $items;
        }

        return $items->sortBy(fn ($it) => $it['offers']->map(fn ($r) => $r['request']?->internal_code)->filter()->min() ?? 'я')->values();
    }

    /**
     * Решение по позиции: buy — выбран поставщик из её предложений, ignore — все предложения
     * отмечены «Игнорировать», ask — задан уточняющий вопрос, open — решения нет.
     *
     * @return array{state:string, chosen:?int}
     */
    public function stateOf(array $it): array
    {
        $choice = $it['cid'] ? $this->choices->get($it['cid']) : null;
        $ids = $it['offers']->pluck('id')->all();
        $chosen = $choice && $choice->source === 'quoteparts' && in_array((int) $choice->offer_ref, $ids, true) ? (int) $choice->offer_ref : null;
        $live = $it['offers']->reject(fn ($r) => $r['stale']);

        return ['chosen' => $chosen, 'state' => match (true) {
            $chosen !== null => 'buy',
            $live->isNotEmpty() && $live->every(fn ($r) => $r['ignored']) => 'ignore',
            $live->contains(fn ($r) => isset($this->clarifications[$r['id']])) => 'ask',
            default => 'open',
        }];
    }

    /**
     * Нижняя панель: сколько решено в «Готово», список выгрузки и его сумма, что выгружать.
     *
     * @return array{done:int, total:int, inList:int, sum:float, addable:Collection}
     */
    #[Computed]
    public function bar(): array
    {
        $ready = $this->readyList;
        $list = $this->positions->filter(fn (PricePosition $p) => $p->in_export_at !== null);
        $choices = app(SupplierChoiceService::class)->forItems($list->pluck('catalog_item_id')->filter()->values()->all());
        $sum = $list->sum(function (PricePosition $p) use ($choices) {
            $c = $choices->get($p->catalog_item_id);
            $rub = $c ? IqotCurrencyConverter::toRub($c->price !== null ? (float) $c->price : null, $c->currency) : null;

            return $rub && $rub['known'] ? (float) $rub['rub'] : 0.0;
        });

        return [
            'done' => $ready->filter(fn ($it) => $this->stateOf($it)['state'] !== 'open')->count(),
            'total' => $ready->count(),
            'inList' => $list->count(),
            'sum' => $sum,
            'addable' => $this->newItems->merge($ready)
                ->filter(fn ($it) => $it['cid'] && $this->stateOf($it)['chosen'] !== null && ! ($this->positions->get($it['sku'])?->in_export_at))
                ->values(),
        ];
    }

    /** Раскрытые карточки: позиция каталога и последнее изменение её цены. */
    #[Computed]
    public function openCards(): array
    {
        if ($this->openItems === []) {
            return [];
        }
        $cards = CatalogItem::query()->whereIn('id', $this->openItems)->get()->keyBy('id');
        $pcs = \App\Models\CatalogPriceChange::query()->whereIn('catalog_item_id', $this->openItems)
            ->orderByDesc('changed_at')->orderByDesc('id')->get()->unique('catalog_item_id')->keyBy('catalog_item_id');

        return $cards->map(fn ($c) => ['cat' => $c, 'pc' => $pcs->get($c->id)])->all();
    }

    /** @return array<string, mixed> */
    private static function row(QuotePartsOffer $o, ?string $draftName = null): array
    {
        $ci = $o->catalogItem;
        $rub = IqotCurrencyConverter::toRub($o->price !== null ? (float) $o->price : null, $o->currency);
        $purchase = $ci?->purchase_price !== null ? (float) $ci->purchase_price : null;

        return [
            'id' => $o->id,
            'at' => $o->remote_updated_at ?? $o->created_at,
            'created' => $o->created_at,
            'request_id' => $o->request_id,
            'request' => $o->request,
            'rfq_tag' => $o->rfq_tag,
            'cid' => $o->catalog_item_id,
            'sku' => $ci?->sku ?? $o->sku,
            // Новый товар (T-…) — название из заявки, иначе как назвал поставщик.
            'name' => $ci?->name ?? $draftName ?? ($o->product_name ?: null),
            'supplier' => (string) ($o->supplier_name ?: $o->supplier_key),
            // Цена агента — от какого реального поставщика (UniSystem → Sicor).
            'origin' => $o->origin_supplier,
            'key' => $o->supplier_key,
            'counts' => $o->countsAsItemPrice(),
            'rfq_remote_id' => $o->rfq_remote_id,
            'clarify_asked_at' => $o->clarify_asked_at,
            'clarify_cleared_at' => $o->clarify_cleared_at,
            'source' => $o->source_type,
            'kind' => $o->kind ?? 'original',
            'product' => trim(($o->brand ?? '').' '.($o->part_number ?? '')),
            'review' => $o->review_status,
            'ignored' => $o->ignored_at !== null,
            // Комментарий в выгрузке 1С: поправленный снабжением или собранный.
            'export_comment' => $o->export_comment ?? \App\Services\Procurement\PriceExportService::offerComment($o),
            'comment_edited' => $o->export_comment !== null,
            'tiers' => (array) ($o->tiers ?? []),
            'price' => $o->price !== null ? (float) $o->price : null,
            'currency' => $o->currency,
            'rub' => $rub['known'] ? $rub['rub'] : null,
            'lead' => $o->lead_time_days,
            'valid' => $o->valid_until,
            'note' => $o->note,
            'stale' => $o->superseded || ! in_array($o->status, ['active', 'expiring'], true),
            'purchase' => $purchase,
            'sale' => $ci?->price !== null ? (float) $ci->price : null,
            'actual' => (bool) ($ci?->is_price_actual ?? false),
            // С закупкой не сравниваем: в ней логистика и таможня, цена поставщика — без них.
        ];
    }

    public function render()
    {
        if (! array_key_exists($this->view, self::VIEWS)) {
            $this->view = $this->view === 'list' ? 'item' : 'request';
        }

        return view('livewire.quoteparts.price-offers');
    }
}
