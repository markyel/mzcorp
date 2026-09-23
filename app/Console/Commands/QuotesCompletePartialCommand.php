<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Services\Mail\OutboundReplyHooks;
use App\Services\Mail\OutgoingMailSender;
use App\Services\Quotations\PartialQuoteService;
use App\Services\Quotations\QuotationDispatchService;
use App\Services\Quotations\QuotationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Досылка полного КП по заявкам с частичным.
 *
 * Менеджер выдал цену по тому, что было актуально; остальные позиции ушли под
 * наблюдение. Цены приходят из 1С — и как только они появились, клиент должен
 * получить полное предложение, не дожидаясь, пока менеджер вспомнит.
 *
 * Правило отправки (решение заказчика 23.09.2026):
 *  • цена появилась у ВСЕХ отложенных позиций → полное КП уходит сразу;
 *  • появилась у части → ждём два дня с прошлой отправки и шлём дополненное,
 *    чтобы не слать клиенту письмо на каждую позицию;
 *  • всё считаем по сегодняшним ценам, включая уже названные ранее;
 *  • две недели с первого частичного КП — предел, дальше заявка остаётся
 *    менеджеру.
 */
class QuotesCompletePartialCommand extends Command
{
    protected $signature = 'quotes:complete-partial
        {--limit=50 : Сколько заявок разобрать за прогон}
        {--dry-run : Только показать, что было бы отправлено}';

    protected $description = 'Дослать полное КП по заявкам с частичным';

    public function handle(
        PartialQuoteService $partial,
        QuotationService $quotations,
        QuotationDispatchService $dispatch,
        OutgoingMailSender $sender,
    ): int {
        $dry = (bool) $this->option('dry-run');
        $sent = 0;
        $held = 0;

        foreach ($partial->due((int) $this->option('limit')) as $request) {
            $hold = $partial->holdReason($request);
            if ($hold !== null) {
                $held++;
                $this->line(sprintf('  %s — ждём: %s', $request->internal_code, $hold));

                continue;
            }

            $complete = $partial->isComplete($request);
            $author = $partial->actorFor($request);
            if ($author === null) {
                $this->warn(sprintf('  %s — некому отправить: у заявки нет менеджера', $request->internal_code));

                continue;
            }

            if ($dry) {
                $this->line(sprintf(
                    '  %s — отправили бы %s КП',
                    $request->internal_code,
                    $complete ? 'полное' : 'дополненное',
                ));
                $sent++;

                continue;
            }

            if ($this->send($request, $complete, $quotations, $dispatch, $sender, $author)) {
                $sent++;
            }
        }

        // Заявки, у которых окно вышло: досылать больше не будем, и менеджер
        // должен об этом узнать — иначе они молча зависнут в «Частичном КП».
        $expired = Request::query()
            ->where('status', RequestStatus::PartiallyQuoted->value)
            ->whereNotNull('partial_quote_started_at')
            ->where('partial_quote_started_at', '<', now()->subDays(PartialQuoteService::WINDOW_DAYS))
            ->count();

        $this->info(sprintf(
            '%s: отправлено %d, ждут %d, вышел срок у %d.',
            $dry ? 'Сухой прогон' : 'Готово',
            $sent,
            $held,
            $expired,
        ));

        return self::SUCCESS;
    }

    private function send(
        Request $request,
        bool $complete,
        QuotationService $quotations,
        QuotationDispatchService $dispatch,
        OutgoingMailSender $sender,
        \App\Models\User $author,
    ): bool {
        try {
            // Новая версия КП по сегодняшним ценам: createDraft собирает
            // состав из заявки заново, а всё ещё неоценённое выбрасываем —
            // в клиентском документе строк без цены быть не должно.
            $quotation = $quotations->createDraft($request, $author);
            app(PartialQuoteService::class)->trimToPriced($quotation);
            $quotation = $quotation->fresh('items');

            if ($quotation->items->isEmpty()) {
                $this->warn(sprintf('  %s — нечего слать: ни одной оценённой позиции', $request->internal_code));

                return false;
            }

            $prepared = $dispatch->prepareDraft($quotation, $author);
            $result = $sender->sendDraft($prepared['draft']->id);

            if (! ($result['success'] ?? false)) {
                $this->warn(sprintf(
                    '  %s — письмо не ушло: %s',
                    $request->internal_code,
                    (string) ($result['error'] ?? 'неизвестная ошибка'),
                ));

                return false;
            }

            // Тот же post-send hook: он и КП пометит отправленным, и статус
            // заявки поставит — полное КП переведёт в «КП отправлено», а
            // дополненное оставит в «Частичном КП».
            $message = $result['draft'] ?? $prepared['draft'];
            $hooks = app(OutboundReplyHooks::class);
            if (! $hooks->applyPostSendHooks($message, $author)) {
                $hooks->detectOutboundDocuments($message);
            }

            $this->line(sprintf(
                '  %s — отправлено %s КП %s',
                $request->internal_code,
                $complete ? 'полное' : 'дополненное',
                $quotation->internal_code,
            ));

            return true;
        } catch (\Throwable $e) {
            Log::error('QuotesCompletePartialCommand: досылка не удалась', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
            $this->warn(sprintf('  %s — ошибка: %s', $request->internal_code, $e->getMessage()));

            return false;
        }
    }
}
