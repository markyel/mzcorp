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
 * Логотип ($company->short_name) подтягивается из `public/assets/logos/myzip-corporate.png`
 * (dompdf плохо рендерит inline SVG, поэтому используем PNG). Если файла нет —
 * шаблон скрывает logo-блок.
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
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('chroot', [resource_path(), public_path()]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
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
     * PNG-логотип для dompdf. Если файла нет — null, template скроет блок.
     */
    private function resolveLogoPath(): ?string
    {
        foreach (['myzip-corporate.png', 'myzip-corporate.jpg'] as $name) {
            $path = public_path("assets/logos/{$name}");
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
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
