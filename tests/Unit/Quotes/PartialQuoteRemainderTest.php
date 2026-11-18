<?php

namespace Tests\Unit\Quotes;

use App\Services\Quotes\PartialQuoteRemainderService;
use PHPUnit\Framework\TestCase;

/** «Частичное КП», этап 2 (18.11.2026): письмо в треде закрытой заявки — про позиции остатка или постпродажа. */
class PartialQuoteRemainderTest extends TestCase
{
    private function items(array $rows): \Illuminate\Support\Collection
    {
        return collect(array_map(fn ($r) => (object) ['parsed_name' => $r[0], 'parsed_article' => $r[1] ?? null], $rows));
    }

    public function test_a_letter_naming_a_remainder_item_concerns_the_remainder(): void
    {
        // MZ2026-01209: остался «Центрирующий ролик».
        $items = $this->items([['Центрирующий ролик']]);

        $this->assertTrue(PartialQuoteRemainderService::textConcernsItems($items, 'Добрый день! А по центрирующему ролику есть новости?'));
        $this->assertFalse(PartialQuoteRemainderService::textConcernsItems($items, 'Когда отгрузка? Пришлите, пожалуйста, УПД.'));
    }

    public function test_an_article_or_the_rest_of_the_items_concern_the_remainder(): void
    {
        $items = $this->items([['Цепь привода поручня', 'KM1338924']]);

        $this->assertTrue(PartialQuoteRemainderService::textConcernsItems($items, 'Что с KM 1338924?'));
        $this->assertTrue(PartialQuoteRemainderService::textConcernsItems($items, 'Оплатили. Ждём КП по остальным позициям.'));
        $this->assertFalse(PartialQuoteRemainderService::textConcernsItems($items, 'Оплатили, ждём счёт-фактуру.'));
    }

    public function test_one_word_of_the_name_is_not_enough(): void
    {
        // «ролик» есть и в оплаченной части — одного общего слова мало.
        $items = $this->items([['Ролик направляющий поручня']]);

        $this->assertFalse(PartialQuoteRemainderService::textConcernsItems($items, 'Ролик получили, спасибо.'));
        $this->assertTrue(PartialQuoteRemainderService::textConcernsItems($items, 'Направляющий ролик когда будет?'));
    }
}
