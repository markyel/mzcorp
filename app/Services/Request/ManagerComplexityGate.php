<?php

namespace App\Services\Request;

use App\Enums\ComplexityLevel;
use App\Enums\MatchPath;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Потолок сложности заявок у менеджера — единственный владелец правила
 * «эта заявка по плечу этому менеджеру».
 *
 * Зачем (запрос РОПа 2026-09-09): отстающему менеджеру какое-то время дают
 * только простые заявки, в идеале — только с M-артикулами, а по мере роста
 * потолок в его карточке поднимают.
 *
 * Настройки в карточке менеджера (`users`):
 *   - `max_complexity_level` — NULL (по умолчанию, без ограничения) либо
 *     уровень из ComplexityLevel: заявка подходит, если её `complexity_level`
 *     не сложнее потолка;
 *   - `only_internal_sku_requests` — жёстче: ВСЕ активные позиции заявки
 *     должны прийти M-артикулом (`request_items.match_path = internal_sku`).
 *
 * Где применяется: только round-robin в AssignmentService. Sticky-уровни
 * (письмо в личный ящик, «этот клиент уже мой», тот же товар) сильнее:
 * менеджер продолжает вести своих клиентов независимо от потолка.
 *
 * Если под фильтр не проходит НИ ОДИН менеджер — фильтр снимается целиком
 * (заявка важнее настройки, без менеджера она висеть не должна).
 *
 * Сложность считается по позициям (RequestComplexityService), а к моменту
 * назначения они уже разобраны в 95% случаев (замер на проде 2026-09-09:
 * 1598 из 1685 за 14 дней). В остальных заявка приходит без позиций —
 * сложность неизвестна, и ограниченному менеджеру такую не отдаём.
 */
class ManagerComplexityGate
{
    /** @var array<int, array{total: int, internal_sku: int}> кэш на время одного назначения */
    private array $cache = [];

    /** Есть ли у менеджера ограничение вообще. */
    public function hasLimit(User $manager): bool
    {
        return $this->maxLevel($manager) !== null || $this->onlyInternalSku($manager);
    }

    /**
     * Может ли менеджер получить эту заявку в round-robin.
     */
    public function canTake(User $manager, Request $request): bool
    {
        if (! $this->hasLimit($manager)) {
            return true;
        }

        $stats = $this->itemStats($request);
        // Позиций нет — сложность ещё неизвестна (парсер не отработал либо
        // заявка без позиций). Ограниченному менеджеру не отдаём.
        if ($stats['total'] === 0) {
            return false;
        }

        if ($this->onlyInternalSku($manager) && $stats['internal_sku'] !== $stats['total']) {
            return false;
        }

        $max = $this->maxLevel($manager);
        if ($max !== null) {
            $level = $this->levelOf($request);
            if ($level === null || ! $level->isAtMost($max)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Оставить менеджеров, которым эта заявка по плечу. Если таких нет —
     * вернуть исходный список (фильтр снимается, заявка не зависает).
     *
     * @param  Collection<int, User>  $managers
     * @return array{managers: Collection<int, User>, excluded: array<int, int>, relaxed: bool}
     */
    public function filter(Collection $managers, Request $request): array
    {
        $excluded = [];
        $kept = $managers->filter(function (User $u) use ($request, &$excluded) {
            $ok = $this->canTake($u, $request);
            if (! $ok) {
                $excluded[] = $u->id;
            }

            return $ok;
        })->values();

        if ($kept->isEmpty()) {
            return ['managers' => $managers, 'excluded' => $excluded, 'relaxed' => true];
        }

        return ['managers' => $kept, 'excluded' => $excluded, 'relaxed' => false];
    }

    /** Уровень сложности заявки; null — позиций нет, уровень неизвестен. */
    public function levelOf(Request $request): ?ComplexityLevel
    {
        if ($this->itemStats($request)['total'] === 0) {
            return null;
        }

        $level = $request->complexity_level;
        if ($level instanceof ComplexityLevel) {
            return $level;
        }

        return is_string($level) ? ComplexityLevel::tryFrom($level) : null;
    }

    /** Все ли активные позиции заявки пришли M-артикулом. */
    public function isInternalSkuOnly(Request $request): bool
    {
        $stats = $this->itemStats($request);

        return $stats['total'] > 0 && $stats['total'] === $stats['internal_sku'];
    }

    private function maxLevel(User $manager): ?ComplexityLevel
    {
        $raw = $manager->max_complexity_level;
        if ($raw instanceof ComplexityLevel) {
            return $raw;
        }

        return is_string($raw) ? ComplexityLevel::tryFrom($raw) : null;
    }

    private function onlyInternalSku(User $manager): bool
    {
        return (bool) ($manager->only_internal_sku_requests ?? false);
    }

    /**
     * Позиции заявки: сколько активных всего и сколько из них M-артикулы.
     *
     * @return array{total: int, internal_sku: int}
     */
    protected function itemStats(Request $request): array
    {
        if (isset($this->cache[$request->id])) {
            return $this->cache[$request->id];
        }

        $row = DB::table('request_items')
            ->where('request_id', $request->id)
            ->where('is_active', true)
            ->selectRaw('count(*) as total, count(*) filter (where match_path = ?) as internal_sku', [MatchPath::InternalSku->value])
            ->first();

        return $this->cache[$request->id] = [
            'total' => (int) ($row->total ?? 0),
            'internal_sku' => (int) ($row->internal_sku ?? 0),
        ];
    }
}
