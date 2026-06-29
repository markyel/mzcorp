<?php

namespace App\Services\Analytics;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Статистика использования системы менеджерами (раздел /dashboard/usage,
 * директорат + админ).
 *
 * Метрики за период по дням и менеджерам:
 *   - время в системе (measured — heartbeat user_activity_minutes; для дней
 *     до внедрения heartbeat — estimated по таймстампам действий);
 *   - РУЧНЫЕ письма менеджера, отправленные через систему (email_messages,
 *     составленные в CRM: direction=outbound, is_draft=false, draft_author_user_id),
 *     БЕЗ авто-уведомлений (исключены по client_notifications_sent);
 *   - ручные сопоставления каталогу (request_items.quality_assessment_payload
 *     → catalog_match.method=manual_link, by_user_id, matched_at);
 *   - заданные уточняющие вопросы (clarification_questions отправленных батчей).
 *
 * Все timestamps проекта — MSK-naive (app.timezone=Europe/Moscow, сессия БД
 * Europe/Moscow), поэтому группировка по дню — DATE(col) без конвертаций, а
 * границы периода передаём MSK-строками (без ->utc()).
 */
class ManagerUsageStatsService
{
    private const TZ = 'Europe/Moscow';

    /** Разрыв (мин), внутри которого действия склеиваются в одну сессию (оценка). */
    private const GAP_MINUTES = 30;

    /** Вклад одиночного действия в оценку времени (мин). */
    private const SOLO_EVENT_MINUTES = 1;

    /**
     * Активные менеджеры-обработчики заявок (Manager + РОП), по имени.
     */
    public function managers(): Collection
    {
        return User::query()
            ->active()
            ->role(RoleEnum::requestHandlerRoles())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Полный отчёт за период.
     *
     * @param  CarbonImmutable  $from  Начало периода (включительно, MSK).
     * @param  CarbonImmutable  $to  Конец периода (ИСКЛЮЧИТЕЛЬНО, MSK).
     * @param  array<int, int>  $managerIds  Фильтр (пусто = все менеджеры).
     * @return array{summary: array<int, array<string, mixed>>, daily: array<int, array<string, mixed>>}
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to, array $managerIds = []): array
    {
        $managers = $this->managers();
        if ($managerIds !== []) {
            $managers = $managers->whereIn('id', $managerIds)->values();
        }
        if ($managers->isEmpty()) {
            return ['summary' => [], 'daily' => []];
        }

        $ids = $managers->pluck('id')->map(fn ($i) => (int) $i)->all();
        $names = $managers->pluck('name', 'id');

        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');

        $measured = $this->minutesByDay($ids, $fromStr, $toStr);   // [uid][day] => minutes
        $emails = $this->emailsByDay($ids, $fromStr, $toStr);       // [uid][day] => count
        $matches = $this->matchesByDay($ids, $fromStr, $toStr);     // [uid][day] => count
        $questions = $this->questionsByDay($ids, $fromStr, $toStr); // [uid][day] => count
        $estimated = $this->estimatedMinutes($ids, $fromStr, $toStr); // [uid][day] => minutes

        $daily = [];
        $summary = [];

        foreach ($ids as $uid) {
            // Все дни, в которые есть хоть какая-то активность этого менеджера.
            $days = array_unique(array_merge(
                array_keys($measured[$uid] ?? []),
                array_keys($emails[$uid] ?? []),
                array_keys($matches[$uid] ?? []),
                array_keys($questions[$uid] ?? []),
                array_keys($estimated[$uid] ?? []),
            ));

            $sum = [
                'user_id' => $uid,
                'name' => (string) ($names[$uid] ?? ('#'.$uid)),
                'time_min' => 0,
                'has_estimated' => false,
                'emails' => 0,
                'matches' => 0,
                'questions' => 0,
                'active_days' => 0,
            ];

            foreach ($days as $day) {
                $m = (int) ($measured[$uid][$day] ?? 0);
                $e = (int) ($estimated[$uid][$day] ?? 0);
                $isEstimated = $m === 0 && $e > 0;
                $timeMin = $m > 0 ? $m : $e;

                $em = (int) ($emails[$uid][$day] ?? 0);
                $mt = (int) ($matches[$uid][$day] ?? 0);
                $q = (int) ($questions[$uid][$day] ?? 0);

                // Пропускаем «пустые» дни (всё по нулям).
                if ($timeMin === 0 && $em === 0 && $mt === 0 && $q === 0) {
                    continue;
                }

                $daily[] = [
                    'user_id' => $uid,
                    'name' => $sum['name'],
                    'day' => $day,
                    'time_min' => $timeMin,
                    'is_estimated' => $isEstimated,
                    'emails' => $em,
                    'matches' => $mt,
                    'questions' => $q,
                ];

                $sum['time_min'] += $timeMin;
                $sum['has_estimated'] = $sum['has_estimated'] || $isEstimated;
                $sum['emails'] += $em;
                $sum['matches'] += $mt;
                $sum['questions'] += $q;
                $sum['active_days']++;
            }

            $summary[] = $sum;
        }

        // Итоги — по времени убыванию (кто активнее), затем по имени.
        usort($summary, fn ($a, $b) => $b['time_min'] <=> $a['time_min'] ?: strcmp($a['name'], $b['name']));
        // Детализация — свежее сверху, затем по имени менеджера.
        usort($daily, fn ($a, $b) => strcmp($b['day'], $a['day']) ?: strcmp($a['name'], $b['name']));

        return ['summary' => $summary, 'daily' => $daily];
    }

    /**
     * Measured-минуты присутствия (heartbeat) по дню.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, int>>
     */
    private function minutesByDay(array $ids, string $from, string $to): array
    {
        $rows = DB::table('user_activity_minutes')
            ->whereIn('user_id', $ids)
            ->where('minute', '>=', $from)
            ->where('minute', '<', $to)
            ->selectRaw('user_id AS uid, DATE(minute) AS day, COUNT(*) AS c')
            ->groupBy('uid', 'day')
            ->get();

        return $this->pivot($rows);
    }

    /**
     * РУЧНЫЕ письма менеджера, отправленные через систему, по дню.
     *
     * ВАЖНО: исключаем авто-уведомления. ClientNotificationService при
     * системном триггере ставит автором `assignedUser` (см. createReply),
     * поэтому order_received / quote_followup_reminder / invoice_* /
     * revival_offer ложно выглядят как письма менеджера (на проде это ~98%
     * исходящих с draft_author!). Надёжный признак авто — связь
     * `client_notifications_sent.outgoing_email_message_id`. Исключаем её.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, int>>
     */
    private function emailsByDay(array $ids, string $from, string $to): array
    {
        $rows = $this->manualOutboundQuery($ids, $from, $to)
            ->selectRaw('draft_author_user_id AS uid, DATE(COALESCE(sent_at, created_at)) AS day, COUNT(*) AS c')
            ->groupBy('uid', 'day')
            ->get();

        return $this->pivot($rows);
    }

    /**
     * Базовый запрос «ручные исходящие менеджера через систему» за период:
     * outbound, отправлено (не черновик), составлено в CRM (draft_author),
     * и НЕ авто-уведомление (нет записи в client_notifications_sent).
     *
     * @param  array<int, int>  $ids
     * @return \Illuminate\Database\Query\Builder
     */
    private function manualOutboundQuery(array $ids, string $from, string $to)
    {
        return DB::table('email_messages')
            ->where('direction', 'outbound')
            ->where('is_draft', false)
            ->whereNotNull('draft_author_user_id')
            ->whereIn('draft_author_user_id', $ids)
            ->whereRaw('COALESCE(sent_at, created_at) >= ?', [$from])
            ->whereRaw('COALESCE(sent_at, created_at) < ?', [$to])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('client_notifications_sent as cns')
                    ->whereColumn('cns.outgoing_email_message_id', 'email_messages.id');
            });
    }

    /**
     * Ручные сопоставления позиций каталогу по дню.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, int>>
     */
    private function matchesByDay(array $ids, string $from, string $to): array
    {
        $idList = implode(',', array_map('intval', $ids));
        $matchedAt = "((quality_assessment_payload->'catalog_match'->>'matched_at')::timestamptz AT TIME ZONE '".self::TZ."')";

        $rows = DB::table('request_items')
            ->whereRaw("quality_assessment_payload->'catalog_match'->>'method' = 'manual_link'")
            ->whereRaw("(quality_assessment_payload->'catalog_match'->>'by_user_id')::int IN ($idList)")
            ->whereRaw("$matchedAt >= ?", [$from])
            ->whereRaw("$matchedAt < ?", [$to])
            ->selectRaw("(quality_assessment_payload->'catalog_match'->>'by_user_id')::int AS uid, DATE($matchedAt) AS day, COUNT(*) AS c")
            ->groupBy('uid', 'day')
            ->get();

        return $this->pivot($rows);
    }

    /**
     * Заданные уточняющие вопросы (вопросы отправленных батчей) по дню.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, int>>
     */
    private function questionsByDay(array $ids, string $from, string $to): array
    {
        $rows = DB::table('clarification_questions as q')
            ->join('clarification_batches as b', 'q.batch_id', '=', 'b.id')
            ->where('b.status', 'sent')
            ->whereNotNull('b.sent_at')
            ->whereIn('b.created_by_user_id', $ids)
            ->where('b.sent_at', '>=', $from)
            ->where('b.sent_at', '<', $to)
            ->selectRaw('b.created_by_user_id AS uid, DATE(b.sent_at) AS day, COUNT(*) AS c')
            ->groupBy('uid', 'day')
            ->get();

        return $this->pivot($rows);
    }

    /**
     * Оценка активного времени по таймстампам действий (для дней без heartbeat).
     * Союз событий из «следов» менеджера, сессионизация с разрывом GAP.
     *
     * Фильтр (пользователь + период) протолкнут в КАЖДУЮ ветку UNION, чтобы
     * каждая работала по своим индексам времени и не материализовала таблицы
     * целиком. JSONB-матчи каталога намеренно НЕ включены в оценку времени
     * (full-scan request_items дорог; на оценку влияют слабо — событие редкое;
     * как отдельная метрика они считаются в matchesByDay).
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, int>>  [uid][day] => estimated minutes
     */
    private function estimatedMinutes(array $ids, string $from, string $to): array
    {
        $idList = implode(',', array_map('intval', $ids));

        $sql = "
            SELECT user_id, ts FROM (
                SELECT draft_author_user_id AS user_id, COALESCE(sent_at, created_at) AS ts
                  FROM email_messages
                 WHERE direction = 'outbound' AND is_draft = false AND draft_author_user_id IN ($idList)
                   AND COALESCE(sent_at, created_at) >= ? AND COALESCE(sent_at, created_at) < ?
                   AND NOT EXISTS (SELECT 1 FROM client_notifications_sent cns WHERE cns.outgoing_email_message_id = email_messages.id)
                UNION ALL
                SELECT by_user_id, created_at FROM request_state_changes
                 WHERE by_user_id IN ($idList) AND created_at >= ? AND created_at < ?
                UNION ALL
                SELECT by_user_id, assigned_at FROM request_assignments
                 WHERE by_user_id IN ($idList) AND assigned_at >= ? AND assigned_at < ?
                UNION ALL
                SELECT created_by_user_id, COALESCE(sent_at, created_at) FROM clarification_batches
                 WHERE created_by_user_id IN ($idList) AND COALESCE(sent_at, created_at) >= ? AND COALESCE(sent_at, created_at) < ?
                UNION ALL
                SELECT user_id, last_seen_at FROM request_user_views
                 WHERE user_id IN ($idList) AND last_seen_at >= ? AND last_seen_at < ?
                UNION ALL
                SELECT applied_by_user_id, applied_at FROM ai_decisions
                 WHERE applied_by_user_id IN ($idList) AND applied_at >= ? AND applied_at < ?
            ) e
            ORDER BY user_id, ts
        ";

        $rows = DB::select($sql, [
            $from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to,
        ]);

        // Группируем таймстампы по пользователю (уже отсортированы по ts).
        $byUser = [];
        foreach ($rows as $r) {
            if ($r->ts === null) {
                continue;
            }
            $byUser[(int) $r->user_id][] = CarbonImmutable::parse($r->ts, self::TZ);
        }

        $out = [];
        foreach ($byUser as $uid => $timestamps) {
            $out[$uid] = $this->sessionizeToDays($timestamps);
        }

        return $out;
    }

    /**
     * Сессионизация отсортированного списка моментов → минуты по дню начала
     * каждой сессии. Сессия = цепочка событий с разрывами ≤ GAP_MINUTES.
     *
     * @param  array<int, CarbonImmutable>  $timestamps  отсортированы по возрастанию
     * @return array<string, int>  [Y-m-d => minutes]
     */
    private function sessionizeToDays(array $timestamps): array
    {
        $byDay = [];
        $n = count($timestamps);
        if ($n === 0) {
            return $byDay;
        }

        $flush = function (CarbonImmutable $start, CarbonImmutable $end) use (&$byDay): void {
            $day = $start->format('Y-m-d');
            $span = (int) round($start->diffInMinutes($end));
            $byDay[$day] = ($byDay[$day] ?? 0) + max(self::SOLO_EVENT_MINUTES, $span);
        };

        $sessStart = $timestamps[0];
        $prev = $timestamps[0];

        for ($i = 1; $i < $n; $i++) {
            $ts = $timestamps[$i];
            if ($prev->diffInMinutes($ts) <= self::GAP_MINUTES) {
                $prev = $ts; // расширяем текущую сессию
            } else {
                $flush($sessStart, $prev);
                $sessStart = $ts;
                $prev = $ts;
            }
        }
        $flush($sessStart, $prev);

        return $byDay;
    }

    /**
     * Свернуть строки (uid, day, c) в [uid][day] => (int) c.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, array<string, int>>
     */
    private function pivot(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->uid][(string) $r->day] = (int) $r->c;
        }

        return $out;
    }
}
