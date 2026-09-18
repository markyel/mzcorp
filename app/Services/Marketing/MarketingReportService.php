<?php

namespace App\Services\Marketing;

use App\Enums\MarketingSection;
use App\Models\AppSetting;
use App\Models\MarketingEntry;
use App\Models\MarketingReport;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Сборка ежемесячного отчёта по форме Приложения № 1 из журнала работ и плана.
 *
 * Правило договора (п. 4.2): «Разделы отчёта, не относящиеся к фактически
 * выполнявшимся в соответствующем месяце работам, могут не заполняться» —
 * поэтому пустые разделы остаются пустыми, а не заполняются прочерками.
 */
class MarketingReportService
{
    /** Ключи реквизитов договора в app_settings. */
    public const SETTING_CONTRACT_NUMBER = 'marketing.contract_number';

    public const SETTING_CONTRACT_DATE = 'marketing.contract_date';

    public const SETTING_CONTRACTOR = 'marketing.contractor';

    public const SETTING_CUSTOMER = 'marketing.customer';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Черновик формы за месяц: текст разделов собран из журнала, показатели
     * раздела «Реклама» просуммированы, пункт 9 — из плана следующего месяца.
     *
     * @return array<string, mixed>
     */
    public function buildDraft(Carbon|string $period): array
    {
        $period = MarketingEntry::normalizePeriod($period);
        $entries = MarketingEntry::query()
            ->forPeriod($period)
            ->orderBy('priority')
            ->orderBy('happened_on')
            ->orderBy('id')
            ->get();

        $done = $entries->filter(fn (MarketingEntry $e) => $e->countsAsDone());

        $sections = [];
        foreach (MarketingSection::ordered() as $section) {
            $rows = $done->where('section', $section->value);
            if ($rows->isEmpty()) {
                continue;
            }
            $fields = [];
            $keys = array_keys($section->fields());
            // Первое поле раздела — «выполненные работы»: туда идёт журнал.
            $fields[$keys[0]] = $this->joinEntries($rows);
            $sections[$section->value] = $fields;
        }

        return [
            'period' => $period->toDateString(),
            'requisites' => $this->requisites(),
            // Пункт 1 формы — до пяти основных задач месяца (приоритет 1, затем остальные).
            'main_tasks' => $this->mainTasks($done),
            'sections' => $sections,
            'ad_metrics' => $this->sumAdMetrics($done),
            'next_plan' => $this->nextPlan($period),
        ];
    }

    /**
     * Черновик, дополненный уже сохранённым отчётом: ручные правки админа
     * всегда важнее пересборки из журнала.
     *
     * @return array<string, mixed>
     */
    public function mergeWithSaved(MarketingReport $report): array
    {
        $draft = $this->buildDraft($report->period);
        $saved = $report->payload ?? [];

        $merged = $draft;
        foreach (['main_tasks', 'next_plan', 'ad_metrics', 'requisites'] as $key) {
            if (! empty($saved[$key])) {
                $merged[$key] = $saved[$key];
            }
        }
        foreach ((array) ($saved['sections'] ?? []) as $sectionKey => $fields) {
            foreach ((array) $fields as $field => $value) {
                if (is_string($value) && trim($value) !== '') {
                    $merged['sections'][$sectionKey][$field] = $value;
                }
            }
        }

        return $merged;
    }

    /** Найти или создать черновик отчёта за месяц. */
    public function draftFor(Carbon|string $period, ?User $by = null): MarketingReport
    {
        $period = MarketingEntry::normalizePeriod($period);

        $report = MarketingReport::query()->whereDate('period', $period->toDateString())->first();
        if ($report !== null) {
            return $report;
        }

        return MarketingReport::create([
            'period' => $period,
            'status' => MarketingReport::STATUS_DRAFT,
            'payload' => $this->buildDraft($period),
            'created_by_user_id' => $by?->id,
        ]);
    }

    /** Реквизиты договора из настроек (заполняются один раз в разделе). */
    public function requisites(): array
    {
        return [
            'contract_number' => (string) $this->settings->get(self::SETTING_CONTRACT_NUMBER, ''),
            'contract_date' => (string) $this->settings->get(self::SETTING_CONTRACT_DATE, ''),
            'contractor' => (string) $this->settings->get(self::SETTING_CONTRACTOR, 'ИП Маркелов'),
            'customer' => (string) $this->settings->get(self::SETTING_CUSTOMER, 'ООО «Мой Лифт»'),
        ];
    }

    public function saveRequisites(array $values, ?User $by = null): void
    {
        $map = [
            'contract_number' => self::SETTING_CONTRACT_NUMBER,
            'contract_date' => self::SETTING_CONTRACT_DATE,
            'contractor' => self::SETTING_CONTRACTOR,
            'customer' => self::SETTING_CUSTOMER,
        ];
        foreach ($map as $field => $key) {
            $this->settings->set($key, (string) ($values[$field] ?? ''), AppSetting::TYPE_STRING, $by?->id);
        }
    }

    /**
     * Пункт 1 формы: до пяти основных задач месяца.
     *
     * @param  Collection<int, MarketingEntry>  $done
     * @return array<int, string>
     */
    private function mainTasks(Collection $done): array
    {
        return $done->sortBy([['priority', 'asc'], ['id', 'asc']])
            ->take(5)
            ->map(fn (MarketingEntry $e) => (string) $e->title)
            ->values()
            ->all();
    }

    /**
     * Пункт 9: план и приоритеты на следующий месяц — записи kind=plan
     * следующего периода, кроме снятых.
     *
     * @return array<int, string>
     */
    private function nextPlan(Carbon $period): array
    {
        return MarketingEntry::query()
            ->forPeriod($period->copy()->addMonth())
            ->ofKind(MarketingEntry::KIND_PLAN)
            ->where('status', '!=', MarketingEntry::STATUS_DROPPED)
            ->orderBy('priority')
            ->orderBy('id')
            ->limit(5)
            ->pluck('title')
            ->map(fn ($t) => (string) $t)
            ->all();
    }

    /**
     * Показатели пункта 2: суммируем числовые метрики журнала; стоимость
     * обращения считаем из суммы, а не усредняем средние.
     *
     * @param  Collection<int, MarketingEntry>  $done
     * @return array<string, string>
     */
    private function sumAdMetrics(Collection $done): array
    {
        $sum = ['spend' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0, 'leads' => 0.0];
        $other = [];
        $seen = false;

        foreach ($done as $entry) {
            foreach ((array) $entry->metrics as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                if (array_key_exists($key, $sum)) {
                    $sum[$key] += (float) str_replace([' ', ','], ['', '.'], (string) $value);
                    $seen = true;
                } elseif ($key === 'other') {
                    $other[] = (string) $value;
                }
            }
        }
        if (! $seen && $other === []) {
            return [];
        }

        $out = [];
        foreach ($sum as $key => $value) {
            if ($value > 0) {
                $out[$key] = $this->num($value);
            }
        }
        if (($sum['leads'] ?? 0) > 0 && ($sum['spend'] ?? 0) > 0) {
            $out['cpl'] = $this->num($sum['spend'] / $sum['leads']);
        }
        if ($other !== []) {
            $out['other'] = implode('; ', $other);
        }

        return $out;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
    }

    /**
     * Журнал раздела в текст отчёта: одна работа — один абзац, с датой факта.
     *
     * @param  Collection<int, MarketingEntry>  $rows
     */
    private function joinEntries(Collection $rows): string
    {
        return $rows->map(function (MarketingEntry $e) {
            $date = $e->happened_on?->format('d.m');
            $line = ($date !== null ? $date.' — ' : '').trim((string) $e->title);
            $body = trim((string) $e->body);

            return $body !== '' ? $line.': '.$body : $line;
        })->implode("\n");
    }
}
