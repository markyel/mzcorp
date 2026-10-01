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
 * Сборка ежемесячного отчёта по форме Приложения № 2 к договору из журнала
 * работ и плана:
 *   1. Регулярные услуги — все семь направлений Приложения № 1, у каждого
 *      статус «Выполнялось / не требовалось» и комментарий (п. 4.2–4.3:
 *      отсутствие изменений — не отсутствие услуги, поэтому строка есть всегда);
 *   2. Дополнительные (проектные) задачи — записи журнала с отметкой is_project;
 *   3. Основные выводы и рекомендации — заметки раздела «Аналитика»;
 *   4. Задачи, переходящие на следующий период — план следующего месяца.
 * Конкретных дат в тексте отчёта нет: отчётный период — месяц.
 */
class MarketingReportService
{
    public const STATUS_DONE = 'done';

    public const STATUS_NOT_NEEDED = 'not_needed';

    public const STATUS_LABELS = [
        self::STATUS_DONE => 'Выполнялось',
        self::STATUS_NOT_NEEDED => 'Не требовалось',
    ];

    /** Формулировка п. 4.3 для направления, по которому в месяце ничего не менялось. */
    public const QUIET_COMMENT = 'Регулярный мониторинг осуществлялся; существенных отклонений и необходимости корректирующих действий не выявлено.';

    /** Ключи реквизитов договора в app_settings. */
    public const SETTING_CONTRACT_NUMBER = 'marketing.contract_number';

    public const SETTING_CONTRACT_DATE = 'marketing.contract_date';

    public const SETTING_CONTRACTOR = 'marketing.contractor';

    public const SETTING_CUSTOMER = 'marketing.customer';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Черновик формы за месяц, собранный из журнала.
     *
     * @return array{period: string, requisites: array<string, string>,
     *     regular: array<string, array{status: string, comment: string}>,
     *     projects: list<array{task: string, stage: string, result: string}>,
     *     conclusions: string, next_tasks: list<string>}
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

        $works = $entries->filter(fn (MarketingEntry $e) => $e->kind !== MarketingEntry::KIND_NOTE);
        $done = $works->filter(fn (MarketingEntry $e) => $e->countsAsDone() && ! $e->is_project);

        // 1. Регулярные услуги: строка есть у каждого направления. Источник —
        // записи о работе; выполненный пункт плана («Создать аккаунт…») идёт,
        // только если записей о работе по направлению нет: иначе он
        // повторяет их же повелительным наклонением.
        $regular = [];
        foreach (MarketingSection::ordered() as $section) {
            $rows = $done->where('section', $section->value);
            $workRows = $rows->where('kind', MarketingEntry::KIND_WORK);
            if ($workRows->isNotEmpty()) {
                $rows = $workRows;
            }
            $comment = $this->joinEntries($rows);
            if ($section === MarketingSection::Ads) {
                $metrics = $this->metricsLine($this->sumAdMetrics($done->where('section', $section->value)));
                $comment = trim($comment.($metrics !== '' ? "\n".$metrics : ''));
            }
            $regular[$section->value] = [
                'status' => $rows->isNotEmpty() ? self::STATUS_DONE : self::STATUS_NOT_NEEDED,
                'comment' => $comment,
            ];
        }

        // 2. Проектные задачи: сделанные и начатые в месяце.
        $projects = $works
            ->filter(fn (MarketingEntry $e) => $e->is_project && $e->status !== MarketingEntry::STATUS_DROPPED
                && in_array($e->status, [MarketingEntry::STATUS_DONE, MarketingEntry::STATUS_IN_PROGRESS], true))
            ->map(fn (MarketingEntry $e) => [
                'task' => trim((string) $e->title),
                'stage' => $e->status === MarketingEntry::STATUS_DONE ? 'Выполнено' : 'В работе',
                'result' => trim((string) $e->body),
            ])
            ->values()
            ->all();

        // 3. Выводы и рекомендации — заметки раздела «Аналитика».
        $conclusions = $entries
            ->filter(fn (MarketingEntry $e) => $e->kind === MarketingEntry::KIND_NOTE && $e->section === MarketingSection::Analytics->value)
            ->map(fn (MarketingEntry $e) => trim((string) $e->title).(trim((string) $e->body) !== '' ? ': '.trim((string) $e->body) : ''))
            ->implode("\n");

        return [
            'period' => $period->toDateString(),
            'requisites' => $this->requisites(),
            'regular' => $regular,
            'projects' => $projects,
            'conclusions' => $conclusions,
            'next_tasks' => $this->nextPlan($period),
        ];
    }

    /**
     * Черновик, дополненный уже сохранённым отчётом: ручные правки админа
     * всегда важнее пересборки из журнала. Отчёты старой формы (Приложение
     * № 1: main_tasks, sections) новых ключей не имеют — для них берётся черновик.
     *
     * @return array<string, mixed>
     */
    public function mergeWithSaved(MarketingReport $report): array
    {
        $merged = $this->buildDraft($report->period);
        $saved = $report->payload ?? [];

        if (! empty($saved['requisites'])) {
            $merged['requisites'] = $saved['requisites'];
        }
        foreach ((array) ($saved['regular'] ?? []) as $key => $row) {
            if (! isset($merged['regular'][$key])) {
                continue;
            }
            if (isset(self::STATUS_LABELS[$row['status'] ?? ''])) {
                $merged['regular'][$key]['status'] = $row['status'];
            }
            if (trim((string) ($row['comment'] ?? '')) !== '') {
                $merged['regular'][$key]['comment'] = (string) $row['comment'];
            }
        }
        foreach (['projects', 'next_tasks'] as $key) {
            if (! empty($saved[$key])) {
                $merged[$key] = $saved[$key];
            }
        }
        if (trim((string) ($saved['conclusions'] ?? '')) !== '') {
            $merged['conclusions'] = (string) $saved['conclusions'];
        }

        return $merged;
    }

    /** Показатели рекламы одной строкой для комментария направления. */
    private function metricsLine(array $metrics): string
    {
        $parts = [];
        foreach (MarketingSection::AD_METRICS as $key => $label) {
            if (($metrics[$key] ?? '') !== '') {
                $parts[] = $label.': '.$metrics[$key];
            }
        }

        return $parts === [] ? '' : 'Показатели: '.implode('; ', $parts).'.';
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
     * Раздел 4: задачи, переходящие на следующий период — записи kind=plan
     * следующего месяца, кроме снятых.
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
            ->limit(10)
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
     * Журнал направления в текст отчёта: одна работа — один абзац. Дат нет:
     * отчётный период — месяц, а дата отдельной работы в акте не нужна.
     *
     * @param  Collection<int, MarketingEntry>  $rows
     */
    private function joinEntries(Collection $rows): string
    {
        return $rows->map(function (MarketingEntry $e) {
            $line = trim((string) $e->title);
            $body = trim((string) $e->body);

            return $body !== '' ? $line.': '.$body : $line;
        })->implode("\n");
    }
}
