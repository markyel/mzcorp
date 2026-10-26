<?php

namespace Tests\Unit\Services\Quotes;

use App\Models\CatalogItem;
use App\Models\OutboundQuoteItem;
use App\Models\RequestItem;
use App\Prompts\Quotes\MatchOutboundQuoteItemsPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Quotes\OutboundQuoteItemMatcher;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Step 3.5 матчера КП: одна строка КП с каталогом ↔ одна позиция заявки без каталога
 * связываются напрямую только при сходстве товара.
 *
 * Кейс M-2026-20451: в КП 371525 одна строка «Ролик замка дверей AMD» M32178, в заявке
 * единственная позиция без каталога — «Канатоведущий шкив … KM755995H07». Слепая пара
 * дала шкиву каталог ролика, и черновик T-QAAFHE в QuoteParts промоутнулся в M32178 —
 * поставщик OSS увидел в своём запросе чужой товар.
 *
 * БД не нужна — приватный метод вызывается через рефлексию на несохранённых моделях.
 */
class OutboundQuoteSinglePairTest extends TestCase
{
    private function looksLikeSameItem(OutboundQuoteItem $qi, RequestItem $ri): bool
    {
        $matcher = new OutboundQuoteItemMatcher($this->createMock(OpenAIChatService::class), new MatchOutboundQuoteItemsPrompt);
        $m = new ReflectionMethod(OutboundQuoteItemMatcher::class, 'looksLikeSameItem');
        $m->setAccessible(true);

        return (bool) $m->invoke($matcher, $qi, $ri);
    }

    private function quoteLine(string $rawName, ?string $rawArticle, CatalogItem $catalog): OutboundQuoteItem
    {
        $qi = new OutboundQuoteItem(['raw_name' => $rawName, 'raw_article' => $rawArticle]);
        $qi->setRelation('catalogItem', $catalog);

        return $qi;
    }

    private function requestItem(string $name, ?string $article): RequestItem
    {
        $ri = new RequestItem;
        $ri->parsed_name = $name;
        $ri->parsed_article = $article;

        return $ri;
    }

    public function test_a_different_product_is_not_paired_blindly(): void
    {
        $roller = new CatalogItem(['sku' => 'M32178', 'name' => 'Ролик замка дверей AMD D=31,5мм W=19,5мм', 'brand' => 'KONE', 'articles' => ['KM601107G04']]);

        $this->assertFalse($this->looksLikeSameItem(
            $this->quoteLine('Ролик замка дверей AMD D=31,5mm W=19,5mm', 'M32178', $roller),
            $this->requestItem('Канатоведущий шкив (Обод КВШ KONE, U-образная канавка, D=600 мм, 6 лунок, d=13мм лебедка MX20)', 'KM755995H07'),
        ));
    }

    public function test_the_same_product_in_other_words_is_paired(): void
    {
        // M-2026-18523 — ради него шаг и появился: клиент своими словами, без M-кода.
        $rail = new CatalogItem(['sku' => 'M12077', 'name' => 'Алюминиевая направляющая поручня Otis 506NCE', 'brand' => 'Otis']);

        $this->assertTrue($this->looksLikeSameItem(
            $this->quoteLine('Алюминиевая направляющая поручня Otis 506NCE M12077', 'M12077', $rail),
            $this->requestItem('Направляющая поручня эскалатора DAA402NVB5', 'DAA402NVB5'),
        ));
    }

    public function test_the_manufacturer_part_number_of_our_item_is_enough(): void
    {
        $roller = new CatalogItem(['sku' => 'M32178', 'name' => 'Ролик замка дверей AMD D=31,5мм W=19,5мм', 'brand' => 'KONE', 'articles' => ['KM601107G04']]);

        $this->assertTrue($this->looksLikeSameItem(
            $this->quoteLine('Ролик замка дверей AMD', 'M32178', $roller),
            $this->requestItem('Roller lock KONE', 'KM 601107 G04'),
        ));
    }
}
