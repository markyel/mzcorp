<?php

namespace App\Console\Commands\Supplier;

use App\Models\SupplierInquiry;
use App\Services\Supplier\SupplierOfferParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Перепарс офферов по инквайри, где поставщик ОТВЕТИЛ, но офферов нет (0) —
 * из-за прежнего мис-роутинга ответ парсился в контексте чужого инквайри и
 * цена не привязывалась. После suppliers:reroute-replies письма стоят на
 * правильных инквайри — прогоняем SupplierOfferParser заново.
 *
 * Только инквайри С ПОЗИЦИЯМИ (иначе цену не к чему привязать). LLM-вызов на
 * каждый входящий ответ — запускать батчами.
 *
 *   php artisan suppliers:reparse-offers --limit=50
 *   php artisan suppliers:reparse-offers --inquiry=2698
 */
class ReparseSupplierOffersCommand extends Command
{
    protected $signature = 'suppliers:reparse-offers
        {--limit=0 : Ограничить число инквайри (0 = все подходящие)}
        {--inquiry= : Только один инквайри по id}';

    protected $description = 'Перепарсить офферы поставщиков там, где есть ответ, но 0 офферов (после переприкрепки).';

    public function handle(SupplierOfferParser $parser): int
    {
        $ids = $this->targetInquiryIds();
        $this->info('Инквайри к перепарсу: '.count($ids));

        $tot = ['quoted' => 0, 'refused' => 0, 'skipped' => 0];
        $done = 0;
        foreach ($ids as $iid) {
            $inquiry = SupplierInquiry::find($iid);
            if ($inquiry === null) {
                continue;
            }
            $replies = $inquiry->messages()->where('direction', 'inbound')->orderBy('sent_at')->get();
            foreach ($replies as $reply) {
                try {
                    $r = $parser->parse($inquiry, $reply);
                    $tot['quoted'] += $r['quoted'];
                    $tot['refused'] += $r['refused'];
                    $tot['skipped'] += $r['skipped'];
                } catch (\Throwable $e) {
                    $this->warn("инквайри #{$iid} ответ #{$reply->id}: ".$e->getMessage());
                }
            }
            $done++;
            if ($done % 20 === 0) {
                $this->line("  … {$done}/".count($ids)." (офферов: {$tot['quoted']})");
            }
        }

        $this->info("Готово. Обработано инквайри: {$done}. Офферов: цена={$tot['quoted']} отказ={$tot['refused']} пропущено={$tot['skipped']}");

        return self::SUCCESS;
    }

    /** @return array<int, int> */
    private function targetInquiryIds(): array
    {
        if ($one = $this->option('inquiry')) {
            return [(int) $one];
        }

        $q = DB::table('supplier_inquiries as si')
            ->where('si.status', 'open')
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('email_messages')
                ->whereColumn('email_messages.supplier_inquiry_id', 'si.id')->where('email_messages.direction', 'inbound'))
            ->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('supplier_offers')
                ->whereColumn('supplier_offers.supplier_inquiry_id', 'si.id'))
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('supplier_inquiry_items')
                ->whereColumn('supplier_inquiry_items.supplier_inquiry_id', 'si.id'))
            ->orderByDesc('si.id')
            ->select('si.id');

        if (($lim = (int) $this->option('limit')) > 0) {
            $q->limit($lim);
        }

        return $q->pluck('id')->map(fn ($x) => (int) $x)->all();
    }
}
