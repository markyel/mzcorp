<?php

namespace App\Services\Marketing;

use App\Enums\MarketingSection;
use App\Models\MarketingReport;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * Выгрузка ежемесячного отчёта в .docx по форме Приложения № 1 к договору:
 * шапка, 10 пунктов формы, подпись. Пустые разделы пропускаем — п. 4.2
 * договора это прямо разрешает.
 */
class MarketingReportDocxService
{
    public function __construct(private readonly MarketingReportService $reports) {}

    /** Сохранить .docx во временный файл и вернуть путь. */
    public function render(MarketingReport $report): string
    {
        return $this->renderData($this->reports->mergeWithSaved($report), $report->periodLabel());
    }

    /**
     * Отрисовка по уже собранной форме — отдельно от модели, чтобы вёрстку
     * документа можно было проверять без БД.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderData(array $data, string $periodLabel): string
    {
        // Реквизиты могут быть не заполнены (первый отчёт до настройки) —
        // подставляем прочерки формы, а не падаем на отсутствующем ключе.
        $req = array_merge([
            'contract_number' => '',
            'contract_date' => '',
            'contractor' => '',
            'customer' => '',
        ], (array) ($data['requisites'] ?? []));

        $word = new PhpWord;
        $word->getSettings()->setThemeFontLang(new Language(Language::RU_RU));
        $word->setDefaultFontName('Times New Roman');
        $word->setDefaultFontSize(11);

        $word->addTitleStyle(1, ['bold' => true, 'size' => 13], ['spaceAfter' => 160]);
        $word->addTitleStyle(2, ['bold' => true, 'size' => 11.5], ['spaceBefore' => 200, 'spaceAfter' => 80]);

        $section = $word->addSection([
            'marginLeft' => 1134, 'marginRight' => 850, 'marginTop' => 850, 'marginBottom' => 850,
        ]);

        $head = ['spaceAfter' => 0, 'alignment' => Jc::END];
        $section->addText('Приложение № 1', ['italic' => true, 'size' => 10], $head);
        $section->addText('к Договору оказания маркетинговых и консультационных услуг',
            ['italic' => true, 'size' => 10], $head);
        $section->addText(
            '№ '.($req['contract_number'] ?: '___').' от '.($req['contract_date'] ?: '«___» __________ 2026 г.'),
            ['italic' => true, 'size' => 10], $head
        );

        $section->addTextBreak(1);
        $section->addText('ЕЖЕМЕСЯЧНЫЙ ОТЧЁТ', ['bold' => true, 'size' => 13], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $section->addText('об оказанных маркетинговых и консультационных услугах',
            ['size' => 11], ['alignment' => Jc::CENTER, 'spaceAfter' => 200]);

        $section->addText('Исполнитель: '.($req['contractor'] ?: 'ИП Маркелов'), [], ['spaceAfter' => 0]);
        $section->addText('Заказчик: '.($req['customer'] ?: 'ООО «Мой Лифт»'), [], ['spaceAfter' => 0]);
        $section->addText('Отчётный период: '.$periodLabel, [], ['spaceAfter' => 200]);

        // 1. Основные выполненные задачи
        $section->addText('1. Основные выполненные задачи', ['bold' => true], ['spaceBefore' => 160, 'spaceAfter' => 80]);
        $tasks = array_values(array_filter((array) ($data['main_tasks'] ?? []), fn ($t) => trim((string) $t) !== ''));
        if ($tasks === []) {
            $section->addText('—');
        }
        foreach ($tasks as $i => $task) {
            $section->addText(($i + 1).'. '.$task, [], ['spaceAfter' => 0]);
        }

        // 2–8. Разделы формы
        foreach (MarketingSection::ordered() as $sec) {
            $fields = (array) ($data['sections'][$sec->value] ?? []);
            $metrics = $sec === MarketingSection::Ads ? (array) ($data['ad_metrics'] ?? []) : [];
            if ($this->isBlank($fields) && $metrics === []) {
                continue;
            }
            $section->addText($sec->formNumber().'. '.$sec->label(), ['bold' => true],
                ['spaceBefore' => 200, 'spaceAfter' => 80]);

            foreach ($sec->fields() as $key => $label) {
                $value = trim((string) ($fields[$key] ?? ''));
                if ($value === '') {
                    continue;
                }
                $section->addText($label.':', ['italic' => true], ['spaceAfter' => 0]);
                foreach (preg_split('/\R/u', $value) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $section->addText(trim($line), [], ['spaceAfter' => 0]);
                    }
                }
            }

            if ($metrics !== []) {
                $section->addText('Основные показатели:', ['italic' => true], ['spaceBefore' => 80, 'spaceAfter' => 0]);
                foreach (MarketingSection::AD_METRICS as $key => $label) {
                    if (($metrics[$key] ?? '') !== '') {
                        $section->addText($label.': '.$metrics[$key], [], ['spaceAfter' => 0]);
                    }
                }
            }
        }

        // 9. План на следующий месяц
        $plan = array_values(array_filter((array) ($data['next_plan'] ?? []), fn ($t) => trim((string) $t) !== ''));
        if ($plan !== []) {
            $section->addText('9. План и приоритеты на следующий месяц', ['bold' => true],
                ['spaceBefore' => 200, 'spaceAfter' => 80]);
            foreach ($plan as $i => $task) {
                $section->addText(($i + 1).'. '.$task, [], ['spaceAfter' => 0]);
            }
        }

        // 10. Итог
        $section->addText('10. Итог', ['bold' => true], ['spaceBefore' => 200, 'spaceAfter' => 80]);
        $section->addText(
            'Услуги за указанный отчётный период оказаны в рамках Договора оказания маркетинговых '.
            'и консультационных услуг № '.($req['contract_number'] ?: '___').' от '.
            ($req['contract_date'] ?: '«___» __________ 2026 г.').'.',
            [], ['spaceAfter' => 200]
        );
        $section->addText('Исполнитель: '.($req['contractor'] ?: 'ИП Маркелов'), [], ['spaceAfter' => 160]);
        $section->addText('________________ /________________/', [], ['spaceAfter' => 0]);
        $section->addText('«___» __________ 20__ г.');

        $path = tempnam(sys_get_temp_dir(), 'mrep').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /** Имя файла для скачивания. */
    public function filename(MarketingReport $report): string
    {
        return 'Отчёт_маркетинг_'.$report->period->format('Y_m').'.docx';
    }

    private function isBlank(array $fields): bool
    {
        foreach ($fields as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }
}
