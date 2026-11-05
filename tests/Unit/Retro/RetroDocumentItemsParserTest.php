<?php

namespace Tests\Unit\Retro;

use App\Services\Retro\RetroDocumentItemsParser;
use PHPUnit\Framework\TestCase;

/**
 * Раскладки таблиц наших КП и счетов из 1С в тексте pdftotext -layout (данные вымышленные,
 * отступы как в настоящих документах).
 */
class RetroDocumentItemsParserTest extends TestCase
{
    public function test_invoice_with_the_name_continued_below_and_the_article_at_its_end(): void
    {
        $text = <<<'TXT'
Счет на оплату № 9999 от 14 сентября 2026
 №                      Товар                     Кол-во       Ед.          Резерв    Заказ       Цена       Сумма
  1   Трос бесконечности d=3мм, L=1790,                    3 шт                               3   1 788,81     5 366,43
      шпилька M6 Fermator, M34432
  2   Доставка транспортной компанией                      1 шт                               1   1 000,00     1 000,00
                                                                                                Итого:        6 366,43
                                                                                      В том числе НДС:        1 147,98
TXT;
        $result = RetroDocumentItemsParser::parse($text);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('Трос бесконечности d=3мм, L=1790, шпилька M6 Fermator', $result['items'][0]['name']);
        $this->assertSame('M34432', $result['items'][0]['article']);
        $this->assertSame(3.0, $result['items'][0]['qty']);
        $this->assertSame('шт', $result['items'][0]['unit']);
        $this->assertSame(1788.81, $result['items'][0]['price']);
        $this->assertSame(5366.43, $result['items'][0]['sum']);
    }

    public function test_quote_with_the_name_above_and_below_the_numbered_line(): void
    {
        $text = <<<'TXT'
        Наименование, характеристика, артикул                                                    Актуальность                                 %      Цена со                    НДС
№                                                                         Срок поставки
                                                                                                                    Кол-во      Цена
                       товаров
     Ролик прижимной поручня эскалатора (D76 mm, W57 mm,                                      Гарантировано
 1                                                               M11274   3,00 шт   В наличии                        3,00 шт     11 651,57   20,00      9 321,26     27 963,78       5 042,65
     подшипник 6204 RS) с кронштейном                                                               до 01.07.2026
 2 Плата управления дверей                                       M03619     1,00 шт   В наличии                         1,00 шт    1 000,00     0,00      1 000,00     1 000,00       180,33
                                                                                                    до 01.07.2026
Примечание: Информация о наличии указана по состоянию склада на 26.06.2026 13:53:43.
                                                                                                                                                        Итого:                   35 954,71
                                                                                                                                               Скидка %19,44:                    - 6 990,93
                                                                                                                                             Итого со скидкой:                   28 963,78
TXT;
        $result = RetroDocumentItemsParser::parse($text);

        $this->assertTrue($result['ok'], 'Сумма строк сходится с «Итого со скидкой»');
        $this->assertSame('Ролик прижимной поручня эскалатора (D76 mm, W57 mm, подшипник 6204 RS) с кронштейном', $result['items'][0]['name']);
        $this->assertSame('M11274', $result['items'][0]['article']);
        $this->assertSame(3.0, $result['items'][0]['qty']);
        $this->assertSame(20.0, $result['items'][0]['discount']);
        $this->assertSame(9321.26, $result['items'][0]['price_discounted']);
        $this->assertSame(27963.78, $result['items'][0]['sum'], 'Сумма — предпоследняя колонка, последняя — НДС');
        $this->assertSame('Плата управления дверей', $result['items'][1]['name']);
    }

    public function test_quote_where_the_number_stands_below_the_amounts_and_delivery_has_its_own_table(): void
    {
        $text = <<<'TXT'
        Наименование, характеристика, артикул                                                      Актуальность                                 %      Цена со                     НДС
                      товаров
   Поручень резиновый тип C699 черный 80мм,                                                       Гарантировано
                                                                 M15555     22,30 м   В наличии                    22,30 м        3 190,00   17,00      2 647,70     59 043,71    10 647,23
 1 слайдер SF (Производство SKG)                                                                   до 18.10.2026
   Гарантия 1,5 года
   Цепь отклоняющая балюстрады I-тип                                                              Гарантировано
 2                                                               M03794     2,00 шт   В наличии                     2,00 шт       4 219,68   17,00      3 502,33      7 004,66     1 263,14
   L=1365 mm Шаг 60 mm                                                                             до 18.10.2026
Примечание: Информация о наличии указана по состоянию склада на 13.10.2026 10:46:06.
№               Наименование, характеристика, артикул товаров                                      доставки
 3 Доставка Деловые Линии до терминала                                                            5 сут/дн       1,00 шт       6 021,00    0,00      6 021,00      6 021,00     1 085,70
Примечание: Сумма доставки рассчитывается на все позиции предложения.
                                                                                                                                                        Итого:              72 069,37
TXT;
        $result = RetroDocumentItemsParser::parse($text);

        $this->assertTrue($result['ok']);
        $this->assertSame(['Поручень резиновый тип C699 черный 80мм, слайдер SF (Производство SKG) Гарантия 1,5 года',
            'Цепь отклоняющая балюстрады I-тип L=1365 mm Шаг 60 mm', 'Доставка Деловые Линии до терминала'], array_column($result['items'], 'name'));
        $this->assertSame([1, 2, 3], array_column($result['items'], 'position'));
        $this->assertSame(22.3, $result['items'][0]['qty']);
        $this->assertSame('м', $result['items'][0]['unit']);
    }

    public function test_document_whose_lines_do_not_add_up_gives_no_items(): void
    {
        $text = <<<'TXT'
 №                      Товар                     Кол-во       Ед.          Резерв    Заказ       Цена       Сумма
  1   Кнопка вызова                                        2 шт                               2     100,00       200,00
                                                                                                Итого:          500,00
TXT;
        $result = RetroDocumentItemsParser::parse($text);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['items']);
        $this->assertSame(500.0, $result['total']);
    }
}
