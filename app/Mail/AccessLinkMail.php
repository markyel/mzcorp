<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ссылка, по которой сотрудник сам задаёт пароль: приглашение в MyLift (users:send-access)
 * или сброс по «Забыли пароль?» (User::sendPasswordResetNotification). Пароль в письме не
 * передаётся. Шлётся через SystemNotificationMailer — на проде MAIL_MAILER=log.
 */
class AccessLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $login,
        public string $link,
        public int $validHours,
        public bool $welcome,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->welcome ? 'Доступ в MyLift' : 'Сброс пароля MyLift');
    }

    public function content(): Content
    {
        $e = fn (string $s) => e($s);
        $loginUrl = route('login');
        $intro = $this->welcome
            ? 'Для вас открыт доступ в MyLift — CRM для работы с заявками. Чтобы войти, задайте себе пароль по ссылке ниже.'
            : 'Мы получили запрос на сброс пароля в MyLift. Задайте новый пароль по ссылке ниже. Если вы пароль не сбрасывали — просто не открывайте ссылку.';

        return new Content(htmlString: '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.55">'
            .'<p>'.$e($this->userName).', здравствуйте.</p>'
            .'<p>'.$e($intro).'</p>'
            .'<p><a href="'.$e($this->link).'" style="display:inline-block;padding:10px 18px;background:#1f2937;color:#ffffff;text-decoration:none;border-radius:6px">Задать пароль</a></p>'
            .'<p>Логин — ваш адрес: <b>'.$e($this->login).'</b>.<br>'
            .'Ссылка действует '.$this->validHours.' ч. Если не успели — на <a href="'.$e($loginUrl).'">странице входа</a> нажмите «Забыли пароль?», придёт новая.</p>'
            .'<p>После этого входите на <a href="'.$e($loginUrl).'">'.$e(preg_replace('#^https?://#', '', rtrim($loginUrl, '/'))).'</a>.</p>'
            .'</div>');
    }
}
