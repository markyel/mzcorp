<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\IqotPosition;
use App\Services\Iqot\CbrFxRateProvider;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;

/**
 * Ежедневное обновление курсов валют (USD/EUR/CNY) из ЦБ РФ для конвертации
 * офферов IQOT в рубли. Пишет в те же настройки, что редактируются в UI
 * (`iqot.fx_usd/fx_eur/fx_cny`), плюс штамп `iqot.fx_updated_at`.
 *
 * Крон — раз в день (см. routes/console.php). Уважает тумблер
 * `iqot.fx_auto_update` (по умолчанию вкл.) — выключив его, оператор может
 * «запинить» ручные курсы. `--force` игнорирует тумблер, `--dry-run` только
 * показывает, ничего не пишет.
 *
 *   php artisan iqot:update-fx-rates
 *   php artisan iqot:update-fx-rates --dry-run
 *   php artisan iqot:update-fx-rates --force
 */
class IqotUpdateFxRatesCommand extends Command
{
    protected $signature = 'iqot:update-fx-rates {--dry-run : Показать курсы, ничего не записывать} {--force : Игнорировать тумблер iqot.fx_auto_update}';

    protected $description = 'Обновить курсы валют IQOT (USD/EUR/CNY) из ЦБ РФ';

    /** ISO-код → ключ настройки. */
    private const KEY_MAP = [
        'USD' => 'iqot.fx_usd',
        'EUR' => 'iqot.fx_eur',
        'CNY' => 'iqot.fx_cny',
    ];

    public function handle(CbrFxRateProvider $provider, SettingsService $settings): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $autoUpdate = (bool) app_setting('iqot.fx_auto_update', true);
        if (! $autoUpdate && ! $force && ! $dryRun) {
            $this->info('iqot.fx_auto_update выключен — пропуск (используйте --force).');

            return self::SUCCESS;
        }

        $result = $provider->fetch();
        $rates = $result['rates'];
        $cbrDate = $result['date'];

        if ($rates === []) {
            $this->warn('ЦБ РФ не вернул курсов — старые значения сохранены. См. лог.');

            return self::FAILURE;
        }

        $rows = [];
        $changed = 0;
        foreach (self::KEY_MAP as $code => $key) {
            if (! isset($rates[$code])) {
                $rows[] = [$code, '—', 'нет в ответе ЦБ'];

                continue;
            }
            $new = round($rates[$code], 4);
            $old = app_setting($key, (float) config('services.iqot.fx_rates.'.$code, 0));
            $old = $old === null ? null : (float) $old;

            if (! $dryRun) {
                $settings->set($key, $new, AppSetting::TYPE_FLOAT, null, 'auto: ЦБ РФ '.($cbrDate ?? ''));
            }
            $rows[] = [
                $code,
                $old !== null ? number_format($old, 4, '.', '') : '—',
                number_format($new, 4, '.', '').' ₽',
            ];
            $changed++;
        }

        if (! $dryRun && $changed > 0) {
            $settings->set('iqot.fx_updated_at', now()->toDateTimeString().($cbrDate ? ' (ЦБ '.$cbrDate.')' : ''), AppSetting::TYPE_STRING);
            // Кешированные «Мин. цена» (report_min_price) и сигналы сравнения
            // (cmp_*, для фильтра «требуют внимания») считаются в рублях по
            // текущему курсу — пересчитываем по новым курсам, иначе колонки в
            // пуле/каталоге/КП отстают на день.
            $refreshed = $this->refreshCachedComparison($settings);
            $this->line("Пересчитаны кеши сравнения у {$refreshed} позиций.");
        }

        $this->table(['Валюта', 'Было', 'Стало (за 1 ед.)'], $rows);
        $this->info(($dryRun ? '[dry-run] ' : '').'Курсы ЦБ РФ'.($cbrDate ? " на {$cbrDate}" : '').": обновлено {$changed}.");

        return self::SUCCESS;
    }

    /**
     * Пересчитать кеши, зависящие от курса: report_min_price (рублёвый
     * эквивалент) + сигналы сравнения cmp_* (ранг/отклонение для фильтра
     * «требуют внимания»). SettingsService-кэш уже сброшен set()'ом, так что
     * minPriceFromReport/priceComparison видят свежие курсы.
     */
    private function refreshCachedComparison(SettingsService $settings): int
    {
        $settings->forget();
        $updated = 0;
        IqotPosition::whereNotNull('report')->chunkById(200, function ($rows) use (&$updated) {
            foreach ($rows as $pos) {
                $newMin = $pos->minPriceFromReport();
                $cmp = $pos->priceComparison();

                $changed =
                    (($newMin === null ? null : round($newMin, 2)) !== ($pos->report_min_price === null ? null : round((float) $pos->report_min_price, 2)))
                    || ((int) $cmp['our_rank'] !== (int) $pos->cmp_our_rank)
                    || (($cmp['delta_pct'] === null ? null : round($cmp['delta_pct'], 2)) !== ($pos->cmp_deviation_pct === null ? null : round((float) $pos->cmp_deviation_pct, 2)));

                if ($changed) {
                    $pos->forceFill([
                        'report_min_price' => $newMin,
                        'cmp_our_rank' => $cmp['our_rank'],
                        'cmp_deviation_pct' => $cmp['delta_pct'],
                        'cmp_total' => $cmp['total'] ?: null,
                    ])->save();
                    $updated++;
                }
            }
        });

        return $updated;
    }
}
