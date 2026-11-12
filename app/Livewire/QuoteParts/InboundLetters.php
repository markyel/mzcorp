<?php

namespace App\Livewire\QuoteParts;

use App\Enums\Role;
use App\Models\QuotePartsRfq;
use App\Models\Request as RequestModel;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * «Письма без запроса» в разделе «QuoteParts» (12.11.2026): письма, которые QuoteParts не привязал к запросу
 * сам (очередь «Не разобрано»). Раньше их видел только сотрудник платформы, и ответы с ценами лежали там
 * незамеченными (UniSystem по MZ2026-00339 с 10.11). Здесь письмо читают, привязывают к поставщику запроса —
 * дальше QuoteParts разбирает его как обычный ответ — или отклоняют.
 */
class InboundLetters extends Component
{
    /** Сколько дней назад искать запросы поставщика отправителя для подсказки. */
    private const SUGGEST_DAYS = 60;

    public ?int $openId = null;

    /** Ручной поиск запроса: метка QP-…, номер заявки или номер 1С. */
    public string $find = '';

    /** @var array<string, string> «{remote rfq id}» => выбранный поставщик (ключ QuoteParts) */
    public array $supplierFor = [];

    public ?string $error = null;

    public static function canHandle(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([
            Role::Procurement->value, Role::Manager->value,
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
        ]);
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function letters(): array
    {
        try {
            return app(QuotePartsClient::class)->inboundLetters();
        } catch (Throwable $e) {
            $this->error = 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 200);

            return [];
        }
    }

    /** Текст открытого письма. */
    #[Computed]
    public function opened(): ?array
    {
        if ($this->openId === null) {
            return null;
        }
        try {
            return app(QuotePartsClient::class)->inboundLetter($this->openId);
        } catch (Throwable) {
            return null;
        }
    }

    public function open(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
        $this->find = '';
        $this->supplierFor = [];
        $this->error = null;
        unset($this->opened, $this->options);
    }

    /**
     * Запросы, к которым письмо может относиться: по метке [QP-…] в теме, по номеру заявки или номеру 1С
     * в теме, по ручному поиску, затем недавние запросы, где есть поставщик отправителя.
     *
     * @return list<array{remote_id:int, tag:string, request:?string, request_id:?int, at:string, why:string, suppliers:array<string, string>, default:?string}>
     */
    #[Computed]
    public function options(): array
    {
        $letter = collect($this->letters)->firstWhere('id', $this->openId);
        if ($letter === null) {
            return [];
        }
        $subject = (string) ($letter['subject'] ?? '').' '.$this->find;
        $senders = (array) ($letter['suppliers'] ?? []);
        $found = collect();

        $tags = array_merge((array) ($letter['tags'] ?? []), self::tagsIn($this->find));
        foreach (array_unique($tags) as $tag) {
            $found = $found->merge(QuotePartsRfq::query()->where('tag', 'like', '%'.$tag.'%')->get()->map(fn ($r) => [$r, 'метка '.$tag]));
        }
        $requestIds = $this->requestIdsIn($subject);
        if ($requestIds !== []) {
            $found = $found->merge(QuotePartsRfq::query()->whereIn('request_id', $requestIds)->latest('id')->limit(20)->get()->map(fn ($r) => [$r, 'номер заявки в теме']));
        }
        if ($senders !== []) {
            $recent = QuotePartsRfq::query()->where('created_at', '>=', now()->subDays(self::SUGGEST_DAYS))->latest('id')->limit(300)->get()
                ->filter(fn ($r) => array_intersect($senders, self::supplierKeys($r)) !== []);
            $found = $found->merge($recent->take(15)->map(fn ($r) => [$r, 'поставщик отправителя в запросе']));
        }

        $rows = $found->unique(fn ($p) => $p[0]->id)->values();
        if ($rows->isEmpty()) {
            return [];
        }
        $names = app(QuotePartsRfqService::class)->supplierNames($rows->map(fn ($p) => $p[0])->all());
        $codes = RequestModel::query()->whereIn('id', $rows->map(fn ($p) => $p[0]->request_id)->filter()->unique())->pluck('internal_code', 'id');

        return $rows->map(function ($p) use ($names, $codes, $senders) {
            [$rfq, $why] = $p;
            $keys = self::supplierKeys($rfq);
            $suppliers = collect($keys)->mapWithKeys(fn ($k) => [$k => (string) ($names[$k] ?? $k)])->all();

            return [
                'remote_id' => (int) $rfq->remote_id,
                'tag' => trim((string) $rfq->tag, '[]'),
                'request' => $rfq->request_id ? ($codes[$rfq->request_id] ?? null) : null,
                'request_id' => $rfq->request_id,
                'at' => $rfq->created_at?->format('d.m.Y') ?? '',
                'why' => $why,
                'suppliers' => $suppliers,
                'default' => collect($keys)->first(fn ($k) => in_array($k, $senders, true)),
            ];
        })->all();
    }

    public function assign(int $remoteId): void
    {
        abort_unless(self::canHandle(), 403);
        $option = collect($this->options)->firstWhere('remote_id', $remoteId);
        $supplier = (string) ($this->supplierFor[(string) $remoteId] ?? ($option['default'] ?? ''));
        if ($this->openId === null || $option === null || $supplier === '') {
            $this->error = 'Выберите поставщика в запросе.';

            return;
        }
        try {
            app(QuotePartsClient::class)->assignInboundLetter($this->openId, $remoteId, $supplier);
        } catch (Throwable $e) {
            $this->error = mb_substr($e->getMessage(), 0, 300);

            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Письмо привязано к запросу '.$option['tag'].($option['request'] ? ' ('.$option['request'].')' : '')
            .': QuoteParts разберёт его как ответ поставщика, цены появятся в заявке и «Актуализации цен» через пару минут.');
        $this->afterDecision();
    }

    public function ignore(int $id): void
    {
        abort_unless(self::canHandle(), 403);
        try {
            app(QuotePartsClient::class)->ignoreInboundLetter($id);
        } catch (Throwable $e) {
            $this->error = mb_substr($e->getMessage(), 0, 300);

            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Письмо отклонено — в очереди его больше нет.');
        $this->afterDecision();
    }

    private function afterDecision(): void
    {
        $this->openId = null;
        $this->find = '';
        $this->supplierFor = [];
        $this->error = null;
        unset($this->letters, $this->opened, $this->options);
        $this->dispatch('qp-letters-changed');
    }

    /** @return list<string> ключи поставщиков запроса из снимка */
    private static function supplierKeys(QuotePartsRfq $rfq): array
    {
        return array_values(array_filter(array_map(fn ($s) => (string) ($s['external_id'] ?? ''), (array) ($rfq->snapshot['suppliers'] ?? []))));
    }

    /** @return list<string> */
    private static function tagsIn(string $text): array
    {
        preg_match_all('/QP-([A-Z0-9]{6})/i', $text, $m);

        return array_map(fn ($t) => 'QP-'.strtoupper($t), $m[1] ?? []);
    }

    /** Заявки по номеру в тексте: MZ2026-00339 / M-2026-20734 или номер 1С (372742). @return list<int> */
    private function requestIdsIn(string $text): array
    {
        preg_match_all('/\b(MZ\d{4}-\d{3,6}|M-\d{4}-\d{3,6})\b/u', $text, $codes);
        preg_match_all('/(?<!\d)0*(\d{5,9})(?!\d)/', $text, $onec);
        $ids = collect();
        if (($codes[1] ?? []) !== []) {
            $ids = RequestModel::query()->whereIn('internal_code', array_unique($codes[1]))->pluck('id');
        }
        if (($onec[1] ?? []) !== []) {
            $ids = $ids->merge(RequestModel::query()->whereIn('onec_number', array_unique($onec[1]))->pluck('id'));
        }

        return $ids->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    public function updatedFind(): void
    {
        unset($this->options);
    }

    public function render()
    {
        return view('livewire.quoteparts.inbound-letters');
    }
}
