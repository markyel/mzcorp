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
 * Backfill режимы (пишут в БД):
 *   --backfill-dedup    — записать parsing_meta.dedup_dropped и
 *                         request_items.parsing_merged_from на основе
 *                         текущего трейса (матч по нормализованному
 *                         артикулу). Существующие позиции не пересоздаём.
 *   --backfill-meta     — запустить AttachmentMetaExtractionApplier на
 *                         вложения этой заявки (записать
 *                         parsing_meta.attachment_extracted).
 *
 * По умолчанию — READ-ONLY (только дамп).
 */
class RequestsReparseTraceCommand extends Command
{
    protected $signature = 'requests:reparse-trace
        {code : internal_code заявки}
        {--attachment-id=* : id вложений; по умолчанию все структурные (xlsx/pdf/docx)}
        {--dump-dir= : каталог для дампа; по умолчанию storage/app/reparse-trace/<code>-<timestamp>}
        {--backfill-dedup : записать parsing_meta.dedup_dropped и items.parsing_merged_from по результатам трейса}
        {--backfill-meta : запустить AttachmentMetaExtractionApplier на эту заявку}';

    protected $description = 'READ-ONLY трейс re-parse + опциональный backfill parsing_meta для одной заявки.';

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

        // ── Backfill (опционально) ──────────────────────────────────────
        if ((bool) $this->option('backfill-dedup')) {
            $this->newLine();
            $this->info('=== Backfill: dedup-trace в parsing_meta + items.parsing_merged_from ===');
            $this->backfillDedup($req, $existingItems, $summary, $attachments);
        }

        if ((bool) $this->option('backfill-meta')) {
            $this->newLine();
            $this->info('=== Backfill: attachment_extracted (extra-info LLM) ===');
            try {
                app(\App\Services\Mail\AttachmentMetaExtractionApplier::class)
                    ->applyForMessage($msg, $req);
                $req->refresh();
                $extracted = ($req->parsing_meta['attachment_extracted'] ?? []);
                $this->info(sprintf('  → записано %d блок(а/ов) attachment_extracted', count($extracted)));
            } catch (\Throwable $e) {
                $this->error("  ✗ extra-info backfill упал: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Backfill дедуп-трассы в БД на основе уже посчитанного $summary.
     * Матч съеденных дублей к существующим request_items по нормализованному
     * артикулу + qty + invoice_index. На каждой найденной позиции
     * перезаписывает parsing_merged_from; в requests.parsing_meta.dedup_dropped
     * аппендит сводку.
     *
     * @param  \Illuminate\Support\Collection<int,EmailAttachment>  $attachments
     */
    private function backfillDedup(
        ClientRequest $req,
        \Illuminate\Support\Collection $existingItems,
        array $summary,
        \Illuminate\Support\Collection $attachments,
    ): void {
        // Перепрогон step1-3 для каждого attachment не нужен — мы только что
        // его сделали и сохранили в $summary.attachments[*].step3_file.
        // Читаем json'ы и матчим.
        $meta = is_array($req->parsing_meta) ? $req->parsing_meta : [];
        $dropped = $meta['dedup_dropped'] ?? [];
        $perItem = []; // request_item.id => list of dropped entries

        $nowIso = now()->toIso8601String();

        foreach ($summary['attachments'] as $perAtt) {
            $step3File = $perAtt['step3_file'] ?? null;
            if (! $step3File || ! is_file($step3File)) {
                continue;
            }
            $trace = json_decode((string) file_get_contents($step3File), true);
            if (! is_array($trace)) {
                continue;
            }
            $kept = $trace['kept'] ?? [];
            $localDropped = $trace['dropped'] ?? [];
            if (empty($localDropped)) {
                continue;
            }

            $ext = strtolower(pathinfo($perAtt['filename'] ?? '', PATHINFO_EXTENSION));
            $sourceTag = "{$ext}_attachment_{$perAtt['attachment_id']}";

            // Индекс по dedup_key → kept-item (winner среди LLM).
            // Ключ синхронизирован с RequestItemParsingService и
            // traceDedupeWithinList: qty в ключе не учитывается.
            $winnerByKey = [];
            foreach ($kept as $k) {
                $base = $this->normalizeArticle($k['article'] ?? null);
                if ($base === '') {
                    $base = mb_strtolower(trim((string) ($k['name'] ?? '')));
                }
                $inv = (int) ($k['invoice_index'] ?? 1);
                $key = $base . '|inv=' . $inv;
                $winnerByKey[$key] = $k;
            }

            foreach ($localDropped as $d) {
                $key = $d['key'] ?? null;
                if (! $key || ! isset($winnerByKey[$key])) {
                    continue;
                }
                $winner = $winnerByKey[$key];
                $winnerArticle = $this->normalizeArticle($winner['article'] ?? null);
                $winnerName = mb_strtolower(trim((string) ($winner['name'] ?? '')));

                // Найти соответствующий RequestItem.
                // Стратегия двухпроходная:
                //   1) exact-match нормализованного артикула;
                //   2) если не нашли — fallback по имени (similar_text ≥ 70%).
                // Раньше fallback срабатывал ТОЛЬКО когда winnerArticle == '',
                // но при backfill LLM иногда возвращает «обогащённый» артикул
                // (со склеенным описанием faceplate'ов), который не совпадает
                // с тем, что в БД. Без fallback мы теряли матч.
                $matchItem = null;
                if ($winnerArticle !== '') {
                    foreach ($existingItems as $ri) {
                        if (! $ri->is_active) {
                            continue;
                        }
                        $riArticle = $this->normalizeArticle($ri->parsed_article);
                        if ($riArticle !== '' && $riArticle === $winnerArticle) {
                            $matchItem = $ri;
                            break;
                        }
                    }
                }
                if (! $matchItem && $winnerName !== '') {
                    $bestPercent = 0.0;
                    foreach ($existingItems as $ri) {
                        if (! $ri->is_active) {
                            continue;
                        }
                        $riName = mb_strtolower(trim((string) ($ri->parsed_name ?? '')));
                        if ($riName === '') {
                            continue;
                        }
                        similar_text($winnerName, $riName, $percent);
                        if ($percent >= 70 && $percent > $bestPercent) {
                            $bestPercent = $percent;
                            $matchItem = $ri;
                        }
                    }
                }
                if (! $matchItem) {
                    $this->warn("  не нашёл RequestItem для dedup_key={$key}");
                    continue;
                }

                // Backfill: рассчитываем qty_summed_into = сумма qty всех
                // дублей этого ключа в трассе LLM + qty победителя. Это
                // только информативная сводка для UI; реальный qty
                // существующих request_items не меняем (backfill не
                // переписывает позиции, только parsing_meta).
                $winnerQty = (float) ($winner['qty'] ?? 0);
                $eatenSum = 0.0;
                foreach ($localDropped as $dx) {
                    if (($dx['key'] ?? null) === $key) {
                        $eatenSum += (float) ($dx['qty'] ?? 0);
                    }
                }
                $qtySummedInto = $winnerQty + $eatenSum;

                $entry = [
                    'source' => $sourceTag,
                    'name' => (string) ($d['name'] ?? ''),
                    'article' => $d['article'] ?? null,
                    'qty' => (string) ($d['qty'] ?? ''),
                    'reason' => $d['reason'] ?? 'same_normalized_article_inv',
                    'dedup_key' => $key,
                    'qty_original_winner' => (string) $winnerQty,
                    'qty_summed_into' => $qtySummedInto,
                ];

                $perItem[$matchItem->id][] = $entry;
                $dropped[] = $entry + [
                    'merged_into_position' => $matchItem->position,
                    'at' => $nowIso,
                ];
            }
        }

        // Запись в request_items.
        $itemsUpdated = 0;
        foreach ($perItem as $itemId => $entries) {
            $ri = $existingItems->firstWhere('id', $itemId);
            if (! $ri) {
                continue;
            }
            $existingMerged = is_array($ri->parsing_merged_from) ? $ri->parsing_merged_from : [];
            $merged = array_merge($existingMerged, $entries);
            // Уникализация по dedup_key (на случай повторного backfill).
            $seen = [];
            $uniq = [];
            foreach ($merged as $m) {
                $k = $m['dedup_key'] ?? json_encode($m);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $uniq[] = $m;
            }
            $ri->parsing_merged_from = $uniq;
            $ri->save();
            $itemsUpdated++;
        }

        // Запись в requests.parsing_meta.
        $meta['dedup_dropped'] = $dropped;
        $req->parsing_meta = $meta;
        $req->save();

        $this->info(sprintf(
            '  → items updated: %d, dedup_dropped total: %d',
            $itemsUpdated,
            count($dropped),
        ));
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
            // Синхронизировано с RequestItemParsingService::dedupeWithinList:
            // qty НЕ входит в ключ — дубли в одном invoice суммируются.
            $key = $base . '|inv=' . $invoiceIndex;

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
