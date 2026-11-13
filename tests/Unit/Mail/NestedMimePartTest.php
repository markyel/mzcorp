<?php

namespace Tests\Unit\Mail;

use App\Support\NestedMimePart;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * 13.11.2026, MZ2026-01489: письмо площадки ОТС — multipart/mixed → multipart/alternative с одним
 * text/plain. webklex отдал блок alternative вложением «d5e59bf9», тело письма осталось пустым,
 * и позиции собрались из темы письма. Тело достаём из такого вложения сами.
 */
class NestedMimePartTest extends TestCase
{
    private function otcLetter(): string
    {
        $text = base64_encode("Здравствуйте!\nПрошу выставить счёт.\n\n1: Вилка ГРПМ 1-31ШУ2-В НЩО.364.006ТУ 10.0 шт\n2: Плата файл COM26.pcb 10.0 шт\n");

        return "MIME-Version: 1.0\r\nFrom: a@otc-prom.ru\r\nTo: info@myzip.ru\r\nSubject: =?UTF-8?B?".base64_encode('Разъёмы и печатные платы')."?=\r\n"
            ."Content-Type: multipart/mixed; boundary=\"MXhSa3Q5LWVxbnpJbC1QNg==\"\r\n\r\n"
            ."--MXhSa3Q5LWVxbnpJbC1QNg==\r\n"
            ."Content-Type: multipart/alternative; \r\n\tboundary=\"----=_Part_16854220_1965840263.1794551755028\"\r\n\r\n"
            ."------=_Part_16854220_1965840263.1794551755028\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split($text, 76, "\r\n")."\r\n"
            ."------=_Part_16854220_1965840263.1794551755028--\r\n\r\n"
            ."--MXhSa3Q5LWVxbnpJbC1QNg==\r\n"
            ."Content-Type: application/pdf; name=\"card.pdf\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"card.pdf\"\r\n\r\n"
            .base64_encode('%PDF-1.4 card')."\r\n"
            ."--MXhSa3Q5LWVxbnpJbC1QNg==--\r\n";
    }

    public function test_the_text_of_an_otc_letter_is_found_in_the_unparsed_part(): void
    {
        $msg = Message::fromString($this->otcLetter());
        // Сама ошибка webklex: тела нет, блок alternative — «вложение».
        $this->assertSame('', trim((string) $msg->getTextBody()));

        $found = null;
        foreach ($msg->getAttachments() as $att) {
            $found ??= NestedMimePart::bodies((string) $att->getContent());
        }

        $this->assertNotNull($found);
        $this->assertStringContainsString('1: Вилка ГРПМ 1-31ШУ2-В НЩО.364.006ТУ 10.0 шт', $found['plain']);
        $this->assertStringContainsString('2: Плата файл COM26.pcb', $found['plain']);
        $this->assertSame('', $found['html']);
    }

    public function test_an_html_only_nested_block_gives_the_html(): void
    {
        $part = "--===============7751578874171545046==\r\nContent-Type: text/html; charset=\"utf-8\"\r\nMIME-Version: 1.0\r\n"
            ."Content-Transfer-Encoding: quoted-printable\r\n\r\n<p>=D0=9F=D1=80=D0=B8=D0=B2=D0=B5=D1=82</p>\r\n"
            ."--===============7751578874171545046==--\r\n";

        $this->assertSame(['plain' => '', 'html' => '<p>Привет</p>'], NestedMimePart::bodies($part));
    }

    public function test_real_attachments_and_plain_text_files_are_left_alone(): void
    {
        $withFile = "--b1\r\nContent-Type: text/plain; name=\"list.txt\"\r\nContent-Disposition: attachment; filename=\"list.txt\"\r\n\r\nтовары\r\n--b1--\r\n";
        $this->assertNull(NestedMimePart::bodies($withFile));
        $this->assertNull(NestedMimePart::bodies("Просто текстовый файл\n--не граница\n"));
        $this->assertNull(NestedMimePart::bodies('%PDF-1.4'));
    }
}
