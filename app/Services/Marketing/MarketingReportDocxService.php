<?php

namespace App\Services\Marketing;

use App\Enums\MarketingSection;
use App\Models\MarketingReport;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * Выгрузка ежемесячного отчёта в .docx по форме Приложения № 2 к договору:
 * таблица регулярных услуг (все семь направлений со статусом), таблица
 * дополнительных задач (если были), выводы, задачи на следующий период,
 * подпись. Конкретных дат в тексте нет — только отчётный месяц.
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
        $contract = '№ '.($req['contract_number'] ?: '___').' от '.($req['contract_date'] ?: '«___» __________ 2026 г.');

        $word = new PhpWord;
        $word->getSettings()->setThemeFontLang(new Language(Language::RU_RU));
        $word->setDefaultFontName('Times New Roman');
        $word->setDefaultFontSize(11);

        $section = $word->addSection([
            'marginLeft' => 1134, 'marginRight' => 850, 'marginTop' => 850, 'marginBottom' => 850,
        ]);

        $head = ['spaceAfter' => 0, 'alignment' => Jc::END];
        $section->addText('Приложение № 2', ['italic' => true, 'size' => 10], $head);
        $section->addText('к Договору '.$contract, ['italic' => true, 'size' => 10], $head);

        $section->addTextBreak(1);
        $section->addText('ЕЖЕМЕСЯЧНЫЙ ОТЧЁТ ОБ ОКАЗАННЫХ УСЛУГАХ', ['bold' => true, 'size' => 13],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 200]);

        $section->addText('Исполнитель: '.($req['contractor'] ?: 'ИП Маркелов'), [], ['spaceAfter' => 0]);
        $section->addText('Заказчик: '.($req['customer'] ?: 'ООО «Мой Лифт»'), [], ['spaceAfter' => 0]);
        $section->addText('Отчётный период: '.$periodLabel, [], ['spaceAfter' => 200]);

        $table = ['borderSize' => 6, 'borderColor' => '808080', 'cellMargin' => 70];
        $th = ['bold' => true, 'size' => 10];
        $td = ['size' => 10];
        $cellP = ['spaceAfter' => 0];

        // 1. Регулярные услуги — все семь направлений, у каждого статус.
        $section->addText('1. Регулярные услуги', ['bold' => true], ['spaceBefore' => 160, 'spaceAfter' => 80]);
        $t = $section->addTable($table);
        $t->addRow();
        foreach ([[500, '№'], [2900, 'Направление'], [1500, 'Статус'], [5000, 'Основные действия / показатели / комментарий']] as [$w, $label]) {
            $t->addCell($w)->addText($label, $th, $cellP);
        }
        foreach (MarketingSection::ordered() as $sec) {
            $row = (array) ($data['regular'][$sec->value] ?? []);
            $status = MarketingReportService::STATUS_LABELS[$row['status'] ?? ''] ?? MarketingReportService::STATUS_LABELS[MarketingReportService::STATUS_NOT_NEEDED];
            $t->addRow();
            $t->addCell(500)->addText((string) $sec->formNumber(), $td, $cellP);
            $t->addCell(2900)->addText($sec->label(), $td, $cellP);
            $t->addCell(1500)->addText($status, $td, $cellP);
            $this->multiline($t->addCell(5000), (string) ($row['comment'] ?? ''), $td, $cellP);
        }

        // 2. Дополнительные (проектные) задачи — раздел не заполняется, если их не было.
        $projects = array_values(array_filter((array) ($data['projects'] ?? []),
            fn ($p) => trim((string) ($p['task'] ?? '')) !== ''));
        if ($projects !== []) {
            $section->addText('2. Дополнительные (проектные) задачи', ['bold' => true], ['spaceBefore' => 240, 'spaceAfter' => 80]);
            $t = $section->addTable($table);
            $t->addRow();
            foreach ([[500, '№'], [3600, 'Задача'], [1500, 'Выполнено / стадия'], [4300, 'Результат / комментарий']] as [$w, $label]) {
                $t->addCell($w)->addText($label, $th, $cellP);
            }
            foreach ($projects as $i => $p) {
                $t->addRow();
                $t->addCell(500)->addText((string) ($i + 1), $td, $cellP);
                $t->addCell(3600)->addText(trim((string) $p['task']), $td, $cellP);
                $t->addCell(1500)->addText(trim((string) ($p['stage'] ?? '')), $td, $cellP);
                $this->multiline($t->addCell(4300), (string) ($p['result'] ?? ''), $td, $cellP);
            }
        }

        // 3. Выводы и рекомендации
        $section->addText('3. Основные выводы и рекомендации', ['bold' => true], ['spaceBefore' => 240, 'spaceAfter' => 80]);
        $conclusions = trim((string) ($data['conclusions'] ?? ''));
        $this->multiline($section, $conclusions !== '' ? $conclusions : '—', [], $cellP);

        // 4. Задачи на следующий период
        $section->addText('4. Задачи, переходящие на следующий период', ['bold' => true], ['spaceBefore' => 240, 'spaceAfter' => 80]);
        $next = array_values(array_filter((array) ($data['next_tasks'] ?? []), fn ($v) => trim((string) $v) !== ''));
        if ($next === []) {
            $section->addText('—');
        }
        foreach ($next as $i => $task) {
            $section->addText(($i + 1).'. '.trim((string) $task), [], $cellP);
        }

        $section->addTextBreak(1);
        $section->addText('Исполнитель: '.($req['contractor'] ?: 'ИП Маркелов'), [], ['spaceAfter' => 160]);
        $section->addText('________________ /________________/       «___» __________ 20__ г.');

        $path = tempnam(sys_get_temp_dir(), 'mrep').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /** Имя файла для скачивания. */
    public function filename(MarketingReport $report): string
    {
        return 'Отчёт_маркетинг_'.$report->period->format('Y_m').'.docx';
    }

    /** Текст с переносами строк — абзацами в ячейку или раздел. */
    private function multiline($container, string $text, array $font, array $para): void
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn ($l) => $l !== ''));
        if ($lines === []) {
            $container->addText('', $font, $para);
        }
        foreach ($lines as $line) {
            $container->addText($line, $font, $para);
        }
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
