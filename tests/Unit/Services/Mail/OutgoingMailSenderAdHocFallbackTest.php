<?php

namespace Tests\Unit\Services\Mail;

use App\Models\Mailbox;
use App\Services\Mail\MailFallbackSettings;
use App\Services\Mail\OutgoingMailMimeBuilder;
use App\Services\Mail\OutgoingMailSender;
use App\Services\Mail\OutgoingSendRetryService;
use App\Services\Mail\SmtpReachabilityProbe;
use App\Services\Mail\YandexOAuthService;
use Mockery;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Автоматические письма (уведомления, отчёты, пересылка по правилам) идут
 * через OutgoingMailSender::sendAdHoc и при недоступном SMTP Яндекса уходят
 * резервным каналом (решение 2026-10-11). Без резерва — ошибка как раньше.
 * БД и сеть не нужны: зависимости — моки, резервный транспорт — шпион.
 */
class OutgoingMailSenderAdHocFallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function spyTransport(): TransportInterface
    {
        return new class implements TransportInterface
        {
            /** @var RawMessage[] */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sent[] = $message;

                return null;
            }

            public function __toString(): string
            {
                return 'spy://';
            }
        };
    }

    private function mailbox(): Mailbox
    {
        $m = Mockery::mock(Mailbox::class)->makePartial();
        $m->shouldReceive('canSendOutbound')->andReturn(true);
        $m->id = 15;
        $m->email = 'info@myzip.ru';
        $m->smtp_host = 'smtp.yandex.ru';
        $m->smtp_port = 465;

        return $m;
    }

    private function sender(bool $fallbackOn, bool $yandexDown, TransportInterface $spy): OutgoingMailSender
    {
        $retry = Mockery::mock(OutgoingSendRetryService::class)->makePartial();
        $retry->shouldReceive('fallbackEnabled')->andReturn($fallbackOn);

        $fallback = Mockery::mock(MailFallbackSettings::class);
        $fallback->shouldReceive('transport')->andReturn($spy);
        $fallback->shouldReceive('headers')->andReturn(['X-Test' => '1']);
        $fallback->shouldReceive('name')->andReturn('smtp.bz');

        $probe = Mockery::mock(SmtpReachabilityProbe::class);
        $probe->shouldReceive('isKnownDown')->andReturn($yandexDown);
        $probe->shouldReceive('assertReachable')->andReturnUsing(function () use ($yandexDown) {
            if ($yandexDown) {
                throw new TransportException('Connection could not be established with host "smtp.yandex.ru:465": timeout');
            }
        });

        $oauth = Mockery::mock(YandexOAuthService::class);
        $oauth->shouldReceive('ensureFreshToken')->andReturnNull();

        return new OutgoingMailSender($oauth, Mockery::mock(OutgoingMailMimeBuilder::class), $retry, $fallback, $probe);
    }

    private function email(): Email
    {
        return (new Email())->from('info@myzip.ru')->to('manager@myzip.ru')->subject('Уведомление')->text('x');
    }

    public function test_known_down_yandex_sends_through_fallback_with_provider_headers(): void
    {
        $spy = $this->spyTransport();
        $email = $this->email();

        $this->sender(fallbackOn: true, yandexDown: true, spy: $spy)->sendAdHoc($this->mailbox(), $email);

        $this->assertCount(1, $spy->sent);
        $this->assertTrue($spy->sent[0]->getHeaders()->has('X-Test'));
        // Заголовки провайдера — только в отправленную копию.
        $this->assertFalse($email->getHeaders()->has('X-Test'));
    }

    public function test_without_fallback_the_error_reaches_the_caller(): void
    {
        $spy = $this->spyTransport();

        $this->expectException(TransportException::class);
        try {
            $this->sender(fallbackOn: false, yandexDown: true, spy: $spy)->sendAdHoc($this->mailbox(), $this->email());
        } finally {
            $this->assertCount(0, $spy->sent);
        }
    }
}
