<?php

namespace App\Console\Commands;

use App\Enums\MailDirection;
use App\Jobs\Kb\ResolveKbJob;
use App\Jobs\Mail\ParseRequestItemsJob;
use App\Models\EmailMessage;
use App\Models\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Перепрогон парсинга позиций + KB-resolve для одной заявки.
 *
 * По умолчанию проходит ВСЕ inbound-сообщения треда в хронологическом
 * порядке: сначала исходное письмо (создаёт позиции), затем каждый
 * reply (clarifications / Path C обогащают существующие). В конце
 * запускается ResolveKbJob (контекст + KB-assessment + Photo classifier).
 *
 * Запуск:
 *   php artisan request:reparse M-2026-1147               — все письма треда + KB
 *   php artisan request:reparse M-2026-1147 --queue       — через очередь
 *   php artisan request:reparse M-2026-1147 --reset       — сбросить items
 *                                                            (применяется только
 *                                                            к первому письму)
 *   php artisan request:reparse M-2026-1147 --only-original — только исходное
 *   php artisan request:reparse M-2026-1147 --no-kb       — пропустить ResolveKbJob
 */
class RequestReparse extends Command
{
    protected $signature = 'request:reparse {code} {--queue : через очередь вместо sync} {--reset : удалить items и распарсить заново} {--no-kb : пропустить ResolveKbJob} {--only-original : только исходное письмо, не проходить reply треда}';

    protected $description = 'Перепрогон ParseRequestItemsJob (вся цепочка треда) + ResolveKbJob для одной заявки';

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        $req = Request::where('internal_code', $code)->first();
        if (! $req) {
            $this->error("Заявка {$code} не найдена");
            return self::FAILURE;
        }
        if (! $req->email_message_id) {
            $this->error("У заявки {$code} нет связанного email_message_id — нечего перепарсить");
            return self::FAILURE;
        }

        $reset = (bool) $this->option('reset');
        $async = (bool) $this->option('queue');
        $skipKb = (bool) $this->option('no-kb');
        $onlyOriginal = (bool) $this->option('only-original');

        // Собираем все inbound-письма треда (исходное + reply'и) в порядке
        // sent_at. Inbound — потому что reparse outbound не имеет смысла
        // (это наши же сообщения, парсить из них позиции не надо).
        $messages = $this->collectThreadMessages($req, $onlyOriginal);

        $this->line("Заявка #{$req->id} {$req->internal_code}");
        $this->line('Режим: '.($async ? 'queue' : 'sync').', reset='.($reset ? 'yes' : 'no')
            .', no-kb='.($skipKb ? 'yes' : 'no').', only-original='.($onlyOriginal ? 'yes' : 'no'));
        $this->line('Inbound-сообщений в обработку: '.$messages->count());
        foreach ($messages as $m) {
            $this->line('  · #'.$m->id.' | '.$m->sent_at?->format('d.m H:i').' | '.$m->subject);
        }
        $this->line('');

        // --- 1. ParseRequestItemsJob для каждого сообщения треда ---
        foreach ($messages as $idx => $message) {
            // reset применяется ТОЛЬКО к первому сообщению — иначе reply'и
            // будут многократно стирать items.
            $resetThis = $reset && $idx === 0;
            $tag = $idx === 0 ? 'оригинал' : 'reply';

            $this->line("--- ParseRequestItemsJob msg #{$message->id} ({$tag}) force=true"
                .($resetThis ? ' reset=true' : '').' ---');
            try {
                $job = new ParseRequestItemsJob($message->id, true, $resetThis);
                if ($async) {
                    Bus::dispatch($job);
                    $this->info('  поставлен в очередь');
                } else {
                    Bus::dispatchSync($job);
                    $this->info('  выполнен sync');
                }
            } catch (\Throwable $e) {
                $this->error('  ОШИБКА на msg #'.$message->id.': '.$e->getMessage());
                // Не останавливаем pipeline — даём шанс остальным сообщениям.
            }
        }
        $this->line('');

        if ($skipKb) {
            $this->line('--- ResolveKbJob пропущен (--no-kb) ---');
            return self::SUCCESS;
        }

        // --- 2. ResolveKbJob один раз на всю заявку (после всех Parse) ---
        $this->line('--- ResolveKbJob ---');
        try {
            $job = new ResolveKbJob($req->id);
            if ($async) {
                Bus::dispatch($job);
                $this->info('  поставлен в очередь');
            } else {
                Bus::dispatchSync($job);
                $this->info('  выполнен sync');
            }
        } catch (\Throwable $e) {
            $this->error('  ОШИБКА: '.$e->getMessage());
            return self::FAILURE;
        }
        $this->line('');

        $this->line('=== Готово. Проверь UI или запусти inspect:request '.$code.' ===');
        return self::SUCCESS;
    }

    /**
     * Inbound-сообщения треда в порядке sent_at. Первое — обычно исходное
     * (email_message_id у заявки), но не делаем strong assumption, потому
     * что после merge'ов / делегирования это может быть не так.
     *
     * @return \Illuminate\Support\Collection<int, EmailMessage>
     */
    private function collectThreadMessages(Request $req, bool $onlyOriginal): \Illuminate\Support\Collection
    {
        if ($onlyOriginal) {
            $m = EmailMessage::find($req->email_message_id);
            return $m ? collect([$m]) : collect();
        }

        return EmailMessage::query()
            ->where('related_request_id', $req->id)
            ->where('direction', MailDirection::Inbound->value)
            ->orderByRaw('COALESCE(sent_at, created_at) ASC')
            ->orderBy('id')
            ->get();
    }
}
