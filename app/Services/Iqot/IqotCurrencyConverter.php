<?php

namespace App\Services\Iqot;

/**
 * Конвертация цен офферов IQOT в рубли для сравнения.
 *
 * Зачем: офферы IQOT приходят в РАЗНЫХ валютах (поле `currency` в каждом
 * оффере — RUB / USD / EUR / CNY). Раньше код брал `price_per_unit` как
 * рубли и по нему же ранжировал — китайский поставщик с «80 USD» оказывался
 * «дешевле» нашего КП в 2300 ₽ (баг: 80 трактовалось как 80 ₽). Также сам
 * IQOT в `best_offer_by_price` выбирает минимум по «голому» числу без учёта
 * валюты — доверять ему нельзя.
 *
 * Курсы редактируются в Настройках (app_settings `iqot.fx_*`), дефолты —
 * `config('services.iqot.fx_rates')`. Это ПРИБЛИЗИТЕЛЬНЫЙ ручной курс
 * (не онлайн-котировка) — UI помечает сконвертированные строки бейджем.
 */
class IqotCurrencyConverter
{
    /** Синонимы/символы валют → ISO-код. */
    private const ALIASES = [
        'RUB' => 'RUB', 'RUR' => 'RUB', 'РУБ' => 'RUB', 'Р' => 'RUB', '₽' => 'RUB',
        'USD' => 'USD', 'US$' => 'USD', '$' => 'USD', 'ДОЛЛАР' => 'USD',
        'EUR' => 'EUR', '€' => 'EUR', 'ЕВРО' => 'EUR',
        'CNY' => 'CNY', 'RMB' => 'CNY', '¥' => 'CNY', '元' => 'CNY', 'ЮАНЬ' => 'CNY',
    ];

    /** ISO-код → ключ настройки курса. */
    private const RATE_KEYS = [
        'USD' => 'iqot.fx_usd',
        'EUR' => 'iqot.fx_eur',
        'CNY' => 'iqot.fx_cny',
    ];

    /** Привести валюту оффера к ISO-коду. Пусто/неизвестно-домашнее → RUB. */
    public static function normalize(?string $currency): string
    {
        $c = mb_strtoupper(trim((string) $currency));
        if ($c === '') {
            return 'RUB';
        }

        return self::ALIASES[$c] ?? $c;
    }

    /**
     * Курс единицы валюты в рублях. RUB → 1.0. Неизвестная валюта или
     * незаданный (≤0) курс → null (нельзя сравнивать в рублях).
     */
    public static function rateToRub(?string $currency): ?float
    {
        $c = self::normalize($currency);
        if ($c === 'RUB') {
            return 1.0;
        }
        $key = self::RATE_KEYS[$c] ?? null;
        if ($key === null) {
            return null;
        }
        $rate = (float) app_setting($key, (float) config('services.iqot.fx_rates.'.$c, 0));

        return $rate > 0 ? $rate : null;
    }

    /**
     * Перевести сумму в рубли.
     *
     * @return array{rub: ?float, rate: ?float, currency: string, converted: bool, known: bool}
     *   rub       — сумма в рублях (null, если курс неизвестен);
     *   rate      — применённый курс (null при неизвестном);
     *   currency  — нормализованный ISO-код;
     *   converted — true, если валюта не RUB;
     *   known     — true, если смогли привести к рублям (RUB или курс задан).
     */
    public static function toRub(?float $amount, ?string $currency): array
    {
        $c = self::normalize($currency);
        if ($c === 'RUB') {
            return ['rub' => $amount, 'rate' => 1.0, 'currency' => 'RUB', 'converted' => false, 'known' => true];
        }
        $rate = self::rateToRub($c);
        if ($rate === null || $amount === null) {
            return ['rub' => null, 'rate' => $rate, 'currency' => $c, 'converted' => true, 'known' => false];
        }

        return ['rub' => $amount * $rate, 'rate' => $rate, 'currency' => $c, 'converted' => true, 'known' => true];
    }

    /** Символ валюты для вывода. */
    public static function symbol(?string $currency): string
    {
        return match (self::normalize($currency)) {
            'RUB' => '₽',
            'USD' => '$',
            'EUR' => '€',
            'CNY' => '¥',
            default => self::normalize($currency),
        };
    }

    /**
     * Первая ПОЛОЖИТЕЛЬНАЯ цена оффера (price_per_unit → total_price → price).
     * Цена ≤ 0 — невалидный/пустой ответ поставщика (ошибка IQOT, поставщик не
     * проставил цену) — такие офферы НЕ участвуют в сравнении/ранге/мин.цене.
     * Возвращает null, если положительной цены нет.
     *
     * @param  array<string, mixed>  $offer
     */
    public static function firstPositivePrice(array $offer): ?float
    {
        foreach (['price_per_unit', 'total_price', 'price'] as $k) {
            if (isset($offer[$k]) && is_numeric($offer[$k]) && (float) $offer[$k] > 0) {
                return (float) $offer[$k];
            }
        }

        return null;
    }

    /**
     * Минимальная цена офферов в рублёвом эквиваленте (для «Мин. цена» в пуле).
     * Берёт первую ПОЛОЖИТЕЛЬНУЮ цену каждого оффера, конвертирует по его валюте
     * и возвращает min среди приведённых к рублям. Нулевые офферы игнорируются.
     * Если ни один не сконвертировался (неизвестные валюты) — fallback на min
     * «голых» положительных чисел, чтобы не потерять значение совсем.
     *
     * @param  iterable<mixed>  $offers
     */
    public static function minRawRub(iterable $offers): ?float
    {
        $rub = [];
        $raw = [];
        foreach ($offers as $o) {
            if (! is_array($o)) {
                continue;
            }
            $price = self::firstPositivePrice($o);
            if ($price === null) {
                continue;
            }
            $raw[] = $price;
            $conv = self::toRub($price, $o['currency'] ?? null);
            if ($conv['rub'] !== null) {
                $rub[] = $conv['rub'];
            }
        }
        if ($rub !== []) {
            return min($rub);
        }

        return $raw === [] ? null : min($raw);
    }
}
