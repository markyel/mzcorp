<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Request as ClientRequest;
use Illuminate\Support\Facades\Log;

/**
 * Применяет AttachmentMetaExtractionService ко всем структурным вложениям
 * письма и сохраняет результат в requests.parsing_meta.attachment_extracted[].
 *
 * Идемпотентность: при повторном вызове перезаписывает записи с тем же
 * `source` (xlsx_attachment_<id>) — это позволяет re-parse через
 * `request:reparse` обновить справку, не плодя дубликатов.
 */
class AttachmentMetaExtractionApplier
{
    public function __construct(
        private readonly AttachmentMetaExtractionService $extractor,
    ) {
    }

    public function applyForMessage(EmailMessage $message, ClientRequest $request): void
    {
        $attachments = $message->attachments ?? collect();
        if ($attachments->isEmpty()) {
            return;
        }

        $structured = $attachments->filter(
            fn ($a) => preg_match('/\.(xlsx|xls|pdf|docx)$/i', (string) $a->filename) === 1
        );
        if ($structured->isEmpty()) {
            return;
        }

        $meta = is_array($request->parsing_meta) ? $request->parsing_meta : [];
        $existing = $meta['attachment_extracted'] ?? [];

        // Индекс по source, чтобы не дублировать.
        $bySource = [];
        foreach ($existing as $i => $row) {
            $bySource[$row['source'] ?? ''] = $i;
        }

        $nowIso = now()->toIso8601String();
        $changed = false;

        foreach ($structured as $att) {
            try {
                $extracted = $this->extractor->extractFromAttachment($att);
            } catch (\Throwable $e) {
                Log::warning('AttachmentMetaExtractionApplier: extraction threw', [
                    'attachment_id' => $att->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if ($extracted === null) {
                continue;
            }

            $record = [
                'source' => $extracted['source'],
                'attachment_id' => (int) $att->id,
                'filename' => $extracted['filename'],
                'fields' => $extracted['fields'],
                'at' => $nowIso,
            ];

            if (isset($bySource[$record['source']])) {
                $existing[$bySource[$record['source']]] = $record;
            } else {
                $bySource[$record['source']] = count($existing);
                $existing[] = $record;
            }
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        $meta['attachment_extracted'] = array_values($existing);
        $request->parsing_meta = $meta;
        $request->save();
    }
}
