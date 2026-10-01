<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingReport;
use App\Services\Marketing\MarketingActService;
use App\Services\Marketing\MarketingReportDocxService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Выгрузка ежемесячного отчёта по маркетингу в .docx (форма Приложения № 1).
 * Доступ — только админ (см. группу role:admin в routes/web.php).
 */
class MarketingReportController extends Controller
{
    public function download(MarketingReport $report, MarketingReportDocxService $docx): BinaryFileResponse
    {
        $path = $docx->render($report);

        return response()
            ->download($path, $docx->filename($report), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])
            ->deleteFileAfterSend();
    }

    /** Акт оказанных услуг (Приложение № 3) за период, сохранённый в отчёте. */
    public function act(MarketingReport $report, MarketingActService $acts): BinaryFileResponse
    {
        $act = array_merge($acts->defaults($report->period),
            array_filter((array) ($report->payload['act'] ?? []), fn ($v) => is_string($v) && $v !== ''));
        $path = $acts->render($report);

        return response()
            ->download($path, $acts->filename($report, $act), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])
            ->deleteFileAfterSend();
    }
}
