<?php

namespace App\Services\Quotations;

use App\Models\Quotation;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;

/**
 * Рендер КП в PDF через dompdf. Шаблон в `resources/views/quotations/pdf-template.blade.php`.
 *
 * Шрифт — PT Sans / PT Mono (ParaType, OFL), TTF лежат в `resources/fonts/` и
 * регистрируются в dompdf программно через FontMetrics::registerFont() (надёжнее
 * @font-face url(), не зависит от base-path и слэшей на Windows). PT Sans заметно
 * компактнее и аккуратнее дефолтного DejaVu Sans.
 *
 * Логотип ($company->short_name) — цветной SVG `public/assets/logos/myzip-corporate.svg`
 * (dompdf 3.x умеет рендерить path-only SVG через php-svg-lib). Fallback на PNG.
 * Если файла нет — шаблон скрывает logo-блок.
 *
 * Подпись и печать — PNG с прозрачным фоном из `public/assets/stamp/`
 * (signature.png, stamp.png). Печать накладывается на подпись (position:absolute).
 *
 * Сумма прописью — RuMoneySpeller (без внешних deps).
 *
 * Используется QuotationPdfController (preview / download routes) и
 * Phase 4 ComposeForm-интеграцией (attach binary к outgoing email).
 */
class QuotationPdfService
{
    public function __construct(private readonly RuMoneySpeller $speller)
    {
    }

    /**
     * Сгенерировать PDF и вернуть бинарный контент.
     *
     * @param  bool  $isolated  true → используем snapshot_company из quotation
     *                          (для sent-версий), false → актуальный config('services.company')
     */
    public function render(Quotation $quotation, bool $isolated = true): string
    {
        $quotation->loadMissing(['items', 'request.items', 'responsibleUser']);

        $company = $isolated && is_array($quotation->snapshot_company)
            ? $quotation->snapshot_company
            : (array) config('services.company');

        $validUntil = $quotation->valid_until ?? Carbon::now();
        $issueAt = $quotation->sent_at ?? $quotation->created_at ?? Carbon::now();

        $payload = [
            'q' => $quotation,
            'company' => $company,
            'logoPath' => $this->resolveLogoPath(),
            'signaturePath' => $this->resolveStampAsset('signature.png'),
            'stampPath' => $this->resolveStampAsset('stamp.png'),
            'issueDateRu' => $this->formatRuDate($issueAt) . ' г.',
            'validUntilShort' => $validUntil->format('d.m.y'),
            'stockStamp' => $issueAt->setTimezone('Europe/Moscow')->format('d.m.Y \в H:i'),
            'totalInWords' => $this->speller->spell((float) $quotation->total),
            // Для шапки «Содержание запроса» — все active items заявки
            // (включая несматченные, как просил заказчик).
            'requestItemsForSubj' => $quotation->request
                ? $quotation->request->items->where('is_active', true)->sortBy('position')->values()
                : collect(),
        ];

        $html = View::make('quotations.pdf-template', $payload)->render();

        $options = new Options();
        $options->set('defaultFont', 'PT Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        // dpi 72 — 1pt становится равен 1 device-px, шрифты-в-pt не
        // масштабируются 1.33× как при default 96. Сравнимо с поведением
        // Browsershot (px-units) в LazyLift, но для нашего pt-шаблона.
        $options->set('dpi', 72);
        $options->set('chroot', [resource_path(), public_path()]);

        // Writable font cache в storage — иначе dompdf пытается писать в
        // vendor/dompdf/dompdf/lib/fonts/ (часто read-only, без cache
        // кириллица рендерится как ????).
        $fontDir = storage_path('app/dompdf/fonts');
        if (! is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);

        $dompdf = new Dompdf($options);
        $this->registerFonts($dompdf);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Регистрируем PT Sans / PT Mono из resources/fonts/ в dompdf.
     * Программная регистрация надёжнее @font-face: не зависит от base-path
     * документа и не ломается на Windows-слэшах в url(). Файлов нет — тихо
     * пропускаем (dompdf откатится на defaultFont).
     */
    private function registerFonts(Dompdf $dompdf): void
    {
        $dir = resource_path('fonts');
        $fonts = [
            ['PT Sans', 'normal', 'normal', 'PTSans-Regular.ttf'],
            ['PT Sans', 'bold', 'normal', 'PTSans-Bold.ttf'],
            ['PT Sans', 'normal', 'italic', 'PTSans-Italic.ttf'],
            ['PT Sans', 'bold', 'italic', 'PTSans-BoldItalic.ttf'],
            ['PT Mono', 'normal', 'normal', 'PTMono-Regular.ttf'],
        ];

        $metrics = $dompdf->getFontMetrics();
        foreach ($fonts as [$family, $weight, $style, $file]) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                $metrics->registerFont(
                    ['family' => $family, 'weight' => $weight, 'style' => $style],
                    $path
                );
            }
        }
    }

    /**
     * Имя файла для Content-Disposition (download/attach).
     * Формат: `КП-2026-NNNN-v1.pdf` (asciifold для совместимости).
     */
    public function filename(Quotation $quotation): string
    {
        $code = preg_replace('/[^A-Za-zА-Яа-я0-9\-]+/u', '-', $quotation->internal_code);

        return "{$code}-v{$quotation->version}.pdf";
    }

    /**
     * Логотип для dompdf. Предпочитаем цветной SVG (php-svg-lib рендерит
     * path-only SVG), fallback на PNG. Если файла нет — null, template скроет блок.
     */
    private function resolveLogoPath(): ?string
    {
        foreach (['myzip-corporate.svg', 'myzip-corporate.png', 'myzip-corporate.jpg'] as $name) {
            $path = public_path("assets/logos/{$name}");
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Подпись/печать из public/assets/stamp/. null → template скроет блок.
     */
    private function resolveStampAsset(string $file): ?string
    {
        $path = public_path("assets/stamp/{$file}");

        return is_file($path) ? $path : null;
    }

    /**
     * Русская дата формата «19 мая 2026».
     */
    private function formatRuDate(Carbon $date): string
    {
        $months = [
            1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
            5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
            9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
        ];

        return $date->day . ' ' . $months[$date->month] . ' ' . $date->year;
    }
}
