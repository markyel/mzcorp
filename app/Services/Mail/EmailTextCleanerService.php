<?php

namespace App\Services\Mail;

/**
 * Очистка текста inbound-письма перед AI-парсингом позиций.
 *
 * Source: LazyLift n8n workflow «Flow 1: Email Classification v9.2»,
 * узел `Code: Prepare Parse Input` (JS) — PHP-порт трёх функций:
 *   - extractForwardedContent — изоляция блока «--- Пересылаемое сообщение ---»;
 *   - dequoteText             — снимает маркеры `>` цитирования, режет служебные
 *                              строки («Отправлено из...», «01.01.2026 ... пишет:»);
 *   - removeSignature         — режет подпись после `--` / «С уважением» / «Best regards»,
 *                              но УМНО: если после `--` есть товаро-подобные строки —
 *                              это разделитель перед позициями, не подпись.
 *
 * Цель — отдать GPT только полезный текст. Без этой обвязки парсер галлюцинирует:
 *   · вылавливает фантомные позиции из подписи / реквизитов / mailto-ссылок;
 *   · дублирует позиции из forward'нутого блока поверх позиций самого reply'я.
 *
 * Foundation §«Что переиспользуется» помечает этот сервис как drop-in candidate
 * из LazyLift — здесь именно он, переписан под PHP без зависимостей от n8n.
 */
class EmailTextCleanerService
{
    /**
     * Композитная очистка: выделить forwarded-блок если есть, очистить
     * каждую часть от цитирования и подписи, склеить результат.
     *
     * Возвращает текст готовый для AI-промпта.
     */
    public function cleanInboundReferenceText(string $bodyText): string
    {
        if (trim($bodyText) === '') {
            return '';
        }

        // ВАЖНО: dequote ПЕРВЫМ. Yandex 360 оборачивает forward'ы в цитату:
        //
        //     07.05.2026 9:53, info@... пишет:
        //     > -------- Перенаправленное сообщение --------
        //     > Тема: Заявка на ...
        //     > ...
        //
        // Если применить extractForwardedContent на raw text — regex не сматчится
        // (строка начинается с `>`, не с `-`). Сначала снимаем `>`-префиксы,
        // затем ищем маркер. Это противоположно порядку n8n — там полагались
        // на промпт «игнорируй цитаты», но GPT всё равно парсит позиции из
        // forward'нутых блоков (см. parser-corpus.txt #349 — 9 позиций из
        // нашей же исходящей КП), поэтому здесь физически вырезаем.
        $dequoted = $this->dequoteText($bodyText);

        ['forwarded' => $forwarded, 'original' => $original] = $this->extractForwardedContent($dequoted);

        if ($forwarded !== null) {
            // Forwarded — это, как правило, либо наша же исходящая КП, либо
            // чужая старая переписка. Для парсера позиций — мусор. Не отдаём AI.
            $cleanOriginal = $this->removeSignature($original);

            return $cleanOriginal;
        }

        return $this->removeSignature($dequoted);
    }

    /**
     * Найти блок «--- Пересылаемое сообщение --- ... --- Конец ---»,
     * вернуть отдельно `forwarded` (тело внутри) и `original` (то что до).
     *
     * Ищем разные написания startMarker'а (Yandex шлёт «Пересылаемое сообщение»,
     * но встречаются «Forwarded message» и др.).
     *
     * @return array{forwarded: ?string, original: string}
     */
    public function extractForwardedContent(string $text): array
    {
        if ($text === '') {
            return ['forwarded' => null, 'original' => $text];
        }

        $startPattern = '/^-{4,}\s*(?:Пересылаемое сообщение|Пересланное сообщение|Forwarded message|Перенаправленное сообщение|Original Message)\s*-{0,}\s*$/imu';
        $endPattern   = '/^-{4,}\s*(?:Конец пересылаемого сообщения|End forwarded message)\s*-{0,}\s*$/imu';

        if (! preg_match($startPattern, $text, $startMatch, PREG_OFFSET_CAPTURE)) {
            return ['forwarded' => null, 'original' => $text];
        }

        $startOffset = $startMatch[0][1];
        $startLength = strlen($startMatch[0][0]);

        $afterStart = substr($text, $startOffset + $startLength);
        $lines = preg_split("/\r?\n/", $afterStart) ?: [];

        // Пропустить служебные строки header'а: «От: ...», «Кому: ...», «Subject: ...», «Дата: ...».
        // Лимит — 6 строк, чтобы не съесть тело.
        $bodyStart = 0;
        for ($i = 0, $cap = min(count($lines), 6); $i < $cap; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                $bodyStart = $i + 1;
                break;
            }
            if (preg_match('/^(Кому|To|Тема|Subject|От|From|Дата|Date):?\s/iu', $line)) {
                continue;
            }
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}/', $line)) {
                continue;
            }
            $bodyStart = $i;
            break;
        }

        $fwdBody = implode("\n", array_slice($lines, $bodyStart));

        if (preg_match($endPattern, $fwdBody, $endMatch, PREG_OFFSET_CAPTURE)) {
            $fwdBody = substr($fwdBody, 0, $endMatch[0][1]);
        }

        $beforeFwd = trim(substr($text, 0, $startOffset));

        return [
            'forwarded' => trim($fwdBody),
            'original' => $beforeFwd,
        ];
    }

    /**
     * Снять маркеры цитирования `>` со строк, но СОХРАНИТЬ содержимое.
     * AI сам разбирёт что в цитате — позиция или старый текст диалога.
     *
     * Дополнительно режем строки-шум:
     *   - «01.01.2026 12:34, Иван пишет:»
     *   - «Отправлено из ...»
     *   - mailto:/compose-ссылки.
     */
    public function dequoteText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $lines = preg_split("/\r?\n/", $text) ?: [];
        $result = [];

        foreach ($lines as $line) {
            // Снять `>`/`> >`/`> > >` префикс (с возможными пробелами).
            $dequoted = preg_replace('/^(\s*>)+\s?/u', '', $line) ?? $line;
            $trimmed = trim($dequoted);

            // Атрибуции «12.05.2026 14:32, Иван Иванов <ivan@...> пишет:»
            if ($trimmed !== '' && preg_match('/\d{2}\.\d{2}\.\d{4}.*пишет:\s*$/iu', $trimmed)) {
                continue;
            }
            if ($trimmed !== '' && preg_match('/^\d{2}\.\d{2}\.\d{4}.*от\s+.*:$/iu', $trimmed)) {
                continue;
            }
            if ($trimmed !== '' && preg_match('/отправлено из/iu', $trimmed)) {
                continue;
            }
            if ($trimmed !== '' && preg_match('/\/\/e\.mail\.ru\/compose/iu', $trimmed)) {
                continue;
            }
            if ($trimmed !== '' && preg_match('/^mailto:/iu', $trimmed)) {
                continue;
            }

            $result[] = $dequoted;
        }

        return trim(implode("\n", $result));
    }

    /**
     * Срезать подпись. Маркеры:
     *   - строка ровно `--` или `-- ` (RFC 3676 sig delimiter), но НЕ в первых
     *     3 строках (там это артефакт пересылки/forward'а);
     *   - «С уважением...» (start of line, не в первых 3 строках);
     *   - «Best regards...».
     *
     * Умная защита: если после `--` идёт текст с товарными признаками
     * («5 шт», «10 м», «ARTI-001» и т.п.) — это НЕ подпись, а разделитель
     * перед позициями. В этом случае строку `--` пропускаем, обработку
     * продолжаем.
     */
    public function removeSignature(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $lines = preg_split("/\r?\n/", $text) ?: [];
        $result = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);

            if (($line === '--' || $line === '-- ') && $i > 2) {
                $remaining = implode("\n", array_slice($lines, $i + 1));
                $hasProductLines = preg_match(
                    '/\d+\s*(шт|компл|м\.|п\.м|кг)|[A-Z0-9]{3,}[-][A-Z0-9]+|\bшт\b/iu',
                    $remaining,
                ) === 1;

                if ($hasProductLines) {
                    // Это разделитель перед позициями — пропускаем строку, продолжаем.
                    continue;
                }

                break; // Настоящая подпись — обрезаем.
            }

            if ($i > 2 && preg_match('/^с уважением/iu', $line)) {
                break;
            }
            if ($i > 2 && preg_match('/^best regards/iu', $line)) {
                break;
            }

            $result[] = $lines[$i];
        }

        return trim(implode("\n", $result));
    }
}
