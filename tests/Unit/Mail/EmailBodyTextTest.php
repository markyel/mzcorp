<?php

namespace Tests\Unit\Mail;

use App\Services\Mail\EmailTextCleanerService;
use PHPUnit\Framework\TestCase;

/** Текст письма из HTML и обрезанная текстовая часть (19.11.2026: bidzaar, JIEFENG). */
class EmailBodyTextTest extends TestCase
{
    private function cleaner(): EmailTextCleanerService
    {
        return new EmailTextCleanerService;
    }

    public function test_nested_layout_tables_keep_the_letter_text(): void
    {
        // Вёрстка рассылки: таблица-каркас, в ячейке — таблицы с текстом (bidzaar «Запрос изменен»).
        $html = '<html><head><style>td{padding:0}</style></head><body><table><tr><td>'
            .'<table><tr><td><div>bidzaar</div></td></tr></table>'
            .'<table><tr><td><div>Запрос изменен</div><div>Здравствуйте, Мой ЗиП! Ознакомьтесь с изменениями.</div></td></tr></table>'
            .'<table><tr><td>Закупка: 393-737</td><td>Организатор: МФК «Меркурий Тауэр»</td></tr></table>'
            .'</td></tr></table></body></html>';

        $text = $this->cleaner()->htmlToText($html);

        $this->assertStringContainsString('Запрос изменен', $text);
        $this->assertStringContainsString('Здравствуйте, Мой ЗиП!', $text);
        $this->assertStringContainsString('Закупка: 393-737 | Организатор: МФК «Меркурий Тауэр»', $text);
        $this->assertStringNotContainsString('padding', $text);
    }

    public function test_a_plain_items_table_is_still_rows_with_pipes(): void
    {
        // M-2026-1961: артикул | наименование | кол-во — одна позиция на строку.
        $html = '<table><tr><td><p>ABC123</p></td><td><p>Ролик натяжной</p></td><td>2</td></tr>'
            .'<tr><td>XYZ9</td><td>Башмак</td><td>4</td></tr></table>';

        $this->assertSame("ABC123 | Ролик натяжной | 2\nXYZ9 | Башмак | 4", $this->cleaner()->htmlToText($html));
    }

    public function test_a_text_part_cut_after_the_greeting_is_replaced_by_the_html(): void
    {
        $html = '<div>Hello Sergey Agryzkov</div><div>Request No. MZ2026-01763</div>'
            .'<div>No.1 GAA65DP36 EXW price 1.8USD/PC, delivery time 3~5 days.</div>'
            .'<div>No.2 GAA65DP37 EXW price 2.2USD/PC, delivery time 3~5 days.</div>'
            .'<div>No.3 EXW price 2.2USD/PC, delivery time 3~5 days.</div>'
            .'<div>Best Regards! JIEFENG E&amp;E CO., LTD · Mr. Kid Xue, Business Development Manager · WhatsApp/Wechat · No.5 Xiadong Road Jiangyin City Jiangsu Province China · www.escalatorparts.cn</div>';

        $text = $this->cleaner()->bodyText('Hello Sergey Agryzkov', $html);

        $this->assertStringContainsString('EXW price 1.8USD/PC', $text);
        $this->assertSame('Hello', $this->cleaner()->bodyText('Hello', ''), 'без HTML — текстовая часть как есть');
    }

    public function test_junk_text_part_gives_way_to_the_html(): void
    {
        $this->assertTrue(EmailTextCleanerService::plainIsCut('| |', 'bidzaar Запрос изменен Здравствуйте, Мой ЗиП! Ознакомьтесь с изменениями.'));
    }

    public function test_a_short_reply_over_a_quote_and_a_real_short_word_are_kept(): void
    {
        $quoted = 'Спасибо, получили. '.str_repeat('Пт, 13 нояб. 2026 г., 14:14 Андрей Васюхно <andrey@myzip.ru> написал: Добрый день, высылаем КП по запросу. ', 6);
        $this->assertFalse(EmailTextCleanerService::plainIsCut('Спасибо, получили.', $quoted));

        // Liftway: клиент написал «см», HTML добавляет лишь шапку площадки и подвал — текст не обрезан.
        $liftway = 'LZ-REQ-4400 29.10.2026 15:53 · Liftway.ru Добрый день! см 📎 К письму приложены файлы. '.str_repeat('Liftway.ru Запчасти для лифтов и эскалаторов +7 (495) 111-77-33 support@liftway.ru ', 5);
        $this->assertFalse(EmailTextCleanerService::plainIsCut('см', $liftway));

        // Полный текст длиннее 300 знаков не трогаем, даже если в HTML ещё цитата и подписи.
        $this->assertFalse(EmailTextCleanerService::plainIsCut(str_repeat('Добрый день. ', 30), str_repeat('Добрый день. ', 30).str_repeat('подпись ', 100)));
    }
}
