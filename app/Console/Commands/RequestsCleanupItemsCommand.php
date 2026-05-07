<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.8e support CLI: удалить распарсенные RequestItem для диапазона
 * inbound-писем, чтобы повторный прогон `requests:parse-items` дал чистый
 * rebake без следов старого парсера.
 *
 *   php artisan requests:cleanup-items --from-id=300              # dry-run
 *   php artisan requests:cleanup-items --from-id=300 --apply      # удалить
 *   php artisan requests:cleanup-items --from-id=300 --to-id=400 --apply
 *
 * Логика:
 *   1. Найти все EmailMessage в диапазоне id с непустым related_request_id.
 *   2. Собрать соответствующие Request id.
 *   3. DELETE FROM request_items WHERE request_id IN (...).
 *   4. Для Request у которых после удаления items пусто — перевести status
 *      в Pending (чтобы менеджер не видел пустую заявку в пуле). Если новый
 *      парсер при apply-прогоне найдёт items — AssignmentService внутри
 *      RequestItemPersister::persist вернёт статус в Assigned автоматически.
 *
 * Защита: без --apply печатает план, ничего не трогает.
 *         Без --from-id команда падает (защита от случайного DELETE * FROM).
 */
class RequestsCleanupItemsCommand extends Command
{
    protected $signature = 'requests:cleanup-items
        {--from-id= : EmailMessage id с какого начинать (обязательно для безопасности)}
        {--to-id=0 : EmailMessage id до какого включительно (0 = без верхней границы)}
        {--apply : Реально удалить items + перевести Request в Pending. Без флага — dry-run.}';

    protected $description = 'Phase 1.8e: удалить RequestItem в диапазоне писем для чистого rebake-парсинга.';

    public function handle(): int
    {
        $fromOpt = $this->option('from-id');
        if ($fromOpt === null || $fromOpt === '') {
            $this->error('--from-id обязателен (защита от удаления всех items в БД).');

            return self::FAILURE;
        }

        $from = (int) $fromOpt;
        $to = (int) $this->option('to-id');
        $apply = (bool) $this->option('apply');

        $emailQuery = EmailMessage::query()
            ->where('id', '>=', $from)
            ->whereNotNull('related_request_id');
        if ($to > 0) {
            $emailQuery->where('id', '<=', $to);
        }

        $requestIds = $emailQuery
            ->pluck('related_request_id')
            ->unique()
            ->values();

        if ($requestIds->isEmpty()) {
            $this->info(sprintf(
                'Нет привязанных Request в диапазоне email_message id %d..%s.',
                $from,
                $to > 0 ? $to : '∞',
            ));

            return self::SUCCESS;
        }

        $itemsCount = RequestItem::whereIn('request_id', $requestIds)->count();
        $emptyAfter = Request::whereIn('id', $requestIds)
            ->where('status', '!=', RequestStatus::Pending->value)
            ->count();

        $this->info(sprintf(
            'Диапазон email_message %d..%s → %d Request, %d items для удаления.',
            $from,
            $to > 0 ? $to : '∞',
            $requestIds->count(),
            $itemsCount,
        ));
        $this->info(sprintf(
            'Из них %d Request не в статусе Pending — после cleanup переведутся в Pending '
            . '(если их повторный парсинг не вернёт items, менеджер не будет их видеть).',
            $emptyAfter,
        ));

        if (! $apply) {
            $this->warn('Dry-run. Запустите с --apply чтобы реально удалить.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($requestIds) {
            RequestItem::whereIn('request_id', $requestIds)->delete();

            // После удаления у некоторых Request items действительно пуст —
            // ставим Pending. Те Request у которых items НЕ удалились (их не
            // было в выбранном диапазоне писем — но Request попал по
            // email_message id) будут пропущены доп. проверкой
            // whereDoesntHave('items'). Items уже удалены transaction'ом
            // выше, поэтому условие точно опишет «пустые» Request.
            Request::whereIn('id', $requestIds)
                ->whereDoesntHave('items')
                ->where('status', '!=', RequestStatus::Pending->value)
                ->update(['status' => RequestStatus::Pending->value]);
        });

        $this->info(sprintf('Удалено items, %d Request переведены в Pending.', $emptyAfter));
        $this->line('Следующий шаг: `php artisan requests:parse-items --apply --force --limit=50 --from-id=' . $from . '` для rebake.');

        return self::SUCCESS;
    }
}
