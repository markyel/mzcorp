<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Services\Request\RequestStatusReassessor;
use Illuminate\Console\Command;

/**
 * Переоценка статуса «застрявших» заявок в менеджерских статусах
 * (assigned/in_progress), по которым давно тишина. LLM читает переписку и, если
 * ход за клиентом (мы задали вопрос / прислали КП / счёт, клиент молчит),
 * переводит заявку в waiting-on-client статус → дальше её штатно закроет
 * requests:auto-close-inactive по таймауту.
 *
 *   php artisan requests:reassess-stalled                 (dry-run)
 *   php artisan requests:reassess-stalled --apply
 */
class RequestsReassessStalledCommand extends Command
{
    protected $signature = 'requests:reassess-stalled
        {--apply : Реально переводить статус (иначе dry-run)}
        {--limit=200 : Максимум заявок за прогон}
        {--days=7 : Тишина ≥ N календарных дней (нет писем в клиентском треде)}
        {--min-confidence=0.75 : Порог уверенности LLM для авто-перевода}
        {--statuses=assigned,in_progress : Из каких статусов переоценивать}';

    protected $description = 'LLM-переоценка статуса застрявших заявок: если ход за клиентом — перевести в waiting-on-client.';

    public function handle(RequestStatusReassessor $reassessor): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $days = (int) $this->option('days');
        $minConf = (float) $this->option('min-confidence');
        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('statuses')))));

        $targets = Request::query()
            ->whereIn('status', $statuses)
            ->whereNull('merged_into_id')
            // есть клиентская переписка
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('email_messages as em0')
                ->whereColumn('em0.related_request_id', 'requests.id')
                ->whereNull('em0.supplier_inquiry_id')->where('em0.is_draft', false))
            // тишина: нет писем клиентского треда за последние N дней
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('email_messages as em')
                ->whereColumn('em.related_request_id', 'requests.id')
                ->whereNull('em.supplier_inquiry_id')->where('em.is_draft', false)
                ->where('em.sent_at', '>=', now()->subDays($days)))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->info(sprintf('Кандидатов: %d (mode: %s, тишина ≥%d дн, порог %.2f)', $targets->count(), $apply ? 'APPLY' : 'dry-run', $days, $minConf));

        $stats = ['client' => 0, 'us' => 0, 'unclear' => 0, 'applied' => 0, 'skipped' => 0];
        foreach ($targets as $req) {
            $decision = $reassessor->assess($req);
            if ($decision === null) {
                $stats['skipped']++;

                continue;
            }
            $ball = $decision['ball_with'];
            $stats[$ball] = ($stats[$ball] ?? 0) + 1;

            $line = sprintf('  %s [%s] ход=%s → %s conf=%.2f | %s',
                $req->internal_code, $req->status->value, $ball,
                $decision['target_status'] ?? '—', $decision['confidence'],
                mb_substr($decision['reasoning'], 0, 70));

            if ($apply) {
                $target = $reassessor->apply($req, $decision, null, $minConf);
                if ($target !== null) {
                    $stats['applied']++;
                    $this->line($line . "  ✓ переведено в {$target->value}");
                } else {
                    $this->line($line);
                }
            } else {
                $wouldApply = $ball === 'client'
                    && in_array($decision['target_status'], ['awaiting_client_clarification', 'quoted', 'invoiced'], true)
                    && $decision['confidence'] >= $minConf;
                $this->line($line . ($wouldApply ? '  → БУДЕТ переведено' : ''));
            }
        }

        $this->info(sprintf('Итог: ход за клиентом=%d, за нами=%d, неясно=%d, применено=%d, пропущено=%d',
            $stats['client'], $stats['us'], $stats['unclear'], $stats['applied'], $stats['skipped']));

        return self::SUCCESS;
    }
}
