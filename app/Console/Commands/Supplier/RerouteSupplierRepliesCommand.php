<?php

namespace App\Console\Commands\Supplier;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Services\Supplier\SupplierInquiryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовая переприкрепка ошибочно привязанных ответов поставщиков. Поставщик
 * часто рвёт In-Reply-To, но сохраняет тему с нашим кодом; раньше ответ валился
 * в «последний инквайри по e-mail» (ingestSupplierMessage), даже если тема несёт
 * код ДРУГОГО запроса. matchInboundByAnyCode находит правильный инквайри по коду
 * (M-YYYY-NNNN / док-номер) в теме. Команда прогоняет это по уже привязанным
 * входящим и переносит те, чей код указывает на иной инквайри.
 *
 *   php artisan suppliers:reroute-replies            # dry-run
 *   php artisan suppliers:reroute-replies --apply     # перенести
 */
class RerouteSupplierRepliesCommand extends Command
{
    protected $signature = 'suppliers:reroute-replies
        {--apply : Применить перенос (иначе dry-run)}
        {--limit=0 : Ограничить число писем (0 = все)}';

    protected $description = 'Переприкрепить ошибочно привязанные ответы поставщиков к правильному инквайри по коду в теме.';

    public function handle(SupplierInquiryService $service): int
    {
        $q = EmailMessage::query()
            ->where('direction', MailDirection::Inbound->value)
            ->whereNotNull('supplier_inquiry_id')
            ->orderByDesc('id');
        if (($lim = (int) $this->option('limit')) > 0) {
            $q->limit($lim);
        }

        $checked = 0;
        $moved = 0;
        $samples = [];
        $q->chunkById(300, function ($rows) use ($service, &$checked, &$moved, &$samples) {
            foreach ($rows as $msg) {
                $checked++;
                $target = $service->matchInboundByAnyCode($msg);
                if ($target === null || (int) $target->id === (int) $msg->supplier_inquiry_id) {
                    continue;
                }
                $moved++;
                if (count($samples) < 20) {
                    $samples[] = sprintf('msg#%d «%s» : #%d → #%d', $msg->id,
                        mb_substr((string) $msg->subject, 0, 42), $msg->supplier_inquiry_id, $target->id);
                }
                if ($this->option('apply')) {
                    DB::transaction(fn () => $msg->forceFill(['supplier_inquiry_id' => $target->id])->save());
                }
            }
        }, 'id');

        $this->info(($this->option('apply') ? 'ПЕРЕНЕСЕНО' : 'НАШЛОСЬ (dry-run)').": {$moved} из {$checked} проверенных.");
        foreach ($samples as $s) {
            $this->line('  '.$s);
        }
        if (! $this->option('apply') && $moved > 0) {
            $this->warn('Это dry-run. Повторите с --apply, чтобы перенести.');
        }

        return self::SUCCESS;
    }
}
