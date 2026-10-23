<?php

namespace App\Services\Procurement;

use App\Models\QuotePartsOffer;
use App\Models\QuotePartsRfq;
use App\Models\RequestItem;
use App\Models\SupplierInquiry;
use App\Models\SupplierInquiryItem;
use App\Services\Iqot\IqotCurrencyConverter;
use App\Services\QuoteParts\QuotePartsRfqService;
use App\Services\QuoteParts\QuotePartsThread;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Вкладка «Поставщики» заявки (дизайн «Request — Поставщики», 10.2026): кого спросили, кто ответил
 * и почём (оригинал или копия), что ждёт нас. Письма рассылки и запросы QuoteParts — вместе:
 *  - сравнение цен по позициям (строка — предложение; у поставщика может быть несколько);
 *  - поставщики и переписка (строка — поставщик, лента писем и событий);
 *  - вопросы поставщиков (группы QuoteParts) и сводка.
 * Выбор цены для 1С — SupplierChoiceService (общий с «Актуализацией цен»): один на позицию.
 */
class RequestSupplierOverview
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(
        private readonly int $requestId,
        private readonly Collection $rfqs,
    ) {}

    public static function for(int $requestId): self
    {
        return new self($requestId, QuotePartsRfq::query()->where('request_id', $requestId)->with('createdBy:id,name')->latest('id')->get());
    }

    public function rfqs(): Collection
    {
        return $this->rfqs;
    }

    /** @return array<string, string> ключ поставщика QuoteParts => название */
    public function supplierNames(): array
    {
        return $this->cache['names'] ??= collect(app(QuotePartsRfqService::class)->supplierNames($this->rfqs))->all();
    }

    /** @return Collection<int, SupplierInquiry> */
    public function inquiries(): Collection
    {
        return $this->cache['inquiries'] ??= SupplierInquiry::query()->where('related_request_id', $this->requestId)
            ->withCount(['items', 'messages'])->with('createdBy:id,name')->orderByDesc('id')->get();
    }

    /**
     * Сравнение цен: позиции, по которым спрашивали поставщиков, и все ответы на них.
     *
     * @return list<array<string, mixed>>
     */
    public function comparison(): array
    {
        if (isset($this->cache['comparison'])) {
            return $this->cache['comparison'];
        }
        $items = RequestItem::query()->where('request_id', $this->requestId)->where('is_active', true)
            ->with(['catalogItem:id,sku,name', 'brand:id,name'])->orderBy('position')->get();
        $byCode = [];
        foreach ($items as $it) {
            foreach (array_filter([$it->catalogItem?->sku, $it->quoteparts_code]) as $code) {
                $byCode[mb_strtoupper($code)] = $it->id;
            }
        }
        $rows = [];

        // Письма рассылки: ответ поставщика по позиции (цена / отказ) или ждём.
        $sii = SupplierInquiryItem::query()->whereIn('request_item_id', $items->pluck('id'))
            ->with(['inquiry:id,supplier_email,supplier_name,created_at', 'offers'])->get();
        $mailKeys = SupplierChoiceService::keysByEmail($sii->pluck('inquiry.supplier_email'));
        foreach ($sii as $x) {
            $inq = $x->inquiry;
            if ($inq === null) {
                continue;
            }
            $o = $x->offers->sortByDesc('id')->first();
            $base = [
                'supplier' => (string) ($inq->supplier_name ?: $inq->supplier_email), 'channel' => 'mail',
                'key' => $mailKeys[mb_strtolower(trim((string) $inq->supplier_email))] ?? null, 'inquiry_id' => (int) $inq->id,
            ];
            if ($o && $o->outcome === 'quoted' && $o->price !== null) {
                $rub = IqotCurrencyConverter::toRub((float) $o->price, $o->currency);
                $rows[$x->request_item_id][] = $base + [
                    'id' => 'e'.$o->id, 'source' => 'email', 'ref' => (int) $o->id, 'state' => 'priced',
                    'kind' => 'unknown', 'price' => (float) $o->price, 'currency' => $o->currency ?: 'RUB', 'rub' => $rub['known'] ? $rub['rub'] : null,
                    'terms' => array_values(array_filter([$o->valid_until_text ? Str::limit((string) $o->valid_until_text, 40) : null, 'письмо '.$o->created_at?->timezone(config('app.timezone'))->format('d.m H:i')])),
                    'review' => false, 'at' => $o->created_at, 'when' => 'ответ '.$o->created_at?->timezone(config('app.timezone'))->format('d.m H:i'),
                ];
            } elseif ($o && $o->outcome === 'refused') {
                $rows[$x->request_item_id][] = $base + ['id' => 'er'.$o->id, 'state' => 'refused', 'note' => $o->refusal_reason, 'rub' => null,
                    'at' => $o->created_at, 'when' => 'ответ '.$o->created_at?->timezone(config('app.timezone'))->format('d.m H:i')];
            } else {
                $rows[$x->request_item_id][] = $base + ['id' => 'ew'.$x->id, 'state' => 'waiting', 'rub' => null,
                    'at' => $inq->created_at, 'when' => 'запрошено '.$inq->created_at?->timezone(config('app.timezone'))->format('d.m H:i')];
            }
        }

        // QuoteParts: строки поставщика в снимке запроса — все варианты, отказ или ждём.
        $remoteIds = $this->rfqs->flatMap(fn ($r) => collect($r->snapshot['suppliers'] ?? [])->flatMap(fn ($s) => collect($s['lines'] ?? [])->flatMap(fn ($l) => collect(($l['offers'] ?? []) ?: (empty($l['offer']) ? [] : [$l['offer']]))->pluck('id'))))->filter()->unique();
        $local = QuotePartsOffer::query()->whereIn('remote_id', $remoteIds)->get()->keyBy('remote_id');
        foreach ($this->rfqs as $rfq) {
            foreach ((array) ($rfq->snapshot['suppliers'] ?? []) as $s) {
                $key = (string) ($s['external_id'] ?? '');
                $base = ['supplier' => (string) ($this->supplierNames()[$key] ?? $key), 'channel' => 'quoteparts', 'key' => $key, 'tag' => $rfq->tag];
                $replied = ! empty($s['last_reply_at']) ? Carbon::parse($s['last_reply_at']) : null;
                foreach ((array) ($s['lines'] ?? []) as $line) {
                    $itemId = $byCode[mb_strtoupper((string) ($line['code'] ?? ''))] ?? null;
                    if ($itemId === null) {
                        continue;
                    }
                    $status = (string) ($line['status'] ?? 'pending');
                    $offers = ($line['offers'] ?? []) ?: (empty($line['offer']) ? [] : [$line['offer']]);
                    if ($status === 'offered' && $offers !== []) {
                        foreach ($offers as $of) {
                            $lo = $local[$of['id'] ?? 0] ?? null;
                            $tier = collect($of['tiers'] ?? [])->sortBy('min_qty')->first();
                            $price = $lo?->price !== null ? (float) $lo->price : (isset($tier['price']) ? (float) $tier['price'] : null);
                            if ($price === null) {
                                continue;
                            }
                            $kind = (string) ($of['kind'] ?? 'original');
                            $rub = IqotCurrencyConverter::toRub($price, $of['currency'] ?? null);
                            $rows[$itemId][] = $base + [
                                'id' => 'q'.($lo?->id ?? $of['id']), 'source' => 'quoteparts', 'ref' => $lo?->id, 'state' => 'priced',
                                'kind' => $kind, 'price' => $price, 'currency' => (string) ($of['currency'] ?? ''), 'rub' => $rub['known'] ? $rub['rub'] : null,
                                'terms' => array_values(array_filter([
                                    'от '.rtrim(rtrim(number_format((float) ($tier['min_qty'] ?? 1), 2, ',', ''), '0'), ',').' шт.',
                                    ! empty($of['lead_time_days']) ? $of['lead_time_days'].' дн.' : null,
                                    ! empty($of['valid_until']) ? 'до '.Carbon::parse($of['valid_until'])->format('d.m.Y') : null,
                                    $kind !== 'original' && ! empty($of['product']) ? trim(($of['product']['brand'] ?? '').' '.($of['product']['part_number'] ?? '')) ?: null : null,
                                    ! empty($of['note']) ? '«'.Str::limit((string) $of['note'], 40).'»' : null,
                                ])),
                                // Копия / аналог, ещё не принятые к закупке, — на разборе.
                                'review' => $kind !== 'original' && ($lo?->review_status ?? null) !== QuotePartsOffer::REVIEW_ACCEPTED,
                                'at' => $replied, 'when' => $replied ? 'ответ '.$replied->timezone(config('app.timezone'))->format('d.m H:i') : '',
                            ];
                        }
                    } elseif ($status === 'declined') {
                        $rows[$itemId][] = $base + ['id' => 'qr'.$rfq->id.'-'.$key.'-'.$itemId, 'state' => 'refused', 'note' => $line['decline_reason'] ?? null, 'rub' => null,
                            'at' => $replied, 'when' => $replied ? 'ответ '.$replied->timezone(config('app.timezone'))->format('d.m H:i') : ''];
                    } else {
                        $sent = ! empty($s['sent_at']) ? Carbon::parse($s['sent_at']) : $rfq->created_at;
                        $rows[$itemId][] = $base + ['id' => 'qw'.$rfq->id.'-'.$key.'-'.$itemId, 'state' => 'waiting', 'question' => $status === 'question', 'rub' => null,
                            'at' => $sent, 'when' => 'запрошено '.$sent?->timezone(config('app.timezone'))->format('d.m H:i')];
                    }
                }
            }
        }

        $choices = app(SupplierChoiceService::class)->forItems($items->pluck('catalog_item_id')->filter()->unique()->values()->all());
        $out = [];
        foreach ($items as $it) {
            $list = collect($rows[$it->id] ?? []);
            if ($list->isEmpty()) {
                continue;
            }
            $priced = $list->where('state', 'priced');
            // «Дешевле всех» — без копий.
            $cheapest = $priced->filter(fn ($r) => $r['kind'] !== 'copy' && $r['rub'] !== null)->sortBy('rub')->first();
            $choice = $it->catalog_item_id ? $choices->get($it->catalog_item_id) : null;
            $chosenId = $choice ? $priced->first(fn ($r) => ($r['source'] ?? null) === $choice->source && (int) ($r['ref'] ?? 0) === (int) $choice->offer_ref)['id'] ?? null : null;
            $out[] = [
                'item_id' => (int) $it->id,
                'cid' => $it->catalog_item_id ? (int) $it->catalog_item_id : null,
                'sku' => $it->catalogItem?->sku ?: $it->quoteparts_code,
                'name' => (string) ($it->catalogItem?->name ?: $it->parsed_name ?: '—'),
                'oem' => $it->parsed_article,
                'brand' => $it->brand?->name ?: $it->parsed_brand,
                'qty' => $it->parsed_qty !== null ? rtrim(rtrim(number_format((float) $it->parsed_qty, 3, ',', ''), '0'), ',') : null,
                'asked' => $list->pluck('supplier')->unique()->count(),
                'answered' => $priced->pluck('supplier')->unique()->count(),
                'offers' => $priced->count(),
                'rows' => $list->sortBy(fn ($r) => [['priced' => 0, 'refused' => 1, 'waiting' => 2][$r['state']], $r['rub'] ?? PHP_FLOAT_MAX])->values()->all(),
                'cheapest' => $cheapest,
                'chosen' => $chosenId,
                'choice' => $choice,
            ];
        }

        return $this->cache['comparison'] = $out;
    }

    /**
     * Поставщики и переписка: строка — поставщик (письмо рассылки или QuoteParts), лента — его письма и события.
     *
     * @param  list<array<string, mixed>>  $correspondence  SupplierDispatchPanel::supplierCorrespondence
     * @return list<array<string, mixed>>
     */
    public function suppliers(array $correspondence): array
    {
        $tz = config('app.timezone');
        $priced = collect($this->comparison())->flatMap(fn ($p) => $p['rows'])->where('state', 'priced');
        $openQuestions = collect($this->questions())->where('open', true);
        $out = [];

        foreach ($this->inquiries() as $inq) {
            $mails = collect($correspondence)->where('inquiry_id', (int) $inq->id)->sortBy(fn ($m) => $m['at']?->getTimestamp() ?? 0)->values();
            $hasPrice = $priced->where('inquiry_id', (int) $inq->id)->isNotEmpty();
            $repliedNoPrice = ! $hasPrice && $mails->contains(fn ($m) => ! $m['ours']);
            $last = $mails->max('at');
            $out[] = [
                'id' => 'm'.$inq->id, 'channel' => 'mail', 'name' => (string) ($inq->supplier_name ?: $inq->supplier_email),
                'by' => $inq->createdBy?->name, 'sent' => $inq->created_at?->timezone($tz)->format('d.m H:i'),
                'positions' => (int) $inq->items_count, 'letters' => $mails->count(),
                'status' => $hasPrice ? 'priced' : ($repliedNoPrice ? 'no_price' : 'waiting'),
                'last' => ($last ?? $inq->created_at)?->timezone($tz)->format('d.m H:i'), 'sort' => ($last ?? $inq->created_at)?->getTimestamp() ?? 0,
                'thread' => $mails->map(fn ($m) => ['dir' => $m['ours'] ? 'out' : 'in', 'from' => ($m['ours'] ? 'Мы → ' : '').$m['who'], 'subject' => $m['subject'],
                    'text' => $m['snippet'], 'body' => $m['body'], 'at' => $m['at']?->timezone($tz)->format('d.m H:i'), 'attachments' => $m['attachments']])->all(),
                'inquiry_id' => (int) $inq->id,
            ];
        }

        foreach ($this->rfqs as $rfq) {
            foreach ((array) ($rfq->snapshot['suppliers'] ?? []) as $si => $s) {
                $key = (string) ($s['external_id'] ?? '');
                $lines = collect($s['lines'] ?? []);
                $thread = QuotePartsThread::of($s);
                $hasPrice = $lines->contains('status', 'offered');
                $asks = $openQuestions->contains(fn ($q) => (int) $q['rfq_id'] === (int) $rfq->id && in_array($key, $q['supplier_keys'], true))
                    || $lines->contains('status', 'question');
                $lastAt = collect([$s['last_reply_at'] ?? null, $s['sent_at'] ?? null])->filter()->map(fn ($v) => Carbon::parse($v))->max();
                $out[] = [
                    'id' => 'q'.$rfq->id.'-'.$si, 'channel' => 'quoteparts', 'name' => (string) ($this->supplierNames()[$key] ?? $key),
                    'by' => $rfq->createdBy?->name, 'sent' => ! empty($s['sent_at']) ? Carbon::parse($s['sent_at'])->timezone($tz)->format('d.m H:i') : $rfq->created_at?->timezone($tz)->format('d.m H:i'),
                    'positions' => $lines->count(), 'letters' => collect($thread)->where('direction', '!=', 'event')->count(),
                    'status' => match (true) {
                        $hasPrice && $asks => 'priced_question',
                        $hasPrice => 'priced',
                        $asks => 'question',
                        $lines->isNotEmpty() && $lines->every(fn ($l) => ($l['status'] ?? '') === 'declined') => 'refused',
                        ! empty($s['last_reply_at']) => 'no_price',
                        default => 'waiting',
                    },
                    'last' => $lastAt?->timezone($tz)->format('d.m H:i'), 'sort' => $lastAt?->getTimestamp() ?? 0,
                    'thread' => collect($thread)->map(fn ($m) => [
                        'dir' => $m['direction'] === 'event' ? 'event' : ($m['direction'] === 'out' ? 'out' : 'in'),
                        'from' => $m['direction'] === 'event' ? 'QuoteParts' : ($m['direction'] === 'out' ? 'Мы'.(! empty($m['who']) ? ' · '.$m['who'] : '') : (string) ($this->supplierNames()[$key] ?? $key)),
                        'subject' => $m['subject'] ?? null, 'text' => Str::limit(preg_replace('/\s+/u', ' ', (string) ($m['text'] ?? '')) ?? '', 220),
                        'body' => (string) ($m['text'] ?? ''), 'at' => ! empty($m['at']) ? Carbon::parse($m['at'])->timezone($tz)->format('d.m H:i') : '',
                        'files' => collect($m['files'] ?? [])->map(fn ($f) => ['name' => (string) ($f['name'] ?? 'file'), 'mime' => $f['mime'] ?? null,
                            'url' => isset($f['message_id'], $f['index']) ? route('quoteparts.rfq-file', [$rfq->remote_id, $f['message_id'], $f['index']] + (! empty($f['relay']) ? ['relay' => 1] : [])) : null])->filter(fn ($f) => $f['url'])->values()->all(),
                    ])->all(),
                    'rfq_id' => (int) $rfq->id, 'tag' => $rfq->tag, 'remote_id' => $rfq->remote_id,
                ];
            }
        }

        // Копии на rfq@, не привязанные к запросу, — отдельной строкой.
        $loose = collect($correspondence)->whereNull('inquiry_id')->sortBy(fn ($m) => $m['at']?->getTimestamp() ?? 0)->values();
        if ($loose->isNotEmpty()) {
            $out[] = [
                'id' => 'loose', 'channel' => 'mail', 'name' => 'Письма без запроса', 'by' => null, 'sent' => null, 'loose' => true,
                'positions' => null, 'letters' => $loose->count(), 'status' => 'loose',
                'last' => $loose->max('at')?->timezone($tz)->format('d.m H:i'), 'sort' => $loose->max('at')?->getTimestamp() ?? 0,
                'thread' => $loose->map(fn ($m) => ['dir' => $m['ours'] ? 'out' : 'in', 'from' => ($m['ours'] ? 'Мы → ' : '').$m['who'], 'subject' => $m['subject'],
                    'text' => $m['snippet'], 'body' => $m['body'], 'at' => $m['at']?->timezone($tz)->format('d.m H:i'), 'attachments' => $m['attachments']])->all(),
            ];
        }

        return $out;
    }

    /**
     * Вопросы поставщиков по запросам QuoteParts заявки: группы (одинаковые по смыслу) и вопросы без группы.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(): array
    {
        if (isset($this->cache['questions'])) {
            return $this->cache['questions'];
        }
        $tz = config('app.timezone');
        $out = [];
        foreach ($this->rfqs as $rfq) {
            $snap = $rfq->snapshot ?? [];
            $suppliers = collect($snap['suppliers'] ?? []);
            // Файлы вопроса — из письма поставщика, пришедшего в ту же минуту.
            $filesOf = function (string $key, ?string $at) use ($suppliers, $rfq) {
                if ($at === null) {
                    return [];
                }
                $t = Carbon::parse($at);
                $s = $suppliers->firstWhere('external_id', $key);
                $m = collect($s['thread'] ?? [])->first(fn ($x) => ($x['direction'] ?? '') !== 'out' && ! empty($x['at']) && abs(Carbon::parse($x['at'])->diffInSeconds($t)) <= 120 && ! empty($x['files']));

                return collect($m['files'] ?? [])->map(fn ($f) => ['name' => (string) ($f['name'] ?? 'file'), 'mime' => $f['mime'] ?? null,
                    'url' => isset($f['message_id'], $f['index']) ? route('quoteparts.rfq-file', [$rfq->remote_id, $f['message_id'], $f['index']]) : null])->filter(fn ($f) => $f['url'])->values()->all();
            };
            foreach ((array) ($snap['question_groups'] ?? []) as $g) {
                $qs = collect($g['questions'] ?? []);
                $out[] = [
                    'rfq_id' => (int) $rfq->id, 'tag' => $rfq->tag, 'group_id' => (int) $g['id'], 'title' => (string) ($g['title_ru'] ?? 'Вопрос поставщика'),
                    'open' => ($g['status'] ?? 'open') === 'open', 'answered_at' => ! empty($g['answered_at']) ? Carbon::parse($g['answered_at'])->timezone($tz)->format('d.m H:i') : null,
                    'supplier_keys' => $qs->pluck('supplier')->unique()->values()->all(),
                    'suppliers' => $qs->pluck('supplier')->unique()->map(fn ($k) => (string) ($this->supplierNames()[$k] ?? $k))->values()->all(),
                    'at' => $qs->min('at') ? Carbon::parse($qs->min('at'))->timezone($tz)->format('d.m H:i') : null,
                    'items' => $qs->map(fn ($q) => ['supplier' => (string) ($this->supplierNames()[$q['supplier']] ?? $q['supplier']),
                        'text' => (string) (($q['text_ru'] ?? null) ?: $q['text']), 'original' => ! empty($q['text_ru']) && $q['text_ru'] !== $q['text'] ? (string) $q['text'] : null,
                        'files' => $filesOf((string) $q['supplier'], $q['at'] ?? null)])->values()->all(),
                    'group' => $g,
                ];
            }
        }

        return $this->cache['questions'] = collect($out)->sortBy(fn ($q) => [$q['open'] ? 0 : 1])->values()->all();
    }

    /**
     * Сводка сверху: кого спросили, кто дал цену, самое дешёвое, вопросы.
     *
     * @param  list<array<string, mixed>>  $suppliers
     * @return array<string, mixed>
     */
    public function summary(array $suppliers): array
    {
        $real = collect($suppliers)->reject(fn ($s) => ! empty($s['loose']));
        $priced = collect($this->comparison())->flatMap(fn ($p) => $p['rows'])->where('state', 'priced');
        $cheapest = $priced->filter(fn ($r) => $r['kind'] !== 'copy' && $r['rub'] !== null)->sortBy('rub')->first();
        $copy = $priced->filter(fn ($r) => $r['kind'] === 'copy' && $r['rub'] !== null)->sortBy('rub')->first();

        return [
            'asked' => $real->pluck('name')->unique()->count(),
            'positions' => count($this->comparison()),
            'answered' => $real->filter(fn ($s) => in_array($s['status'], ['priced', 'priced_question'], true))->pluck('name')->unique()->count(),
            'offers' => $priced->count(),
            'cheapest' => $cheapest,
            'copy' => $copy && (! $cheapest || $copy['rub'] < $cheapest['rub']) ? $copy : null,
            'open_questions' => collect($this->questions())->where('open', true)->count(),
            'has_questions' => $this->questions() !== [],
        ];
    }
}
