<?php

namespace Tests\Unit\Retro;

use App\Services\Retro\RetroImapReader;
use App\Services\Retro\RetroMailParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RetroMailParserTest extends TestCase
{
    public function test_footer_number_is_not_glued_to_the_next_html_block(): void
    {
        $html = '<div>— <br>№ заявки: <span style="font-family:monospace">M-2026-2306</span></div><div>28 мая 2026 написал</div>';

        $this->assertSame(['M-2026-2306'], RetroMailParser::footerCodes(RetroMailParser::htmlToText($html)));
    }

    public function test_codes_accept_cyrillic_letter_and_drop_leading_zeros(): void
    {
        $this->assertSame(['M-2026-123', 'M-2026-4567'], RetroMailParser::codes('Re: [М-2026-0123] и M-2026-4567'));
        $this->assertSame([], RetroMailParser::codes('AM-2026-123 M-2026-12'));
    }

    public function test_body_parts_decode_multipart_and_skip_attachments(): void
    {
        $raw = "Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain; charset=koi8-r\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .base64_encode(iconv('UTF-8', 'KOI8-R', 'Привет'))."\r\n"
            ."--b1\r\nContent-Type: text/plain\r\nContent-Disposition: attachment; filename=a.txt\r\n\r\nСЕКРЕТ\r\n"
            ."--b1--\r\n";

        $this->assertSame('Привет', RetroMailParser::bodyParts($raw)['plain']);
    }

    public function test_truncated_base64_does_not_break_parsing(): void
    {
        $raw = "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".substr(base64_encode('Длинное письмо'), 0, 11);

        $this->assertStringStartsWith('Дл', RetroMailParser::bodyParts($raw)['plain']);
    }

    public function test_attachment_names_decode_rfc2231(): void
    {
        $line = '* 1 FETCH (UID 5 BODYSTRUCTURE (("APPLICATION" "PDF" ("NAME*" "utf-8\'\'%D0%A1%D1%87%D0%B5%D1%82.pdf") NIL)))';

        $this->assertSame(['Счет.pdf'], RetroMailParser::attachmentNames($line));
    }

    public function test_attachments_are_extracted_with_decoded_names_and_content(): void
    {
        $raw = "Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain\r\n\r\nТекст письма\r\n"
            ."--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment;\r\n filename*0*=utf-8''%D0%A1%D1%87%D0%B5;\r\n filename*1*=%D1%82.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode('%PDF-данные'))."\r\n"
            ."--b1\r\nContent-Type: image/png; name=\"=?UTF-8?B?".base64_encode('Фото.png')."?=\"\r\nContent-ID: <abc@x>\r\nContent-Disposition: inline\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .base64_encode('PNG')."\r\n"
            ."--b1--\r\n";

        $files = RetroMailParser::attachments($raw);

        $this->assertSame(['Счет.pdf', 'Фото.png'], array_column($files, 'filename'));
        $this->assertSame('%PDF-данные', $files[0]['content']);
        $this->assertFalse($files[0]['inline']);
        $this->assertTrue($files[1]['inline']);
        $this->assertSame('abc@x', $files[1]['content_id']);
    }

    public function test_document_totals_read_our_template(): void
    {
        $text = "   Итого:                         1 234 567,50\n   Итого со скидкой:              1 200 000,00\n"
            ."   В том числе НДС:                 218 181,82\n\n Всего наименований 12, на сумму 1 200 000,00 RUB\n";

        $this->assertSame(
            ['subtotal' => 1234567.5, 'discounted' => 1200000.0, 'vat' => 218181.82, 'sum' => 1200000.0, 'lines' => 12, 'currency' => 'RUB'],
            RetroMailParser::documentTotals($text),
        );
        $this->assertNull(RetroMailParser::documentTotals('Свидетельство ИНН')['sum']);
    }

    public function test_our_logo_and_banner_are_not_attachments(): void
    {
        $line = '* 1 FETCH (UID 5 BODYSTRUCTURE (("IMAGE" "PNG" ("NAME" "mylift-logo.png")) ("IMAGE" "PNG" ("NAME" "mylift-promo")) ("APPLICATION" "PDF" ("NAME" "file.pdf"))))';

        $this->assertSame(['file.pdf'], RetroMailParser::attachmentNames($line));
    }

    public function test_html_only_letter_gets_plain_text(): void
    {
        $fromHtml = RetroMailParser::plainBody(['plain' => '', 'html' => '<p>Добрый <b>день</b></p>']);

        $this->assertSame('Добрый день', preg_replace('/\s+/u', ' ', $fromHtml));
        $this->assertSame('Текст', RetroMailParser::plainBody(['plain' => 'Текст', 'html' => '<p>Другой</p>']));
    }

    public function test_documents_from_our_filename_templates(): void
    {
        $this->assertSame(
            ['type' => 'quote', 'number' => '360610', 'date' => '2026-06-01'],
            RetroMailParser::documentFromFilename('Предложение МЗ-360610 от 2026-06-01_12-30-00.pdf'),
        );
        $this->assertSame(
            ['type' => 'invoice', 'number' => '7143', 'date' => '2026-09-12'],
            RetroMailParser::documentFromFilename('Счет МЗ-7143 от 2026-09-12_09-00-00.pdf'),
        );
        $this->assertSame(
            ['type' => 'invoice', 'number' => '12345', 'date' => '2026-06-30'],
            RetroMailParser::documentFromFilename('Счёт на оплату № 12345 от 30.06.2026.pdf'),
        );
    }

    public function test_foreign_files_are_not_documents(): void
    {
        foreach (['МЛ_спецификация к счету № 99999 от 01.01.26.pdf', 'Договор Мой Лифт разовый договор (счет 9999).pdf', 'Свидетельство ИНН.pdf', 'Счет.pdf'] as $name) {
            $this->assertNull(RetroMailParser::documentFromFilename($name), $name);
        }
    }

    public function test_addresses_parse_names_and_commas(): void
    {
        $list = RetroMailParser::addresses('"Иванов, Иван" <Ivan@Client.ru>, petr@client.ru, ООО Лифт <lift@x.ru>');

        $this->assertSame(['ivan@client.ru', 'petr@client.ru', 'lift@x.ru'], array_column($list, 'email'));
        $this->assertSame('Иванов, Иван', $list[0]['name']);
    }

    public function test_reader_refuses_anything_that_can_change_the_mailbox(): void
    {
        foreach (['SELECT "INBOX"', 'UID STORE 1 +FLAGS (\\Seen)', 'UID COPY 1 "X"', 'EXPUNGE', 'APPEND "X" {1}', 'UID FETCH 1 (BODY[])', 'UID FETCH 1 (RFC822)'] as $command) {
            try {
                RetroImapReader::assertReadOnly($command);
                $this->fail('Пропущена команда: '.$command);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        RetroImapReader::assertReadOnly('UID FETCH 1,2 (UID BODYSTRUCTURE BODY.PEEK[]<0.1000>)');
        RetroImapReader::assertReadOnly('EXAMINE "INBOX"');
        $this->addToAssertionCount(1);
    }
}
