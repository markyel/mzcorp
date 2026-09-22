<?php

namespace App\Services\Quotes;

use App\Models\AutoQuoteSnapshot;
use App\Models\Request;

/**
 * Фиксация решения авто-КП на момент заявки.
 *
 * Правило и цены меняются: сегодня у клиента скидка 15%, завтра 20%, каталог
 * переоценивается импортом, а проверки мы правим по ходу. Пересчёт задним
 * числом показывал бы не то, что автомат сделал бы тогда, и сравнение с
 * документом менеджера теряло смысл. Поэтому решение записывается один раз —
 * и дальше живёт как есть.
 *
 * Версию правила держим здесь же: поправили проверки — подняли версию, и по
 * снимку видно, каким автоматом он сделан.
 */
class AutoQuoteSnapshotService
{
    /**
     * Версия правила. Поднимать при ЛЮБОМ изменении набора проверок или
     * формулы цены — иначе снимки разных правил смешаются в одну статистику.
     *
     *  v1 — исходное правило (22.09.2026): однострочные, M-артикул, цена клиента,
     *       неопознанному розница, у адреса с несколькими юрлицами лучшие условия.
     *  v2 — добавлена проверка «в строке один наш артикул»: парсер иногда
     *       складывает два разных M-кода в одну позицию, и заявка выглядит
     *       однострочной (кейс M-2026-16171).
     */
    public const RULE_VERSION = 'v2';

    public function __construct(
        private readonly AutoQuoteRuleService $rule,
    ) {}

    /**
     * Зафиксировать решение по заявке. Повторный вызов ничего не переписывает:
     * снимок на то и снимок.
     */
    public function capture(Request $request, bool $force = false): AutoQuoteSnapshot
    {
        $existing = AutoQuoteSnapshot::query()->where('request_id', $request->id)->first();
        if ($existing !== null && ! $force) {
            return $existing;
        }

        $verdict = $this->rule->verdict($request);

        return AutoQuoteSnapshot::updateOrCreate(
            ['request_id' => $request->id],
            [
                'evaluated_at' => now(),
                'eligible' => (bool) $verdict['eligible'],
                'stopped_at' => $verdict['stopped_at'],
                'rule_version' => self::RULE_VERSION,
                'organization_id' => $verdict['organization']?->id,
                'pricing' => mb_substr((string) $verdict['pricing'], 0, 160),
                'total' => (float) $verdict['total'],
                'lines' => $verdict['lines'],
                'checks' => $verdict['checks'],
            ],
        );
    }

    /**
     * Заявки, по которым снимка ещё нет.
     *
     * @return \Illuminate\Support\Collection<int, Request>
     */
    public function pending(int $hours, int $limit)
    {
        return Request::query()
            ->with(['items.catalogItem', 'organization'])
            ->where('created_at', '>', now()->subHours($hours))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('auto_quote_snapshots as s')
                ->whereColumn('s.request_id', 'requests.id'))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
