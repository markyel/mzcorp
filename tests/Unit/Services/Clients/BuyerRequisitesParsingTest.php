<?php

namespace Tests\Unit\Services\Clients;

use App\Console\Commands\ClientsExtractRequisitesCommand as Cmd;
use Tests\TestCase;

/**
 * Опознание организации-покупателя в наших исходящих документах.
 *
 * Защита от мусора здесь важнее полноты: раньше «голый ИНН» без проверки
 * названия давал 17 мусорных организаций из 18, поэтому имя обязано выглядеть
 * как организация — с формой собственности. Без БД и без сети.
 */
class BuyerRequisitesParsingTest extends TestCase
{
    public function test_company_forms_are_recognised(): void
    {
        $this->assertTrue(Cmd::looksLikeCompany('ООО«Техкомплект»'));
        $this->assertTrue(Cmd::looksLikeCompany('ООО "Мой Лифт"'));
        $this->assertTrue(Cmd::looksLikeCompany('ИП Маркелов Дмитрий Евгеньевич'));
        $this->assertTrue(Cmd::looksLikeCompany('АО «ВДНХ»'));
        $this->assertTrue(Cmd::looksLikeCompany('Общество с ограниченной ответственностью «Лифт»'));
    }

    public function test_name_starts_at_the_company_form(): void
    {
        // Слева от названия в КП остаётся хвост соседней колонки документа.
        $this->assertSame('ООО«Техкомплект»', Cmd::fromCompanyForm('info@mylift.ru ООО«Техкомплект»'));
        $this->assertSame('АО «ВДНХ»', Cmd::fromCompanyForm('тел.: +7 (495) 111-22-33 АО «ВДНХ»'));
        $this->assertSame('Просто название', Cmd::fromCompanyForm('Просто название'));
    }

    public function test_address_stops_at_the_next_column(): void
    {
        // «…ком. 12, Заказчик: тел.: …» — всё после подписи уже не адрес.
        $this->assertSame(
            '129626, г. Москва, Проспект Мира, д. 102, корп. 1, эт. 7, ком. 12',
            Cmd::cutAddress('129626, г. Москва, Проспект Мира, д. 102, корп. 1, эт. 7, ком. 12, Заказчик: тел.: +7 (495) 988-25-58 Карта клиента:'),
        );
    }

    public function test_junk_is_not_a_company(): void
    {
        // Строки из тела документа не должны стать организацией.
        $this->assertFalse(Cmd::looksLikeCompany('Датчик шахтной информации 325 МВ'));
        $this->assertFalse(Cmd::looksLikeCompany('тел.: +7 (495) 988-25-58'));
        $this->assertFalse(Cmd::looksLikeCompany('6311-2RS'));
        $this->assertFalse(Cmd::looksLikeCompany(''));
    }
}
