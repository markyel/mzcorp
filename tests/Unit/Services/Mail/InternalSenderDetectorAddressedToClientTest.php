<?php

namespace Tests\Unit\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Mail\InternalSenderDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * InternalSenderDetector::isAddressedToClient — гард outbound document
 * detector'а: КП/счёт двигают статус только когда письмо ушло заказчику.
 *
 * Регресс M-2026-14608: «Fwd: Запрос счета…» из info@myzip.ru на сторонний
 * адрес (не заказчик) с PDF «Реквизиты ООО …» → LLM решил «счёт» → заявка
 * ушла в «Счёт отправлен» без счёта. Без БД: только to/cc + config.
 */
class InternalSenderDetectorAddressedToClientTest extends TestCase
{
    private InternalSenderDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mail.internal_domains' => ['myzip.ru'],
            'services.mail.public_mail_domains' => ['gmail.com', 'mail.ru', 'yandex.ru'],
        ]);

        $this->detector = new InternalSenderDetector();
    }

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     */
    #[DataProvider('cases')]
    public function test_is_addressed_to_client(array $to, array $cc, ?string $client, bool $expected): void
    {
        $m = new EmailMessage();
        $m->to_recipients = array_map(fn (string $e) => ['name' => '', 'email' => $e], $to);
        $m->cc_recipients = array_map(fn (string $e) => ['name' => '', 'email' => $e], $cc);

        $this->assertSame($expected, $this->detector->isAddressedToClient($m, $client));
    }

    /**
     * Второй адрес того же заказчика (кейс M-2026-14316): счёт ушёл на
     * vershina2004@yandex.ru, а в заявке записан rodionshvedchikov@yandex.ru.
     * Оба на публичном домене, поэтому спасает только то, что второй адрес
     * сам писал в эту заявку.
     */
    public function test_recipient_that_wrote_into_the_request_counts_as_the_client(): void
    {
        $m = new EmailMessage();
        $m->to_recipients = [['name' => 'Вершина', 'email' => 'vershina2004@yandex.ru']];

        $this->assertFalse(
            $this->detector->isAddressedToClient($m, 'rodionshvedchikov@yandex.ru'),
            'без участия в треде второй адрес на публичном домене не подтверждён',
        );

        $withThread = new class extends InternalSenderDetector
        {
            protected function wroteIntoRequest(array $recipients, ?Request $request, EmailMessage $message): bool
            {
                return in_array('vershina2004@yandex.ru', $recipients, true);
            }
        };

        $this->assertTrue($withThread->isAddressedToClient($m, 'rodionshvedchikov@yandex.ru'));
    }

    public function test_third_party_that_never_wrote_into_the_request_is_still_blocked(): void
    {
        $m = new EmailMessage();
        $m->to_recipients = [['name' => '', 'email' => 'info@liftway.ru']];

        $withThread = new class extends InternalSenderDetector
        {
            protected function wroteIntoRequest(array $recipients, ?Request $request, EmailMessage $message): bool
            {
                return in_array('vershina2004@yandex.ru', $recipients, true);
            }
        };

        $this->assertFalse($withThread->isAddressedToClient($m, 'semenova_aa@sminex.com'));
    }

    /**
     * @return iterable<string, array{array<int, string>, array<int, string>, ?string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'M-2026-14608: внутренний пересыл на сторонний адрес' => [
            ['info@liftway.ru'], [], 'semenova_aa@sminex.com', false,
        ];
        yield 'точное совпадение в to' => [
            ['semenova_aa@sminex.com'], [], 'semenova_aa@sminex.com', true,
        ];
        yield 'точное совпадение в cc' => [
            ['manager@myzip.ru'], ['Semenova_AA@Sminex.com'], 'semenova_aa@sminex.com', true,
        ];
        yield 'коллега заказчика на корпоративном домене' => [
            ['buh@sminex.com'], [], 'semenova_aa@sminex.com', true,
        ];
        yield 'только наши получатели' => [
            ['manager@myzip.ru'], ['rop@myzip.ru'], 'semenova_aa@sminex.com', false,
        ];
        yield 'публичный домен: другой gmail — не заказчик' => [
            ['somebody@gmail.com'], [], 'client@gmail.com', false,
        ];
        yield 'публичный домен: точный адрес — заказчик' => [
            ['client@gmail.com'], [], 'client@gmail.com', true,
        ];
        yield 'заказчик на нашем домене (ошибка данных) — домен не считаем' => [
            ['other@myzip.ru'], [], 'someone@myzip.ru', false,
        ];
        yield 'e-mail заказчика не заполнен — подтвердить нечем, не блокируем' => [
            ['anyone@example.com'], [], null, true,
        ];
        yield 'e-mail заказчика пустая строка — не блокируем' => [
            ['anyone@example.com'], [], '  ', true,
        ];
        yield 'нет получателей вовсе' => [
            [], [], 'semenova_aa@sminex.com', false,
        ];
    }
}
