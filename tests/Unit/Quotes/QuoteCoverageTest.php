<?php

namespace Tests\Unit\Quotes;

use App\Models\RequestItem;
use App\Services\Quotes\QuoteCoverageService;
use PHPUnit\Framework\TestCase;

/** «Частичное КП» (18.11.2026): какие позиции заявки не покрыты отправленными КП. Случаи — с прода. */
class QuoteCoverageTest extends TestCase
{
    private function item(int $id, ?int $catalog = null, ?string $resolution = null): RequestItem
    {
        $i = new RequestItem;
        $i->forceFill(['id' => $id, 'catalog_item_id' => $catalog, 'quote_resolution' => $resolution, 'parsed_name' => 'item '.$id]);

        return $i;
    }

    private function line(string $name, ?int $item = null, ?int $catalog = null): object
    {
        return (object) ['raw_name' => $name, 'matched_request_item_id' => $item, 'matched_catalog_item_id' => $catalog];
    }

    private function ids($c): array
    {
        return $c->pluck('id')->all();
    }

    public function test_a_line_matched_to_the_item_or_to_its_catalog_card_covers_it(): void
    {
        // MZ2026-01469: 4 подшипника, в КП те же M-артикулы, но строки сопоставились только с каталогом.
        $items = collect([$this->item(1, 5178), $this->item(2, 4509)]);
        $lines = collect([$this->line('Подшипник 2x рядный', null, 5178), $this->line('Подшипник вторичного вала', 2, 4509), $this->line('Доставка Деловые Линии')]);

        $this->assertSame([], $this->ids(QuoteCoverageService::uncoveredOf($items, $lines)));
    }

    public function test_an_extra_line_is_a_substitution_for_an_uncovered_item(): void
    {
        // MZ2026-01343: просили поручень SDS (карточка 3944), в КП три других поручня — замена.
        $items = collect([$this->item(1, 3944)]);
        $lines = collect([$this->line('Поручень 1699-sw', null, 19365), $this->line('Поручень C699', null, 1200), $this->line('Доставка ДЛ')]);

        $this->assertSame([], $this->ids(QuoteCoverageService::uncoveredOf($items, $lines)));
    }

    public function test_an_item_without_any_offer_keeps_the_quote_partial(): void
    {
        // MZ2026-01209: две позиции, КП на одну — «центрирующий ролик» без предложения.
        $items = collect([$this->item(1, 100), $this->item(2)]);
        $lines = collect([$this->line('Ролик', 1, 100), $this->line('Доставка ЭКСПРЕСС по адресу')]);

        $this->assertSame([2], $this->ids(QuoteCoverageService::uncoveredOf($items, $lines)));
    }

    public function test_manager_marks_and_mzcorp_quotations_cover_items(): void
    {
        $items = collect([$this->item(1), $this->item(2, null, QuoteCoverageService::RESOLUTION_DECLINED), $this->item(3, null, QuoteCoverageService::RESOLUTION_SUBSTITUTE), $this->item(4)]);
        $lines = collect([$this->line('Плата', 1, 7)]);

        $this->assertSame([4], $this->ids(QuoteCoverageService::uncoveredOf($items, $lines)));
        $this->assertSame([], $this->ids(QuoteCoverageService::uncoveredOf($items, $lines, [4])));
    }
}
