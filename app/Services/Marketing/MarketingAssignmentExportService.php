<?php

namespace App\Services\Marketing;

use App\Models\MarketingAssignment;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\View;

/**
 * Выгрузка поручения для исполнителя без доступа в MyLift: инструкция в PDF
 * (читать и печатать) и HTML (копировать текст), материалы — в архиве рядом.
 *
 * Рендер PDF повторяет CorrespondenceExportService: PT Sans из
 * resources/fonts (встроенные шрифты dompdf без кириллицы), удалённые
 * картинки выключены.
 */
class MarketingAssignmentExportService
{
    /** Инструкция в HTML: markdown без сырого HTML пользователя. */
    public function instructionsHtml(MarketingAssignment $assignment): string
    {
        $text = trim((string) $assignment->instructions);

        return $text !== ''
            ? Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false])
            : '';
    }

    public function html(MarketingAssignment $assignment, bool $forPdf = false): string
    {
        $assignment->loadMissing(['files', 'author']);

        return View::make('marketing.assignment-export', [
            'a' => $assignment,
            'body' => $this->instructionsHtml($assignment),
            'forPdf' => $forPdf,
            'font' => $forPdf ? "'PT Sans'" : "'PT Sans', 'Segoe UI', Arial, sans-serif",
        ])->render();
    }

    public function pdf(MarketingAssignment $assignment): string
    {
        $options = new Options();
        $options->set('defaultFont', 'PT Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('dpi', 72);
        $options->set('chroot', [resource_path(), public_path()]);

        $fontDir = storage_path('app/dompdf/fonts');
        if (! is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);

        $dompdf = new Dompdf($options);
        $this->registerFonts($dompdf);
        $dompdf->loadHtml($this->html($assignment, true), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** Архив: инструкция (PDF + HTML) и папка «Материалы» с файлами поручения. */
    public function zip(MarketingAssignment $assignment): string
    {
        $assignment->loadMissing('files');

        $tmp = tempnam(sys_get_temp_dir(), 'assign_');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось создать архив.');
        }

        $zip->addFromString('Инструкция.pdf', $this->pdf($assignment));
        $zip->addFromString('Инструкция.html', $this->html($assignment));

        $i = 0;
        foreach ($assignment->files as $file) {
            $contents = $file->contents();
            if ($contents === null) {
                continue;
            }
            $i++;
            $zip->addFromString(sprintf('Материалы/%02d_%s', $i, $this->entryName($file->filename)), $contents);
        }

        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** Имя файла выгрузки: «Поручение 12 — Страница 404.zip». */
    public function filename(MarketingAssignment $assignment, string $ext): string
    {
        $title = $this->entryName(Str::limit((string) $assignment->title, 60, ''));

        return 'Поручение '.$assignment->id.' — '.$title.'.'.$ext;
    }

    private function entryName(string $name): string
    {
        $clean = preg_replace('#[\x00-\x1F\x7F/\\\\:*?"<>|]#u', '_', $name) ?? '';
        $clean = trim(preg_replace('/_+/', '_', $clean) ?? '', '_ .');

        return $clean !== '' ? $clean : 'file';
    }

    private function registerFonts(Dompdf $dompdf): void
    {
        $dir = resource_path('fonts');
        $fonts = [
            ['PT Sans', 'normal', 'normal', 'PTSans-Regular.ttf'],
            ['PT Sans', 'bold', 'normal', 'PTSans-Bold.ttf'],
            ['PT Sans', 'normal', 'italic', 'PTSans-Italic.ttf'],
            ['PT Sans', 'bold', 'italic', 'PTSans-BoldItalic.ttf'],
            ['PT Mono', 'normal', 'normal', 'PTMono-Regular.ttf'],
            ['PT Mono', 'bold', 'normal', 'PTMono-Regular.ttf'],
        ];

        $metrics = $dompdf->getFontMetrics();
        foreach ($fonts as [$family, $weight, $style, $file]) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_file($path)) {
                $metrics->registerFont(['family' => $family, 'weight' => $weight, 'style' => $style], $path);
            }
        }
    }
}
