<?php

namespace Tests\Unit\Mail;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Services\Mail\MailToRequestLinker;
use PHPUnit\Framework\TestCase;

class MailToRequestLinkerTest extends TestCase
{
    /** Тема «000372869» и файл «Предложение МЗ-372869 от 2026-11-09_12-35-37.pdf» → 1С 372869, дата — не номер. */
    public function test_onec_number_from_subject_and_file_name(): void
    {
        $refs = MailToRequestLinker::referencedNumbers('000372869 Предложение МЗ-372869 от 2026-11-09_12-35-37.pdf');

        $this->assertSame([], $refs['codes']);
        $this->assertSame(['372869'], $refs['onec']);
    }

    public function test_request_codes_are_taken_whole_not_as_onec_numbers(): void
    {
        $refs = MailToRequestLinker::referencedNumbers('Re: КП по M-2026-215490 и MZ2026-00524, счёт МЗ-11719');

        $this->assertSame(['M-2026-215490', 'MZ2026-00524'], $refs['codes']);
        $this->assertSame([], $refs['onec']);
    }

    public function test_seven_digit_numbers_are_not_onec(): void
    {
        $this->assertSame([], MailToRequestLinker::referencedNumbers('тел 1234567, заказ 98765432')['onec']);
    }

    public function test_counterparty_is_recipients_of_outbound_and_sender_of_inbound(): void
    {
        $out = new EmailMessage;
        $out->forceFill(['direction' => MailDirection::Outbound, 'from_email' => 'dmitry.yakubovich@myzip.ru',
            'to_recipients' => [['name' => '', 'email' => 'Lift-M1zip@mail.ru']], 'cc_recipients' => [['email' => 'boss@client.ru']]]);
        $this->assertSame(['lift-m1zip@mail.ru', 'boss@client.ru'], MailToRequestLinker::counterpartyEmails($out));

        $in = new EmailMessage;
        $in->forceFill(['direction' => MailDirection::Inbound, 'from_email' => ' Client@Mail.ru ', 'to_recipients' => [['email' => 'info@myzip.ru']]]);
        $this->assertSame(['client@mail.ru'], MailToRequestLinker::counterpartyEmails($in));
    }
}
