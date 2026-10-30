<?php

namespace App\Console\Commands\QuoteParts;

use App\Models\QuotePartsOffer;
use App\Models\QuotePartsRfq;
use App\Services\QuoteParts\ForeignTextTranslator;
use App\Services\QuoteParts\QuotePartsPriceService;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Console\Command;

/**
 * Перевести уже пришедшее по-китайски: примечания и аналоги в предложениях, письма в
 * переписке запросов. Новое переводят синхронизация предложений и обновление снимка запроса;
 * команда — для того, что пришло раньше. --dry-run только считает.
 */
class TranslateForeignTextsCommand extends Command
{
    protected $signature = 'quoteparts:translate-foreign {--days=90 : За сколько дней} {--dry-run : Только показать, сколько переводить}';

    protected $description = 'Перевести на русский китайские тексты поставщиков QuoteParts (предложения и переписка)';

    public function handle(ForeignTextTranslator $translator, QuotePartsRfqService $rfqs): int
    {
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $dry = (bool) $this->option('dry-run');

        $offers = QuotePartsOffer::query()->where('updated_at', '>=', $since)
            ->get(['id', 'brand', 'part_number', 'product_name', 'note', 'original_texts'])
            ->filter(fn (QuotePartsOffer $o) => collect($o->only(['brand', 'part_number', 'product_name', 'note']))->contains(fn ($t) => ForeignTextTranslator::needs($t)));
        $this->info('Предложений с китайским текстом: '.$offers->count());
        if (! $dry && $offers->isNotEmpty()) {
            $texts = $offers->mapWithKeys(fn (QuotePartsOffer $o) => [$o->id => $o->only(['brand', 'part_number', 'product_name', 'note'])]);
            $ru = $translator->toRussian($texts->flatten()->filter()->values()->all());
            $done = 0;
            foreach ($offers as $o) {
                [$local, $originals] = QuotePartsPriceService::localized($texts[$o->id], $ru);
                if ($originals === []) {
                    continue;
                }
                $o->forceFill($local + ['original_texts' => array_merge((array) ($o->original_texts ?? []), $originals)])->save();
                $done++;
            }
            $this->info('Переведено: '.$done);
        }

        $count = 0;
        QuotePartsRfq::query()->where('synced_at', '>=', $since)->orderBy('id')->chunkById(50, function ($chunk) use ($rfqs, $dry, &$count) {
            foreach ($chunk as $rfq) {
                $snapshot = (array) $rfq->snapshot;
                $after = $dry ? $snapshot : $rfqs->russified($snapshot);
                $foreign = collect($snapshot['suppliers'] ?? [])->flatMap(fn ($s) => $s['thread'] ?? [])
                    ->filter(fn ($m) => ($m['direction'] ?? null) === 'in' && empty($m['text_ru']) && ForeignTextTranslator::needs((string) ($m['text'] ?? '')))->count();
                if ($foreign === 0) {
                    continue;
                }
                $count += $foreign;
                if (! $dry && $after !== $snapshot) {
                    // Только снимок: время обновления и статус не трогаем.
                    $rfq->forceFill(['snapshot' => $after])->saveQuietly();
                }
            }
        });
        $this->info(($dry ? 'Писем к переводу: ' : 'Писем переведено (или в работе): ').$count);

        return self::SUCCESS;
    }
}
