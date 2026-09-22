<?php

namespace App\Console\Commands;

use App\Services\Direct\DirectSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Сопровождение рекламы в Директе: выключить объявления позиций, которых не
 * стало на складе, вернуть те, что вернулись, и подтянуть состояние из Директа.
 *
 * Прогон ничего не делает, пока в разделе не включён выключатель
 * («direct.sync_enabled»), и по умолчанию работает в режиме предложений —
 * автомат в рекламном кабинете тратит деньги, поэтому заводится осторожно.
 */
class DirectSyncCommand extends Command
{
    protected $signature = 'direct:sync
        {--apply : Применить изменения в Директе, игнорируя режим «только предложения»}
        {--dry-run : Только показать, что изменилось бы}';

    protected $description = 'Синхронизация объявлений Директа с наличием на складе';

    public function handle(DirectSyncService $sync): int
    {
        if (! $sync->enabled() && ! $this->option('dry-run')) {
            $this->info('Синхронизация выключена в настройках раздела «Директ».');

            return self::SUCCESS;
        }

        $apply = match (true) {
            (bool) $this->option('dry-run') => false,
            (bool) $this->option('apply') => true,
            default => null,
        };

        $report = $sync->run($apply);

        $this->info(sprintf(
            '%s: проверено %d, выключить %d, включить %d, тексты %d, создать %d, на модерацию %d, ставок обновлено %d.',
            $report['applied'] ? 'Применено' : 'Предложения',
            $report['checked'],
            count($report['suspend']),
            count($report['resume']),
            count($report['texts']),
            count($report['published']),
            count($report['moderated']),
            $report['bids_set'] ?? 0,
        ));

        foreach ($report['suspend'] as $line) {
            $this->line('  − '.$line);
        }
        foreach ($report['resume'] as $line) {
            $this->line('  + '.$line);
        }
        if (($report['demand'] ?? 0) > 0) {
            $this->line('  спрос померен по фразам: '.$report['demand']);
        }
        if (($report['autotargeting'] ?? 0) > 0) {
            $this->line(sprintf(
                '  автотаргетинг: ставка %s ₽ в %d группах',
                \App\Services\Direct\DirectPublisherService::autotargetingBid(),
                $report['autotargeting'],
            ));
        }
        foreach (['texts' => 'тексты', 'published' => 'создать', 'moderated' => 'модерация', 'fixed' => 'переписать после отказа', 'retired' => 'убрать из кабинета', 'keywords' => 'фразы', 'bids' => 'ставки'] as $key => $label) {
            if ($report[$key] !== []) {
                $this->line('  '.$label.': '.implode(', ', $report[$key]));
            }
        }
        foreach ($report['attention'] as $line) {
            $this->warn('  ⚠ '.$line);
        }
        foreach ($report['errors'] as $line) {
            $this->warn('  ! '.$line);
        }

        if ($report['errors'] !== []) {
            Log::warning('Direct: синхронизация с ошибками', ['errors' => $report['errors']]);
        }

        return self::SUCCESS;
    }
}
