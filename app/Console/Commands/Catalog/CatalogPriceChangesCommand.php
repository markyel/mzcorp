<?php

namespace App\Console\Commands\Catalog;

use App\Models\CatalogPriceChange;
use Illuminate\Console\Command;

/**
 * Отчёт по истории изменения цен каталога (было → стало).
 *
 *   php artisan catalog:price-changes                 # за 30 дней, последние 50
 *   php artisan catalog:price-changes --days=90
 *   php artisan catalog:price-changes --sku=M17627
 *   php artisan catalog:price-changes --direction=up  # только подорожания
 *   php artisan catalog:price-changes --direction=down --limit=100
 */
class CatalogPriceChangesCommand extends Command
{
    protected $signature = 'catalog:price-changes
        {--days=30 : Окно в днях (по changed_at)}
        {--sku= : Только одна SKU}
        {--direction= : up (подорожания) | down (удешевления)}
        {--limit=50 : Сколько строк показать}';

    protected $description = 'История изменения цен каталожных позиций (было → стало, тренд).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $sku = $this->option('sku') ? trim((string) $this->option('sku')) : null;
        $direction = $this->option('direction');

        $q = CatalogPriceChange::query()
            ->with('catalogItem:id,sku,name')
            ->where('changed_at', '>=', now()->subDays($days));

        if ($sku !== null && $sku !== '') {
            $q->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)]);
        }

        // Фильтр направления — только по записям с обеими ценами.
        if ($direction === 'up') {
            $q->whereNotNull('old_price')->whereNotNull('new_price')
                ->whereColumn('new_price', '>', 'old_price');
        } elseif ($direction === 'down') {
            $q->whereNotNull('old_price')->whereNotNull('new_price')
                ->whereColumn('new_price', '<', 'old_price');
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('changed_at')->limit($limit)->get();

        if ($rows->isEmpty()) {
            $this->info('Изменений цен за выбранный период не найдено.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Изменений цен: %d (показаны %d, окно %d дн.)', $total, $rows->count(), $days));
        $this->newLine();

        $table = [];
        foreach ($rows as $c) {
            $delta = $c->priceDelta();
            $pct = ($delta !== null && (float) $c->old_price != 0.0)
                ? round($delta / (float) $c->old_price * 100, 1)
                : null;
            $arrow = $delta === null ? '—' : ($delta > 0 ? '▲' : ($delta < 0 ? '▼' : '='));

            $table[] = [
                $c->changed_at?->format('d.m.Y H:i') ?? '—',
                $c->sku,
                mb_strimwidth((string) ($c->catalogItem?->name ?? '—'), 0, 32, '…'),
                $this->fmt($c->old_price),
                $this->fmt($c->new_price),
                $arrow . ' ' . ($delta !== null ? $this->fmt((string) $delta) : '—'),
                $pct !== null ? ($pct > 0 ? '+' : '') . $pct . '%' : '—',
            ];
        }

        $this->table(['Дата', 'SKU', 'Наименование', 'Было', 'Стало', 'Δ', '%'], $table);

        return self::SUCCESS;
    }

    private function fmt(?string $v): string
    {
        if ($v === null) {
            return '—';
        }

        return number_format((float) $v, 2, ',', ' ');
    }
}
