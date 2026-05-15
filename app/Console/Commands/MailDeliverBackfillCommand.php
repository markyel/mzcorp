<?php

namespace App\Console\Commands;

use App\Jobs\Mail\DeliverToManagerInboxJob;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use Illuminate\Console\Command;

/**
 * Backfill IMAP APPEND писем по уже назначенным заявкам.
 *
 * Зачем: до фичи доставки в личный ящик менеджера заявки распределялись,
 * но письма у менеджеров не появлялись. Эта команда пройдёт по active-
 * заявкам с emailMessage и assigned_user_id, для каждой dispatch'нет
 * DeliverToManagerInboxJob. Сам сервис идемпотентен — если письмо уже
 * там (или уже доставляли) — no-op.
 *
 *   php artisan mail:deliver-backfill                # dry-run, покажет план
 *   php artisan mail:deliver-backfill --apply        # реально dispatch
 *   php artisan mail:deliver-backfill --apply --since=7d   # за последние 7 дней
 *   php artisan mail:deliver-backfill --apply --request=M-2026-0759
 *
 * Ограничения:
 *   - только active-статусы (не paused / closed_*);
 *   - emailMessage.raw_source должен быть непустой;
 *   - manager должен иметь личный Mailbox с OAuth (внутри service).
 */
class MailDeliverBackfillCommand extends Command
{
    protected $signature = 'mail:deliver-backfill
        {--apply : Реально dispatch job\'ы, иначе dry-run}
        {--since= : Период (например 7d / 30d), отсекает старые письма}
        {--request= : Точечно по internal_code заявки (M-2026-NNNN)}
        {--limit=2000 : Максимум dispatch\'ей за прогон (защита от перегруза Yandex)}';

    protected $description = 'Backfill доставки оригиналов писем в личные ящики assigned-менеджеров';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $since = $this->option('since');
        $requestCode = $this->option('request');
        $limit = (int) $this->option('limit');

        $sinceDate = null;
        if ($since !== null && $since !== '') {
            $sinceDate = $this->parseSince($since);
            if ($sinceDate === null) {
                $this->error('Bad --since format. Use 7d / 30d / 24h.');

                return self::FAILURE;
            }
        }

        $q = RequestModel::query()
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('email_message_id');

        if ($requestCode) {
            $q->where('internal_code', $requestCode);
        } else {
            // Active-статусы (не paused/closed/won/lost).
            $q->whereIn('status', collect(\App\Enums\RequestStatus::cases())
                ->filter(fn ($s) => $s->isOpenForAssignment())
                ->map(fn ($s) => $s->value)
                ->all());
            if ($sinceDate) {
                $q->where('created_at', '>=', $sinceDate);
            }
        }

        $total = $q->count();
        $this->info(sprintf('Кандидатов: %d. Режим: %s. Limit: %d.',
            $total,
            $apply ? 'APPLY' : 'DRY-RUN',
            $limit,
        ));

        $dispatched = 0;
        $skipped = 0;
        $missing = 0;

        $q->orderBy('id')->chunkById(200, function ($chunk) use ($apply, $limit, &$dispatched, &$skipped, &$missing) {
            foreach ($chunk as $req) {
                if ($dispatched >= $limit) {
                    return false; // stop chunking
                }

                $email = EmailMessage::find($req->email_message_id);
                if (! $email) {
                    $missing++;

                    continue;
                }

                // Уже доставляли этому пользователю? — service сам проверит,
                // но дешевле срезать тут (меньше job'ов в очереди).
                $artifacts = (array) ($email->detected_artifacts ?? []);
                $deliveries = (array) ($artifacts['inbox_deliveries'] ?? []);
                $alreadyDelivered = false;
                foreach ($deliveries as $d) {
                    if ((int) ($d['user_id'] ?? 0) === (int) $req->assigned_user_id) {
                        $alreadyDelivered = true;
                        break;
                    }
                }
                if ($alreadyDelivered) {
                    $skipped++;

                    continue;
                }

                if ($apply) {
                    DeliverToManagerInboxJob::dispatch($email->id, $req->assigned_user_id);
                }
                $dispatched++;
            }

            return null;
        });

        $this->newLine();
        $this->info(sprintf(
            '%s: %d dispatched, %d уже доставлено / skip, %d отсутствует email.',
            $apply ? 'Готово' : 'DRY-RUN',
            $dispatched,
            $skipped,
            $missing,
        ));

        if (! $apply) {
            $this->newLine();
            $this->line('Это dry-run. Запусти с --apply.');
        }

        return self::SUCCESS;
    }

    private function parseSince(string $s): ?\Carbon\Carbon
    {
        if (! preg_match('/^(\d+)([dh])$/', mb_strtolower(trim($s)), $m)) {
            return null;
        }
        $n = (int) $m[1];
        $unit = $m[2];

        return $unit === 'd' ? now()->subDays($n) : now()->subHours($n);
    }
}
