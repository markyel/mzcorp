<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Служебные письма сотрудникам (поддержка «связь с создателем» и т.п.).
 *
 * На проде MAIL_MAILER=log — стандартный Laravel Mail никуда не отправляет.
 * Реальная отправка в системе живёт через SMTP конкретных ящиков
 * (OutgoingMailSender, XOAUTH2) — переиспользуем её: рендерим Mailable и шлём
 * с ОБЩЕГО ящика (services.mail_outbound.shared_email), без создания
 * EmailMessage-записей (это внутренняя почта, не переписка с клиентом).
 *
 * Fail-soft: нет ящика/сбой SMTP → фолбэк в стандартный Mail (уйдёт в лог)
 * + warning; вызывающий код не падает.
 */
class SystemNotificationMailer
{
    public function __construct(private readonly OutgoingMailSender $sender)
    {
    }

    public function sendMailable(string $to, Mailable $mailable): bool
    {
        $to = trim($to);
        if ($to === '') {
            return false;
        }

        $mailbox = $this->sharedMailbox();
        if ($mailbox === null) {
            Log::warning('SystemNotificationMailer: shared mailbox unavailable — falling back to default mailer', ['to' => $to]);
            Mail::to($to)->send($mailable);

            return false;
        }

        try {
            $subject = (string) ($mailable->envelope()->subject ?? 'MyLift CRM');
            $html = $mailable->render();

            $email = (new Email())
                ->from(new Address($mailbox->email, 'MyLift CRM'))
                ->to($to)
                ->subject($subject)
                ->html($html);

            $this->sender->buildSmtpTransport($mailbox)->send($email);

            return true;
        } catch (\Throwable $e) {
            Log::error('SystemNotificationMailer: send failed', [
                'to' => $to,
                'mailable' => get_class($mailable),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sharedMailbox(): ?Mailbox
    {
        $sharedEmail = (string) config('services.mail_outbound.shared_email', 'mail@myzip.ru');

        $mailbox = Mailbox::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($sharedEmail)])
            ->where('is_active', true)
            ->first();
        if ($mailbox !== null && $mailbox->canSendOutbound()) {
            return $mailbox;
        }

        // Настроенный общий ящик отключён (на проде mail@ выключен, живой —
        // info@): берём любой активный shared-ящик, способный отправлять.
        return Mailbox::query()
            ->where('type', \App\Enums\MailboxType::Shared->value)
            ->where('is_active', true)
            ->get()
            ->first(fn (Mailbox $m) => $m->canSendOutbound());
    }
}
