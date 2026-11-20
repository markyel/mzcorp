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

    public function test_delivery_and_a_bonus_card_from_the_signature_are_not_goods(): void
    {
        // MZ2026-01114 / M-2026-3076: «Бонусная карта №20006176» из подписи клиента; MZ2026-01708 — доставка строкой заявки.
        $card = $this->item(2);
        $card->parsed_name = 'Бонусная карта №20006176';
        $delivery = $this->item(3);
        $delivery->parsed_name = 'Доставка Деловые Линии по адресу Ижевск';
        $items = collect([$this->item(1, 100), $card, $delivery]);

        $this->assertSame([], $this->ids(QuoteCoverageService::uncoveredOf($items, collect([$this->line('Плата', 1, 100)]))));
    }

    public function test_our_m_article_in_the_quote_covers_the_item_with_that_card(): void
    {
        // MZ2026-01220: «Кнопка -1» — новая карточка M34817, «Кнопка 1» — M25059; разбор связал обе строки с «Кнопкой 1».
        $card = fn (int $id, string $sku) => tap(new \App\Models\CatalogItem, fn ($c) => $c->forceFill(['id' => $id, 'sku' => $sku]));
        $minus = $this->item(1, 31310);
        $minus->setRelation('catalogItem', $card(31310, 'M34817'));
        $plus = $this->item(2, 721);
        $plus->setRelation('catalogItem', $card(721, 'M25059'));
        $lines = collect([
            (object) ['raw_name' => 'Кнопка приказа "-1" тип A4N49744S05', 'raw_article' => 'M34817', 'matched_request_item_id' => 2, 'matched_catalog_item_id' => null],
            (object) ['raw_name' => 'Кнопка приказа "1" тип A4N49744S05', 'raw_article' => 'M25059', 'matched_request_item_id' => 2, 'matched_catalog_item_id' => 721],
        ]);

        $this->assertSame([], $this->ids(QuoteCoverageService::uncoveredOf(collect([$minus, $plus]), $lines)));
    }

    public function test_the_mylift_page_tells_an_existing_article(): void
    {
        $this->assertSame('Кнопка приказа "-1" тип A4N49744S05 с браилем', \App\Services\Catalog\MyliftArticleLookup::nameFromHtml(
            '<meta itemprop="name" content="Кнопка приказа "-1" тип A4N49744S05 с браилем" />'));
        $this->assertNull(\App\Services\Catalog\MyliftArticleLookup::nameFromHtml('<meta itemprop="name" content="" />'));
    }

    public function test_after_a_quote_waiting_for_the_client_follows_the_quote_milestone(): void
    {
        // MZ2026-01401: КП на одну позицию из двух, затем вопрос клиенту — «Жду клиента» с вехой КП.
        $r = new \App\Models\Request;
        $r->status = \App\Enums\RequestStatus::AwaitingClientClarification;
        $r->peak_status = \App\Enums\RequestStatus::PartiallyQuoted;
        $this->assertTrue(QuoteCoverageService::followsQuote($r));
        $this->assertTrue(QuoteCoverageService::isPartial($r));

        $r->peak_status = \App\Enums\RequestStatus::Invoiced; // сделка ушла дальше КП — веху не трогаем
        $this->assertFalse(QuoteCoverageService::followsQuote($r));
    }

    public function test_manager_marks_and_mzcorp_quotations_cover_items(): void
    {
        $items = collect([$this->item(1), $this->item(2, null, QuoteCoverageService::RESOLUTION_DECLINED), $this->item(3, null, QuoteCoverageService::RESOLUTION_SUBSTITUTE), $this->item(4)]);
        $lines = collect([$this->line('Плата', 1, 7)]);

        $this->assertSame([4], $this->ids(QuoteCoverageService::uncoveredOf($items, $lines)));
        $this->assertSame([], $this->ids(QuoteCoverageService::uncoveredOf($items, $lines, [4])));
    }

    public function test_each_item_is_marked_quoted_open_deferred_or_resolved(): void
    {
        // M-2026-21151: «Частичное КП», а по списку позиций не видно, на что КП уже выдано.
        $deferred = $this->item(3);
        $deferred->setRawAttributes([...$deferred->getAttributes(), 'quote_deferred_at' => \Illuminate\Support\Carbon::parse('2026-11-20 10:00')]);
        $items = collect([$this->item(1, 5178), $this->item(2), $deferred, $this->item(4, null, QuoteCoverageService::RESOLUTION_DECLINED), $this->item(5)]);
        $lines = collect([
            (object) ['raw_name' => 'Башмак', 'matched_request_item_id' => 1, 'matched_catalog_item_id' => 5178, 'position' => 1, 'document_number' => '374209', 'document_date' => '2026-11-20'],
            (object) ['raw_name' => 'Ролик', 'matched_request_item_id' => 5, 'matched_catalog_item_id' => null, 'position' => 2, 'document_number' => '374209', 'document_date' => '2026-11-20'],
            (object) ['raw_name' => 'Доставка Деловые Линии', 'matched_request_item_id' => 5, 'matched_catalog_item_id' => null, 'position' => 3, 'document_number' => '374209', 'document_date' => '2026-11-20'],
        ]);

        $marks = QuoteCoverageService::itemMarksOf($items, $lines);

        $this->assertSame('quoted', $marks[1]['state']);
        $this->assertSame(['1'], array_map('strval', $marks[1]['positions']));
        $this->assertSame(['№374209 от 20.11'], $marks[1]['docs']);
        $this->assertSame('open', $marks[2]['state']);
        $this->assertSame('deferred', $marks[3]['state']);
        $this->assertSame('resolved', $marks[4]['state']);
        $this->assertSame('мы отказали', $marks[4]['label']);
        $this->assertSame(['2'], array_map('strval', $marks[5]['positions']), 'строка доставки — не позиция КП товара');
    }
}