<?php

namespace App\Console\Commands;

use App\Mail\SupportTicketDigestMail;
use App\Models\SupportTicketMessage;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Email-дайджест по обращениям в поддержку: вместо письма на каждый
 * комментарий копим неотправленные ответы (emailed_at IS NULL) и шлём
 * ОДНО письмо на пачку — раз в прогон крона, после «тихого окна»
 * (автор пачки закончил писать серию).
 *
 * Кейс-мотиватор: тикет #70 — автору пришло 3 почти одинаковых письма
 * за 15 минут (2 ответа + «решено»).
 *
 * Направления независимы: ответы создателя → автору обращения; реплики
 * автора → developer_email. Письмо «обращение решено» само вбирает
 * неотправленные ответы и штампует их (SupportTicketService::changeStatus),
 * поэтому сюда они уже не попадают.
 */
class SupportEmailPendingRepliesCommand extends Command
{
    protected $signature = 'support:email-pending-replies
        {--dry-run : Показать, что ушло бы, без отправки и записи}';

    protected $description = 'Отправить дайджесты неотправленных ответов по support-тикетам';

    public function handle(SystemNotificationMailer $mailer): int
    {
        $dry = (bool) $this->option('dry-run');
        $quietMinutes = max(1, (int) config('support.digest_quiet_minutes', 5));

        $pending = SupportTicketMessage::query()
            ->whereNull('emailed_at')
            ->where('is_internal', false)
            ->with(['ticket.user', 'author', 'attachments'])
            ->orderBy('id')
            ->get()
            ->filter(fn ($m) => $m->ticket !== null);

        if ($pending->isEmpty()) {
            $this->info('Неотправленных сообщений нет.');

            return self::SUCCESS;
        }

        $sent = 0;
        // Группа = тикет + сторона (ответы создателя ≠ реплики автора).
        $groups = $pending->groupBy(
            fn ($m) => $m->ticket_id . ':' . ($m->user_id === $m->ticket->user_id ? 'author' : 'staff'),
        );

        foreach ($groups as $key => $messages) {
            $ticket = $messages->first()->ticket;
            $isAuthorSide = str_ends_with($key, ':author');

            // Тихое окно: последний ответ пачки должен «отлежаться», чтобы
            // серия сообщений подряд склеилась в одно письмо.
            if ($messages->max('created_at')->gt(now()->subMinutes($quietMinutes))) {
                continue;
            }

            $to = $isAuthorSide
                ? config('support.developer_email')
                : $ticket->user?->email;

            $label = sprintf(
                'тикет #%d → %s: %d сообщ. (%s)',
                $ticket->id,
                $to ?: '—',
                $messages->count(),
                $isAuthorSide ? 'от автора' : 'от создателя',
            );

            if (! $to) {
                // Получателя нет (developer_email не настроен / у автора нет
                // email) — штампуем, иначе пачка будет вечно висеть в очереди.
                $this->warn('Пропуск (нет получателя): ' . $label);
                if (! $dry) {
                    SupportTicketMessage::query()
                        ->whereIn('id', $messages->pluck('id'))
                        ->update(['emailed_at' => now()]);
                }

                continue;
            }

            if ($dry) {
                $this->line('[dry-run] ' . $label);
                $sent++;

                continue;
            }

            try {
                $mailer->sendMailable(
                    $to,
                    new SupportTicketDigestMail($ticket, $messages->values(), toAuthor: ! $isAuthorSide),
                );
                SupportTicketMessage::query()
                    ->whereIn('id', $messages->pluck('id'))
                    ->update(['emailed_at' => now()]);
                $this->info('Отправлено: ' . $label);
                $sent++;
            } catch (\Throwable $e) {
                // Не штампуем — следующий прогон повторит попытку.
                Log::error('Support digest mail failed', [
                    'ticket_id' => $ticket->id,
                    'message_ids' => $messages->pluck('id')->all(),
                    'error' => $e->getMessage(),
                ]);
                $this->error('Сбой отправки: ' . $label . ' — ' . $e->getMessage());
            }
        }

        $this->info(sprintf('Готово: %d дайджест(ов).', $sent));

        return self::SUCCESS;
    }
}
