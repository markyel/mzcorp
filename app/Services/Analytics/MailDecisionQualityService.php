<?php

namespace App\Services\Analytics;

use App\Enums\ClosedLostReason;
use App\Enums\DetectorType;
use App\Enums\RequestStatus;
use App\Services\Mail\MailDecisionRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Панель качества решений почтового конвейера (/dashboard/mail-quality).
 * Четыре сигнала дрейфа, которые в сентябре 2026 замечали только по жалобам:
 *
 *  - AI-решения по детекторам: сколько применилось автоматически и сколько
 *    менеджеры отклонили (доля отклонённых = шум детектора);
 *  - ручные откаты статуса назад (из «КП отправлено» / «ждёт счёт» / «счёт
 *    выставлен» обратно в работу) — авто-статусы ставятся зря;
 *  - заявки-фантомы: созданы и закрыты системой/руками как дубль, постпродажа,
 *    не наша тематика, пустой парсинг;
 *  - куда уходят письма (журнал mail_decisions по стадиям).
 *
 * Плюс понедельная динамика тех же чисел, чтобы дрейф был виден за дни.
 * Только чтение, без кеша: запросы агрегатные, период ≤ 90 дней.
 */
class MailDecisionQualityService
{
    /** Статусы «после КП», откат из которых назад считаем ручным исправлением авто-статуса. */
    private const POST_QUOTE = ['quoted', 'under_review', 'awaiting_invoice', 'invoiced'];

    /** Куда откатывают: в работу. */
    private const BACK_TO_WORK = ['assigned', 'in_progress', 'awaiting_client_clarification', 'under_review'];

    /** Причины закрытия, означающие «заявку не надо было создавать». */
    private const PHANTOM_REASONS = ['duplicate', 'post_sale_correspondence', 'off_topic', 'parser_no_content', 'supplier_reply'];

    /**
     * @return array{
     *   detectors: list<array{type:string,label:string,total:int,auto_applied:int,dismissed:int,not_applicable:int,confirmed:int,overridden:int,dismiss_rate:?float}>,
     *   rollbacks: array{total:int, by_manager: list<array{user_id:int,name:string,n:int}>},
     *   phantoms: array{total:int, requests_created:int, by_reason: list<array{reason:string,label:string,n:int}>},
     *   stages: list<array{stage:string,label:string,outcome:string,n:int}>,
     *   weekly: list<array{week:string,requests:int,rollbacks:int,dismissed:int,phantoms:int,post_sale:int}>
     * }
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'detectors' => $this->detectors($from, $to),
            'rollbacks' => $this->rollbacks($from, $to),
            'phantoms' => $this->phantoms($from, $to),
            'stages' => $this->stages($from, $to),
            'weekly' => $this->weekly($from, $to),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function detectors(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('ai_decisions')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('detector_type')
            ->selectRaw("detector_type,
                count(*) as total,
                count(*) filter (where status = 'auto_applied') as auto_applied,
                count(*) filter (where status = 'dismissed' and applied_by_user_id is not null) as dismissed,
                count(*) filter (where status = 'dismissed' and applied_by_user_id is null) as not_applicable,
                count(*) filter (where status = 'manually_confirmed') as confirmed,
                count(*) filter (where status = 'manually_overridden') as overridden")
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($r) => self::detectorRow($r))->values()->all();
    }

    /**
     * Строка детектора. «Отклонил менеджер» — dismissed с автором и manually_overridden;
     * «статус уже дальше» — dismissed без автора: решение не применила схема статусов
     * (заявка уже дальше по воронке — «запросил счёт», когда счёт уже выставлен) или оно
     * устарело. Это не несогласие с классификатором, в долю отклонённых не входит
     * (до 29.10.2026 входило — «на согласовании» 24% и «отложил» 70% были почти целиком им).
     *
     * @return array{type:string,label:string,total:int,auto_applied:int,dismissed:int,not_applicable:int,confirmed:int,overridden:int,dismiss_rate:?float}
     */
    public static function detectorRow(object $r): array
    {
        $rejected = (int) $r->dismissed + (int) $r->overridden;
        $judged = (int) $r->auto_applied + (int) $r->confirmed + $rejected;
        $type = DetectorType::tryFrom((string) $r->detector_type);

        return [
            'type' => (string) $r->detector_type,
            'label' => $type?->label() ?? (string) $r->detector_type,
            'total' => (int) $r->total,
            'auto_applied' => (int) $r->auto_applied,
            'dismissed' => (int) $r->dismissed,
            'not_applicable' => (int) $r->not_applicable,
            'confirmed' => (int) $r->confirmed,
            'overridden' => (int) $r->overridden,
            'dismiss_rate' => $judged > 0 ? round($rejected / $judged, 3) : null,
        ];
    }

    /** @return array{total:int, by_manager: list<array{user_id:int,name:string,n:int}>} */
    private function rollbacks(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = DB::table('request_state_changes as c')
            ->whereBetween('c.created_at', [$from, $to])
            ->where('c.event', 'manual')
            ->whereIn('c.from_status', self::POST_QUOTE)
            ->whereIn('c.to_status', self::BACK_TO_WORK);

        $byManager = (clone $base)
            ->join('users as u', 'u.id', '=', 'c.by_user_id')
            ->groupBy('u.id', 'u.name')
            ->selectRaw('u.id as user_id, u.name, count(*) as n')
            ->orderByDesc('n')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['user_id' => (int) $r->user_id, 'name' => (string) $r->name, 'n' => (int) $r->n])
            ->all();

        return ['total' => (int) (clone $base)->count(), 'by_manager' => $byManager];
    }

    /** @return array{total:int, requests_created:int, by_reason: list<array{reason:string,label:string,n:int}>} */
    private function phantoms(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $created = (int) DB::table('requests')->whereBetween('created_at', [$from, $to])->count();
        $rows = DB::table('requests')
            ->whereBetween('created_at', [$from, $to])
            ->where('status', RequestStatus::ClosedLost->value)
            ->whereIn('closed_lost_reason', self::PHANTOM_REASONS)
            ->groupBy('closed_lost_reason')
            ->selectRaw('closed_lost_reason as reason, count(*) as n')
            ->orderByDesc('n')
            ->get();

        return [
            'total' => (int) $rows->sum('n'),
            'requests_created' => $created,
            'by_reason' => $rows->map(fn ($r) => [
                'reason' => (string) $r->reason,
                'label' => ClosedLostReason::tryFrom((string) $r->reason)?->label() ?? (string) $r->reason,
                'n' => (int) $r->n,
            ])->all(),
        ];
    }

    /** @return list<array{stage:string,label:string,outcome:string,n:int}> */
    private function stages(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('mail_decisions')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('stage', 'outcome')
            ->selectRaw('stage, outcome, count(*) as n')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => [
                'stage' => (string) $r->stage,
                'label' => MailDecisionRecorder::label((string) $r->stage),
                'outcome' => (string) $r->outcome,
                'n' => (int) $r->n,
            ])->all();
    }

    /** @return list<array{week:string,requests:int,rollbacks:int,dismissed:int,phantoms:int,post_sale:int}> */
    private function weekly(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $weekExpr = "to_char(date_trunc('week', created_at), 'DD.MM')";
        $requests = DB::table('requests')->whereBetween('created_at', [$from, $to])
            ->groupByRaw("date_trunc('week', created_at)")->selectRaw("$weekExpr as w, count(*) as n")->pluck('n', 'w');
        $phantoms = DB::table('requests')->whereBetween('created_at', [$from, $to])
            ->where('status', RequestStatus::ClosedLost->value)->whereIn('closed_lost_reason', self::PHANTOM_REASONS)
            ->groupByRaw("date_trunc('week', created_at)")->selectRaw("$weekExpr as w, count(*) as n")->pluck('n', 'w');
        $rollbacks = DB::table('request_state_changes')->whereBetween('created_at', [$from, $to])
            ->where('event', 'manual')->whereIn('from_status', self::POST_QUOTE)->whereIn('to_status', self::BACK_TO_WORK)
            ->groupByRaw("date_trunc('week', created_at)")->selectRaw("$weekExpr as w, count(*) as n")->pluck('n', 'w');
        // Отклонил менеджер (с автором или переопределил); «статус уже дальше» не считаем.
        $dismissed = DB::table('ai_decisions')->whereBetween('created_at', [$from, $to])
            ->where(fn ($q) => $q->where('status', 'manually_overridden')
                ->orWhere(fn ($w) => $w->where('status', 'dismissed')->whereNotNull('applied_by_user_id')))
            ->groupByRaw("date_trunc('week', created_at)")->selectRaw("$weekExpr as w, count(*) as n")->pluck('n', 'w');
        $postSale = DB::table('mail_decisions')->whereBetween('created_at', [$from, $to])
            ->where('outcome', 'post_sale')
            ->groupByRaw("date_trunc('week', created_at)")->selectRaw("$weekExpr as w, count(*) as n")->pluck('n', 'w');

        $weeks = [];
        for ($d = $from->startOfWeek(); $d->lte($to); $d = $d->addWeek()) {
            $weeks[] = $d->format('d.m');
        }

        return array_map(fn (string $w) => [
            'week' => $w,
            'requests' => (int) ($requests[$w] ?? 0),
            'rollbacks' => (int) ($rollbacks[$w] ?? 0),
            'dismissed' => (int) ($dismissed[$w] ?? 0),
            'phantoms' => (int) ($phantoms[$w] ?? 0),
            'post_sale' => (int) ($postSale[$w] ?? 0),
        ], $weeks);
    }
}
