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
 * Строка КП с новой карточкой (M-артикул есть на mylift.ru, в каталоге ещё нет) не связывается с позицией, у которой
 * уже есть другая карточка. MZ2026-01220: LLM связал «Кнопку -1» M34817 с соседней «Кнопкой 1» (M25059).
 */
class OutboundQuoteNewSkuTest extends TestCase
{
    public function test_a_line_with_a_new_card_goes_only_to_an_item_without_a_card(): void
    {
        $matcher = new OutboundQuoteItemMatcher($this->createMock(OpenAIChatService::class), new MatchOutboundQuoteItemsPrompt);
        $line = new OutboundQuoteItem(['raw_name' => 'Кнопка приказа "-1" тип A4N49744S05', 'raw_article' => 'M34817']);
        $line->id = 42635;
        (new ReflectionProperty(OutboundQuoteItemMatcher::class, 'newSkuLines'))->setValue($matcher, [42635 => 'M34817']);
        $mayLink = new ReflectionMethod(OutboundQuoteItemMatcher::class, 'mayLink');

        $withCard = new RequestItem(['parsed_name' => 'Кнопка 1']);
        $withCard->catalog_item_id = 721;
        $withoutCard = new RequestItem(['parsed_name' => 'Кнопка -1']);

        $this->assertFalse($mayLink->invoke($matcher, $line, $withCard));
        $this->assertTrue($mayLink->invoke($matcher, $line, $withoutCard));

        $other = new OutboundQuoteItem(['raw_name' => 'Кнопка приказа "1"', 'raw_article' => 'M25059']);
        $other->id = 42636;
        $this->assertTrue($mayLink->invoke($matcher, $other, $withCard), 'обычная строка — без ограничений');
    }
}
