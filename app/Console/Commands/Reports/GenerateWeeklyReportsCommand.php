<?php

namespace App\Console\Commands\Reports;

use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Models\WeeklyReport;
use App\Services\Mail\OutgoingMailSender;
use App\Services\Reports\WeeklyManagerReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Генерация еженедельных персональных отчётов менеджеров + (опц.) рассылка на
 * их почту. По расписанию — понедельник 08:00 МСК за ПРОШЛУЮ неделю (пн–вс).
 *
 *   php artisan reports:weekly-generate            # прошлая неделя, без писем
 *   php artisan reports:weekly-generate --send     # + разослать менеджерам
 *   php artisan reports:weekly-generate --current   # текущая неделя (пн–сейчас), для отладки
 */
class GenerateWeeklyReportsCommand extends Command
{
    protected $signature = 'reports:weekly-generate
        {--send : Разослать отчёты менеджерам на почту}
        {--current : Текущая неделя (пн–сейчас) вместо прошлой — для отладки}';

    protected $description = 'Сгенерировать еженедельные отчёты менеджеров (+ рассылка по --send).';

    public function handle(WeeklyManagerReportService $service, OutgoingMailSender $sender): int
    {
        [$from, $to] = $this->period();
        $this->info('Период: '.$from->format('d.m.Y').' – '.$to->format('d.m.Y'));

        $n = $service->generateForWeek($from, $to);
        $this->info("Сгенерировано/обновлено отчётов: {$n}");

        if (! $this->option('send')) {
            return self::SUCCESS;
        }

        $mailbox = $this->sharedMailbox();
        if ($mailbox === null) {
            $this->error('Нет активного общего ящика для отправки — рассылка пропущена.');

            return self::FAILURE;
        }

        $sent = 0;
        $skipped = 0;
        $reports = WeeklyReport::with('user')->where('period_start', $from->toDateString())->get();
        foreach ($reports as $report) {
            $to_ = trim((string) ($report->user?->email ?? ''));
            if ($to_ === '') {
                $skipped++;

                continue;
            }
            if ($this->emailReport($sender, $mailbox, $report, $to_)) {
                $report->forceFill(['emailed_at' => now()])->save();
                $sent++;
            }
        }
        $this->info("Разослано: {$sent}, пропущено (нет почты): {$skipped}");

        return self::SUCCESS;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(): array
    {
        $tz = config('app.timezone', 'Europe/Moscow');
        if ($this->option('current')) {
            return [now($tz)->startOfWeek(Carbon::MONDAY), now($tz)];
        }
        $from = now($tz)->startOfWeek(Carbon::MONDAY)->subWeek();
        $to = now($tz)->startOfWeek(Carbon::MONDAY)->subSecond();

        return [$from, $to];
    }

    private function emailReport(OutgoingMailSender $sender, Mailbox $mailbox, WeeklyReport $report, string $to): bool
    {
        $data = $report->data;
        $label = $data['period']['label'] ?? '';
        $html = '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;background:oklch(98.6% 0.003 250);padding:16px 8px">'
            .view('reports.weekly', ['data' => $data])->render()
            .'</body></html>';

        try {
            $email = (new Email())
                ->from(new Address($mailbox->email, 'MyLift CRM'))
                ->to($to)
                ->subject("Ваш отчёт за неделю {$label}")
                ->html($html);
            $email->getHeaders()->addTextHeader('X-MyLift-System-Notification', '1');
            $sender->buildSmtpTransport($mailbox)->send($email);

            return true;
        } catch (\Throwable $e) {
            Log::error('WeeklyReport: email failed', [
                'report_id' => $report->id, 'to' => $to, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sharedMailbox(): ?Mailbox
    {
        $sharedEmail = (string) config('services.mail_outbound.shared_email', 'mail@myzip.ru');
        $mailbox = Mailbox::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($sharedEmail)])
            ->where('is_active', true)->first();
        if ($mailbox !== null && $mailbox->canSendOutbound()) {
            return $mailbox;
        }

        return Mailbox::query()->where('type', MailboxType::Shared->value)->where('is_active', true)
            ->get()->first(fn (Mailbox $m) => $m->canSendOutbound());
    }
}
