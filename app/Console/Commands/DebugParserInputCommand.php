<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Services\Mail\EmailTextCleanerService;
use Illuminate\Console\Command;

/**
 * Диагностика: что парсер на самом деле видит как «cleaned body».
 * Не дёргает LLM — просто прогоняет body_plain/body_html через тот же
 * pipeline (bodyPlainLooksBroken → htmlToText → cleanInboundReferenceText).
 *
 *   php artisan mail:debug-parser-input 1533
 */
class DebugParserInputCommand extends Command
{
    protected $signature = 'mail:debug-parser-input {message_id : EmailMessage id}';

    protected $description = 'Показать что парсер видит как cleaned body для конкретного письма.';

    public function handle(EmailTextCleanerService $cleaner): int
    {
        $id = (int) $this->argument('message_id');
        $m = EmailMessage::find($id);
        if (! $m) {
            $this->error("EmailMessage #{$id} не найден");
            return self::FAILURE;
        }

        $plain = (string) ($m->body_plain ?? '');
        $html  = (string) ($m->body_html ?? '');

        $this->line("=== INPUT ===");
        $this->line("body_plain len: " . mb_strlen($plain));
        $this->line("body_html len:  " . mb_strlen($html));

        $plainBroken = $cleaner->bodyPlainLooksBroken($plain);
        $this->line("bodyPlainLooksBroken: " . ($plainBroken ? 'YES → use htmlToText' : 'NO → use plain'));

        $rawBody = $plain;
        if ($plainBroken && trim($html) !== '') {
            $rawBody = $cleaner->htmlToText($html);
        }

        $this->line("");
        $this->line("=== STEP 1: rawBody (after htmlToText if needed) ===");
        $this->line("length: " . mb_strlen($rawBody));
        $this->line("--- BEGIN ---");
        $this->line($rawBody);
        $this->line("--- END ---");

        $cleaned = $cleaner->cleanInboundReferenceText($rawBody);

        $this->line("");
        $this->line("=== STEP 2: cleanedBody (after cleanInboundReferenceText) ===");
        $this->line("length: " . mb_strlen($cleaned));
        $this->line("--- BEGIN ---");
        $this->line($cleaned);
        $this->line("--- END ---");

        // Метрики: сколько раз встретилось «счёт», «счет», артикулы M????, etc.
        $this->line("");
        $this->line("=== HEURISTICS ===");
        $this->line("«счёт» occurrences: " . substr_count(mb_strtolower($cleaned), 'счёт'));
        $this->line("«счет» occurrences: " . substr_count(mb_strtolower($cleaned), 'счет'));
        preg_match_all('/M\d{4,6}/u', $cleaned, $mskus);
        $this->line("M-SKU artefacts found: " . count($mskus[0]) . " → " . implode(', ', array_unique($mskus[0])));

        return self::SUCCESS;
    }
}
