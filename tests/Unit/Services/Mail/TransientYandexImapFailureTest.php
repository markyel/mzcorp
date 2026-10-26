<?php

namespace Tests\Unit\Services\Mail;

use App\Exceptions\Mail\TransientImapException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Exceptions\RuntimeException as ImapRuntimeException;

/**
 * Временные сбои Яндекса — WARNING, отказ во входе — ERROR.
 * 26.10.2026: в laravel.log 15–130 ERROR «failed to authenticate» в день по разным ящикам выглядели как
 * проблема с паролем; в worker.log ответ сервера — «NO [UNAVAILABLE] AUTHENTICATE internal server error».
 * Тексты — из прод-логов.
 */
class TransientYandexImapFailureTest extends TestCase
{
    private function unavailableAuthenticate(): ResponseException
    {
        return new ResponseException("Command failed to process:\nCauses:\n\t- got failure response: NO [UNAVAILABLE] AUTHENTICATE internal server error sc=ZrUeF60sKSw0_2026-10-21-06-53_\nError occurred");
    }

    private function droppedDuringLogin(): AuthFailedException
    {
        return new AuthFailedException('failed to authenticate', 0, new ImapRuntimeException('empty response'));
    }

    private function invalidCredentials(): ResponseException
    {
        return new ResponseException("Command failed to process:\nCauses:\n\t- got failure response: NO [AUTHENTICATIONFAILED] AUTHENTICATE invalid credentials or IMAP is disabled sc=1tV3G20iBW20_2026-10-14-14-55_\nError occurred");
    }

    public function test_yandex_unavailable_and_a_dropped_login_are_transient(): void
    {
        $this->assertSame('NO [UNAVAILABLE] AUTHENTICATE internal server error', TransientImapException::yandexOutage($this->unavailableAuthenticate()));
        $this->assertSame('empty response to AUTHENTICATE', TransientImapException::yandexOutage($this->droppedDuringLogin()));
        $this->assertSame(
            'NO [UNAVAILABLE] APPEND Failed to store message. Please, try again later',
            TransientImapException::yandexOutage(new ImapServerErrorException('NO [UNAVAILABLE] APPEND Failed to store message. Please, try again later sc=abc_2026-10-12-09-51_')),
        );
    }

    public function test_a_rejected_login_and_other_errors_are_not_transient(): void
    {
        $this->assertNull(TransientImapException::yandexOutage($this->invalidCredentials()));
        $this->assertNull(TransientImapException::yandexOutage(new AuthFailedException('failed to authenticate', 0, $this->invalidCredentials())));
        // Обрыв не на входе (чтение папки) — не этот случай.
        $this->assertNull(TransientImapException::yandexOutage(new ImapRuntimeException('empty response')));
        $this->assertNull(TransientImapException::yandexOutage(new \RuntimeException('Sent folder not found')));
    }

    public function test_a_transient_failure_is_reported_as_a_warning(): void
    {
        Log::spy();
        $e = $this->droppedDuringLogin();
        // Стек, как в прод-логе: Client->connect ← MailboxConnector->imapClient ← SyncMailboxFolderJob->handle ← контейнер.
        (new \ReflectionProperty(\Exception::class, 'trace'))->setValue($e, [
            ['class' => 'Webklex\PHPIMAP\Client', 'function' => 'connect'],
            ['class' => 'App\Services\Mail\MailboxConnector', 'function' => 'imapClient'],
            ['class' => 'App\Jobs\Mail\SyncMailboxFolderJob', 'function' => 'handle'],
            ['class' => 'Illuminate\Container\BoundMethod', 'function' => 'call'],
        ]);

        app(ExceptionHandler::class)->report($e);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['cause'] === 'empty response to AUTHENTICATE'
            && $context['at'] === 'App\Jobs\Mail\SyncMailboxFolderJob');
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_rejected_login_is_still_reported_as_an_error(): void
    {
        Log::spy();

        app(ExceptionHandler::class)->report(new AuthFailedException('failed to authenticate', 0, $this->invalidCredentials()));

        Log::shouldHaveReceived('error')->once();
        Log::shouldNotHaveReceived('warning');
    }
}
