<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Request as RequestModel;
use App\Services\Requests\CorrespondenceExportService;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Экспорт переписки заявки.
 *
 *   GET /dashboard/requests/{request}/correspondence/export[?messages=1,2,3]
 *
 * Без параметра messages — экспортируется весь тред, иначе только выбранные
 * письма. Если в выбранных письмах есть файлы-вложения (не картинки) — отдаём
 * ZIP (PDF + файлы), иначе один PDF.
 *
 * Permission: owner / acting (delegation) / privileged (РОП/директор/секретарь/админ)
 * — как в QuotationPdfController.
 */
class CorrespondencePdfController extends Controller
{
    public function __construct(private readonly CorrespondenceExportService $svc)
    {
    }

    public function export(HttpRequest $httpRequest, RequestModel $request): Response
    {
        $this->authorizeAccess($request);

        $ids = $this->parseMessageIds($httpRequest->query('messages'));
        $thread = $this->svc->buildThread($request, $ids);

        if ($thread->isEmpty()) {
            abort(404, 'Нет писем для экспорта.');
        }

        $pdf = $this->svc->render($request, $thread);
        $bundle = $this->svc->bundleAttachments($thread);

        if ($bundle->isEmpty()) {
            return $this->fileResponse(
                $pdf,
                'application/pdf',
                $this->svc->filename($request, 'pdf'),
            );
        }

        return $this->fileResponse(
            $this->buildZip($pdf, $request, $bundle),
            'application/zip',
            $this->svc->filename($request, 'zip'),
        );
    }

    /**
     * @return int[]|null  null → весь тред
     */
    private function parseMessageIds(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', $raw)
        )));

        return $ids ?: null;
    }

    /**
     * Собирает ZIP: PDF переписки + файлы-вложения. Имена файлов префиксуем
     * порядковым номером, чтобы дубли имён из разных писем не перетирались.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\EmailAttachment>  $bundle
     */
    private function buildZip(string $pdf, RequestModel $request, $bundle): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'corr_');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Не удалось создать архив.');
        }

        $zip->addFromString($this->svc->filename($request, 'pdf'), $pdf);

        $i = 0;
        foreach ($bundle as $att) {
            $disk = Storage::disk($att->disk);
            if (! $disk->exists($att->file_path)) {
                continue;
            }
            $i++;
            $name = sprintf('%02d_%s', $i, $this->sanitizeEntryName((string) $att->filename));
            $zip->addFromString($name, (string) $disk->get($att->file_path));
        }

        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * Чистим имя файла внутри архива: убираем сепараторы путей и control-байты.
     */
    private function sanitizeEntryName(string $name): string
    {
        $clean = preg_replace('#[\x00-\x1F\x7F/\\\\]#', '_', $name) ?? '';
        $clean = trim(preg_replace('/_+/', '_', $clean) ?? '', '_ .');

        return $clean !== '' ? $clean : 'file.bin';
    }

    private function fileResponse(string $content, string $mime, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                $this->asciiFallback($filename),
            ),
        ]);
    }

    private function asciiFallback(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]|[\\/\\\\%"\']/', '_', $name) ?? '';
        $ascii = trim(preg_replace('/_+/', '_', $ascii) ?? '', '_ .');

        return $ascii !== '' ? $ascii : 'correspondence';
    }

    private function authorizeAccess(RequestModel $request): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }
        if ($user->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Secretary->value,
            Role::Admin->value,
        ])) {
            return;
        }
        if (! $request->isAccessibleBy($user)) {
            abort(403, 'Нет доступа к этой заявке.');
        }
    }
}
