<?php

namespace App\Livewire\AutoQuote;

use App\Models\Request;
use App\Services\Quotes\AutoQuoteComparisonService;
use App\Services\Quotes\AutoQuoteRuleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Холостой прогон автоматической выдачи КП.
 *
 * Ничего не создаёт и никому не отправляет: показывает, какое КП автомат выдал
 * бы по заявке, рядом — что по ней реально ушло клиенту, и подсвечивает, чем
 * они расходятся. Пока не увидим это на живых заявках своими глазами, включать
 * выдачу нельзя: ошибка автомата здесь уходит клиенту письмом.
 */
class Index extends Component
{
    /**
     * Сколько заявок считаем за раз: вердикт — это несколько запросов на
     * заявку, поэтому потолок есть. Но он искажал картину объёма («46 за две
     * недели» при потоке вдвое больше), поэтому рядом всегда показываем,
     * сколько кандидатов было всего.
     */
    public const MAX_ROWS = 400;

    #[Url]
    public int $days = 14;

    /** Фильтр по виду расхождения. */
    #[Url]
    public string $kind = 'all';

    /**
     * Раздел открыт всем сотрудникам: он только показывает, ничего не создаёт
     * и никому не отправляет. Менеджеру он нужнее прочих — это его заявки и
     * его цена, и возражения на правило приходят от него.
     */
    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
    }

    /**
     * Заявки, по которым автомат выдал бы КП, вместе со сравнением.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        $rule = app(AutoQuoteRuleService::class);
        $compare = app(AutoQuoteComparisonService::class);

        $requests = Request::query()
            // price_min обязателен: он пол цены в формуле, и без него скидка
            // уводит КП ниже минимальной цены позиции (кейс M22546).
            ->with([
                'items.catalogItem:id,sku,name,price,price_min,purchase_price,is_price_actual,stock_available',
                'organization:id,name,inn,discount_percent,pricing_mode',
                'assignedUser:id,name',
            ])
            ->whereIn('id', $this->candidateIds)
            ->orderByDesc('created_at')
            ->get();

        $rows = [];
        foreach ($requests as $request) {
            $verdict = $rule->verdict($request);
            if (! $verdict['eligible']) {
                continue;
            }
            $comparison = $compare->compare($request, $verdict['lines']);

            $rows[] = [
                'request' => $request,
                'verdict' => $verdict,
                'comparison' => $comparison,
                // Текст клиента рядом с решением автомата: без него по списку
                // не понять, что именно просили и почему менеджер ответил иначе.
                'asked_text' => $this->inboundExcerpt($request->id),
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    #[Computed]
    public function visible(): array
    {
        $rows = $this->rows;
        if ($this->kind === 'all') {
            return $rows;
        }

        return array_values(array_filter($rows, fn ($r) => $r['comparison']['kind'] === $this->kind));
    }

    /**
     * Объём потока: сколько заявок прошло предотбор и сколько правило приняло.
     * Пересчитываем в месяц — прогноз из анализа тоже месячный.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function volume(): array
    {
        $days = max(1, min(180, $this->days));
        $candidates = count($this->candidateIds);
        $eligible = count($this->rows);

        return [
            'days' => $days,
            'candidates' => $candidates,
            'eligible' => $eligible,
            'capped' => $candidates >= self::MAX_ROWS,
            'per_month' => (int) round($eligible / $days * 30.4),
        ];
    }

    /**
     * Сводка по видам расхождений — то, ради чего прогон и затеян.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function summary(): array
    {
        $out = array_fill_keys(array_keys(AutoQuoteComparisonService::LABELS), 0);
        foreach ($this->rows as $row) {
            $out[$row['comparison']['kind']]++;
        }

        return $out;
    }

    /**
     * Предварительный отбор в SQL: однострочные заявки, сматченные по
     * M-артикулу, с актуальной ценой. Полный вердикт считаем только по ним —
     * иначе на каждую заявку окна ушло бы по несколько запросов.
     *
     * @return array<int, int>
     */
    #[Computed(persist: true)]
    public function candidateIds(): array
    {
        $days = max(1, min(180, $this->days));

        return array_map(fn ($r) => (int) $r->id, DB::select("
            select r.id
            from requests r
            join request_items ri on ri.request_id = r.id and ri.is_active
            left join catalog_items ci on ci.id = ri.catalog_item_id
            where r.created_at > now() - interval '{$days} days'
            group by r.id
            having count(*) = 1
               and bool_and(ri.match_path = 'internal_sku')
               and bool_and(ci.id is not null and ci.price > 0 and ci.is_price_actual)
               and bool_and(coalesce(ri.parsed_qty, 0) > 0)
               and sum(coalesce(ri.parsed_qty, 0) * ci.price) <= ".AutoQuoteRuleService::MAX_TOTAL."
            order by max(r.created_at) desc
            limit ".self::MAX_ROWS.'
        '));
    }

    /** Первое письмо клиента по заявке, коротко — «что просили». */
    private function inboundExcerpt(int $requestId): string
    {
        $message = \App\Models\EmailMessage::query()
            ->where('related_request_id', $requestId)
            ->where('direction', \App\Enums\MailDirection::Inbound->value)
            ->where('is_draft', false)
            ->orderBy('id')
            ->first(['subject', 'body_plain']);

        if ($message === null) {
            return '';
        }

        // Цитаты и подписи отрезаем грубо: нужен смысл запроса, а не письмо.
        $body = preg_split('/^(>|--|С уважением|Best regards)/miu', (string) $message->body_plain)[0] ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $message->subject.' — '.$body) ?? '');

        return mb_substr($text, 0, 400);
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind;
    }

    public function render()
    {
        return view('livewire.auto-quote.index');
    }
}
