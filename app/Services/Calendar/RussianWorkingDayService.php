<?php

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Российский производственный календарь.
 *
 * Расчёт «рабочего дня» с учётом:
 *   - выходных Пн-Пт vs Сб-Вс,
 *   - официальных праздников РФ,
 *   - перенесённых рабочих суббот (если будут опубликованы Минтрудом).
 *
 * Данные — в `config/russian_calendar.php`. Обновляются вручную раз в год.
 *
 * Используется для расчёта срока действия счёта (default 5 рабочих дней),
 * а также для будущих SLA-таймеров.
 */
class RussianWorkingDayService
{
    /**
     * Прибавить к дате N рабочих дней.
     *
     * Если $from = пятница и $days = 1 — вернёт понедельник (или вторник
     * если понедельник праздник). Корректно учитывает Сб-Вс и праздники.
     *
     * $days может быть 0 (вернёт $from если он рабочий, иначе ближайший
     * следующий рабочий день).
     */
    public function addBusinessDays(CarbonInterface $from, int $days): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($from)->startOfDay();

        // days=0 — выровнять на ближайший рабочий день (вкл. сегодня).
        if ($days <= 0) {
            while (! $this->isBusinessDay($cursor)) {
                $cursor = $cursor->addDay();
            }
            return $cursor;
        }

        // days>0 — итерируем посуточно, считаем только рабочие.
        $added = 0;
        $safetyLimit = $days * 3 + 30; // защита от бесконечного цикла на корявом конфиге
        while ($added < $days) {
            $cursor = $cursor->addDay();
            if ($this->isBusinessDay($cursor)) {
                $added++;
            }
            if (--$safetyLimit < 0) {
                Log::warning('RussianWorkingDayService: safety limit reached', [
                    'from' => $from->toDateString(),
                    'days' => $days,
                ]);
                break;
            }
        }

        return $cursor;
    }

    /**
     * Считается ли день рабочим:
     *  - Пн-Пт И не в списке holidays
     *  - ИЛИ суббота в списке working_saturdays
     */
    public function isBusinessDay(CarbonInterface $date): bool
    {
        $dateStr = $date->toDateString();

        // Working Saturday override — приоритетнее обычной логики.
        $workingSaturdays = $this->workingSaturdays();
        if (isset($workingSaturdays[$dateStr])) {
            return true;
        }

        // Sat-Sun по умолчанию выходные.
        if ($date->isSaturday() || $date->isSunday()) {
            return false;
        }

        // Праздник, попавший на Пн-Пт.
        $holidays = $this->holidays();
        if (isset($holidays[$dateStr])) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, bool>   Set дат-строк праздников.
     */
    private function holidays(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $list = (array) config('russian_calendar.holidays', []);
        $cache = array_fill_keys(array_map('strval', $list), true);
        return $cache;
    }

    /**
     * @return array<string, bool>   Set дат-строк рабочих суббот.
     */
    private function workingSaturdays(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $list = (array) config('russian_calendar.working_saturdays', []);
        $cache = array_fill_keys(array_map('strval', $list), true);
        return $cache;
    }
}
