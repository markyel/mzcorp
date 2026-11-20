<?php

namespace Tests\Unit\Services\Quotes;

use App\Models\OutboundQuoteItem;
use App\Models\RequestItem;
use App\Prompts\Quotes\MatchOutboundQuoteItemsPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Quotes\OutboundQuoteCatalogEnricher;
use App\Services\Quotes\OutboundQuoteItemMatcher;
use ReflectionMethod;
use Tests\TestCase;

/**
 * КП перебивает карточку позиции (MZ2026-02473, 20.11.2026): в КП замена M04160 «Контактор 32 A» на «Пускатель
 * LC1DS32», у которого осталась автопривязка M32038. БД не нужна.
 */
class OutboundQuoteOverridesCardTest extends TestCase
{
    private function item(?string $method, bool $active = true): RequestItem
    {
        $ri = new RequestItem;
        $ri->is_active = $active;
        $ri->catalog_item_id = 18205;
        $ri->quality_assessment_payload = $method ? ['catalog_match' => ['method' => $method]] : [];

        return $ri;
    }

    private function line(string $source): OutboundQuoteItem
    {
        $qi = new OutboundQuoteItem;
        $qi->match_source = $source;

        return $qi;
    }

    public function test_one_quote_line_overrides_an_automatic_card(): void
    {
        $qi = $this->line(OutboundQuoteItem::MATCH_SOURCE_CATALOG_NAME_TO_REQUEST);

        $this->assertTrue(OutboundQuoteCatalogEnricher::quoteOverridesCard($qi, $this->item('B_brand_article'), collect([$qi])));
        $this->assertTrue(OutboundQuoteCatalogEnricher::quoteOverridesCard($qi, $this->item(null), collect([$qi])));
    }

    public function test_a_card_set_by_a_person_stays_unless_a_person_linked_the_line(): void
    {
        $auto = $this->line(OutboundQuoteItem::MATCH_SOURCE_LLM);
        $manual = $this->line(OutboundQuoteItem::MATCH_SOURCE_MANUAL);

        $this->assertFalse(OutboundQuoteCatalogEnricher::quoteOverridesCard($auto, $this->item('manual_link'), collect([$auto])));
        $this->assertTrue(OutboundQuoteCatalogEnricher::quoteOverridesCard($manual, $this->item('manual_link'), collect([$manual])));
    }

    public function test_several_lines_on_one_item_are_options_not_a_replacement(): void
    {
        // Канат и барабан для каната, левая и правая гребёнка — к одной позиции.
        $a = $this->line(OutboundQuoteItem::MATCH_SOURCE_CATALOG_TO_REQUEST);
        $b = $this->line(OutboundQuoteItem::MATCH_SOURCE_LLM);

        $this->assertFalse(OutboundQuoteCatalogEnricher::quoteOverridesCard($b, $this->item('B_brand_article'), collect([$a, $b])));
        $this->assertFalse(OutboundQuoteCatalogEnricher::quoteOverridesCard($b, $this->item('B_brand_article', active: false), collect([$b])));
    }

    public function test_the_replacement_is_closer_to_the_items_own_card_than_to_the_neighbour(): void
    {
        $matcher = new OutboundQuoteItemMatcher($this->createMock(OpenAIChatService::class), new MatchOutboundQuoteItemsPrompt);
        $sim = new ReflectionMethod(OutboundQuoteItemMatcher::class, 'nameSimilarity');
        $sim->setAccessible(true);

        $line = 'КОНТАКТОР D 3Р,32 A,НО+НЗ,110VAC 50/60ГЦ,ЗАЖИМ ПОД ВИНТ';
        $ownCard = $sim->invoke($matcher, $line, 'КОНТАКТОР D TeSys Deca 3Р,32 A,НО+НЗ,110VAC 50/60ГЦ,ЗАЖИМ ПОД ВИНТ');
        $neighbour = $sim->invoke($matcher, $line, 'Контактор 3Р, 9 A, НО+НЗ, 110В 50/60 Гц, ЗАЖИМ ПОД ВИНТ');

        $this->assertGreaterThan($neighbour, $ownCard);
        $this->assertGreaterThanOrEqual(0.5, $ownCard);
    }
}
