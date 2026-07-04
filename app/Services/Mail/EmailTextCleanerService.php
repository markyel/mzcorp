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
        // Хвост с цитатой НАШЕГО ЖЕ исходящего режем ДО dequote: dequote
        // выбрасывает attribution-строки («… пишет:»), по которым мы находим
        // начало цитаты. Кейс M-2026-5848: LLM достал «новую позицию» M26966
        // из ссылки mylift.ru в нашем процитированном письме.
        $dequoted = $this->dequoteText($this->cutOwnQuotedTail($bodyText));

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
     * Эвристика: body_plain «битый» — пустой, очень короткий ИЛИ выглядит
     * как CSS-мусор (HTML-письма от LazyLift / маркетинговые рассылки часто
     * не имеют plain-alternative, IMAP-парсер либо отдаёт пустоту, либо
     * вытаскивает CSS из `<style>` блока).
     *
     * В таких случаях `parseItemsFromInboundMessage` должен переключиться
     * на `htmlToText(body_html)`.
     */
    /**
     * Есть ли в HTML «структурная» таблица — минимум 2 строки <tr> с >=2
     * cells (<td>/<th>) в каждой. Маркетинговые wrapper-таблицы и
     * single-cell layout-обёртки Outlook этому условию не подходят.
     *
     * Используется парсером чтобы предпочитать htmlToText когда body_plain
     * содержит уже разложенную по столбцам версию таблицы (Outlook
     * экспортирует обычно ОБА варианта — структурный HTML + flatten-plain).
     * Кейс M-2026-1961: body_plain — последовательные строки артикул /
     * описание / qty с пустыми разделителями, body_html — корректная
     * 3×2 таблица.
     */
    public function htmlHasStructuredTable(string $html): bool
    {
        if (trim($html) === '') {
            return false;
        }
        if (! preg_match_all('/<table\b[^>]*>(.*?)<\/table>/is', $html, $tables)) {
            return false;
        }
        foreach ($tables[1] as $tableHtml) {
            if (! preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $tableHtml, $rows)) {
                continue;
            }
            if (count($rows[1]) < 2) {
                continue;
            }
            // Для каждой row — посчитать число cells. Принимаем таблицу,
            // если МИНИМУМ во всех row'ах >= 2 cells (т.е. это не одна
            // wrap-cell на row).
            $minCells = PHP_INT_MAX;
            foreach ($rows[1] as $rowHtml) {
                $count = preg_match_all('/<t[dh]\b/i', $rowHtml);
                $minCells = min($minCells, $count);
            }
            if ($minCells >= 2) {
                return true;
            }
        }
        return false;
    }

    public function bodyPlainLooksBroken(string $bodyPlain): bool
    {
        $trimmed = trim($bodyPlain);

        // Совсем пустой ИЛИ < 20 символов — реальный mail-клиент даже с
        // одной короткой просьбой пишет 20+ символов («Прошу счёт M03309 -
        // 3шт» — 22 chars, не считаем битым).
        if ($trimmed === '' || mb_strlen($trimmed) < 20) {
            return true;
        }

        // CSS-маркеры: `body{...}`, `.class{...}`, `@media`, `font-family:`,
        // `padding:`, `margin:`. Если такого больше 3 фрагментов в первых
        // 1000 символах — это явно CSS, не текст письма.
        $head = mb_substr($trimmed, 0, 1000);
        $cssMarkers = preg_match_all(
            '/(?:\{[^}]{0,80}\}|font-family\s*:|background\s*:|padding\s*:|margin\s*:|@media\b)/i',
            $head,
        );

        return $cssMarkers >= 3;
    }

    /**
     * Конверсия HTML → plain text с сохранением табличной структуры.
     * Source: LazyLift n8n workflow `Code: Prepare Parse Input`, функция
     * htmlToText() — PHP-порт.
     *
     * Ключевое: `</td>` → ` | `, `</tr>` → `\n` сохраняют табличный layout
     * (LazyLift и маркетинговые письма часто содержат таблицу позиций;
     * без этого преобразования AI получит сплошную строку без структуры).
     */
    public function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $text = $html;

        // Таблицы — ПЕРВЫМ: каждая <tr> → одна строка с pipe-разделителем
        // между cells. Делаем regex-callback'ом ДО общего strip_tags, чтобы
        // вложенные <p>/<div>/<br> внутри <td> не вставили \n которые порвут
        // row на несколько строк. Кейс M-2026-1961 (Outlook table 3×2:
        // article | description | qty — раньше выходила «article\n |
        // \ndescription\n | \nqty\n», LLM делил по cells, появлялось 4
        // позиции из 2 реальных).
        $text = preg_replace_callback(
            '/<table\b[^>]*>(.*?)<\/table>/is',
            function ($m) {
                $tableHtml = $m[1];
                $rows = [];
                if (preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $tableHtml, $rs)) {
                    foreach ($rs[1] as $rowHtml) {
                        $cells = [];
                        if (preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $cs)) {
                            foreach ($cs[1] as $cellHtml) {
                                $cell = strip_tags($cellHtml);
                                $cell = html_entity_decode($cell, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                $cell = preg_replace('/\s+/u', ' ', $cell) ?? $cell;
                                $cells[] = trim($cell);
                            }
                        }
                        if (count($cells) > 0) {
                            $rows[] = implode(' | ', $cells);
                        }
                    }
                }
                return $rows === [] ? '' : "\n" . implode("\n", $rows) . "\n";
            },
            $text
        ) ?? $text;

        // Прочие табличные/блочные разделители — на случай если таблица
        // была повреждена или используется без <table> (раньше работали
        // эти три замены). После table-callback'а они применятся только к
        // tail-фрагментам без table-обёртки.
        $text = preg_replace('/<\/th>/iu', ' | ', $text) ?? $text;
        $text = preg_replace('/<\/td>/iu', ' | ', $text) ?? $text;
        $text = preg_replace('/<\/tr>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<br\s*\/?>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<\/div>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<\/p>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<\/li>/iu', "\n", $text) ?? $text;

        // Удалить style/script/head полностью (с содержимым).
        $text = preg_replace('/<style[^>]*>[\s\S]*?<\/style>/iu', '', $text) ?? $text;
        $text = preg_replace('/<script[^>]*>[\s\S]*?<\/script>/iu', '', $text) ?? $text;
        $text = preg_replace('/<head[^>]*>[\s\S]*?<\/head>/iu', '', $text) ?? $text;

        // Все остальные тэги — strip.
        $text = strip_tags($text);

        // HTML-entities.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Лишние пустые строки и пробелы.
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
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
     * Обрезать хвост письма, начиная со строки-атрибуции цитаты НАШЕГО ЖЕ
     * исходящего («понедельник, 29 июня 2026 г. в 15:28 от manager@myzip.ru:»,
     * «29.06.2026 8:55, manager@myzip.ru пишет:», «From: manager@myzip.ru …»).
     *
     * Содержимое такой цитаты — наш собственный текст (КП, ссылки на каталог
     * вида mylift.ru/…code=M26966, вопросы менеджера) плюс более старая
     * переписка. Для парсера позиций это источник ложных «новых позиций»:
     * dequoteText сознательно сохраняет содержимое цитат (клиент может
     * дописать позиции под цитатой), но всё, что ниже цитаты нашего письма,
     * клиентского текста уже не содержит. Домены — services.mail.internal_domains.
     *
     * Если до атрибуции текста нет (весь ответ — одна цитата) — не режем.
     */
    public function cutOwnQuotedTail(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }
        $domains = array_filter(array_map('trim', (array) config('services.mail.internal_domains', [])));
        if ($domains === []) {
            return $text;
        }
        $domainRe = implode('|', array_map(fn ($d) => preg_quote($d, '/'), $domains));

        $lines = preg_split("/\r?\n/", $text) ?: [];
        foreach ($lines as $i => $line) {
            // Возможный `>`-префикс (наша цитата может быть уже внутри чужой).
            $t = trim((string) preg_replace('/^(\s*>)+\s?/u', '', $line));
            if ($t === '' || mb_strlen($t) > 300) {
                continue;
            }
            if (preg_match('/@(' . $domainRe . ')\b/iu', $t) !== 1) {
                continue;
            }
            $isAttribution =
                preg_match('/(пишет|написал\(а\)|написал[аи]?|wrote|writes)\s*:?\s*$/iu', $t) === 1
                || preg_match('/\bот\b[^:]*@(' . $domainRe . ')[^:]*:\s*$/iu', $t) === 1
                || preg_match('/^(From|От)\s*:\s*.*@(' . $domainRe . ')/iu', $t) === 1;
            if (! $isAttribution) {
                continue;
            }
            $head = trim(implode("\n", array_slice($lines, 0, $i)));

            return $head !== '' ? $head : $text;
        }

        return $text;
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
            // mail.ru: «понедельник, 29 июня 2026 г. в 15:28 +08:00 от X <x@y>:»
            if ($trimmed !== '' && preg_match('/^\p{L}+,\s*\d{1,2}\s+\p{L}+\s+\d{4}\s*г?\.?.*\bот\b.*:$/iu', $trimmed)) {
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
