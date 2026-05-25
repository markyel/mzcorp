<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use App\Services\Mail\MessagePersister;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill filename'ов attachment'ов из raw_source писем.
 *
 * Кейс: webklex `Attachment::getName()` не справляется с multi-part
 * `filename*0*=`, `filename*1*=`, `filename*2*=` (RFC 2231 continuation)
 * — длинные русские filename'ы приходят как мусор вида
 * «Для заказа по д/?-?.4a?.4c?/.xlsx» (на самом деле «Для заказа по
 * позициям.xlsx»).
 *
 * `mail:redecode-attachment-names` (старый CLI) обрабатывает только
 * encoded-words `=?charset?B?...?=`, но не RFC 2231 continuation.
 * Этот CLI — другой источник: парсит сам raw_source через
 * `MessagePersister::parseRfc2231Filename`.
 *
 * Алгоритм:
 *   1. Найти attachment'ы с подозрительным filename (содержат повторные
 *      `?` или слэши, или короче 4 символов после расширения).
 *   2. Для каждого взять `EmailMessage::raw_source`, парсить MIME-блоки.
 *   3. Сопоставить attachment'ы с блоками по индексу появления
 *      (одинаковый порядок). Для каждого блока вычислить filename.
 *   4. Обновить `email_attachments.filename` если новый отличается и
 *      не пустой.
 *
 * Usage:
 *   php artisan mail:redecode-attachment-names-from-raw --dry-run
 *   php artisan mail:redecode-attachment-names-from-raw --limit=500
 *   php artisan mail:redecode-attachment-names-from-raw --attachment=2384
 */
class MailRedecodeAttachmentNamesFromRawCommand extends Command
{
    protected $signature = 'mail:redecode-attachment-names-from-raw
        {--dry-run : Показать что будет переименовано без записи}
        {--limit=1000 : Максимум email-message обработать за прогон}
        {--attachment= : Точечный режим: ID конкретного attachment\'а}
        {--name-prefix= : Применять только если НОВОЕ имя начинается с подстроки (case-insensitive)}
        {--since-hours= : Применять только если created_at attachment\'а позже now()-N часов}';

    protected $description = 'Backfill email_attachments.filename из raw_source писем (RFC 2231 continuation).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $singleAttachmentId = $this->option('attachment') ? (int) $this->option('attachment') : null;
        $namePrefixes = $this->option('name-prefix')
            ? array_filter(array_map('trim', explode('|', (string) $this->option('name-prefix'))))
            : [];
        $sinceHours = $this->option('since-hours') !== null
            ? (int) $this->option('since-hours')
            : null;
        $cutoff = $sinceHours !== null ? now()->subHours($sinceHours) : null;

        // Кандидаты на ре-декод: filename содержит подозрительные паттерны.
        // Не дёргаем нормальные имена — только явно битые.
        // Признаки:
        //   - 2+ подряд `?` или `?` где должна быть буква
        //   - `/` или `\` в filename (RFC 2231 потерял байты → mojibake)
        //   - суффикс вида `.4a?.4c?` (encoded → decode без encoding)
        $query = EmailAttachment::query()
            ->select(['id', 'email_message_id', 'filename', 'mime_type', 'size_bytes']);

        if ($singleAttachmentId !== null) {
            $query->where('id', $singleAttachmentId);
        } else {
            $query->where(function ($q) {
                $q->where('filename', 'like', '%?%?%')
                  ->orWhere('filename', 'like', '%/%')
                  ->orWhere('filename', 'like', '%\\%');
            });
        }

        // Группируем по email_message_id чтобы взять raw_source один раз.
        $rows = $query->orderBy('email_message_id')->get();
        if ($rows->isEmpty()) {
            $this->info('Битых filename не найдено.');
            return self::SUCCESS;
        }

        $byMessage = $rows->groupBy('email_message_id');
        $this->info(sprintf(
            'Найдено: %d attachment\'ов в %d письмах.',
            $rows->count(),
            $byMessage->count(),
        ));

        $stats = ['ok' => 0, 'unchanged' => 0, 'no_raw' => 0, 'no_match' => 0, 'filtered' => 0];
        $messageCount = 0;

        foreach ($byMessage as $msgId => $atts) {
            if (++$messageCount > $limit) {
                $this->warn("Достигнут лимит --limit={$limit}; остаток пропущен.");
                break;
            }

            $rawSource = DB::table('email_messages')->where('id', $msgId)->value('raw_source');
            if (! is_string($rawSource) || $rawSource === '') {
                $stats['no_raw'] += $atts->count();
                $this->line("  msg#{$msgId}: raw_source пуст — skip ({$atts->count()} att)");
                continue;
            }

            // Парсим все Content-Disposition блоки (multi-line с continuation).
            // RFC 2822 boundary: блок начинается с заглавного «Content-Disposition:»
            // и тянется до следующего header'а / пустой строки.
            $blocks = $this->extractDispositionBlocks($rawSource);
            if (count($blocks) === 0) {
                $stats['no_match'] += $atts->count();
                $this->line("  msg#{$msgId}: ни одного Content-Disposition блока с filename не нашлось — skip");
                continue;
            }

            // Сопоставляем attachment'ы с блоками по порядку. Порядок
            // сохранения в БД совпадает с порядком в письме (MessagePersister
            // обходит attachment'ы в том же порядке что webklex даёт).
            $idx = 0;
            foreach ($atts as $att) {
                if (! isset($blocks[$idx])) {
                    $stats['no_match']++;
                    $this->line(sprintf(
                        "  msg#%d att#%d: блок %d отсутствует — skip",
                        $msgId, $att->id, $idx,
                    ));
                    $idx++;
                    continue;
                }
                $newName = MessagePersister::parseRfc2231Filename($blocks[$idx]);
                $idx++;
                if ($newName === null || $newName === '') {
                    $stats['no_match']++;
                    continue;
                }
                $newName = mb_substr(trim($newName), 0, 255);
                if ($newName === $att->filename) {
                    $stats['unchanged']++;
                    continue;
                }

                // Safety-фильтры (опциональные): применять только если
                //   - newName начинается с одного из --name-prefix (через | разделитель)
                //   - И/ИЛИ attachment свежее --since-hours
                if (!empty($namePrefixes)) {
                    $matched = false;
                    foreach ($namePrefixes as $p) {
                        if ($p !== '' && mb_stripos($newName, $p) === 0) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        $stats['filtered']++;
                        continue;
                    }
                }
                if ($cutoff !== null) {
                    $attRow = EmailAttachment::find($att->id);
                    if (!$attRow || !$attRow->created_at || $attRow->created_at->lt($cutoff)) {
                        $stats['filtered']++;
                        continue;
                    }
                }

                if (! $dry) {
                    EmailAttachment::query()->where('id', $att->id)->update(['filename' => $newName]);
                }
                $stats['ok']++;
                $this->line(sprintf(
                    "  att#%d  %s  →  %s",
                    $att->id,
                    mb_substr((string) $att->filename, 0, 60),
                    mb_substr($newName, 0, 60),
                ));
            }
        }

        $this->line('');
        $this->info(sprintf(
            'Готово: переименовано=%d · без_изменений=%d · нет_raw=%d · нет_блока=%d · отфильтровано=%d',
            $stats['ok'], $stats['unchanged'], $stats['no_raw'], $stats['no_match'], $stats['filtered'],
        ));
        if ($dry) {
            $this->warn('--dry-run: изменения НЕ записаны в БД.');
        }

        return self::SUCCESS;
    }

    /**
     * Извлекает все Content-Disposition блоки из raw email source.
     * Блок начинается с «Content-Disposition:» и тянется до следующего
     * header-name (строка, начинающаяся с letter:) или пустой строки.
     * Если у блока нет filename — пропускаем (это inline image и т.п.).
     *
     * @return list<string>  Каждый элемент — multi-line block как есть из raw_source.
     */
    private function extractDispositionBlocks(string $raw): array
    {
        // Греедным regex'ом ловим «Content-Disposition:...» + все строки
        // продолжения (начинаются с пробела/таба).
        if (! preg_match_all(
            '/^Content-Disposition:[^\r\n]*(?:\r?\n[ \t]+[^\r\n]*)*/mi',
            $raw,
            $matches,
        )) {
            return [];
        }
        $blocks = [];
        foreach ($matches[0] as $block) {
            if (stripos($block, 'filename') !== false) {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }
}
