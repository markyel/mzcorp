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
 *   (B) НЕ классифицированных как заявка (category = irrelevant), ИЛИ
 *   (C) НЕ привязанных ни к какой заявке (переписка с поставщиками, исходящие
 *       без заявки, post_sale) — их не доставляют в личный ящик; к 2026-10 они
 *       держали ~15 ГБ из 21 ГБ raw_source, рост ~9 ГБ/мес,
 * и старше `--days` (доставка оригинала в личный ящик идёт ~30 мин, ре-парс
 * читает вложения С ДИСКА — raw_source после обработки/закрытия не требуется).
 * Черновики (is_draft) не трогаем.
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

    protected $description = 'Обнулить raw_source у писем закрытых заявок, не-заявок и писем без заявки (высвобождение места)';

    /** Оценка среднего размера raw_source для отчёта (по замеру 2026-06-22). */
    private const AVG_RAW_BYTES = 464 * 1024;

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $chunk = max(50, (int) $this->option('chunk'));
        $apply = (bool) $this->option('apply');
        $cutoff = now()->subDays($days);

        $closed = fn ($q) => $q->whereHas('relatedRequest', fn ($r) => $r->whereIn('status', [
            RequestStatus::ClosedWon->value,
            RequestStatus::ClosedLost->value,
        ]));
        $irrelevant = fn ($q) => $q->where('category', EmailCategory::Irrelevant->value);
        $unlinked = fn ($q) => $q->whereNull('related_request_id');

        $candidates = fn () => EmailMessage::query()
            ->whereNotNull('raw_source')
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->where('is_draft', false)->orWhereNull('is_draft'));

        $base = fn () => $candidates()
            ->where(function ($q) use ($closed, $irrelevant, $unlinked) {
                // (A) письмо связано с ЗАКРЫТОЙ заявкой.
                $closed($q);
                // (B) письмо не является заявкой (нерелевантное / спам).
                $q->orWhere(fn ($q) => $irrelevant($q));
                // (C) письмо не привязано ни к какой заявке.
                $q->orWhere(fn ($q) => $unlinked($q));
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
            // Разбивка по причинам (пересекаются: письмо без заявки бывает и irrelevant) — реальный объём.
            foreach (['A закрытая заявка' => $closed, 'B irrelevant' => $irrelevant, 'C без заявки' => $unlinked] as $label => $scope) {
                $row = $scope($candidates())->selectRaw('count(*) AS n, coalesce(sum(pg_column_size(raw_source)), 0) AS bytes')->toBase()->first();
                $this->line(sprintf('  %s: %d писем, %.2f ГБ', $label, $row->n, $row->bytes / 1024 ** 3));
            }
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
