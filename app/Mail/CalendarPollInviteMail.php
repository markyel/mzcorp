<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Приглашение в опрос «Фото для календаря»: личная ссылка входа без пароля. Шлётся через
 * SystemNotificationMailer с общего ящика; в списке «писем по действию человека» — рассылку запускает организатор.
 */
class CalendarPollInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $pollTitle,
        public string $description,
        public string $link,
        public int $picks,
        public ?Carbon $closesAt,
        public int $validDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->pollTitle.' — выберите '.$this->picks.' фото');
    }

    public function content(): Content
    {
        $e = fn (string $s) => e($s);
        $deadline = $this->closesAt !== null
            ? ' до '.$this->closesAt->timezone(config('app.timezone'))->format('d.m.Y H:i')
            : '';

        return new Content(htmlString: '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.55">'
            .'<p>'.$e($this->name).', здравствуйте.</p>'
            .'<p>Выбираем фото для корпоративного календаря. Посмотрите все снимки и отметьте '.$this->picks
            .' лучших по местам — от самого понравившегося к шестому'.$e($deadline).'. По сумме мест всех сотрудников выберем фото для календаря.</p>'
            .(trim($this->description) !== '' ? '<p>'.nl2br($e(trim($this->description))).'</p>' : '')
            .'<p><a href="'.$e($this->link).'" style="display:inline-block;padding:10px 18px;background:#D32027;color:#ffffff;text-decoration:none;border-radius:6px">Выбрать фото</a></p>'
            .'<p style="color:#6b7280;font-size:12px">Ссылка личная, пароль не нужен. Она открывается один раз, дальше браузер вас помнит — выбор можно поменять до конца голосования. '
            .'Действует '.$this->validDays.' дн. Если откроете её на другом устройстве, страница предложит прислать новую ссылку на этот адрес.</p>'
            .'</div>');
    }
}
