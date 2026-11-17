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
 * 17.11.2026: текст подробнее — зачем, как голосовать по шагам, как считается итог, срок отдельной строкой.
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
        public int $photos = 0,
        public int $winners = 0,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->pollTitle.': выберите '.$this->picks.' лучших фото'
            .($this->closesAt !== null ? ' до '.$this->deadline('d.m') : ''));
    }

    private function deadline(string $format): string
    {
        return (string) $this->closesAt?->timezone(config('app.timezone'))->format($format);
    }

    public function content(): Content
    {
        $e = fn (string $s) => e($s);
        $p = fn (string $html) => '<p style="margin:0 0 12px">'.$html.'</p>';
        $intro = 'Готовим корпоративный календарь и выбираем для него фотографии всей командой.'
            .($this->photos > 0 ? ' В опросе '.$this->photos.' '.self::plural($this->photos, 'снимок', 'снимка', 'снимков').' —' : '')
            .($this->winners > 0 ? ($this->photos > 0 ? ' в' : ' В').' календарь попадут '.$this->winners.' '.self::plural($this->winners, 'фото', 'фото', 'фото').', и какие именно, решают голоса сотрудников.' : ' какие попадут в календарь, решают голоса сотрудников.');
        $steps = [
            'Откройте опрос по кнопке ниже — пароль не нужен.',
            'Посмотрите все фото. Нажмите на снимок, чтобы рассмотреть его крупно.',
            'Отметьте '.$this->picks.' понравившихся кнопкой «Выбрать» — по порядку, от самого лучшего к '.$this->picks.'-му. Порядок можно поменять стрелками.',
            'Нажмите «Сохранить выбор».',
        ];
        // Ссылка живёт validDays, но не дольше голосования — пишем то, что наступит раньше.
        $linkUntil = $this->closesAt !== null && $this->closesAt->lt(now()->addDays($this->validDays))
            ? 'до конца голосования.'
            : $this->validDays.' дн.';

        return new Content(htmlString: '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937;line-height:1.55;max-width:600px">'
            .$p($e($this->name).', здравствуйте!')
            .$p($e($intro))
            .(trim($this->description) !== '' ? $p(nl2br($e(trim($this->description)))) : '')
            .$p('<b>Как проголосовать</b> — займёт пару минут:')
            .'<ol style="margin:0 0 12px;padding-left:20px">'.implode('', array_map(fn ($s) => '<li style="margin-bottom:4px">'.$e($s).'</li>', $steps)).'</ol>'
            .$p('Первое место приносит снимку '.$this->picks.' '.self::plural($this->picks, 'балл', 'балла', 'баллов').', '.$this->picks.'-е — 1 балл. '
                .($this->winners > 0 ? $this->winners.' фото с наибольшей суммой баллов' : 'Фото с наибольшей суммой баллов').' войдут в календарь.')
            .($this->closesAt !== null
                ? $p('<b>Голосование открыто до '.$e($this->deadline('d.m.Y, H:i')).'.</b> До этого времени выбор можно поменять — откройте ссылку ещё раз на том же устройстве.')
                : $p('До конца голосования выбор можно поменять — откройте ссылку ещё раз на том же устройстве.'))
            .'<p style="margin:16px 0"><a href="'.$e($this->link).'" style="display:inline-block;padding:11px 22px;background:#D32027;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold">Выбрать фото</a></p>'
            .'<p style="margin:0 0 12px;color:#6b7280;font-size:12px">Ссылка личная — не пересылайте её коллегам, у каждого своя. Она открывается один раз, дальше браузер вас помнит; действует '
            .$e($linkUntil).' Работает и с телефона. Если откроете её на другом устройстве, страница предложит прислать новую ссылку на этот адрес.</p>'
            .$p('Спасибо за участие!')
            .'</div>');
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;

        return match (true) {
            $n > 10 && $n < 20 => $many,
            $n1 === 1 => $one,
            $n1 >= 2 && $n1 <= 4 => $few,
            default => $many,
        };
    }
}
