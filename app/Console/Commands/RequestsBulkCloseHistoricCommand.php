<?php

namespace App\Console\Commands;

use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pre-launch cleanup: массово закрыть исторические активные заявки как
 * closed_lost (без удаления — audit сохраняется в request_state_changes).
 *
 * Использование:
 *   php artisan requests:bulk-close-historic --before="2026-05-28 19:00"
 *   php artisan requests:bulk-close-historic --before="2026-05-28 19:00" --apply
 *   php artisan requests:bulk-close-historic --before="2026-05-28 19:00" --reason=manual_other --comment="Pre-launch" --apply
 *
 * Применяется ко ВСЕМ active заявкам с created_at < $before:
 *   - status IN (pending, new, assigned, in_progress,
 *                awaiting_client_clarification, quoted, under_review,
 *                postponed_until, awaiting_invoice, invoiced, paid,
 *                paused).
 *   - closed_won / closed_lost — не трогаем.
 *
 * Особенность: paused-заявки тоже закрываем. allowedTransitions Paused
 * не разрешает прямой переход — обходим через прямой UPDATE + ручную
 * audit-запись (cron-стиль), чтобы не возиться с RequestPauseService::resume.
 */
class RequestsBulkCloseHistoricCommand extends Command
{
    protected $signature = 'requests:bulk-close-historic
        {--before= : Cutoff datetime (например "2026-05-28 19:00"). Обязательный.}
        {--reason=off_topic : ClosedLostReason value (default off_topic, не требует комментария)}
        {--comment= : Комментарий (по умолчанию авто-генерируется)}
        {--apply : Применить (без флага — dry-run)}';

    protected $description = 'Массово закрыть исторические активные заявки как closed_lost (pre-launch cleanup).';

    public function handle(): int
    {
        $before = $this->option('before');
        if (! $before) {
            $this->error('Обязательно --before="YYYY-MM-DD HH:MM"');
            return self::FAILURE;
        }
        try {
            $cutoff = Carbon::parse($before);
        } catch (\Throwable $e) {
            $this->error('Не удалось распарсить --before: ' . $e->getMessage());
            return self::FAILURE;
        }

        $reasonStr = (string) $this->option('reason');
        $reason = ClosedLostReason::tryFrom($reasonStr);
        if (! $reason) {
            $this->error('Неизвестная reason: ' . $reasonStr . '. Допустимые: ' . implode(', ', array_map(fn (ClosedLostReason $r) => $r->value, ClosedLostReason::cases())));
            return self::FAILURE;
        }

        $comment = (string) ($this->option('comment') ?: sprintf(
            'Pre-launch cleanup: историческая заявка до %s — закрыта массово.',
            $cutoff->format('d.m.Y H:i'),
        ));

        if ($reason->requiresComment() && $comment === '') {
            $this->error('Эта причина требует --comment.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        // Активные = всё что НЕ closed_won/closed_lost.
        $activeStatuses = collect(RequestStatus::cases())
            ->filter(fn (RequestStatus $s) => $s !== RequestStatus::ClosedWon && $s !== RequestStatus::ClosedLost)
            ->map(fn (RequestStatus $s) => $s->value)
            ->values()
            ->all();

        $targets = Request::query()
            ->whereIn('status', $activeStatuses)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->get(['id', 'internal_code', 'status', 'assigned_user_id', 'created_at']);

        if ($targets->isEmpty()) {
            $this->info('Подходящих заявок нет.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d заявок до %s → closed_lost (%s)',
            $apply ? 'Закрываем' : '[DRY-RUN] Будем закрывать',
            $targets->count(),
            $cutoff->format('d.m.Y H:i'),
            $reason->value,
        ));
        $this->line('Комментарий: ' . $comment);
        $this->newLine();

        // Сводка по текущим статусам.
        $byStatus = $targets->groupBy(fn ($r) => is_object($r->status) ? $r->status->value : (string) $r->status)
            ->map(fn ($g) => $g->count())
            ->sortDesc();
        $this->table(
            ['from_status', 'count'],
            $byStatus->map(fn ($cnt, $st) => [$st, $cnt])->values()->all(),
        );

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY-RUN — никаких изменений. Для применения добавь --apply.');
            return self::SUCCESS;
        }

        if (! $this->confirm(sprintf('Действительно закрыть %d заявок? Это операция БЕЗ ВОЗВРАТА.', $targets->count()), false)) {
            $this->info('Отменено.');
            return self::SUCCESS;
        }

        // Системный actor для audit — берём первого admin'а (если есть),
        // иначе оставляем by_user_id = null.
        $systemActorId = $this->resolveSystemActorId();
        $this->info('Audit by_user_id = ' . ($systemActorId ?? 'NULL (system)'));

        $ok = 0;
        $fail = 0;
        $now = now();

        foreach ($targets as $target) {
            try {
                DB::transaction(function () use ($target, $reason, $comment, $systemActorId, $now) {
                    $fromStatus = is_object($target->status) ? $target->status->value : (string) $target->status;

                    // Прямой UPDATE минуя allowedTransitions — чтобы paused
                    // и pending тоже могли закрыться.
                    DB::table('requests')
                        ->where('id', $target->id)
                        ->update([
                            'status' => RequestStatus::ClosedLost->value,
                            'closed_at' => $now,
                            'closed_lost_reason' => $reason->value,
                            'closed_lost_comment' => $comment,
                            'updated_at' => $now,
                        ]);

                    // Audit-запись.
                    DB::table('request_state_changes')->insert([
                        'request_id' => $target->id,
                        'from_status' => $fromStatus,
                        'to_status' => RequestStatus::ClosedLost->value,
                        'by_user_id' => $systemActorId,
                        'event' => 'bulk_close_historic',
                        'comment' => $comment,
                        'payload' => json_encode([
                            'closed_lost_reason' => $reason->value,
                            'cutoff' => $now->toIso8601String(),
                            'bulk_command' => true,
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $this->error(sprintf('  ✗ #%d %s: %s', $target->id, $target->internal_code, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->info(sprintf('Готово: ok=%d, fail=%d', $ok, $fail));

        return self::SUCCESS;
    }

    /**
     * Первый admin для audit-метки. Если admin'ов нет — null (system).
     */
    private function resolveSystemActorId(): ?int
    {
        $admin = User::query()
            ->role(\App\Enums\Role::Admin->value)
            ->orderBy('id')
            ->first();
        return $admin?->id;
    }
}
