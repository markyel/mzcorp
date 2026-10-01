<?php

namespace App\Services\Marketing;

use App\Models\MarketingReport;
use App\Services\Quotations\RuMoneySpeller;
use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * Акт оказанных услуг по форме Приложения № 3 к договору с ИП Маркелов.
 *
 * Стоимость — п. 3.1–3.5 договора: фиксированная сумма за полный календарный
 * месяц без НДС, НДС сверху; за неполный месяц — пропорционально числу
 * календарных дней действия договора в этом месяце. Период акта может
 * захватывать несколько месяцев — тогда каждый считается отдельно.
 */
class MarketingActService
{
    private const MONTHS_GEN = [
        1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
    ];

    public function __construct(
        private readonly MarketingReportService $reports,
        private readonly RuMoneySpeller $speller,
    ) {}

    /**
     * Параметры акта по умолчанию для отчётного месяца: период — с начала
     * месяца или с даты начала договора, если она позже, до конца месяца.
     *
     * @return array{number: string, date: string, from: string, to: string}
     */
    public function defaults(Carbon $period): array
    {
        $from = $period->copy()->startOfMonth();
        $to = $period->copy()->endOfMonth()->startOfDay();
        $start = $this->reports->requisites()['contract_start'] ?? '';
        if ($start !== '') {
            try {
                $s = Carbon::parse($start)->startOfDay();
                if ($s->gt($from) && $s->lte($to)) {
                    $from = $s;
                }
            } catch (\Throwable) {
                // Дата начала не разобралась — считаем полный месяц.
            }
        }

        return ['number' => '', 'date' => $to->toDateString(), 'from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /**
     * Стоимость за период по правилу п. 3.5.
     *
     * @return array{base: float, vat: float, total: float, vat_rate: float, full_month: bool,
     *     parts: list<array{month: string, days: int, of: int, base: float}>}
     */
    public function amounts(string $from, string $to, float $monthlyFee, float $vatRate): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->startOfDay();
        if ($t->lt($f)) {
            [$f, $t] = [$t, $f];
        }

        $parts = [];
        $base = 0.0;
        for ($m = $f->copy()->startOfMonth(); $m->lte($t); $m->addMonth()) {
            $mStart = $m->copy()->max($f);
            $mEnd = $m->copy()->endOfMonth()->startOfDay()->min($t);
            $days = (int) $mStart->diffInDays($mEnd) + 1;
            $of = $m->daysInMonth;
            $sum = round($monthlyFee * $days / $of, 2);
            $base += $sum;
            $parts[] = ['month' => $m->format('Y-m'), 'days' => $days, 'of' => $of, 'base' => $sum];
        }
        $base = round($base, 2);
        $vat = round($base * $vatRate / 100, 2);

        return [
            'base' => $base,
            'vat' => $vat,
            'total' => round($base + $vat, 2),
            'vat_rate' => $vatRate,
            'full_month' => count($parts) === 1 && $parts[0]['days'] === $parts[0]['of'],
            'parts' => $parts,
        ];
    }

    /** Сохранить .docx акта во временный файл и вернуть путь. */
    public function render(MarketingReport $report): string
    {
        $data = $this->reports->mergeWithSaved($report);
        $act = array_merge($this->defaults($report->period), array_filter((array) ($report->payload['act'] ?? []), fn ($v) => $v !== ''));

        return $this->renderData((array) ($data['requisites'] ?? []), $act);
    }

    /**
     * @param  array<string, string>  $req  реквизиты договора
     * @param  array{number: string, date: string, from: string, to: string}  $act
     */
    public function renderData(array $req, array $act): string
    {
        $req = array_merge([
            'contract_number' => '', 'contract_date' => '', 'contractor' => '', 'customer' => '',
            'city' => '', 'monthly_fee' => '160000', 'vat_rate' => '22',
        ], array_filter($req, fn ($v) => trim((string) $v) !== ''));
        $sum = $this->amounts($act['from'], $act['to'], $this->number($req['monthly_fee']), $this->number($req['vat_rate']));
        $contract = '№ '.($req['contract_number'] ?: '___').' от '.($req['contract_date'] ?: '«___» __________ 2026 г.');
        $contractor = $req['contractor'] ?: 'ИП Маркелов';
        $customer = $req['customer'] ?: 'ООО «Мой Лифт»';

        $word = new PhpWord;
        $word->getSettings()->setThemeFontLang(new Language(Language::RU_RU));
        $word->setDefaultFontName('Times New Roman');
        $word->setDefaultFontSize(11);
        $section = $word->addSection(['marginLeft' => 1134, 'marginRight' => 850, 'marginTop' => 850, 'marginBottom' => 850]);
        $p = ['spaceAfter' => 120, 'alignment' => Jc::BOTH];

        $section->addText('АКТ № '.($act['number'] !== '' ? $act['number'] : '___').' ОКАЗАННЫХ УСЛУГ', ['bold' => true, 'size' => 13],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 160]);
        $t = $section->addTable(['borderSize' => 0, 'cellMargin' => 0]);
        $t->addRow();
        $t->addCell(4800)->addText('г. '.($req['city'] ?: '__________'), [], ['spaceAfter' => 0]);
        $t->addCell(5100)->addText(self::dateLong($act['date']), [], ['spaceAfter' => 0, 'alignment' => Jc::END]);
        $section->addTextBreak(1);

        $section->addText(
            ($contractor === 'ИП Маркелов' ? 'Индивидуальный предприниматель Маркелов' : $contractor)
            .', именуемый «Исполнитель», с одной стороны, и '.$customer
            .', именуемое «Заказчик», с другой стороны, составили настоящий Акт о нижеследующем:', [], $p);

        $section->addText('1. В период с '.self::dateLong($act['from']).' по '.self::dateLong($act['to'])
            .' Исполнитель оказал Заказчику маркетинговые, информационно-аналитические и консультационные услуги'
            .' в соответствии с Договором '.$contract.'.', [], $p);

        $money = fn (float $v) => self::money($v).' ('.$this->speller->spell($v).')';
        $rate = rtrim(rtrim(number_format($sum['vat_rate'], 2, ',', ''), '0'), ',');
        if ($sum['full_month']) {
            $basis = 'Стоимость оказанных услуг за полный календарный месяц';
        } else {
            $days = implode(', ', array_map(fn ($x) => $x['days'].' из '.$x['of'], $sum['parts']));
            $basis = 'Стоимость оказанных услуг за неполный календарный месяц ('.$days
                .' календарных дней действия Договора, п. 3.5 Договора)';
        }
        $section->addText('2. '.$basis.': '.$money($sum['base']).' без НДС; НДС '.$rate.'% — '.$money($sum['vat'])
            .'; итого с НДС — '.$money($sum['total']).'.', [], $p);

        $section->addText('3. Содержание регулярных услуг и дополнительных задач, фактически выполнявшихся в отчётном периоде, отражено в ежемесячном Отчёте.', [], $p);
        $section->addText('4. Услуги оказаны в согласованном объёме и надлежащего качества. Заказчик претензий к объёму, срокам и качеству оказанных услуг не имеет.', [], $p);
        $section->addTextBreak(1);

        $sign = $section->addTable(['borderSize' => 0, 'cellMargin' => 0]);
        $sign->addRow();
        foreach ([['ЗАКАЗЧИК', $customer, '________________ /________________/'],
            ['ИСПОЛНИТЕЛЬ', $contractor, '________________ /Маркелов __________/']] as [$role, $name, $line]) {
            $cell = $sign->addCell(4950);
            $cell->addText($role, ['bold' => true], ['spaceAfter' => 0]);
            $cell->addText($name, [], ['spaceAfter' => 360]);
            $cell->addText($line, [], ['spaceAfter' => 0]);
        }

        $path = tempnam(sys_get_temp_dir(), 'mact').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    public function filename(MarketingReport $report, array $act): string
    {
        return 'Акт_маркетинг_'.str_replace('-', '_', $act['from']).'_'.str_replace('-', '_', $act['to']).'.docx';
    }

    /** «30» сентября 2026 г. */
    public static function dateLong(string $date): string
    {
        try {
            $d = Carbon::parse($date);
        } catch (\Throwable) {
            return '«___» __________ 20__ г.';
        }

        return '«'.$d->format('d').'» '.self::MONTHS_GEN[(int) $d->format('n')].' '.$d->format('Y').' г.';
    }

    /** 74 666,67 руб. */
    public static function money(float $v): string
    {
        return number_format($v, 2, ',', "\u{00A0}").' руб.';
    }

    private function number(string $v): float
    {
        return (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $v);
    }
}
