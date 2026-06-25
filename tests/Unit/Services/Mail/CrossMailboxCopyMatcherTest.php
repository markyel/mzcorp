<?php

namespace Tests\Unit\Services\Mail;

use App\Models\EmailMessage;
use App\Services\Mail\CrossMailboxCopyMatcher;
use Tests\TestCase;

/**
 * Cross-mailbox дедуп должен отличать ПОДЛИННУЮ копию (одно физическое
 * письмо в двух ящиках) от разных писем с общим Message-ID.
 *
 * Регресс M-2026-5907: Outlook у клиента выдал исходному «Поручни» и
 * пересланному «FW: Поручни» один Message-ID (Thread-Index reuse). Старый
 * дедуп по одному message_id принял follow-up за копию — письмо «Может
 * быть 76 мм» зависло в info@/INBOX и не попало в тред заявки. Матчер
 * дополнительно сверяет subject + sent_at.
 *
 * Расширяет Laravel TestCase ради datetime-cast'а sent_at (Carbon). БД не
 * нужна — модели живут только в памяти.
 */
class CrossMailboxCopyMatcherTest extends TestCase
{
    private function message(?string $subject, ?string $sentAt): EmailMessage
    {
        $m = new EmailMessage();
        $m->subject = $subject;
        $m->sent_at = $sentAt;

        return $m;
    }

    public function test_true_for_genuine_copy_same_subject_and_date(): void
    {
        // #30834 (личный ящик Агрызкова) vs #30825 (info@) — одно письмо.
        $a = $this->message('Поручни', '2026-06-25 10:59:32');
        $b = $this->message('Поручни', '2026-06-25 10:59:32');

        $this->assertTrue(CrossMailboxCopyMatcher::isSamePhysicalMessage($a, $b));
    }

    public function test_false_for_forwarded_followup_M_2026_5907(): void
    {
        // #30841 «FW: Поручни» (11:01) НЕ копия #30825 «Поручни» (10:59),
        // хотя Message-ID совпал.
        $original = $this->message('Поручни', '2026-06-25 10:59:32');
        $forward = $this->message('FW: Поручни', '2026-06-25 11:01:15');

        $this->assertFalse(CrossMailboxCopyMatcher::isSamePhysicalMessage($forward, $original));
    }

    public function test_false_when_only_date_differs(): void
    {
        $a = $this->message('Поручни', '2026-06-25 10:59:32');
        $b = $this->message('Поручни', '2026-06-25 11:01:15');

        $this->assertFalse(CrossMailboxCopyMatcher::isSamePhysicalMessage($a, $b));
    }

    public function test_false_when_only_subject_differs(): void
    {
        $a = $this->message('Поручни', '2026-06-25 10:59:32');
        $b = $this->message('FW: Поручни', '2026-06-25 10:59:32');

        $this->assertFalse(CrossMailboxCopyMatcher::isSamePhysicalMessage($a, $b));
    }

    public function test_subject_compared_trimmed(): void
    {
        $a = $this->message('Поручни', '2026-06-25 10:59:32');
        $b = $this->message("  Поручни \t", '2026-06-25 10:59:32');

        $this->assertTrue(CrossMailboxCopyMatcher::isSamePhysicalMessage($a, $b));
    }

    public function test_true_when_both_dates_null_same_subject(): void
    {
        // Редкое письмо без Date-заголовка — но subject совпал.
        $a = $this->message('Поручни', null);
        $b = $this->message('Поручни', null);

        $this->assertTrue(CrossMailboxCopyMatcher::isSamePhysicalMessage($a, $b));
    }

    public function test_false_when_one_date_null(): void
    {
        $a = $this->message('Поручни', '2026-06-25 10:59:32');
        $b = $this->message('Поручни', null);

        $this->assertFalse(CrossMailboxCopyMatcher::isSamePhysicalMessage($a, $b));
    }
}
