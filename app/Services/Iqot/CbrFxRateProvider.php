<?php

namespace App\Services\Iqot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Поставщик официальных курсов валют ЦБ РФ (XML_daily.asp) для конвертации
 * офферов IQOT в рубли. Бесплатно, без ключа, публикуется раз в день
 * (курс на текущую дату появляется накануне вечером).
 *
 * Формат ответа (windows-1251 XML):
 *   <ValCurs Date="08.06.2026" name="Foreign Currency Market">
 *     <Valute ID="R01235"><CharCode>USD</CharCode><Nominal>1</Nominal>
 *       <Value>90,1234</Value><VunitRate>90,1234</VunitRate></Valute>
 *     ...
 *   </ValCurs>
 * Value — за Nominal единиц (юань исторически шёл по 10), поэтому курс за
 * единицу = Value / Nominal (или готовый VunitRate, если есть).
 */
class CbrFxRateProvider
{
    /** Валюты, которые нас интересуют (ISO CharCode). */
    public const CURRENCIES = ['USD', 'EUR', 'CNY'];

    /**
     * Забрать курсы за единицу валюты в рублях.
     *
     * @return array{rates: array<string,float>, date: ?string}
     *   rates — CharCode → курс за 1 единицу (только успешно распарсенные);
     *   date  — дата курса по данным ЦБ (d.m.Y) или null.
     */
    public function fetch(): array
    {
        $url = (string) config('services.iqot.fx_source_url', 'https://www.cbr.ru/scripts/XML_daily.asp');

        try {
            $res = Http::timeout(20)
                ->retry(2, 1500, throw: false)
                ->get($url);

            if (! $res->successful()) {
                Log::warning('CbrFxRateProvider: bad HTTP status', ['status' => $res->status(), 'url' => $url]);

                return ['rates' => [], 'date' => null];
            }

            return $this->parse($res->body());
        } catch (\Throwable $e) {
            Log::warning('CbrFxRateProvider: fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return ['rates' => [], 'date' => null];
        }
    }

    /**
     * Распарсить XML ЦБ. Числа/коды — ASCII, поэтому конвертация кодировки не
     * критична; на всякий случай приводим declared encoding к UTF-8.
     *
     * @return array{rates: array<string,float>, date: ?string}
     */
    public function parse(string $body): array
    {
        $body = preg_replace('/encoding=["\']windows-1251["\']/i', 'encoding="UTF-8"', $body, 1) ?? $body;
        $body = @mb_convert_encoding($body, 'UTF-8', 'Windows-1251') ?: $body;

        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            Log::warning('CbrFxRateProvider: XML parse failed');

            return ['rates' => [], 'date' => null];
        }

        $wanted = array_flip(self::CURRENCIES);
        $rates = [];
        foreach ($xml->Valute as $valute) {
            $code = strtoupper(trim((string) $valute->CharCode));
            if (! isset($wanted[$code])) {
                continue;
            }
            $rate = $this->extractRate($valute);
            if ($rate !== null && $rate > 0) {
                $rates[$code] = $rate;
            }
        }

        $date = (string) ($xml['Date'] ?? '');

        return ['rates' => $rates, 'date' => $date !== '' ? $date : null];
    }

    /** Курс за 1 единицу: VunitRate, иначе Value / Nominal. Запятая → точка. */
    private function extractRate(\SimpleXMLElement $valute): ?float
    {
        $num = static fn (string $s): ?float => is_numeric($n = str_replace([' ', ','], ['', '.'], trim($s)))
            ? (float) $n
            : null;

        $vunit = $num((string) $valute->VunitRate);
        if ($vunit !== null && $vunit > 0) {
            return $vunit;
        }

        $value = $num((string) $valute->Value);
        $nominal = $num((string) $valute->Nominal) ?: 1.0;
        if ($value === null || $nominal <= 0) {
            return null;
        }

        return $value / $nominal;
    }
}
