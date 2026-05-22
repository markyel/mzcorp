<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Request as ClientRequest;
use App\Services\RequestItemParsingService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Диагностический re-parse одной заявки с пошаговым трейсом.
 *
 * Зачем: `requests:reparse-items` и `request:reparse` пересоздают позиции,
 * но не показывают ПОЧЕМУ исходник из N строк дал M позиций. Эта команда
 * READ-ONLY проходит весь пайплайн пошагово и сохраняет промежуточные
 * артефакты на диск:
 *
 *   step1-extract.txt      — текст, который ExtractFromExcel дал LLM
 *   step2-llm-response.json — сырой ответ LLM (до dedupe)
 *   step3-dedupe-trace.json — что отбросил dedupeWithinList и почему
 *   step4-existing-match.json — match новых позиций против текущих request_items
 *   summary.json           — сводка: 58 → LLM → dedupe → diff с текущим
 *
 * Запуск:
 *   php artisan requests:reparse-trace M-2026-1215
 *   php artisan requests:reparse-trace M-2026-1215 --attachment-id=123
 *   php artisan requests:reparse-trace M-2026-1215 --dump-dir=storage/app/reparse-trace
 *
 * НИЧЕГО НЕ УДАЛЯЕТ И НЕ СОХРАНЯЕТ В БД. Только читает + пишет файлы трейса.
 */
class RequestsReparseTraceCommand extends Command
{
    protected $signature = 'requests:reparse-trace
        {code : internal_code заявки}
        {--attachment-id=* : id вложений; по умолчанию все структурные (xlsx/pdf/docx)}
        {--dump-dir= : каталог для дампа; по умолчанию storage/app/reparse-trace/<code>-<timestamp>}';

    protected $description = 'READ-ONLY: пошаговый трейс re-parse заявки с дампом артефактов.';

    public function handle(RequestItemParsingService $parser): int
    {
        $code = (string) $this->argument('code');
        $req = ClientRequest::query()->where('internal_code', $code)->first();
        if (! $req) {
            $this->error("Заявка {$code} не найдена");
            return self::FAILURE;
        }
        if (! $req->email_message_id) {
            $this->error("У заявки {$code} нет email_message_id");
            return self::FAILURE;
        }

        $msg = EmailMessage::find($req->email_message_id);
        if (! $msg) {
            $this->error("EmailMessage #{$req->email_message_id} не найден");
            return self::FAILURE;
        }

        $explicitIds = array_map('intval', (array) $this->option('attachment-id'));
        $attachmentsQuery = $msg->attachments();
        if (! empty($explicitIds)) {
            $attachmentsQuery->whereIn('id', $explicitIds);
        }
        /** @var \Illuminate\Support\Collection<int,EmailAttachment> $attachments */
        $attachments = $attachmentsQuery->get()->filter(function (EmailAttachment $a) {
            return preg_match('/\.(xlsx|xls|pdf|docx)$/i', (string) $a->filename) === 1;
        })->values();

        if ($attachments->isEmpty()) {
            $this->error('Нет структурных вложений (xlsx/pdf/docx) для трейса');
            return self::FAILURE;
        }

        $dumpDir = (string) ($this->option('dump-dir') ?: storage_path(
            sprintf('app/reparse-trace/%s-%s', $code, now()->format('Ymd-His'))
        ));
        if (! is_dir($dumpDir) && ! @mkdir($dumpDir, 0o755, true) && ! is_dir($dumpDir)) {
            $this->error("Не удалось создать каталог дампа: {$dumpDir}");
            return self::FAILURE;
        }

        $existingItems = $req->items()->get();
        $this->info(sprintf(
            'Заявка %s (id=%d, email #%d), текущих позиций: %d',
            $code, $req->id, $msg->id, $existingItems->count()
        ));
        $this->info("Dump dir: {$dumpDir}");
        $this->newLine();

        $summary = [
            'request' => [
                'internal_code' => $code,
                'id' => $req->id,
                'email_message_id' => $msg->id,
                'subject' => $msg->subject,
                'existing_items_count' => $existingItems->count(),
            ],
            'attachments' => [],
        ];

        foreach ($attachments as $att) {
            $this->line("=== Attachment #{$att->id}: {$att->filename} ({$att->mime_type}) ===");

            $perAtt = [
                'attachment_id' => $att->id,
                'filename' => $att->filename,
                'mime_type' => $att->mime_type,
            ];

            // Step 1 — извлечение текста.
            try {
                $absolutePath = Storage::disk($att->disk)->path($att->file_path);
                if (! file_exists($absolutePath)) {
                    $this->warn("  файл не найден на диске: {$absolutePath}");
                    $perAtt['error'] = 'file_missing';
                    $summary['attachments'][] = $perAtt;
                    continue;
                }
                $upload = new UploadedFile(
                    $absolutePath,
                    $att->filename ?? basename($absolutePath),
                    $att->mime_type,
                    null,
                    true,
                );
                $extractedText = $parser->extractTextFromFile($upload);
            } catch (\Throwable $e) {
                $this->error("  extract failed: {$e->getMessage()}");
                $perAtt['error'] = 'extract_failed: ' . $e->getMessage();
                $summary['attachments'][] = $perAtt;
                continue;
            }

            $lines = preg_split('/\R/u', $extractedText) ?: [];
            $perAtt['extracted_lines'] = count($lines);
            $perAtt['extracted_chars'] = mb_strlen($extractedText);
            $this->line(sprintf('  step1: extracted %d lines, %d chars', count($lines), mb_strlen($extractedText)));

            $step1Path = $dumpDir . "/att-{$att->id}-step1-extract.txt";
            file_put_contents($step1Path, $extractedText);
            $perAtt['step1_file'] = $step1Path;

            // Step 2 — LLM parse.
            try {
                $llmItems = $parser->parseItemsWithGPT(
                    $extractedText,
                    (string) $msg->subject,
                    (string) $msg->from_email,
                    (string) $msg->from_name,
                    null,
                );
            } catch (\Throwable $e) {
                $this->error("  LLM parse failed: {$e->getMessage()}");
                $perAtt['error'] = 'llm_failed: ' . $e->getMessage();
                file_put_contents($dumpDir . "/att-{$att->id}-error.txt", $e->getMessage() . "\n" . $e->getTraceAsString());
                $summary['attachments'][] = $perAtt;
                continue;
            }

            $perAtt['step2_llm_items'] = count($llmItems);
            $this->line(sprintf('  step2: LLM вернул %d позиций', count($llmItems)));

            $step2Path = $dumpDir . "/att-{$att->id}-step2-llm-response.json";
            file_put_contents($step2Path, json_encode($llmItems, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $perAtt['step2_file'] = $step2Path;

            // Step 3 — внутрилистовой dedupe (воспроизводим логику dedupeWithinList).
            $dedupeTrace = $this->traceDedupeWithinList($llmItems);
            $perAtt['step3_after_dedupe'] = count($dedupeTrace['kept']);
            $perAtt['step3_dropped'] = count($dedupeTrace['dropped']);
            $this->line(sprintf(
                '  step3: dedupeWithinList → kept %d, dropped %d',
                count($dedupeTrace['kept']),
                count($dedupeTrace['dropped']),
            ));
            foreach ($dedupeTrace['dropped'] as $d) {
                $this->line(sprintf(
                    '         drop #%d: %s | qty=%s | key=%s (already seen #%d)',
                    $d['llm_index'],
                    mb_substr($d['name'], 0, 40),
                    $d['qty'],
                    $d['key'],
                    $d['first_seen_index'],
                ));
            }
            $step3Path = $dumpDir . "/att-{$att->id}-step3-dedupe-trace.json";
            file_put_contents($step3Path, json_encode($dedupeTrace, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $perAtt['step3_file'] = $step3Path;

            // Step 4 — сверка с существующими request_items (как сделал бы filterNewItems).
            $matchTrace = $this->traceFilterAgainstExisting($dedupeTrace['kept'], $existingItems);
            $perAtt['step4_new'] = count($matchTrace['new']);
            $perAtt['step4_dup'] = count($matchTrace['dup']);
            $this->line(sprintf(
                '  step4: vs existing → truly new %d, dup %d',
                count($matchTrace['new']),
                count($matchTrace['dup']),
            ));
            foreach ($matchTrace['dup'] as $d) {
                $this->line(sprintf(
                    '         dup: LLM «%s» (art=%s) ↔ existing #%d «%s» (art=%s) [%s]',
                    mb_substr($d['llm']['name'], 0, 35),
                    $d['llm']['article'] ?? '∅',
                    $d['existing']['position'],
                    mb_substr($d['existing']['name'], 0, 35),
                    $d['existing']['article'] ?? '∅',
                    $d['rule'],
                ));
            }
            $step4Path = $dumpDir . "/att-{$att->id}-step4-existing-match.json";
            file_put_contents($step4Path, json_encode($matchTrace, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $perAtt['step4_file'] = $step4Path;

            $this->newLine();
            $summary['attachments'][] = $perAtt;
        }

        $summaryPath = $dumpDir . '/summary.json';
        file_put_contents($summaryPath, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->newLine();
        $this->info('=== Сводка ===');
        $rows = [];
        foreach ($summary['attachments'] as $a) {
            $rows[] = [
                $a['attachment_id'],
                mb_strimwidth($a['filename'] ?? '', 0, 30, '…'),
                $a['extracted_lines'] ?? '-',
                $a['step2_llm_items'] ?? '-',
                $a['step3_after_dedupe'] ?? '-',
                $a['step4_new'] ?? '-',
                $a['error'] ?? '',
            ];
        }
        $this->table(
            ['att_id', 'filename', 'lines', 'llm', 'after_dedupe', 'truly_new_vs_existing', 'error'],
            $rows,
        );
        $this->info("Summary: {$summaryPath}");

        return self::SUCCESS;
    }

    /**
     * Воспроизводит логику RequestItemParsingService::dedupeWithinList с трейсом.
     *
     * @param  array<int,array> $items
     * @return array{kept: array<int,array>, dropped: array<int,array>}
     */
    private function traceDedupeWithinList(array $items): array
    {
        $seen = [];
        $kept = [];
        $dropped = [];

        foreach ($items as $idx => $item) {
            $base = $this->normalizeArticle($item['article'] ?? null);
            $usedField = 'article';
            if ($base === '') {
                $base = mb_strtolower(trim((string) ($item['name'] ?? '')));
                $usedField = 'name';
            }
            if ($base === '') {
                $dropped[] = [
                    'llm_index' => $idx,
                    'name' => (string) ($item['name'] ?? ''),
                    'article' => $item['article'] ?? null,
                    'qty' => (string) ($item['qty'] ?? ''),
                    'reason' => 'empty_article_and_name',
                    'key' => null,
                    'used_field' => $usedField,
                    'first_seen_index' => null,
                ];
                continue;
            }
            $qty = (string) ($item['qty'] ?? '');
            $invoiceIndex = (int) ($item['invoice_index'] ?? 1);
            $key = $base . '|qty=' . $qty . '|inv=' . $invoiceIndex;

            if (isset($seen[$key])) {
                $dropped[] = [
                    'llm_index' => $idx,
                    'name' => (string) ($item['name'] ?? ''),
                    'article' => $item['article'] ?? null,
                    'qty' => $qty,
                    'reason' => 'duplicate_within_llm_response',
                    'used_field' => $usedField,
                    'key' => $key,
                    'first_seen_index' => $seen[$key],
                ];
                continue;
            }
            $seen[$key] = $idx;
            $kept[] = $item + ['__llm_index' => $idx];
        }

        return ['kept' => $kept, 'dropped' => $dropped];
    }

    /**
     * Воспроизводит логику RequestItemParsingService::isDuplicate против
     * существующих request_items, с указанием правила.
     *
     * @param  array<int,array> $parsedItems
     * @param  \Illuminate\Support\Collection<int,\App\Models\RequestItem> $existingItems
     * @return array{new: array<int,array>, dup: array<int,array>}
     */
    private function traceFilterAgainstExisting(array $parsedItems, \Illuminate\Support\Collection $existingItems): array
    {
        $new = [];
        $dup = [];

        foreach ($parsedItems as $parsed) {
            $parsedArticle = $this->normalizeArticle($parsed['article'] ?? null);
            $parsedName = mb_strtolower(trim((string) ($parsed['name'] ?? '')));
            $matched = null;
            $rule = null;
            $similarityPct = null;

            foreach ($existingItems as $existing) {
                if (! $existing->is_active) {
                    continue;
                }
                $existingArticle = $this->normalizeArticle($existing->parsed_article);

                if ($parsedArticle !== '' && $existingArticle !== '') {
                    if ($parsedArticle === $existingArticle) {
                        $matched = $existing;
                        $rule = 'article_exact';
                        break;
                    }
                    continue;
                }

                $existingName = mb_strtolower(trim((string) ($existing->parsed_name ?? '')));
                if ($parsedName !== '' && $existingName !== '') {
                    similar_text($parsedName, $existingName, $percent);
                    if ($percent >= 70) {
                        $matched = $existing;
                        $rule = 'name_similarity';
                        $similarityPct = round($percent, 2);
                        break;
                    }
                }
            }

            if ($matched !== null) {
                $dup[] = [
                    'llm' => [
                        'name' => (string) ($parsed['name'] ?? ''),
                        'article' => $parsed['article'] ?? null,
                        'qty' => (string) ($parsed['qty'] ?? ''),
                        'llm_index' => $parsed['__llm_index'] ?? null,
                    ],
                    'existing' => [
                        'id' => $matched->id,
                        'position' => $matched->position,
                        'name' => (string) ($matched->parsed_name ?? ''),
                        'article' => $matched->parsed_article,
                    ],
                    'rule' => $rule,
                    'similarity_pct' => $similarityPct,
                ];
            } else {
                $new[] = $parsed;
            }
        }

        return ['new' => $new, 'dup' => $dup];
    }

    private function normalizeArticle(?string $article): string
    {
        if (empty($article)) {
            return '';
        }
        return preg_replace('/[\s\-_.\/]/', '', mb_strtoupper(trim($article)));
    }
}
