<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use Illuminate\Console\Command;

/**
 * Дамп всех писем в CSV для ручного анализа классификации.
 *
 * Цель — увидеть весь корпус с признаками, по которым решается,
 * client_request это или нет. Используется при проектировании
 * Phase 1.8c (mail classifier rewrite).
 *
 *   php artisan mail:export-for-analysis              # → storage/app/mail-export.csv
 *   php artisan mail:export-for-analysis --out=path
 *   php artisan mail:export-for-analysis --inbound    # только inbound
 */
class MailExportForAnalysisCommand extends Command
{
    protected $signature = 'mail:export-for-analysis
        {--out=storage/app/mail-export.csv : Куда писать CSV}
        {--inbound : Только inbound письма}
        {--limit=0 : Максимум писем (0 = все)}';

    protected $description = 'Экспорт писем в CSV для анализа классификатора (Phase 1.8c)';

    public function handle(): int
    {
        $outPath = (string) $this->option('out');
        if (! str_starts_with($outPath, '/')) {
            $outPath = base_path($outPath);
        }

        $query = EmailMessage::with(['mailbox', 'attachments', 'relatedRequest.items'])
            ->orderBy('id');
        if ($this->option('inbound')) {
            $query->where('direction', 'inbound');
        }
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = $query->count();
        $this->info("Экспортирую {$count} писем в {$outPath} …");

        $fp = fopen($outPath, 'w');
        if (! $fp) {
            $this->error("Не могу открыть {$outPath} на запись.");

            return self::FAILURE;
        }

        // BOM для Excel/Numbers — иначе кириллица бьётся.
        fwrite($fp, "\xEF\xBB\xBF");

        fputcsv($fp, [
            'id',
            'direction',
            'mailbox',
            'folder',
            'sent_at',
            'from_email',
            'from_name',
            'from_domain',
            'mailbox_domain',
            'is_internal_domain',
            'subject',
            'body_chars',
            'attachments_count',
            'attachment_exts',
            'has_pdf',
            'has_office_doc',
            'has_image',
            'has_archive',
            'has_unsubscribe_header',
            'has_auto_submitted_header',
            'precedence_header',
            'has_list_id_header',
            'ai_classification',
            'related_request_code',
            'request_items_count',
            'request_items_with_article',
            'request_first_item_name',
        ]);

        $progress = $this->output->createProgressBar($count);
        $progress->start();

        $query->chunk(200, function ($messages) use ($fp, $progress) {
            foreach ($messages as $m) {
                $headers = (array) ($m->headers ?? []);
                $hUnsub = $this->headerExists($headers, 'list-unsubscribe');
                $hAuto = $this->headerExists($headers, 'auto-submitted');
                $hListId = $this->headerExists($headers, 'list-id');
                $hPrecedence = $this->headerValue($headers, 'precedence');

                $atts = $m->attachments;
                $exts = [];
                foreach ($atts as $a) {
                    $ext = strtolower(pathinfo((string) $a->filename, PATHINFO_EXTENSION));
                    if ($ext !== '') {
                        $exts[$ext] = ($exts[$ext] ?? 0) + 1;
                    }
                }
                $extStr = '';
                foreach ($exts as $e => $c) {
                    $extStr .= ($extStr ? ' ' : '') . $e . ':' . $c;
                }

                $hasPdf = isset($exts['pdf']);
                $hasOffice = isset($exts['docx']) || isset($exts['xlsx']) || isset($exts['xls']) || isset($exts['doc']);
                $hasImage = isset($exts['jpg']) || isset($exts['jpeg']) || isset($exts['png']) || isset($exts['heic']) || isset($exts['webp']);
                $hasArchive = isset($exts['zip']) || isset($exts['rar']) || isset($exts['7z']);

                $fromDomain = $this->emailDomain((string) ($m->from_email ?? ''));
                $mailboxDomain = $m->mailbox ? $this->emailDomain((string) ($m->mailbox->email ?? '')) : '';
                $isInternal = $fromDomain !== '' && $fromDomain === $mailboxDomain;

                $req = $m->relatedRequest ?? null;
                $items = $req?->items ?? collect();
                $itemsWithArticle = $items->filter(fn ($it) => ! empty(trim((string) $it->parsed_article)))->count();
                $firstItemName = $items->first()?->parsed_name ?? '';

                fputcsv($fp, [
                    $m->id,
                    $m->direction?->value ?? '',
                    $m->mailbox?->email ?? '',
                    $m->folder,
                    $m->sent_at?->format('Y-m-d H:i') ?? '',
                    $m->from_email,
                    $m->from_name,
                    $fromDomain,
                    $mailboxDomain,
                    $isInternal ? '1' : '0',
                    mb_substr((string) $m->subject, 0, 200),
                    mb_strlen((string) ($m->body_plain ?? '')),
                    $atts->count(),
                    $extStr,
                    $hasPdf ? '1' : '0',
                    $hasOffice ? '1' : '0',
                    $hasImage ? '1' : '0',
                    $hasArchive ? '1' : '0',
                    $hUnsub ? '1' : '0',
                    $hAuto ? '1' : '0',
                    $hPrecedence,
                    $hListId ? '1' : '0',
                    $m->ai_classification ?? '',
                    $req?->internal_code ?? '',
                    $items->count(),
                    $itemsWithArticle,
                    mb_substr($firstItemName, 0, 100),
                ]);
                $progress->advance();
            }
        });

        $progress->finish();
        fclose($fp);
        $this->newLine();
        $this->info("Готово. Файл: {$outPath}");

        return self::SUCCESS;
    }

    private function headerExists(array $headers, string $name): bool
    {
        $name = mb_strtolower($name);
        foreach ($headers as $k => $v) {
            if (mb_strtolower((string) $k) === $name || mb_strtolower((string) str_replace(['-', '_'], '', (string) $k)) === str_replace(['-', '_'], '', $name)) {
                return true;
            }
        }

        return false;
    }

    private function headerValue(array $headers, string $name): string
    {
        $name = mb_strtolower($name);
        foreach ($headers as $k => $v) {
            if (mb_strtolower((string) $k) === $name) {
                return is_array($v) ? implode(';', $v) : (string) $v;
            }
        }

        return '';
    }

    private function emailDomain(string $email): string
    {
        $atPos = strrpos($email, '@');

        return $atPos === false ? '' : mb_strtolower(trim(mb_substr($email, $atPos + 1)));
    }
}
