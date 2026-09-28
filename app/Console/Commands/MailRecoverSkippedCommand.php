<?php

namespace App\Console\Commands;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\MailboxFolderState;
use App\Models\MailDecision;
use App\Services\Mail\MailDecisionRecorder;
use App\Services\Mail\MailHistoryMirrorService;
use App\Services\Mail\MailRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Письма, которые живая синхронизация пропустила, а подобрал импорт архива.
 *
 * Архив ящиков (mail:history-mirror) кладёт письма с is_history = true — мимо
 * конвейера: без привязки к заявке, без детекторов КП и счетов. Для писем,
 * пришедших ДО подключения ящика, так и надо. Но если письмо лежит во
 * «Входящих» или «Отправленных» и пришло уже ПОСЛЕ того, как папку начала
 * читать живая синхронизация, — значит, синхронизация его потеряла (кейс
 * M-2026-16361: сбой сессии Яндекса 18.09, КП не засчиталось). Такие письма
 * делаем живыми и прогоняем через маршрутизатор, как при обычном получении.
 *
 * По умолчанию — только исходящие: входящее двухнедельной давности может
 * создать заявку и запустить автоответ клиенту; входящие — осознанно, флагом
 * --inbound, после просмотра списка.
 */
class MailRecoverSkippedCommand extends Command
{
    protected $signature = 'mail:recover-skipped
        {--since=2026-09-01 : С какой даты письма}
        {--mailbox=* : Только эти ящики}
        {--id=* : Только эти письма}
        {--inbound : Включить входящие (по умолчанию только исходящие)}
        {--apply : Выполнить (без флага — только список)}';

    protected $description = 'Route letters that live sync missed and the history mirror picked up as history';

    public function handle(MailHistoryMirrorService $mirror, MailRouter $router): int
    {
        // С какого момента папку читает живая синхронизация: раньше —
        // законная история, позже — пропуск.
        $liveSince = MailboxFolderState::query()->get(['mailbox_id', 'folder', 'created_at'])
            ->mapWithKeys(fn ($s) => [$s->mailbox_id.'|'.$s->folder => $s->created_at]);

        $rows = EmailMessage::withHistory()
            ->where('is_history', true)
            ->where('is_draft', false)
            ->where('sent_at', '>=', (string) $this->option('since'))
            ->whereIn('folder', ['INBOX', 'Sent'])
            ->when($this->option('mailbox'), fn ($q, $ids) => $q->whereIn('mailbox_id', array_map('intval', $ids)))
            ->when($this->option('id'), fn ($q, $ids) => $q->whereIn('id', array_map('intval', $ids)))
            ->when(! $this->option('inbound'), fn ($q) => $q->where('direction', MailDirection::Outbound->value))
            ->orderBy('sent_at')
            ->get();

        $picked = $rows->filter(function (EmailMessage $m) use ($liveSince) {
            $since = $liveSince[$m->mailbox_id.'|'.$m->folder] ?? null;

            // Живая копия того же письма в ящике есть — это не пропуск.
            $live = EmailMessage::query()
                ->where('mailbox_id', $m->mailbox_id)
                ->where('id', '!=', $m->id)
                ->whereRaw('lower(message_id) = ?', [mb_strtolower((string) $m->message_id)])
                ->exists();

            return $since !== null && $m->sent_at !== null && $m->sent_at->gte($since) && ! $live;
        });

        $this->info("Кандидатов: {$rows->count()}, пропущено синхронизацией: {$picked->count()}.");
        foreach ($picked as $m) {
            $this->line(sprintf(
                '  #%d %s ящик %d %s %s → %s «%s»',
                $m->id,
                $m->sent_at?->format('d.m H:i'),
                $m->mailbox_id,
                $m->direction?->value,
                $m->from_email,
                implode(', ', array_column((array) $m->to_recipients, 'email')),
                mb_substr((string) $m->subject, 0, 60),
            ));
        }

        if (! $this->option('apply')) {
            $this->comment('Это список. Выполнить — с --apply.');

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($picked as $m) {
            if (! $mirror->fetchBody($m)) {
                $this->warn("  #{$m->id}: текст с сервера не получен — пропускаю");

                continue;
            }
            $m->forceFill(['is_history' => false])->saveQuietly();

            try {
                $router->route($m->fresh());
                if ($m->direction === MailDirection::Inbound
                    && ! MailDecision::query()->where('email_message_id', $m->id)->exists()) {
                    app(MailDecisionRecorder::class)->record($m, 'ingest_silent', null, [
                        'reason' => 'Восстановлено после пропуска синхронизацией; маршрутизатор решения не принял',
                    ]);
                }
                $fresh = $m->fresh();
                $this->line("  #{$m->id}: обработано, заявка ".($fresh->relatedRequest?->internal_code ?? '—'));
                $done++;
            } catch (\Throwable $e) {
                Log::error('mail:recover-skipped: маршрутизатор упал', ['email_message_id' => $m->id, 'error' => $e->getMessage()]);
                $this->error("  #{$m->id}: ".$e->getMessage());
            }
        }

        $this->info("Обработано: {$done}.");

        return self::SUCCESS;
    }
}
