<?php

namespace Tests\Unit\Mail;

use App\Models\EmailMessage;
use App\Services\Mail\ClientReplySignal;
use Tests\TestCase;

/**
 * Не ответ клиента — ход не переходит к нам (18.11.2026, «Заброшенные»): «спасибо» на КП, автоответ «в отпуске»,
 * служебное письмо mzCorp. Примеры — из прогона по письмам за 30 дней.
 */
class ClientReplySignalTest extends TestCase
{
    private function signal(): ClientReplySignal
    {
        // Без базы: вложений у этих писем нет.
        return new class extends ClientReplySignal
        {
            protected function hasFiles(EmailMessage $message): bool
            {
                return false;
            }
        };
    }

    private function letter(string $body, array $extra = []): EmailMessage
    {
        return new EmailMessage($extra + ['body_plain' => $body, 'from_name' => 'Сабиров Максим', 'to_recipients' => [['name' => 'Сергей Агрызков', 'email' => 'sergey@myzip.ru']]]);
    }

    public function test_a_plain_thanks_is_not_a_reply_but_thanks_with_a_request_is(): void
    {
        $s = $this->signal();
        foreach (['Спасибо', 'Спасибо большое)', 'Принято! Спасибо!', 'Понял, благодарю', 'Сергей, спасибо!', "Добрый день, принято\n\nС уважением, Сабиров Максим", 'Ок'] as $text) {
            $this->assertTrue($s->isThanksOnly($this->letter($text)), $text);
        }
        foreach (['Спасибо. Напомню про башмаки на лифт и ролики на эскалатор', 'Спасибо, ждём счёт', 'Спасибо. Согласны', 'Спасибо, а есть аналог?', ''] as $text) {
            $this->assertFalse($s->isThanksOnly($this->letter($text)), $text);
        }
    }

    public function test_an_auto_reply_by_headers_subject_or_text(): void
    {
        $s = $this->signal();
        $this->assertTrue($s->isAutoReply($this->letter('Спасибо за письмо.', ['headers' => ['auto_submitted' => 'auto-replied']])));
        $this->assertTrue($s->isAutoReply($this->letter('…', ['subject' => 'Автоматический ответ: 11920 Re: 373492'])));
        $this->assertTrue($s->isAutoReply($this->letter('Здравствуйте! С 3 по 10 ноября включительно я нахожусь в отпуске, доступ к почте ограничен.')));
        $this->assertFalse($s->isAutoReply($this->letter('Добрый день. Прошу прощения, находился в отпуске. Заявку снимаем.')));
        $this->assertFalse($s->isAutoReply($this->letter('Добрый день! Виктор в отпуске до 1.11 включительно. RR24497 Канат стальной лифтовый 9,0мм — 450 руб/м')));
    }

    public function test_a_letter_from_mzcorp_itself_is_a_system_one(): void
    {
        config(['app.url' => 'https://mzcorp.ru', 'mail.from.address' => 'noreply@example.com']);

        $this->assertTrue($this->signal()->isSystemSender('rfq@mzcorp.ru'));
        $this->assertTrue($this->signal()->isSystemSender('noreply@example.com'));
        $this->assertFalse($this->signal()->isSystemSender('msabirov@meteor.ru'));
    }
}
