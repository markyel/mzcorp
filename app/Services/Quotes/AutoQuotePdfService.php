<?php

namespace App\Services\Quotes;

use App\Models\AutoQuoteSnapshot;
use App\Models\Organization;
use App\Models\Request;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Коммерческое предложение файлом.
 *
 * Клиент должен получить то же, что прислал бы менеджер руками: письмо с его
 * почты и подписью, а КП — вложенным PDF. Верстается из того же замороженного
 * снимка, что показан менеджеру на экране.
 *
 * Сборка dompdf повторяет экспорт переписки (CorrespondenceExportService):
 * шрифты PT Sans/PT Mono регистрируются вручную, иначе кириллица в PDF
 * превращается в квадраты.
 */
class AutoQuotePdfService
{
    /** Сколько дней держим цену — печатается в предложении. */
    public const VALID_DAYS = 14;

    public function filename(Request $request): string
    {
        $code = preg_replace('/[^A-Za-zА-Яа-я0-9\-]+/u', '-', (string) $request->internal_code) ?: 'request';

        return "Коммерческое предложение {$code}.pdf";
    }

    public function render(Request $request, AutoQuoteSnapshot $snapshot, ?User $manager = null): string
    {
        $html = view('quotes.auto-quote-pdf', $this->data($request, $snapshot, $manager))->render();

        $options = new Options;
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
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Request $request, AutoQuoteSnapshot $snapshot, ?User $manager): array
    {
        $money = fn (float $v) => number_format($v, 2, ',', ' ').' ₽';
        $qty = function (array $line) {
            $value = rtrim(rtrim(number_format((float) ($line['qty'] ?? 0), 2, ',', ' '), '0'), ',');

            return $value.' '.($line['unit'] ?? 'шт.');
        };

        $organization = $snapshot->organization_id
            ? Organization::query()->find($snapshot->organization_id)?->name
            : null;

        return [
            'request' => $request,
            'date' => now()->format('d.m.Y'),
            'organization' => $organization,
            'company' => (string) config('services.company.legal_name', config('app.name')),
            'lines' => array_map(fn (array $line) => $line + [
                'qty_label' => $qty($line),
                'price_label' => $money((float) ($line['unit_price'] ?? 0)),
                'total_label' => $money((float) ($line['total'] ?? 0)),
            ], $snapshot->lines ?? []),
            'totalLabel' => $money((float) $snapshot->total),
            // НДС в каталожных ценах уже сидит — говорим об этом прямо, чтобы
            // клиент не пересчитывал и не переспрашивал.
            'vatNote' => ', с НДС',
            'validDays' => self::VALID_DAYS,
            'validUntil' => now()->addDays(self::VALID_DAYS)->format('d.m.Y'),
            'manager' => (string) ($manager?->name ?? ''),
            'managerEmail' => (string) ($manager?->email ?? ''),
        ];
    }

    private function registerFonts(Dompdf $dompdf): void
    {
        $dir = resource_path('fonts');
        $fonts = [
            ['PT Sans', 'normal', 'normal', 'PTSans-Regular.ttf'],
            ['PT Sans', 'bold', 'normal', 'PTSans-Bold.ttf'],
            ['PT Sans', 'normal', 'italic', 'PTSans-Italic.ttf'],
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
