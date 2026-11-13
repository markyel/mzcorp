<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Services\Mail\EmailTextCleanerService;
use App\Support\EmailHtml;
use App\Support\NestedMimePart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Back-fill: тело письма, которое webklex сохранил вложением (вложенный multipart с одной
 * частью, см. NestedMimePart). У таких писем пустое тело и вложение без имени («d5e59bf9»).
 * Тело переносится в письмо, вложение-обёртка удаляется. Позиции заявок не трогает.
 *
 *   php artisan mail:recover-nested-bodies            # dry-run
 *   php artisan mail:recover-nested-bodies --apply
 */
class MailRecoverNestedBodiesCommand extends Command
{
    protected $signature = 'mail:recover-nested-bodies {--apply : Actually write changes}';

    protected $description = 'Вернуть тело письма, сохранённое вложением из-за неразобранного вложенного multipart';

    public function handle(EmailTextCleanerService $cleaner): int
    {
        $apply = (bool) $this->option('apply');
        $fixed = 0;

        $candidates = EmailAttachment::query()
            ->where('mime_type', 'like', 'text/%')
            ->whereHas('emailMessage', fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('body_plain')->orWhere('body_plain', ''))
                ->where(fn ($q) => $q->whereNull('body_html')->orWhere('body_html', '')))
            ->orderBy('id')
            ->get();

        foreach ($candidates as $att) {
            try {
                $found = NestedMimePart::bodies((string) Storage::disk($att->disk)->get($att->file_path));
            } catch (\Throwable $e) {
                $this->warn("att #{$att->id}: файл не читается — {$e->getMessage()}");

                continue;
            }
            if ($found === null) {
                continue;
            }

            /** @var EmailMessage $email */
            $email = EmailMessage::withHistory()->find($att->email_message_id);
            $plain = trim($found['plain']) !== '' ? $found['plain'] : trim($cleaner->htmlToText($found['html']));
            $fixed++;
            $this->line(sprintf('#%d %s | %s | %s → %d знаков', $email->id, $email->from_email, $email->subject,
                $att->filename, mb_strlen($plain)));

            if ($apply) {
                $email->forceFill([
                    'body_plain' => $plain,
                    'body_html' => $found['html'] !== '' ? EmailHtml::declareUtf8($found['html']) : $email->body_html,
                ])->saveQuietly();
                Storage::disk($att->disk)->delete($att->file_path);
                $att->delete();
            }
        }

        $this->info(($apply ? 'Исправлено' : 'Будет исправлено').": {$fixed}");
        if (! $apply && $fixed > 0) {
            $this->line('Запустите с --apply, чтобы записать.');
        }

        return self::SUCCESS;
    }
}
