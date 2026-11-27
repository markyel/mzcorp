<?php

namespace Tests\Unit\Services\Quotes;

use App\Models\OutboundQuoteItem;
use App\Models\RequestItem;
use App\Prompts\Quotes\MatchOutboundQuoteItemsPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Quotes\OutboundQuoteItemMatcher;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Аналог в КП — вместо запрошенного, карточка у него своя (MZ2026-03007: CT60G M34950 вместо CPI48 M27101):
 * связывается с позицией, даже если у неё карточка оригинала.
 */
class OutboundQuoteAnalogTest extends TestCase
{
    private function item(int $id, string $name, ?int $card = null, bool $active = true): RequestItem
    {
        $ri = new RequestItem(['parsed_name' => $name, 'is_active' => $active]);
        $ri->id = $id;
        $ri->catalog_item_id = $card;

        return $ri;
    }

    private function line(int $id, bool $analog, ?int $linked = null): OutboundQuoteItem
    {
        $qi = new OutboundQuoteItem(['raw_name' => 'Частотный преобразователь CT60G (версия TKE TIS3)', 'raw_article' => 'M34950', 'is_analog' => $analog]);
        $qi->id = $id;
        $qi->matched_request_item_id = $linked;

        return $qi;
    }

    public function test_a_single_analog_line_pairs_with_the_single_free_position(): void
    {
        $items = collect([$this->item(35863, 'Силовой блок частотника ThyssenKrupp CPI48', 19723), $this->item(35868, 'Frequency converter CPI 48', null, false)]);
        $pair = OutboundQuoteItemMatcher::singleAnalogPair(collect([$this->line(49328, true)]), $items);
        $this->assertSame([49328, 35863], [$pair[0]->id, $pair[1]->id]);
    }

    public function test_no_pair_for_an_original_or_when_it_is_ambiguous(): void
    {
        $one = collect([$this->item(1, 'Ролик', 5)]);
        $this->assertNull(OutboundQuoteItemMatcher::singleAnalogPair(collect([$this->line(10, false)]), $one));
        $two = collect([$this->item(1, 'Ролик', 5), $this->item(2, 'Ролик', 6)]);
        $this->assertNull(OutboundQuoteItemMatcher::singleAnalogPair(collect([$this->line(10, true)]), $two));
        $this->assertSame(2, OutboundQuoteItemMatcher::singleAnalogPair(collect([$this->line(10, true), $this->line(11, false, 1)]), $two)[1]->id);
    }

    public function test_an_analog_with_a_new_card_may_link_to_an_item_with_a_card(): void
    {
        $matcher = new OutboundQuoteItemMatcher($this->createMock(OpenAIChatService::class), new MatchOutboundQuoteItemsPrompt);
        (new ReflectionProperty(OutboundQuoteItemMatcher::class, 'newSkuLines'))->setValue($matcher, [49328 => 'M34950', 49329 => 'M34951']);
        $mayLink = new ReflectionMethod(OutboundQuoteItemMatcher::class, 'mayLink');
        $withCard = $this->item(35863, 'CPI48', 19723);
        $this->assertTrue($mayLink->invoke($matcher, $this->line(49328, true), $withCard));
        $this->assertFalse($mayLink->invoke($matcher, $this->line(49329, false), $withCard));
    }
}
