<?php

namespace App\Services\Iqot;

use App\Models\CatalogItem;
use App\Models\IqotMonitoringItem;
use App\Models\IqotPosition;
use Illuminate\Support\Carbon;

/**
 * Цены рынка по позиции каталога из IQOT — одним списком из двух источников:
 *  - заявки на КП (/submissions, IqotPosition): КП поставщиков с именем, контактами и сроком;
 *  - мониторинг цен «Гибрид» (/monitoring, IqotMonitoringItem): цены сайтов поставщиков и их КП
 *    по почте (уже очищены: без перепроверки и дублей поставщика).
 * Всё сравнивается БЕЗ НДС (как в разделе IQOT); наша цена каталога (с НДС) — тоже без НДС и
 * стоит в списке на своём месте.
 */
class IqotMarket
{
    public const KIND_REQUEST = 'request';   // КП по заявке IQOT

    public const KIND_SITE = 'site';         // цена с сайта поставщика (мониторинг)

    public const KIND_MAIL_KP = 'mail_kp';   // КП поставщика по почте (мониторинг, «Гибрид»)

    /**
     * @return array{
     *   rows: list<array<string, mixed>>, offers:int, from_requests:int, from_monitoring:int,
     *   best_net:?float, median_net:?float, our_net:?float, our_rank:?int, total:int, delta:?float, delta_pct:?float,
     *   our_label:?string, request_date:?Carbon, monitoring_date:?Carbon
     * }
     */
    public static function for(?CatalogItem $cat, ?IqotPosition $pos, ?IqotMonitoringItem $mon): array
    {
        $rate = (float) app_setting('tax.vat_percent', config('services.tax.vat_percent', 22)) / 100;
        $rows = [];
        $ourNet = null;
        $ourGross = null;
        $ourLabel = null;

        if ($pos !== null && ! empty($pos->report)) {
            $cmp = $pos->priceComparison();
            foreach ($cmp['rows'] as $r) {
                if ($r['is_ours']) {
                    [$ourNet, $ourGross, $ourLabel] = [$r['net_rub'] ?? $r['net'], $r['raw'], $r['supplier']];

                    continue;
                }
                if ($r['net_rub'] === null) {
                    continue;   // валюта без курса — не сравнить
                }
                $rows[] = [
                    'kind' => self::KIND_REQUEST, 'supplier' => $r['supplier'], 'url' => self::siteFromEmail($r['email'] ?? null),
                    'contacts' => trim(($r['phone'] ?? '').(! empty($r['email']) ? ' · '.$r['email'] : ''), ' ·'),
                    'raw' => $r['raw'], 'symbol' => $r['currency_symbol'], 'vat' => $r['vat_label'], 'net' => round((float) $r['net_rub'], 2),
                    'delivery_days' => $r['delivery_days'], 'availability' => null, 'confirmed' => false, 'pages' => 1,
                    'date' => self::date($r['received_at'] ?? null),
                ];
            }
        }

        if ($mon !== null && $mon->active) {
            foreach ((array) ($mon->sources ?? []) as $s) {
                if (! isset($s['net'])) {
                    continue;
                }
                $fromKp = $s['from_kp'] ?? ! is_numeric($s['price'] ?? null);
                $rows[] = [
                    'kind' => $fromKp || ($s['type'] ?? null) === 'kp' ? self::KIND_MAIL_KP : self::KIND_SITE,
                    'supplier' => self::readableDomain((string) ($s['domain'] ?? '—')), 'url' => $s['url'] ?? null, 'contacts' => null,
                    'raw' => (float) $s['value'], 'symbol' => '₽',
                    'vat' => ($s['vat'] ?? null) === true ? 'с НДС' : (($s['vat'] ?? null) === false ? 'без НДС' : 'НДС не указан'),
                    'net' => (float) $s['net'], 'delivery_days' => null, 'availability' => $s['availability'] ?? null,
                    'confirmed' => ! empty($s['flags']['email_confirmed']), 'mail_kp' => ! $fromKp && ! empty($s['kp']['price']) ? (float) $s['kp']['price'] : null,
                    'pages' => (int) ($s['pages'] ?? 1), 'date' => self::date($s['observed_at'] ?? null),
                    'web_old' => isset($s['web_old']) ? (float) $s['web_old'] : null, 'doubtful' => ! empty($s['doubtful']),
                ];
            }
        }

        // Наша цена: из сравнения по заявке (там же фолбэк на цену проигранного КП), иначе — цена каталога.
        if ($ourNet === null && $cat !== null && is_numeric($cat->price) && (float) $cat->price > 0) {
            $ourGross = (float) $cat->price;
            $ourNet = round($ourGross / (1 + $rate), 2);
            $ourLabel = 'Цена каталога';
        }

        usort($rows, fn ($a, $b) => $a['net'] <=> $b['net']);
        // «Сомнительные» цены сайтов в списке видны, но в лучшую, медиану и наше место не входят.
        $factual = array_values(array_filter($rows, fn ($r) => empty($r['doubtful'])));
        $nets = array_column($factual, 'net');
        $best = $nets === [] ? null : $nets[0];
        $median = null;
        if ($nets !== []) {
            $mid = intdiv(count($nets), 2);
            $median = count($nets) % 2 ? $nets[$mid] : ($nets[$mid - 1] + $nets[$mid]) / 2;
        }
        $rank = $ourNet === null || $nets === [] ? null : 1 + count(array_filter($nets, fn ($n) => $n < $ourNet));

        return [
            'rows' => $rows,
            'offers' => count($factual),
            'from_requests' => count(array_filter($factual, fn ($r) => $r['kind'] === self::KIND_REQUEST)),
            'from_monitoring' => count(array_filter($factual, fn ($r) => $r['kind'] !== self::KIND_REQUEST)),
            'best_net' => $best,
            'median_net' => $median,
            'our_net' => $ourNet,
            'our_gross' => $ourGross,
            'our_label' => $ourLabel,
            'our_rank' => $rank,
            'total' => count($factual) + ($ourNet !== null ? 1 : 0),
            'delta' => $ourNet !== null && $best ? round($ourNet - $best, 2) : null,
            'delta_pct' => $ourNet !== null && $best ? round(($ourNet - $best) / $best * 100, 1) : null,
            'request_date' => $pos?->analyzed_at,
            'monitoring_date' => $mon?->prices_synced_at,
        ];
    }

    /** Публичные почтовые сервисы: домен адреса — не сайт поставщика. */
    private const FREE_MAIL = ['mail.ru', 'inbox.ru', 'list.ru', 'bk.ru', 'internet.ru', 'yandex.ru', 'ya.ru', 'yandex.com', 'gmail.com', 'googlemail.com',
        'rambler.ru', 'lenta.ru', 'autorambler.ru', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com', 'me.com', 'yahoo.com', 'qq.com', '163.com', '126.com'];

    /**
     * Сайт поставщика по домену его почты (в отчёте IQOT по КП — только имя, почта и телефон):
     * info@liftlider.ru → https://liftlider.ru. Публичная почта — без ссылки.
     */
    public static function siteFromEmail(?string $email): ?string
    {
        $domain = mb_strtolower(trim((string) substr(strrchr((string) $email, '@') ?: '', 1)));
        if ($domain === '' || ! str_contains($domain, '.') || in_array($domain, self::FREE_MAIL, true)) {
            return null;
        }

        return 'https://'.$domain;
    }

    /** Кириллический домен в читаемом виде (xn--…рф → …рф). */
    public static function readableDomain(string $domain): string
    {
        if (! str_contains($domain, 'xn--') || ! function_exists('idn_to_utf8')) {
            return $domain;
        }
        $utf = idn_to_utf8($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $utf !== false ? $utf : $domain;
    }

    private static function date(mixed $v): ?Carbon
    {
        try {
            return $v ? Carbon::parse($v)->timezone(config('app.timezone')) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
