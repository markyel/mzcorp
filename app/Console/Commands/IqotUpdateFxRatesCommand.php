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
            // Кешированная «Мин. цена» (report_min_price) считается в рублях по
            // текущему курсу — пересчитываем по новым курсам, иначе колонка в
            // пуле/каталоге/КП отстаёт на день. Live-сравнение (priceComparison)
            // считается в рантайме и в рефреше не нуждается.
            $refreshed = $this->refreshCachedMinPrices($settings);
            $this->line("Пересчитана «Мин. цена» у {$refreshed} позиций.");
        }

        $this->table(['Валюта', 'Было', 'Стало (за 1 ед.)'], $rows);
        $this->info(($dryRun ? '[dry-run] ' : '').'Курсы ЦБ РФ'.($cbrDate ? " на {$cbrDate}" : '').": обновлено {$changed}.");

        return self::SUCCESS;
    }

    /**
     * Пересчитать кешированный report_min_price (рублёвый эквивалент) по
     * актуальным курсам. SettingsService-кэш уже сброшен set()'ом, так что
     * minPriceFromReport видит свежие курсы.
     */
    private function refreshCachedMinPrices(SettingsService $settings): int
    {
        $settings->forget();
        $updated = 0;
        IqotPosition::whereNotNull('report')->chunkById(200, function ($rows) use (&$updated) {
            foreach ($rows as $pos) {
                $new = $pos->minPriceFromReport();
                $old = $pos->report_min_price === null ? null : (float) $pos->report_min_price;
                if (($new === null ? null : round($new, 2)) !== ($old === null ? null : round($old, 2))) {
                    $pos->forceFill(['report_min_price' => $new])->save();
                    $updated++;
                }
            }
        });

        return $updated;
    }
}
