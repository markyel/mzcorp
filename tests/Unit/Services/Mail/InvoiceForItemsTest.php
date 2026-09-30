<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\PostSaleFulfillmentDetector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Граница «заказ / вопрос о статусе».
 *
 * Письмо Liftway «Просим подтвердить наличие, цены и сроки поставки по
 * следующим позициям и выставить счёт» уходило в постпродажу: в нём есть слова
 * про сроки, и reply-ветка детектора считала его вопросом об уже размещённом
 * заказе — заявка на две позиции не создавалась вовсе.
 *
 * Гард узкий намеренно. Прогон корпуса (542 письма) показал: если считать
 * заказом любую просьбу о счёте, в заказы уедут «сделайте счет по заказу во
 * вложении» и «по срокам успеваем? Счет № 6272» — а это постпродажа.
 */
class InvoiceForItemsTest extends TestCase
{
    private function looksLikeOrder(string $text): bool
    {
        $re = (new ReflectionClass(PostSaleFulfillmentDetector::class))->getConstant('INVOICE_FOR_ITEMS_RE');

        return preg_match($re, mb_strtolower($text)) === 1;
    }

    public function test_an_invoice_asked_for_a_list_of_positions_is_an_order(): void
    {
        $this->assertTrue($this->looksLikeOrder(
            'Просим подтвердить наличие, цены и сроки поставки по следующим позициям и выставить счёт:'
        ));
        $this->assertTrue($this->looksLikeOrder('Выставьте счет на позиции из спецификации'));
        $this->assertTrue($this->looksLikeOrder('По списку ниже выставить счет, пожалуйста'));
    }

    public function test_an_invoice_asked_for_an_article_with_quantity_is_an_order(): void
    {
        // ЛифтКоннект «ЛМ6446, МойЛифт, Москва», 30.09: «Заберем самостоятельно»
        // уводило письмо в постпродажу как самовывоз.
        $this->assertTrue($this->looksLikeOrder(
            "Добрый день.\nПрошу прислать счет на:\nKM264859 - 1шт.\n*Заберем самостоятельно!*"
        ));
        $this->assertTrue($this->looksLikeOrder('Выставьте нам счет на M12243 — 5 шт'));
    }

    public function test_an_invoice_for_an_existing_order_is_not_an_order(): void
    {
        $this->assertFalse($this->looksLikeOrder('Пришлите счет на оплату по заказу 1234, заберем сами'));
        $this->assertFalse($this->looksLikeOrder('Пришлите счет на доставку. Заберем 3 шт завтра'));
    }

    public function test_post_sale_letters_are_left_alone(): void
    {
        $this->assertFalse($this->looksLikeOrder('Принял сделайте, пожалуйста, счет по заказу во вложении'));
        $this->assertFalse($this->looksLikeOrder('Добрый день. Дмитрий, по срокам успеваем? Счет № 6272'));
        $this->assertFalse($this->looksLikeOrder('Завтра заберем зап.части, которые готовы к выдаче'));
        $this->assertFalse($this->looksLikeOrder('Ваш заказ готов к отгрузке, доставим завтра'));
    }
}
