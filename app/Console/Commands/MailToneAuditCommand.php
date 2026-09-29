<?php

namespace App\Console\Commands;

use App\Services\Mail\OutboundToneAuditService;
use Illuminate\Console\Command;

/**
 * Проверить письма менеджеров клиентам на соответствие образу компании
 * (медиапрофиль): тон, ответственность, гарантийная политика. Уже
 * проверенные письма пропускаются — команду можно запускать повторно.
 */
class MailToneAuditCommand extends Command
{
    protected $signature = 'mail:tone-audit
        {--since= : С какой даты (по умолчанию — 7 дней назад)}
        {--until= : По какую дату (по умолчанию — сейчас)}
        {--limit=0 : Не больше N писем}
        {--concurrency=6 : Параллельных запросов к модели}
        {--dry : Только посчитать кандидатов}
        {--egregious-only : Только отобрать вопиющие среди уже отмеченных}';

    protected $description = 'Audit managers\' letters to clients against the company media profile (tone, blame, warranty policy)';

    public function handle(OutboundToneAuditService $audit): int
    {
        $since = (string) ($this->option('since') ?: now()->subDays(7)->toDateString());
        $until = (string) ($this->option('until') ?: now()->toDateTimeString());
        $limit = (int) $this->option('limit');

        if ($this->option('egregious-only')) {
            $this->info('Вопиющих: '.$audit->markEgregious($since, (int) $this->option('concurrency')).'.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $c = $audit->candidates($since, $until, $limit);
            $this->info("Писем к проверке: {$c->count()}.");
            foreach ($c->take(5) as $l) {
                $this->line("  #{$l['id']}: ".mb_substr(str_replace("\n", ' / ', $l['text']), 0, 150));
            }

            return self::SUCCESS;
        }

        $res = $audit->run($since, $until, $limit, (int) $this->option('concurrency'), function ($done, $issues, $total) {
            $this->line("  проверено {$done} из {$total}, с замечаниями {$issues}");
        });

        $this->info("Кандидатов {$res['candidates']}, проверено {$res['reviewed']}, с замечаниями {$res['issues']}, из них вопиющих {$res['egregious']}, не удалось {$res['failed']}.");

        return self::SUCCESS;
    }
}
