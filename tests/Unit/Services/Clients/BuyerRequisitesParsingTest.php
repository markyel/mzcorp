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

    public function test_junk_is_not_a_company(): void
    {
        // Строки из тела документа не должны стать организацией.
        $this->assertFalse(Cmd::looksLikeCompany('Датчик шахтной информации 325 МВ'));
        $this->assertFalse(Cmd::looksLikeCompany('тел.: +7 (495) 988-25-58'));
        $this->assertFalse(Cmd::looksLikeCompany('6311-2RS'));
        $this->assertFalse(Cmd::looksLikeCompany(''));
    }
}
