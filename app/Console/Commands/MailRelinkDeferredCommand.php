<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Services\Mail\InboundReplyLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Подобрать inbound reply'ы, которые в момент route() оказались без
 * привязки к Request — потому что parent (письмо-исходник) ещё не был
 * категоризован (OpenAI 503/timeout / invalid JSON).
 *
 * Линкер теперь deferral'ит такие reply'и (см. InboundReplyLinker::tryLink
 * orphan-parent gate). Эта команда повторно вызывает tryLink для висящих
 * категоризованных reply'ев — на втором проходе parent уже обработан
 * (mail:categorize --all его подобрал), level-1 матчит корректно.
 *
 *   php artisan mail:relink-deferred                 # dry-list
 *   php artisan mail:relink-deferred --apply         # реально перепривязать
 *   php artisan mail:relink-deferred --apply --limit=50
 *   php artisan mail:relink-deferred --apply --since-hours=48
 */
class MailRelinkDeferredCommand extends Command
{
    protected $signature = 'mail:relink-deferred
        {--apply : Реально вызвать linker (без флага — dry-list)}
        {--limit=50 : Сколько писем обработать за прогон}
        {--since-hours=24 : Брать только за последние N часов}';

    protected $description = 'Подобрать категоризованные reply\'и без request_id и повторно запустить linker';

    public function handle(InboundReplyLinker $linker): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $sinceHours = max(1, (int) $this->option('since-hours'));

        $query = EmailMessage::query()
            ->where('direction', 'inbound')
            ->whereNull('related_request_id')
            ->whereNotNull('categorized_at')
            ->where('created_at', '>=', now()->subHours($sinceHours))
            ->where(function ($q) {
                $q->whereNotNull('in_reply_to')
                    ->orWhereNotNull('references_header');
            })
            ->orderBy('id');

        $candidates = $query->limit($limit)->get();
        if ($candidates->isEmpty()) {
            $this->info('Нет deferred reply\'ев для повторного линка.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d писем (mode: %s)…',
            $apply ? 'Обрабатываю' : 'Найдено',
            $candidates->count(),
            $apply ? 'apply' : 'dry-list',
        ));

        $linked = 0;
        $stillDeferred = 0;

        foreach ($candidates as $m) {
            $line = sprintf(
                '  #%d  %s  ← %s  (cat=%s, in_reply_to=%s)',
                $m->id,
                mb_substr((string) $m->subject, 0, 50),
                $m->from_email,
                $m->category ?? '-',
                $m->in_reply_to ? mb_substr($m->in_reply_to, 0, 30) : '-',
            );

            if (! $apply) {
                $this->line($line);
                continue;
            }

            try {
                $request = $linker->tryLink($m->fresh());
            } catch (\Throwable $e) {
                $this->line($line . '  → ERROR: ' . $e->getMessage());
                Log::warning('MailRelinkDeferredCommand: linker failed', [
                    'email_message_id' => $m->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($request) {
                $linked++;
                $this->line($line . '  → linked to ' . $request->internal_code);
                // Парсер: для new-relinked нужно вытащить позиции в правильную
                // заявку. Force=true — на случай если parser уже отработал
                // на этом email в момент old-link (вернул empty или попал не туда).
                \App\Jobs\Mail\ParseRequestItemsJob::dispatch($m->id, force: true);
            } else {
                $stillDeferred++;
                $this->line($line . '  → still deferred (parent ещё без request_id?)');
            }
        }

        $this->newLine();
        $this->table(['metric', 'value'], [
            ['examined',       (string) $candidates->count()],
            ['linked',         (string) $linked],
            ['still deferred', (string) $stillDeferred],
        ]);

        return self::SUCCESS;
    }
}
