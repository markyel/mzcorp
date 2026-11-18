<?php

namespace App\Http\Controllers;

use App\Models\CalendarGiftRecipient;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Список получателей календаря в Excel — для отправки (курьер, почта). Отменённые не выгружаются. */
class CalendarGiftExportController extends Controller
{
    public const HEADER = ['№', 'Получатель', 'Компания', 'Телефон', 'Адрес', 'Канал', 'Подписка', 'Статус', 'Записался'];

    public function __invoke(): StreamedResponse
    {
        $campaign = (string) config('services.calendar_gift.campaign');
        $tz = config('app.timezone');
        $rows = CalendarGiftRecipient::query()->where('campaign', $campaign)
            ->where('status', '!=', CalendarGiftRecipient::STATUS_CANCELLED)->orderBy('id')->get();

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Календарь '.$campaign);
        $sheet->fromArray(self::HEADER, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $i = 2;
        foreach ($rows as $n => $r) {
            $sheet->fromArray([
                $n + 1, $r->contact_name, $r->company_name, $r->phone, $r->address, $r->channel->label(),
                ($r->subscribed ? 'да' : 'нет').($r->subscription_checked_at ? ', '.$r->subscription_checked_at->timezone($tz)->format('d.m.Y') : ''),
                $r->statusLabel(), $r->created_at?->timezone($tz)->format('d.m.Y H:i'),
            ], null, 'A'.$i, true);
            $sheet->getCell('D'.$i)->setValueExplicit((string) $r->phone, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $i++;
        }
        foreach (['A' => 5, 'B' => 28, 'C' => 30, 'D' => 18, 'E' => 60, 'F' => 10, 'G' => 16, 'H' => 12, 'I' => 17] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->getStyle('E2:E'.max(2, $i - 1))->getAlignment()->setWrapText(true);
        $sheet->freezePane('A2');

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'),
            'Календарь '.$campaign.' — получатели '.now()->format('d.m.Y').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
