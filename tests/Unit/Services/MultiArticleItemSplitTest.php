<?php

namespace Tests\Unit\Services;

use App\Services\RequestItemParsingService as Parser;
use Tests\TestCase;

/**
 * Резка позиции, в которую попало несколько наших артикулов.
 *
 * Кейсы M-2026-15853 («M00839, M00840 Цепь привода поручня 103 зв+замок») и
 * M-2026-16171 («FAA24350BL2, M00011, M25915»): парсер отдавал одну строку,
 * заявка выглядела однострочной, и в КП уезжала половина запроса.
 *
 * Без БД и без сети.
 */
class MultiArticleItemSplitTest extends TestCase
{
    public function test_two_own_articles_become_two_items(): void
    {
        $items = Parser::splitMultiArticleItems([[
            'name' => 'Цепь привода поручня 103 зв+замок',
            'article' => 'M00839, M00840',
            'qty' => 1,
            'unit' => 'компл.',
        ]]);

        $this->assertCount(2, $items);
        $this->assertSame('M00839', $items[0]['article']);
        $this->assertSame('M00840', $items[1]['article']);
        // Название и количество копируются: что кому принадлежит, знает автор
        // письма, и додумывать это резкой нельзя.
        $this->assertSame('Цепь привода поручня 103 зв+замок', $items[1]['name']);
        $this->assertStringContainsString('M00839, M00840', $items[1]['note']);
    }

    public function test_oem_code_next_to_ours_is_the_same_item(): void
    {
        // «FAA24350BL2» — код производителя того же редуктора, не вторая позиция.
        $items = Parser::splitMultiArticleItems([[
            'name' => 'Редуктор с мотором AT120 ЛЕВЫЙ',
            'article' => 'FAA24350BL2, M00011',
            'qty' => 1,
        ]]);

        $this->assertCount(1, $items);
        $this->assertSame('FAA24350BL2, M00011', $items[0]['article']);
    }

    public function test_three_own_articles_become_three(): void
    {
        $items = Parser::splitMultiArticleItems([[
            'name' => 'Кнопки приказа',
            'article' => 'FAA24350BL2, M00011, M25915',
            'qty' => 2,
        ]]);

        $this->assertCount(2, $items);
        $this->assertSame(['M00011', 'M25915'], array_column($items, 'article'));
        $this->assertSame(2, $items[0]['qty']);
    }

    public function test_a_normal_item_is_left_alone(): void
    {
        $one = [['name' => 'Ролик', 'article' => 'M00193', 'qty' => 4]];

        $this->assertSame($one, Parser::splitMultiArticleItems($one));
    }

    public function test_own_sku_detection(): void
    {
        $this->assertSame(['M00839', 'M00840'], Parser::ownSkusIn('M00839, M00840 Цепь'));
        $this->assertSame(['M00011'], Parser::ownSkusIn('m00011 и он же'));
        // Повтор одного кода — одна позиция.
        $this->assertSame(['M00011'], Parser::ownSkusIn('M00011, M00011'));
        $this->assertSame([], Parser::ownSkusIn('FAA24350BL2, KM857781G12'));
        // Слишком короткий хвост — не наш код.
        $this->assertSame([], Parser::ownSkusIn('M12 болт'));
    }
}
