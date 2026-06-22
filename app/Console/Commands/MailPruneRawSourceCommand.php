<?php

namespace App\Console\Commands;

use App\Enums\EmailCategory;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Высвобождение хранилища: обнуление `email_messages.raw_source` (сырой RFC822
 * с base64-вложениями, ~464 КБ/письмо — ~95% объёма таблицы) у писем, которым
 * он больше НЕ нужен:
 *   (A) связанных с ЗАКРЫТОЙ заявкой (closed_won / closed_lost), ИЛИ
 *   (B) НЕ классифицированных как заявка (category = irrelevant),
 * и старше `--days` (доставка оригинала в личный ящик идёт ~30 мин, ре-парс
 * читает вложения С ДИСКА — raw_source после обработки/закрытия не требуется).
 *
 * raw_source ОТКРЫТЫХ заявок сохраняется (возможен ре-парс / ре-доставка).
 * Идемпотентно, батчами. Место переиспользует autovacuum (без VACUUM FULL —
 * таблица перестаёт расти, квота Beget не уменьшается мгновенно).
 *
 * Что ещё читает raw_source (поэтому окно с запасом, а не 0 дней):
 * DeliverToManagerInboxJob / MailDeliverToManagerService (доставка в ящик),
 * mail:rebuild-attachment-names (редкий ре-декод имён вложений).
 */
class MailPruneRawSourceCommand extends Command
{
    protected $signature = 'mail:prune-raw-source
        {--apply : Реально обнулить raw_source (без флага — dry-run)}
        {--days=3 : Обрабатывать письма старше N дней}
        {--chunk=500 : Размер батча}';

    protected $description = 'Обнулить raw_source у писем закрытых заявок и не-заявок (высвобождение места)';

    /** Оценка среднего размера raw_source для отчёта (по замеру 2026-06-22). */
    private const AVG_RAW_BYTES = 464 * 1024;

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $chunk = max(50, (int) $this->option('chunk'));
        $apply = (bool) $this->option('apply');
        $cutoff = now()->subDays($days);

        $base = fn () => EmailMessage::query()
            ->whereNotNull('raw_source')
            ->where('created_at', '<', $cutoff)
            ->where(function ($q) {
                // (A) письмо связано с ЗАКРЫТОЙ заявкой.
                $q->whereHas('relatedRequest', fn ($r) => $r->whereIn('status', [
                    RequestStatus::ClosedWon->value,
                    RequestStatus::ClosedLost->value,
                ]))
                    // (B) письмо не является заявкой (нерелевантное / спам).
                    ->orWhere('category', EmailCategory::Irrelevant->value);
            });

        $total = $base()->count();
        $estGb = round($total * self::AVG_RAW_BYTES / 1024 / 1024 / 1024, 2);
        $this->info(sprintf(
            'Кандидатов на обнуление raw_source: %d (~%.2f ГБ оценочно), старше %d дн.',
            $total,
            $estGb,
            $days,
        ));

        if ($total === 0) {
            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('DRY-RUN. Запусти с --apply, чтобы обнулить raw_source.');

            return self::SUCCESS;
        }

        $cleared = 0;
        $base()->select('id')->chunkById($chunk, function ($rows) use (&$cleared) {
            $ids = $rows->pluck('id')->all();
            if ($ids !== []) {
                $cleared += EmailMessage::whereIn('id', $ids)->update(['raw_source' => null]);
            }
        });

        $this->info("Обнулено raw_source у писем: {$cleared}. Место переиспользует autovacuum.");
        Log::info('MailPruneRawSource: cleared', ['cleared' => $cleared, 'days' => $days]);

        return self::SUCCESS;
    }
}
