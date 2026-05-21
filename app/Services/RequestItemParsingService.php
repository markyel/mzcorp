<?php

namespace App\Services;

use App\Models\InboundUrlFetch;
use App\Models\RequestItem;
use App\Prompts\Mail\DecideClarificationsPrompt;
use App\Prompts\Mail\ParseItemsPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Mail\EmailTextCleanerService;
use App\Services\Web\InboundUrlFetcherService;
use App\Services\Web\UrlExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class RequestItemParsingService
{
    public function __construct(
        private OpenAIChatService $openai,
        private EmailTextCleanerService $cleaner = new EmailTextCleanerService(),
        private ?UrlExtractor $urlExtractor = null,
        private ?InboundUrlFetcherService $urlFetcher = null,
    ) {}

    /**
     * Единая точка входа: получить список позиций из файла.
     *
     * Для docx/pdf с таблицами внутри фреймов/текстбоксов любой текстовый
     * экстрактор ломает колоночное выравнивание (qty уезжает в отдельный блок,
     * GPT теряет привязку). Поэтому для docx и pdf пробуем Vision-путь —
     * рендерим страницы в PNG и отдаём GPT-4o Vision, он читает таблицу визуально.
     *
     * Для xlsx — сразу текстовый путь (нет фреймов).
     * Если Vision не сработал (нет бинарей, GPT вернул пусто) — fallback на текст.
     *
     * @return array<array{name: string, brand: ?string, article: ?string, qty: float, unit: string, note: ?string}>
     */
    public function parseItemsFromFile(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if (in_array($ext, ['docx', 'pdf'], true)) {
            // Эталонный текст: GPT-Vision путает мелкие символы в артикулах
            // (B↔0, *↔x, ASA↔A5A), а текстовый экстрактор их читает правильно —
            // просто теряет колоночное выравнивание. Передадим Vision и картинку
            // (для структуры qty↔строка), и текст (как словарь точных артикулов/названий).
            $referenceText = null;
            try {
                $referenceText = $this->extractTextFromFile($file);
            } catch (\Throwable $e) {
                Log::info('parseItemsFromFile: no reference text', [
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $images = $ext === 'pdf'
                    ? $this->pdfToImages($file->getRealPath())
                    : $this->docxToImagesViaAbiword($file);

                if (!empty($images)) {
                    $items = $this->parseItemsFromImages($images, $referenceText);
                    if (!empty($items)) {
                        Log::info('Items parsed via Vision', [
                            'file' => $file->getClientOriginalName(),
                            'ext' => $ext,
                            'pages' => count($images),
                            'items_count' => count($items),
                            'has_reference_text' => $referenceText !== null,
                        ]);
                        return $items;
                    }
                }
                Log::info('Vision path returned empty, falling back to text', [
                    'file' => $file->getClientOriginalName(),
                    'ext' => $ext,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Vision path failed, falling back to text', [
                    'file' => $file->getClientOriginalName(),
                    'ext' => $ext,
                    'error' => $e->getMessage(),
                ]);
            }

            // Fallback: если эталонный текст у нас уже есть — используем его напрямую
            if ($referenceText !== null && mb_strlen(trim($referenceText)) >= 10) {
                return $this->parseItemsWithGPT($referenceText);
            }
        }

        $text = $this->extractTextFromFile($file);
        return $this->parseItemsWithGPT($text);
    }

    /**
     * Извлечь текст из загруженного файла.
     */
    public function extractTextFromFile(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        $text = match ($ext) {
            'pdf' => $this->extractFromPdf($file),
            'docx' => $this->extractFromDocx($file),
            'xlsx', 'xls' => $this->extractFromExcel($file),
            default => throw new \RuntimeException("Неподдерживаемый формат: {$ext}"),
        };

        if (mb_strlen(trim($text)) < 10) {
            throw new \RuntimeException('Не удалось извлечь текст из файла');
        }

        return $text;
    }

    /**
     * GPT-парсинг: извлечь список позиций из текста.
     *
     * Текст должен быть УЖЕ очищен через `EmailTextCleanerService` (для inbound)
     * или из структурного файла (для PDF/DOCX/XLSX). System-message содержит
     * полный набор правил v5 (см. App\Prompts\Mail\ParseItemsPrompt).
     *
     * Опциональные subject и fromEmail отдаются OTDЕЛЬНЫМИ секциями user-prompt'а,
     * чтобы GPT не путал «Тему» с источником позиций (см. правило subject в промпте).
     *
     * @return array<array{name: string, brand: ?string, article: ?string, qty: float, unit: string, note: ?string}>
     */
    public function parseItemsWithGPT(
        string $text,
        ?string $subject = null,
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?string $linkedUrlsText = null,
    ): array {
        $userPrompt = $this->buildInboundUserPrompt($text, $subject, $fromEmail, $fromName, $linkedUrlsText);

        $result = $this->openai->chat(
            [
                ['role' => 'system', 'content' => ParseItemsPrompt::systemMessage()],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            config('services.openai.parsing_model'),
            ['response_format' => ['type' => 'json_object'], 'temperature' => 0],
        );

        $parsed = json_decode($result['content'], true);
        $items = $parsed['items'] ?? [];
        $ia = $parsed['invoice_analysis'] ?? null;

        // Полная диагностика raw LLM-ответа парсера: всегда логируем, чтобы
        // отслеживать multi-invoice кейсы и schema compliance промпта.
        // items_brief — article+qty+invoice_index по каждой позиции, этого
        // достаточно чтобы понять что вернул LLM до пост-обработки.
        $itemsBrief = array_map(function ($it) {
            return [
                'article' => $it['article'] ?? null,
                'qty' => $it['qty'] ?? null,
                'invoice_index' => $it['invoice_index'] ?? null,
            ];
        }, $items);
        // warning, а не info — на проде LOG_LEVEL может быть выше info
        // (warning/error), и info-сообщения теряются. Это временная
        // диагностика multi-invoice парсинга, потом можно вернуть на info.
        Log::warning('parseItemsFromInbound: LLM response', [
            'subject_preview' => mb_substr((string) $subject, 0, 60),
            'invoice_analysis' => $ia,
            'items_count' => count($items),
            'items_brief' => $itemsBrief,
        ]);

        return array_map(fn(array $item) => $this->normalizeParsedItem($item), $items);
    }

    /**
     * Собрать user-prompt секциями: ## ОТПРАВИТЕЛЬ / ## ТЕМА / ## ТЕКСТ
     * / ## ИЗВЛЕЧЁННЫЙ ТЕКСТ ИЗ ССЫЛОК (опционально).
     */
    private function buildInboundUserPrompt(
        string $text,
        ?string $subject,
        ?string $fromEmail,
        ?string $fromName,
        ?string $linkedUrlsText = null,
    ): string {
        $parts = [];

        if ($fromEmail || $fromName) {
            $sender = trim(($fromName ?: '') . ' <' . ($fromEmail ?: '') . '>');
            $parts[] = '## ОТПРАВИТЕЛЬ';
            $parts[] = $sender;
            $parts[] = '';
        }

        if ($subject !== null && trim($subject) !== '') {
            $parts[] = '## ТЕМА';
            $parts[] = trim($subject);
            $parts[] = '';
        }

        $parts[] = '## ТЕКСТ';
        $cleanText = trim($text);
        $parts[] = $cleanText !== '' ? $cleanText : '(тело письма пустое)';

        if ($linkedUrlsText !== null && trim($linkedUrlsText) !== '') {
            $parts[] = '';
            $parts[] = '## ИЗВЛЕЧЁННЫЙ ТЕКСТ ИЗ ССЫЛОК';
            $parts[] = trim($linkedUrlsText);
        }

        return implode("\n", $parts);
    }

    /**
     * Vision-парсинг: GPT-4o смотрит на изображения страниц и извлекает позиции.
     * Используется для docx/pdf с фреймовыми таблицами, которые разваливаются
     * при любом текстовом извлечении.
     *
     * Собираем payload вручную чтобы передать detail:high — без этого OpenAI
     * downscale-ит картинки, мелкий шрифт артикулов становится нечитаемым.
     *
     * Если $referenceText передан — кладём его в промпт как "словарь" точных
     * артикулов/названий (их читает текстовый парсер, но колонки qty сломаны).
     *
     * @param array<string> $images — data:image/png;base64,...
     * @return array<array{name: string, brand: ?string, article: ?string, qty: float, unit: string, note: ?string}>
     */
    private function parseItemsFromImages(array $images, ?string $referenceText = null): array
    {
        $images = array_slice($images, 0, 6); // cap 6 страниц
        $prompt = $this->buildItemsParsingPrompt(null, $referenceText);

        $content = [
            ['type' => 'text', 'text' => $prompt],
        ];
        foreach ($images as $img) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $img,
                    'detail' => 'high', // OpenAI не downscale-ит картинку
                ],
            ];
        }

        $result = $this->openai->chat(
            [['role' => 'user', 'content' => $content]],
            config('services.openai.vision_model'),
            [
                'temperature' => 0,
                'max_tokens' => 8192,
                'response_format' => ['type' => 'json_object'],
            ],
        );

        Log::info('parseItemsFromImages: GPT responded', [
            'pages' => count($images),
            'reference_text_chars' => $referenceText !== null ? mb_strlen($referenceText) : 0,
            'finish_reason' => $result['raw']['choices'][0]['finish_reason'] ?? null,
            'usage' => $result['usage'] ?? null,
        ]);

        $raw = $result['content'] ?? '';
        $parsed = json_decode($raw, true);
        $items = $parsed['items'] ?? [];

        return array_map(fn(array $item) => $this->normalizeParsedItem($item), $items);
    }

    /**
     * Нормализация одной позиции перед возвратом в UI/БД.
     * name ≤ 250 символов — ограничение varchar(255) в БД.
     */
    private function normalizeParsedItem(array $item): array
    {
        // Phase 2.0+ : `category` (coarse, 19 значений) идёт от ParseItemsPrompt.
        // Валидируем против CoarseCategories::ALL — мусорные / новые значения
        // ставим в null, чтобы CategoryRefinementService уехал в fallback,
        // а не упал на whereHas с неизвестным coarse.
        $category = !empty($item['category']) ? trim($item['category']) : null;
        if ($category !== null && ! \App\Constants\CoarseCategories::isValid($category)) {
            $category = null;
        }

        // invoice_index — 1-based индекс счёта/КП к которому относится позиция,
        // когда клиент в одном письме просит несколько счетов (см. multi-invoice
        // секцию в ParseItemsPrompt). LLM проставляет 1/2/3... для каждой
        // позиции внутри своего блока. Default = 1 (один счёт).
        // Используется в dedupeWithinList: дубль article+qty с разным
        // invoice_index сохраняется, с одинаковым — режется.
        $invoiceIndex = isset($item['invoice_index']) && is_numeric($item['invoice_index'])
            ? max(1, (int) $item['invoice_index'])
            : 1;

        // Мерные позиции: length / length_unit от LLM-парсера приходят
        // структурированно (ParseItemsPrompt v6). Раньше вторая размерность
        // лежала текстом в note → supplier_note. Теперь:
        //   length      → parsed_length (decimal:3)
        //   length_unit → parsed_length_unit (string)
        // Защита от мусора: length должен быть положительное число, иначе null.
        $rawLength = $item['length'] ?? null;
        $length = (is_numeric($rawLength) && (float) $rawLength > 0)
            ? (float) $rawLength
            : null;
        $rawLengthUnit = $item['length_unit'] ?? null;
        $lengthUnit = ($length !== null && is_string($rawLengthUnit) && trim($rawLengthUnit) !== '')
            ? trim($rawLengthUnit)
            : null;
        // Если length есть, но length_unit пустой — сбрасываем length тоже,
        // не сохраняем «43.56» без единицы измерения.
        if ($lengthUnit === null) {
            $length = null;
        }

        return [
            'name' => mb_substr(trim($item['name'] ?? ''), 0, 250),
            'brand' => !empty($item['brand']) ? trim($item['brand']) : null,
            'article' => !empty($item['article']) ? trim($item['article']) : null,
            'qty' => max(0.01, (float) ($item['qty'] ?? 1)),
            'unit' => trim($item['unit'] ?? 'шт.'),
            'length' => $length,
            'length_unit' => $lengthUnit,
            'category' => $category,
            'note' => !empty($item['note']) ? trim($item['note']) : null,
            'invoice_index' => $invoiceIndex,
            // Phase 2: для не-vision источников (text/документ) остаётся null;
            // parseItemsFromPhotoMarkings выставит позже через mapping
            // image_index → email_attachments.id.
            'email_attachment_id' => isset($item['email_attachment_id']) && is_int($item['email_attachment_id'])
                ? $item['email_attachment_id']
                : null,
        ];
    }

    /**
     * Общий промпт парсинга позиций. Если $text передан — работаем с текстом
     * (text-chat), иначе режим Vision — GPT сам смотрит на таблицу на изображении.
     * $referenceText (только для Vision) — сырой текст документа как словарь
     * точных артикулов/названий (Vision читает мелкий шрифт ненадёжно).
     */
    private function buildItemsParsingPrompt(?string $text = null, ?string $referenceText = null): string
    {
        if ($text !== null) {
            return "Ты — парсер заявок на запасные части лифтового и эскалаторного оборудования.\n"
                . "Извлеки все товарные позиции из текста документа.\n\n"
                . "ТЕКСТ:\n{$text}\n\n"
                . $this->promptFieldsAndRules();
        }

        $intro = "Ты — парсер заявок на запасные части лифтового и эскалаторного оборудования.\n"
            . "На изображении (1 или несколько страниц) — таблица заявки с колонками\n"
            . "«№», «Наименование», «Кол-во», «Ед. изм.». Колонка «Кол-во» обычно справа,\n"
            . "часто с серой/цветной заливкой — это НЕ артикул, а количество штук.\n"
            . "Извлеки ВСЕ строки таблицы как отдельные позиции (не пропускай ни одной).\n\n";

        if ($referenceText !== null && mb_strlen(trim($referenceText)) >= 10) {
            // Обрезаем, чтобы не раздувать токены (типичная заявка 5-8 кБ).
            $ref = mb_substr(trim($referenceText), 0, 12000);
            $intro .= "ОПОРНЫЙ ТЕКСТ (извлечён из исходного файла программно; названия и\n"
                . "артикулы в нём ТОЧНЫЕ посимвольно, но колонки qty/ед. могут быть\n"
                . "перемешаны — не опирайся на порядок qty, бери его с изображения):\n"
                . "```\n{$ref}\n```\n\n"
                . "Правила: названия и артикулы копируй ДОСЛОВНО из опорного текста.\n"
                . "Если на изображении видишь слово или артикул, которого нет в опорном\n"
                . "тексте — это ошибка визуального распознавания, используй вариант из текста.\n"
                . "Количество (qty), единицу измерения и порядок строк бери с изображения.\n\n";
        } else {
            $intro .= "Названия и артикулы копируй ПОСИМВОЛЬНО с изображения:\n"
                . "• не додумывай буквы/цифры; если символ не читается — оставь как видишь\n"
                . "• не путай похожие символы: B↔8↔0, O↔0, I↔1, латиницу и кириллицу\n"
                . "  (С/C, А/A, В/B, О/O, Р/P, Н/H, Х/X, М/M, Т/T, К/K, Е/E)\n"
                . "• не заменяй '*' на 'x', 'X' на '×', дефис на тире\n"
                . "• если артикул разорван переносом строки — склей без пробелов\n\n";
        }

        $intro .= "КОЛОНКА «Кол-во» на таблицах с многострочными названиями:\n"
            . "• для каждой позиции qty — это число, стоящее напротив её ПЕРВОЙ строки названия\n"
            . "  (на уровне начала строки «№» в первой колонке)\n"
            . "• если qty выглядит смещённым вверх/вниз относительно названия — проверь,\n"
            . "  не относится ли оно к соседней позиции\n"
            . "• номер позиции в первой колонке — это НЕ qty\n\n";

        return $intro . $this->promptFieldsAndRules();
    }

    private function promptFieldsAndRules(): string
    {
        return <<<'PROMPT'
═══ ПОЛЯ ═══

- name: ПОЛНОЕ и САМОДОСТАТОЧНОЕ название — тип + бренд + ключевые параметры,
  без которых нельзя подобрать товар (размеры, мощность, серия, модель и т.п.).
  ✅ "Порог ДШ Sigma 1600×105×25мм телескопический"
  ✅ "Двигатель Dunkermotoren GR 63X55 24В 70Вт"
  ✅ "Шкив канатоведущий 550х4х12 для лебедки Montanari (КМЗ), конус 82х72 мм"
  ❌ "Порог ДШ Sigma" (нет размеров)
  ❌ "Шкив (наружный диаметр.../число канатов.../...)" (дубли пояснений)
  Максимум 200 символов. БЕЗ дублирования. БЕЗ пояснений в скобках к числовым параметрам.
- brand: марка лифта/эскалатора или производитель детали (Siemens, Otis, Sigma, Donghua...). Если нет — null.
- article: артикул/код товара ДОСЛОВНО (иначе null). Альтернативные артикулы в одном поле через ", ".
- qty: количество — ЧИСЛО из колонки «Кол-во». Если в документе явно число — бери его. qty=1 только если в документе явно стоит 1 или количество не указано вообще.
- unit: единица измерения (шт., компл., м., п.м., кг, л). Если в заявке указано "компл.", "м", "кг" — используй, НЕ заменяй на "шт.".
- note: СТРОГО вторая физическая размерность на единицу, когда qty ≠ физическая длина/масса.
  Формат: "X [единица] каждый/каждая" или "на X [единица]".
  ✅ "25.2 м каждый", "113 м каждый", "на 67 ступеней", "по 10кг"
  ❌ артикулы, серии (ARES, SCE, FBA24350AM2), бренды, модели — это в article/brand/name
  ❌ требования совместимости, уточнения — если нет размерности, note=null
  Если второй размерности нет — null.

═══ ПРАВИЛА ПАРСИНГА ═══

1. Каждая строка таблицы / пункт списка = отдельная позиция. НЕ объединяй похожие.
2. Сохраняй оригинальные названия и артикулы ДОСЛОВНО.
3. Пропускай заголовки таблиц, итоги, пустые строки, реквизиты.
4. Если бренд упомянут в названии — вынеси в brand.
5. Если артикул и название в одной ячейке — раздели.

6. ПОВТОРЯЮЩИЕСЯ ПОЗИЦИИ — не дублируй, определи правильное количество:
   а) ОДИНАКОВОЕ количество при каждом повторении → qty = число повторений,
      длина/вес → в note. Пример: "Ремень 113м" × 3 → qty=3 шт., note="113 м каждый".
      ❌ qty=339 (не суммируй).
   б) РАЗНОЕ количество → бери последнее (клиент исправил).
   в) Товар с двумя размерностями (канат/ремень/поручень/цепь/труба/кабель/профиль
      × число штук по X м) → qty=штуки, unit="шт.", note="X м каждый".

7. ПЕРЕЧИСЛЕНИЕ ВАРИАНТОВ через "каждого"/"каждый"/"по X шт." — отдельная позиция
   на каждый вариант. Технические параметры, общие для всех — в name каждой.
   Пример: «чиклет с цифрами 1, 2, 3, каждого по 1 шт» → 3 позиции.
   Исключение: перечисление ПАРАМЕТРОВ одного товара («ремень длиной 100м и шириной 25мм») → 1 позиция.

═══ ФОРМАТ ОТВЕТА ═══

Строго JSON без markdown:
{"items": [{"name": "...", "brand": "...", "article": "...", "qty": 1, "unit": "шт.", "note": null}]}
PROMPT;
    }

    /**
     * docx → abiword PDF → pdftoppm PNG → массив base64 изображений.
     * null/[] если abiword/pdftoppm недоступны или конвертация провалилась.
     *
     * @return array<string> data:image/png;base64,... (или пустой массив)
     */
    private function docxToImagesViaAbiword(UploadedFile $file): array
    {
        if (!function_exists('exec')
            || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            return [];
        }

        $abiword = $this->findBinary('abiword', ['/usr/bin/abiword', '/usr/local/bin/abiword']);
        if (!$abiword) {
            return [];
        }
        $verify = [];
        $vCode = -1;
        @exec(escapeshellcmd($abiword) . ' --version 2>&1', $verify, $vCode);
        if ($vCode !== 0) {
            return [];
        }

        $tmpDir = sys_get_temp_dir() . '/docx_vision_' . uniqid();
        if (!@mkdir($tmpDir, 0755, true)) {
            return [];
        }

        $cleanup = function () use ($tmpDir) {
            foreach (@glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        };

        try {
            $pdfPath = $tmpDir . '/out.pdf';
            $cmd = sprintf(
                'HOME=%s %s --to=pdf --to-name=%s %s 2>&1',
                escapeshellarg($tmpDir),
                escapeshellarg($abiword),
                escapeshellarg($pdfPath),
                escapeshellarg($file->getRealPath()),
            );
            $out = [];
            $exit = -1;
            exec($cmd, $out, $exit);

            if ($exit !== 0 || !file_exists($pdfPath) || filesize($pdfPath) < 100) {
                Log::info('docxToImagesViaAbiword: abiword failed', [
                    'exit' => $exit,
                    'stdout_tail' => implode("\n", array_slice($out, -5)),
                    'pdf_exists' => file_exists($pdfPath),
                ]);
                return [];
            }

            return $this->pdfToImages($pdfPath);
        } finally {
            $cleanup();
        }
    }

    /**
     * PDF → PNG-страницы (pdftoppm 150 dpi) → base64 data-URLs.
     *
     * @return array<string>
     */
    private function pdfToImages(string $pdfPath): array
    {
        if (!function_exists('exec')
            || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            return [];
        }

        $pdftoppm = $this->findBinary('pdftoppm', ['/usr/bin/pdftoppm', '/usr/local/bin/pdftoppm']);
        if (!$pdftoppm) {
            return [];
        }

        $tmpDir = sys_get_temp_dir() . '/pdf_png_' . uniqid();
        if (!@mkdir($tmpDir, 0755, true)) {
            return [];
        }

        $cleanup = function () use ($tmpDir) {
            foreach (@glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        };

        try {
            $prefix = $tmpDir . '/page';
            // 200 DPI + detail:high в OpenAI image_url. OpenAI всё равно ресайзит
            // картинку до 2048 по длинной стороне, тайлы 512×512. A4@200 ≈ 1654×2338,
            // укладывается без потерь. 250+ DPI не даёт преимуществ — лишь раздувает
            // base64 и задержку.
            $cmd = sprintf(
                '%s -png -r 200 %s %s 2>&1',
                escapeshellarg($pdftoppm),
                escapeshellarg($pdfPath),
                escapeshellarg($prefix),
            );
            $out = [];
            $exit = -1;
            exec($cmd, $out, $exit);

            if ($exit !== 0) {
                Log::info('pdfToImages: pdftoppm failed', [
                    'exit' => $exit,
                    'stdout_tail' => implode("\n", array_slice($out, -5)),
                ]);
                return [];
            }

            $files = glob($tmpDir . '/page-*.png') ?: [];
            sort($files);

            $images = [];
            foreach ($files as $png) {
                $data = @file_get_contents($png);
                if ($data === false) {
                    continue;
                }
                $images[] = 'data:image/png;base64,' . base64_encode($data);
            }
            return $images;
        } finally {
            $cleanup();
        }
    }

    /**
     * Распарсить позиции из inbound-письма заказчика (фото на шильдике, pdf,
     * docx/xlsx, либо просто текст в теле). Используется ручной кнопкой
     * «Найти позиции в письме» на вкладке «Письмо» — fallback когда n8n
     * по своему промпту не извлёк позиции автоматически.
     *
     * Стратегия:
     *  1) Изображения (jpg/png) → один Vision-call с opting текстом
     *     (subject + body_plain) и ПРОМПТОМ ДЛЯ ШИЛЬДИКОВ (НЕ табличный).
     *  2) Структурные файлы (pdf/docx/xlsx) → каждый через parseItemsFromFile.
     *  3) Если из аттачментов ничего не извлекли — пробуем тело письма.
     *  4) Дедуп внутри объединённого списка по артикулу/имени.
     *
     * @return array<array{name: string, brand: ?string, article: ?string, qty: float, unit: string, note: ?string}>
     */
    public function parseItemsFromInboundMessage(\App\Models\EmailMessage $message): array
    {
        // EmailMessage->attachments() — HasMany, см. App\Models\EmailMessage.
        $attachments = $message->attachments;

        // Выбор источника тела письма:
        //   - Обычно body_plain содержит plain-text alternative и его
        //     достаточно (после dequote / removeSignature).
        //   - НО HTML-only письма (LazyLift `order@liftway.store`,
        //     маркетинговые рассылки, корп-уведомления) часто не имеют
        //     plain-alternative — IMAP-парсер либо отдаёт пустоту, либо
        //     вытаскивает CSS из `<style>` блока. В этом случае реальные
        //     позиции лежат в body_html (table > tr > td) и нужен
        //     htmlToText с сохранением табличной структуры.
        $plain = (string) ($message->body_plain ?? '');
        $html  = (string) ($message->body_html ?? '');

        $rawBody = $plain;
        if ($this->cleaner->bodyPlainLooksBroken($plain) && trim($html) !== '') {
            $rawBody = $this->cleaner->htmlToText($html);
        }

        // Чистим body перед AI: режем подпись, снимаем маркеры цитирования,
        // изолируем блок «--- Пересланное сообщение ---». Без этого парсер
        // вылавливал фантомные позиции из forward'нутых блоков и подписей
        // (см. parser-corpus.txt, кейсы #349, #357).
        $cleanedBody = $this->cleaner->cleanInboundReferenceText($rawBody);

        // Шаг 0: вытащить URL'ы из тела (cleaned plain + raw html) и
        // синхронно сходить за их выжимками. Cache в `inbound_url_fetches`
        // делает повторные письма с тем же URL бесплатными.
        $linkedUrlsText = $this->fetchLinkedUrlsForPrompt($cleanedBody, $html, "msg#{$message->id}");

        return $this->parseItemsFromInboundContent(
            $cleanedBody,
            $attachments,
            "msg#{$message->id}",
            (string) ($message->subject ?? ''),
            (string) ($message->from_email ?? ''),
            (string) ($message->from_name ?? ''),
            $linkedUrlsText,
        );
    }

    /**
     * Извлечь URL'ы из тела письма и сфетчить их выжимки. Результат —
     * строка-секция для промпта (или null если ничего полезного не нашли).
     *
     * Fail-soft: любая ошибка фетчера логируется и возвращает null —
     * text-only парсер всё равно отработает по основному телу.
     */
    private function fetchLinkedUrlsForPrompt(?string $cleanedPlain, ?string $rawHtml, string $sourceTag): ?string
    {
        if (! config('services.web_fetch.enabled', true)) {
            return null;
        }
        if ($this->urlExtractor === null || $this->urlFetcher === null) {
            return null;
        }

        $maxUrls = (int) config('services.web_fetch.max_urls_per_email', 10);
        $urls = $this->urlExtractor->extract($cleanedPlain, $rawHtml, $maxUrls);
        if (empty($urls)) {
            return null;
        }

        try {
            $results = $this->urlFetcher->fetchMany($urls);
        } catch (\Throwable $e) {
            Log::warning('parseItemsFromInboundMessage: url fetch failed', [
                'source' => $sourceTag,
                'urls' => count($urls),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $blocks = [];
        foreach ($results as $row) {
            /** @var InboundUrlFetch $row */
            if (! $row->isSuccessful() || $row->extracted_text === null || trim($row->extracted_text) === '') {
                continue;
            }
            $blocks[] = "### {$row->url}\n" . trim($row->extracted_text);
        }

        if (empty($blocks)) {
            Log::info('parseItemsFromInboundMessage: url fetch — no usable content', [
                'source' => $sourceTag,
                'urls' => count($urls),
                'statuses' => array_count_values(array_map(fn ($r) => $r->status, $results)),
            ]);

            return null;
        }

        Log::info('parseItemsFromInboundMessage: url fetch — ok', [
            'source' => $sourceTag,
            'urls' => count($urls),
            'usable' => count($blocks),
        ]);

        return implode("\n\n", $blocks);
    }

    /**
     * Общая логика для inbound EmailMessage.
     *  1) Фото → Vision с photo-marking промптом и опорным текстом.
     *  2) Структурные (pdf/docx/xlsx) → parseItemsFromFile поштучно.
     *  3) Если из вложений пусто — текст письма через parseItemsWithGPT.
     *  4) Дедуп.
     *
     * @param \Illuminate\Support\Collection<\App\Models\EmailAttachment> $attachments
     * @return array<array{name: string, brand: ?string, article: ?string, qty: float, unit: string, note: ?string}>
     */
    private function parseItemsFromInboundContent(
        string $rawReferenceText,
        \Illuminate\Support\Collection $attachments,
        string $sourceTag,
        string $subject = '',
        string $fromEmail = '',
        string $fromName = '',
        ?string $linkedUrlsText = null,
    ): array {
        $referenceText = trim($rawReferenceText);
        if (mb_strlen($referenceText) < 10) {
            $referenceText = null;
        }

        $items = [];

        // 1) Изображения — Vision с photo-marking промптом.
        // OpenAI vision не поддерживает HEIC/HEIF — пропускаем.
        $imageAttachments = $attachments->filter(function ($a) {
            $mime = strtolower((string) $a->mime_type);
            return str_starts_with($mime, 'image/')
                && !in_array($mime, ['image/heic', 'image/heif'], true);
        });

        if ($imageAttachments->isNotEmpty()) {
            $images = [];
            // Phase 2: id'ы аттачментов строго в том же порядке, что и data:URI'ы
            // в $images. Если какой-то аттачмент не прочитался — он пропускается
            // в ОБА массива, чтобы маппинг image_index не съехал.
            $attachmentIds = [];
            foreach ($imageAttachments as $att) {
                try {
                    $content = \Illuminate\Support\Facades\Storage::disk($att->disk)->get($att->file_path);
                    if ($content === null) {
                        continue;
                    }
                    $mime = $att->mime_type ?: 'image/jpeg';
                    $images[] = "data:{$mime};base64," . base64_encode($content);
                    $attachmentIds[] = (int) $att->id;
                } catch (\Throwable $e) {
                    Log::warning('parseItemsFromInboundContent: image read failed', [
                        'source' => $sourceTag,
                        'attachment_id' => $att->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            if (!empty($images)) {
                try {
                    $items = array_merge(
                        $items,
                        $this->parseItemsFromPhotoMarkings($images, $referenceText, $attachmentIds),
                    );
                } catch (\Throwable $e) {
                    Log::error('parseItemsFromInboundContent: vision parse failed', [
                        'source' => $sourceTag,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 2) Структурные файлы — каждый отдельно через parseItemsFromFile.
        $structuredAttachments = $attachments->filter(function ($a) {
            return preg_match('/\.(pdf|docx|xlsx|xls)$/i', (string) $a->filename) === 1;
        });

        foreach ($structuredAttachments as $att) {
            try {
                $absolutePath = \Illuminate\Support\Facades\Storage::disk($att->disk)->path($att->file_path);
                if (!file_exists($absolutePath)) {
                    continue;
                }
                // test=true разрешает обернуть произвольный файл в UploadedFile —
                // обходит is_uploaded_file проверку.
                $upload = new UploadedFile(
                    $absolutePath,
                    $att->filename ?? basename($absolutePath),
                    $att->mime_type,
                    null,
                    true,
                );
                $items = array_merge($items, $this->parseItemsFromFile($upload));
            } catch (\Throwable $e) {
                Log::error('parseItemsFromInboundContent: structured parse failed', [
                    'source' => $sourceTag,
                    'attachment_id' => $att->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3) Если из аттачментов пусто — пробуем тело письма.
        // Парсер получит body отдельно от subject и отправителя через секции
        // ## ОТПРАВИТЕЛЬ / ## ТЕМА / ## ТЕКСТ — без этого GPT путал тему
        // («Лифтовые Решения Ремни 607») с описанием товара и галлюцинировал.
        // Запускаем text-only парсер даже если cleaned body пустой, но subject
        // есть — пусть GPT решает (по новому правилу subject-only — items: []).
        // Text-only путь активируем не только когда $items пуст, но и когда у нас
        // есть сфетченные ссылки — они могут добавить позиции, не пересекающиеся
        // с тем, что извлечено из вложений (например, клиент прислал фото одной
        // запчасти и ссылку на каталог второй).
        $shouldTryText = ($referenceText !== null || trim($subject) !== '' || $linkedUrlsText !== null)
            && (empty($items) || $linkedUrlsText !== null);
        if ($shouldTryText) {
            try {
                $items = array_merge(
                    $items,
                    $this->parseItemsWithGPT(
                        $referenceText ?? '',
                        $subject !== '' ? $subject : null,
                        $fromEmail !== '' ? $fromEmail : null,
                        $fromName !== '' ? $fromName : null,
                        $linkedUrlsText,
                    ),
                );
            } catch (\Throwable $e) {
                Log::warning('parseItemsFromInboundContent: text-only parse failed', [
                    'source' => $sourceTag,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Диагностика для multi-invoice кейсов: какой путь набрал какие items.
        Log::warning('parseItemsFromInboundContent: pipeline summary', [
            'source' => $sourceTag,
            'subject_preview' => mb_substr($subject, 0, 60),
            'images_count' => $imageAttachments->count(),
            'structured_count' => $structuredAttachments->count(),
            'tried_text' => $shouldTryText,
            'items_before_dedup' => count($items),
            'items_articles' => array_map(
                fn ($it) => ($it['article'] ?? '?') . '/q' . ($it['qty'] ?? '?') . '/inv' . ($it['invoice_index'] ?? '?'),
                $items,
            ),
        ]);

        // 4) Дедуп с уважением к invoice_index. Ключ = article + qty +
        //    invoice_index. Multi-invoice кейсы (M33374 в счёте 1 и счёте 2
        //    оба с qty=2) сохраняются — разные invoice_index. Реальные
        //    дубли (photo+text дают одну позицию) — режутся (одинаковый
        //    invoice_index, дефолт 1).
        return $this->dedupeWithinList($items);
    }

    /**
     * Vision-парсинг ФОТО маркировки/шильдика товара (НЕ табличного документа).
     * Промпт другой: ожидаем фото детали в руке/на полке/в коробке/инфо-табличку
     * с артикулом и брендом. qty обычно не виден на фото — берём из reference text
     * (тело письма «нужны 3 комплекта») или null.
     *
     * Phase 2: дополнительно резолвим `image_attachment_id` — Vision возвращает
     * `image_index` (0..N-1), мапим в id переданных EmailAttachment в том же
     * порядке, что и data:URI'ы. Если index out-of-range / null — оставляем
     * email_attachment_id = null (UI покажет дефолтную заглушку).
     *
     * @param array<string> $images data:image/...;base64,...
     * @param list<int|null> $attachmentIds id из email_attachments в порядке $images
     * @return array<array{name: string, brand: ?string, article: ?string, qty: float, unit: string, note: ?string, email_attachment_id: ?int}>
     */
    private function parseItemsFromPhotoMarkings(
        array $images,
        ?string $referenceText = null,
        array $attachmentIds = [],
    ): array {
        // Срезаем оба массива симметрично, чтобы маппинг index→id не съехал.
        $images = array_slice($images, 0, 8);
        $attachmentIds = array_slice($attachmentIds, 0, 8);

        $intro = "Ты — парсер заявок на запчасти лифтового оборудования.\n"
            . "На изображениях — ФОТО товара (шильдик, маркировка, бирка, упаковка,\n"
            . "распечатка с артикулом). Это НЕ таблица заявки. Извлеки позиции,\n"
            . "которые заказчик хочет купить.\n\n";

        if ($referenceText !== null && mb_strlen(trim($referenceText)) >= 5) {
            $ref = mb_substr(trim($referenceText), 0, 4000);
            $intro .= "ТЕКСТ ПИСЬМА ЗАКАЗЧИКА (subject + body):\n```\n{$ref}\n```\n\n"
                . "В тексте может быть указано количество («нужно 3 комплекта», «5 шт»).\n"
                . "Если qty явно указан в тексте — используй его. Если в тексте нет —\n"
                . "qty=1 (одна штука по умолчанию для фото шильдика).\n\n"
                . "═══ ПРИОРИТЕТ ТЕКСТА НАД ФОТО ═══\n"
                . "Если в тексте письма ЕСТЬ НУМЕРОВАННЫЙ список позиций (строки\n"
                . "начинаются с «1.», «2.», «1)», «- » с номерами и т.п.) — этот\n"
                . "список AUTHORITATIVE: он определяет СКОЛЬКО позиций и КАКИЕ.\n\n"
                . "ОБЯЗАТЕЛЬНО:\n"
                . "  • Верни ОТДЕЛЬНУЮ позицию на КАЖДЫЙ пункт текстового списка,\n"
                . "    даже если на фото для неё нет соответствия (тогда article=null,\n"
                . "    brand=null, name из текста, qty из текста).\n"
                . "  • НЕ объединяй два пункта текста в одну позицию только потому,\n"
                . "    что их фото похожи. Пример: «1. Отводка Левая» + «2. Отводка\n"
                . "    Правая» — это РАЗНЫЕ позиции, даже если фото одинаковы. То же\n"
                . "    с парами «Верхний/Нижний», «Внутренний/Внешний», «M/F»,\n"
                . "    «Серый/Чёрный», «без подсветки/с подсветкой».\n"
                . "  • НЕ создавай позиции, которых нет в тексте, на основании\n"
                . "    «лишних» фото (пакетный снимок, логотип, упаковка).\n"
                . "  • Подбирай каждому пункту текста ОДНО фото (то, на котором видна\n"
                . "    подходящая маркировка). Остальные фото к этой позиции\n"
                . "    игнорируй для image_index.\n\n"
                . "Если в тексте нумерованного списка нет — работаем по фото,\n"
                . "количество позиций = количеству уникальных товаров на фото.\n\n";
        } else {
            $intro .= "Текст письма пустой/недоступен. qty=1 по умолчанию для каждого\n"
                . "уникального товара на фото.\n\n";
        }

        $intro .= "ПРАВИЛА ИЗВЛЕЧЕНИЯ:\n"
            . "• артикул — это ЛЮБОЙ уникальный буквенно-цифровой код товара на\n"
            . "  шильдике/маркировке/штрих-коде/наклейке. Активно ищи его в полях\n"
            . "  с подписями: PRODUCT CODE / CODE / ART / ARTICLE / артикул / арт. /\n"
            . "  PART NO / P/N / TYPE / TYP / Modell / Model / REF / ITEM / № п/п /\n"
            . "  S/N (только если нет других кодов) и т.п. Если подписи нет, но\n"
            . "  возле логотипа бренда / на основной строке шильдика есть компактный\n"
            . "  буквенно-цифровой код 5-20 символов — это тоже артикул.\n"
            . "• ПРИМЕРЫ артикулов разных производителей:\n"
            . "    KONE   — KM897294, KM802859G02, KM\\d+ (часто с .расширением)\n"
            . "    OTIS   — ZAA622Y1, GBA26800S2, GAA/DAA + цифры\n"
            . "    Schindler — ID.NR. 591740, 59340500\n"
            . "    Sigma  — DAA250JR1, SAS-XXX\n"
            . "    Wittur — WL.\\d+, A-\\d+\n"
            . "    Schneider/Telemecanique — LAD/LC1/CAD/A-prefix (A013250)\n"
            . "    ЩЛЗ    — ЩЛЗ-\\d+, ЛП-\\d+\n"
            . "  Эти паттерны — НЕ исчерпывающий список, для других брендов\n"
            . "  применяй ту же логику «код возле логотипа/PRODUCT CODE».\n"
            . "• артикул копируй ПОСИМВОЛЬНО как видишь (не путай B↔8↔0, O↔0, I↔1,\n"
            . "  кириллицу/латиницу С/C, А/A, В/B, Х/X, Р/P, Н/H). Включай точки,\n"
            . "  дефисы, слэши если они часть кода.\n"
            . "• Если на шильдике несколько кодов (PRODUCT CODE и BATCH/SERIAL/DATE)\n"
            . "  — артикул это тот код, что под подписью PRODUCT CODE / ART / TYPE.\n"
            . "  S/N и серийники — НЕ артикул (они уникальны для каждой единицы).\n"
            . "• бренд (поле `brand`) — производитель ИМЕННО ЭТОЙ ДЕТАЛИ.\n"
            . "  Источники по приоритету (НЕ ВЫДУМЫВАЙ):\n"
            . "    1) Логотип/надпись на самом шильдике детали (бренд возле\n"
            . "       логотипа, в углу шильдика, под подписью MAKER/MFR/Made by).\n"
            . "    2) OEM-fallback от ШАПКИ ГРУППЫ в тексте письма. Если в\n"
            . "       тексте есть преамбула «для Лифт пассажирский Schindler\n"
            . "       №7909814 — кнопка вызова, масленка...» — это шапка, и\n"
            . "       для каждой позиции под ней brand=марка лифта (Schindler)\n"
            . "       если на её шильдике явного бренда нет. Шапки бывают:\n"
            . "       «для Лифт … KONE 4000R», «1. Лифт OTIS 2000R, зав. № X:»,\n"
            . "       «по 2-му лифту Sigma», «эскалатор OTIS NCE 9500, секция 2».\n"
            . "       Шапка действует на ВСЕ позиции под ней до следующей шапки/\n"
            . "       конца письма.\n"
            . "    3) Если ни на шильдике, ни в шапке группы нет — brand=null.\n"
            . "  Категорически ЗАПРЕЩЕНО:\n"
            . "    - подставлять бренд на основе своих общих знаний о том, какой\n"
            . "      производитель типичен для этой категории детали, региона или\n"
            . "      типа клиента. Любой бренд должен иметь явный источник:\n"
            . "      логотип/надпись на шильдике, текст позиции, или шапку группы;\n"
            . "    - использовать как brand клиента/отправителя/домен email;\n"
            . "    - дописывать к бренду расшифровку (полное наименование, страну).\n"
            . "  null ВСЕГДА лучше, чем выдуманное значение.\n"
            . "• name — самодостаточное описание (тип + бренд + ключевые параметры).\n"
            . "  Если на шильдике нет описания типа («плата управления», «датчик»,\n"
            . "  «реле») — используй артикул + бренд («Плата OTIS GBA26800S2»).\n"
            . "• если несколько разных шильдиков → отдельная позиция на каждый\n"
            . "• если один и тот же товар сфотографирован с РАЗНЫХ ракурсов — это\n"
            . "  ОДНА позиция, не дублируй; ИСКЛЮЧЕНИЕ: текст явно перечисляет\n"
            . "  эти варианты как разные позиции (см. правило приоритета текста)\n"
            . "• если на фото видна посторонняя инфо (логотип компании БЕЗ артикула,\n"
            . "  реклама, чек, штрих-код БЕЗ читаемых цифр) — игнорируй.\n"
            . "  НО: если штрих-код подписан цифрами/буквами под линиями — это\n"
            . "  валидный артикул, бери его.\n\n"
            . "═══ M-SKU ВНУТРЕННИЙ КОД РЯДОМ С OEM ═══\n"
            . "Если в таблице/тексте рядом с OEM-артикулом (XAA332DS2, RUS0538154,\n"
            . "GBA21230F10 и т.п.) В ПЛОТНОЙ близости (соседняя строка, соседняя\n"
            . "ячейка таблицы) виден код вида M\\d{4,6} (M00482, M13799, M33374,\n"
            . "M06476 — внутренний SKU MyZip) — это НЕ отдельный товар. Это\n"
            . "ВТОРОЙ артикул той же позиции. Складывай ОБА артикула в поле\n"
            . "`article` через запятую: «XAA332DS2, M06476».\n"
            . "M-SKU критичен для матчинга, поэтому НИКОГДА не выкидывай его\n"
            . "и не теряй, даже если он стоит ниже OEM в отдельной ячейке таблицы.\n\n";

        // Phase 2.4a: двухшаговый Chain-of-Thought:
        //  1) описать каждое фото отдельно — главный объект и побочные товары
        //     в кадре (`photo_descriptions[]`);
        //  2) только потом подбирать image_index для каждой позиции, выбирая
        //     фото где товар *главный* объект (closeup label), а не где он
        //     просто виден в кадре.
        //
        // Это лечит Vision dup-mapping (один общий план «прилипает» к двум
        // позициям) и cases когда модель угадывает фото для item'а без
        // подходящего closeup'а.
        // Multi-invoice case — на фото / в reference text может быть несколько
        // блоков-счетов (например клиент шлёт screenshot Outlook'а с таблицей
        // «1 счет:» и «2 счет:» подряд). Каждая позиция должна нести invoice_index,
        // иначе backend-dedupe схлопнет одинаковые article+qty между счетами.
        $intro .= "═══ ТЕКСТ И ФОТО ДОПОЛНЯЮТ ДРУГ ДРУГА ═══\n"
            . "Текст письма и фото — ДВА ИСТОЧНИКА. Они НЕ конкурируют, а\n"
            . "ДОПОЛНЯЮТ друг друга. Извлекай всю информацию из обоих.\n\n"
            . "Текст обычно даёт: name (полное описание), qty, unit, иногда article.\n"
            . "Фото-шильдик даёт: article, brand, иногда уточнение типа.\n\n"
            . "ПРИОРИТЕТ ИСТОЧНИКОВ ПО ПОЛЯМ:\n"
            . "  • `name` — берётся из текста, если в тексте есть полное описание\n"
            . "    («Кнопка приказа с маркировкой Вызов диспетчера, шлифованная»).\n"
            . "    НЕ заменяй name из текста на голый article. НЕ копируй artical\n"
            . "    в name. Если в тексте только артикул без описания и фото\n"
            . "    показывает тип — собери name из артикула+типа+бренда\n"
            . "    («Плата OTIS GBA26800S2»).\n"
            . "  • `article` — приоритет у того, что НАПИСАЛ КЛИЕНТ В ТЕКСТЕ.\n"
            . "    Если в тексте позиции уже есть артикул (KA302-BEL-2NC,\n"
            . "    KM768080G01, M02016 и т.п.) — оставь его, НЕ ищи на фото\n"
            . "    второй. Если в тексте артикула НЕТ И позиции привязано\n"
            . "    фото с has_readable_marking=true — прочти артикул со шильдика\n"
            . "    и положи в article.\n"
            . "  • `brand` — аналогично article: текст приоритетнее, фото\n"
            . "    дополняет если в тексте бренд не указан.\n"
            . "  • `qty`, `unit` — всегда из текста (на фото их обычно нет).\n\n"
            . "НИКОГДА НЕ ТЕРЯЙ информацию, которую КЛИЕНТ ЯВНО НАПИСАЛ.\n"
            . "Если клиент написал артикул в тексте — он ДОЛЖЕН попасть в `article`,\n"
            . "вне зависимости от того что показывает или не показывает фото.\n\n"
            . "═══ ВАЖНО: НЕСКОЛЬКО СЧЕТОВ В КАДРЕ ═══\n"
            . "Если на фото / в подписи / в reference text видны НЕСКОЛЬКО блоков\n"
            . "счетов (подзаголовки «1 счет», «1 счёт», «2 счет:», «Счёт №1»,\n"
            . "«Invoice 1», «КП 1» и т.п.), то каждая позиция = отдельный item\n"
            . "со своим invoice_index (1-based индекс блока). ДАЖЕ если в счёте 2\n"
            . "повторяется тот же артикул что в счёте 1 — это НЕ дубль, а\n"
            . "отдельная позиция второго счёта. Не сливай между блоками.\n\n"
            . "Если счёт один — у всех items invoice_index=1.\n\n"
            . "═══ ШАГ 1: ОПИСАНИЕ ФОТО ═══\n"
            . "Прежде чем выводить позиции, для КАЖДОГО фото (по порядку, начиная с 0)\n"
            . "запиши в `photo_descriptions[]`:\n"
            . "  • `index` — порядковый номер фото (0..N-1)\n"
            . "  • `main_subject` — что главный/центральный объект фото в одной фразе\n"
            . "    (например «closeup шильдика контактора Schneider A013250», «общий план\n"
            . "    двух устройств на ладони», «упаковка/коробка», «логотип бренда»,\n"
            . "    «штрих-код без артикула»)\n"
            . "  • `secondary_items` — массив других товаров, которые видны в кадре, но\n"
            . "    НЕ главный объект (пустой массив если только один товар или фото\n"
            . "    нерелевантно)\n"
            . "  • `has_readable_marking` — true если на фото читается артикул/маркировка\n\n";

        $intro .= "═══ ШАГ 2: ВЫБОР image_index ДЛЯ ПОЗИЦИЙ ═══\n"
            . "Для каждой позиции в `items[]` поставь `image_index` следуя правилам:\n\n"
            . "1) **Главный объект, а не «виден в кадре».** Выбирай только то фото,\n"
            . "   где этот товар — main_subject. Если товар фигурирует лишь как\n"
            . "   secondary_items на общем плане — это НЕ повод выбрать его.\n"
            . "2) **Предпочтение closeup'у.** Если для одной позиции есть и общий\n"
            . "   план, и closeup label/маркировки — ВСЕГДА выбирай closeup\n"
            . "   (has_readable_marking=true).\n"
            . "3) **Уникальность.** Один image_index можно присваивать НЕСКОЛЬКИМ\n"
            . "   позициям ТОЛЬКО если это group-shot, где все эти товары стоят\n"
            . "   в равной композиции (например пакет с тремя одинаковыми платами).\n"
            . "   Если у каждой позиции есть собственный closeup — выбирай его,\n"
            . "   а не повторяй общий план.\n"
            . "4) **Лучше null, чем угадывать.** Если для позиции НЕТ фото, где этот\n"
            . "   товар главный объект, или вообще нет подходящего фото — `image_index: null`.\n"
            . "   Неверная привязка хуже отсутствия привязки.\n\n";

        $intro .= "═══ ФОРМАТ ОТВЕТА ═══\n"
            . "Строго JSON без markdown:\n"
            . '{'
            . '"photo_descriptions":['
                . '{"index":0,"main_subject":"...","secondary_items":["...","..."],"has_readable_marking":true}'
            . '],'
            . '"items":['
                . '{"name":"...","brand":"...","article":"...","qty":1,"unit":"шт.","invoice_index":1,"note":null,"image_index":0}'
            . ']}'
            . "\n\nПоле invoice_index ОБЯЗАТЕЛЬНОЕ для каждого item. Дефолт = 1\n"
            . "(один счёт). Для multi-invoice выставляй 1/2/3... по блокам.";

        $content = [['type' => 'text', 'text' => $intro]];
        foreach ($images as $img) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $img, 'detail' => 'high'],
            ];
        }

        $result = $this->openai->chat(
            [['role' => 'user', 'content' => $content]],
            config('services.openai.vision_model'),
            [
                'temperature' => 0,
                // CoT-preamble (photo_descriptions[]) добавляет ~80-150 токенов
                // на каждое фото — поднимаем потолок чтобы не обрезать ответ.
                'max_tokens' => 6144,
                'response_format' => ['type' => 'json_object'],
            ],
        );

        $parsed = json_decode($result['content'] ?? '', true);
        $items = $parsed['items'] ?? [];
        $photoDescriptions = is_array($parsed['photo_descriptions'] ?? null)
            ? $parsed['photo_descriptions']
            : [];

        Log::info('parseItemsFromPhotoMarkings: GPT responded', [
            'images' => count($images),
            'reference_text_chars' => $referenceText !== null ? mb_strlen($referenceText) : 0,
            'finish_reason' => $result['raw']['choices'][0]['finish_reason'] ?? null,
            'usage' => $result['usage'] ?? null,
            'photo_descriptions_count' => count($photoDescriptions),
            // Для regress-анализа: видно, как Vision сам себе обосновал image_index.
            'photo_descriptions' => $photoDescriptions,
            'image_index_distribution' => array_count_values(array_filter(array_map(
                fn ($it) => $it['image_index'] ?? null,
                $items,
            ), fn ($v) => $v !== null)),
        ]);

        $normalizedItems = array_map(function (array $item) use ($attachmentIds) {
            $normalized = $this->normalizeParsedItem($item);
            // Phase 2: резолв image_index → конкретный email_attachments.id.
            // Если Vision не вернул index, вернул не-int, или out-of-range —
            // FK остаётся null (заглушка в UI).
            $idx = $item['image_index'] ?? null;
            $attId = null;
            if (is_int($idx) && $idx >= 0 && $idx < count($attachmentIds)) {
                $attId = $attachmentIds[$idx] ?? null;
            }
            $normalized['email_attachment_id'] = $attId;

            return $normalized;
        }, $items);

        // Phase 2.4b fallback: тривиальный случай 1×1.
        // Vision-промпт «лучше null чем угадывать» (CoT a39e314) иногда
        // возвращает image_index=null даже когда у заявки ровно одно фото
        // и одна позиция — например, close-up шильдика, где «главный объект»
        // с точки зрения LLM = шильдик, а не товар. Это false null:
        // менеджер всё равно привяжет вручную, а 1-к-1 связь однозначна.
        $itemsWithoutAtt = array_keys(array_filter(
            $normalizedItems,
            fn ($it) => empty($it['email_attachment_id']),
        ));
        $usedAttIds = array_filter(array_map(
            fn ($it) => $it['email_attachment_id'] ?? null,
            $normalizedItems,
        ));
        $unusedAttIds = array_values(array_diff(
            array_filter($attachmentIds, fn ($id) => $id !== null),
            $usedAttIds,
        ));
        if (count($itemsWithoutAtt) === 1 && count($unusedAttIds) === 1) {
            $itemIdx = $itemsWithoutAtt[0];
            $normalizedItems[$itemIdx]['email_attachment_id'] = $unusedAttIds[0];
            Log::info('RequestItemParsingService: trivial 1×1 photo fallback applied', [
                'item_index' => $itemIdx,
                'email_attachment_id' => $unusedAttIds[0],
                'reason' => 'Vision returned image_index=null but only one item/photo — safe auto-bind',
            ]);
        }

        return $normalizedItems;
    }

    /**
     * Дедупликация внутри уже распарсенного списка (фото + pdf могут вернуть
     * одну и ту же позицию). Ключ — артикул (нормализованный) либо имя.
     */
    private function dedupeWithinList(array $items): array
    {
        // Защита от пересечений photo+PDF+text-парсинга — одна и та же позиция
        // могла быть извлечена дважды из разных источников.
        //
        // Ключ дедупа: normalizeArticle(article) + qty + invoice_index
        // (если article пуст — lower(name) + qty + invoice_index).
        //
        // invoice_index приходит от LLM (см. ParseItemsPrompt секция «Несколько
        // счетов в одном письме»). Default = 1.
        //  - Обычное письмо: все items имеют invoice_index=1 → ключ совпадает
        //    при настоящих дублях (photo+text) → схлопывается;
        //  - Multi-invoice (M-2026-1032): items из счёта 1 имеют invoice_index=1,
        //    из счёта 2 — invoice_index=2 → ключи разные → НЕ схлопываются
        //    даже если совпадают article и qty (M33374 qty=2 в обоих счетах).
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $base = $this->normalizeArticle($item['article'] ?? null);
            if ($base === '') {
                $base = mb_strtolower(trim($item['name'] ?? ''));
            }
            if ($base === '') {
                continue;
            }
            $qty = (string) ($item['qty'] ?? '');
            $invoiceIndex = (int) ($item['invoice_index'] ?? 1);
            $key = $base . '|qty=' . $qty . '|inv=' . $invoiceIndex;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Отфильтровать только новые позиции (исключить дубли).
     *
     * @return array{new: array, duplicates: int}
     */
    public function filterNewItems(array $parsedItems, Collection $existingItems): array
    {
        $new = [];
        $duplicates = 0;

        foreach ($parsedItems as $parsed) {
            if ($this->isDuplicate($parsed, $existingItems)) {
                $duplicates++;
                continue;
            }
            $new[] = $parsed;
        }

        return ['new' => $new, 'duplicates' => $duplicates];
    }

    /**
     * Второй проход LLM на reply: разделяем «truly new» позиции и
     * «clarifications» (уточнение артикулов существующих позиций).
     *
     * Возвращает массив той же длины что и $newItems, в котором каждому
     * исходному item ставится в соответствие либо отметка `null`
     * (truly new, item идёт в обычный persist через filterNewItems),
     * либо clarification-запись (для записи в Request->pending_clarifications).
     *
     * Fail-soft: если LLM упал или вернул мусор — все items считаются
     * truly new (поведение как до этого коммита).
     *
     * @param array<int, array{name: string, brand: ?string, article: ?string, qty: float, unit: string, note: ?string}> $newItems
     * @param Collection<int, RequestItem> $existingItems
     * @param int|null $sourceEmailMessageId — id reply'я, для записи в clarification (источник предложения).
     * @param string|null $replyContextSnippet — короткий кусок тела reply'я, чтобы LLM мог увидеть фразы вроде «выставите счёт».
     * @return array{
     *   new_indexes: list<int>,
     *   clarifications: list<array{
     *     id: string,
     *     source_email_message_id: ?int,
     *     target_position: int,
     *     additional_article: ?string,
     *     additional_brand: ?string,
     *     confidence: 'high'|'low',
     *     reasoning: string,
     *     created_at: string
     *   }>
     * }
     *
     * Поле `confidence`: high → RequestItemPersister применяет автоматически
     * (без ручного review), low → идёт в pending_clarifications. Невалидное
     * / пустое значение → 'low' (безопасная сторона).
     */
    public function decideClarifications(
        array $newItems,
        Collection $existingItems,
        ?int $sourceEmailMessageId = null,
        ?string $replyContextSnippet = null,
    ): array {
        $defaultAllNew = [
            'new_indexes' => array_keys($newItems),
            'clarifications' => [],
        ];

        // Глобальный killswitch для дебага.
        if (! config('services.openai.clarifications_enabled', true)) {
            return $defaultAllNew;
        }
        if (empty($newItems) || $existingItems->isEmpty()) {
            return $defaultAllNew;
        }

        // Соберём минимальный payload для LLM.
        $existingPayload = $existingItems
            ->filter(fn ($e) => $e->is_active)
            ->values()
            ->map(fn ($e) => [
                'position' => (int) $e->position,
                'parsed_name' => (string) $e->parsed_name,
                'parsed_brand' => $e->parsed_brand,
                'parsed_article' => $e->parsed_article,
                'parsed_qty' => (string) $e->parsed_qty,
                'parsed_unit' => (string) $e->parsed_unit,
            ])
            ->all();

        $newPayload = array_map(fn (array $i) => [
            'name' => $i['name'] ?? '',
            'brand' => $i['brand'] ?? null,
            'article' => $i['article'] ?? null,
            'qty' => (float) ($i['qty'] ?? 1),
            'unit' => $i['unit'] ?? 'шт.',
        ], $newItems);

        try {
            $result = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => DecideClarificationsPrompt::systemMessage()],
                    ['role' => 'user', 'content' => DecideClarificationsPrompt::userMessage($existingPayload, $newPayload, $replyContextSnippet)],
                ],
                config('services.openai.clarification_model', 'gpt-4o-mini'),
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0],
            );
        } catch (\Throwable $e) {
            Log::warning('decideClarifications: LLM call failed', [
                'error' => $e->getMessage(),
                'new_count' => count($newItems),
                'existing_count' => $existingItems->count(),
            ]);

            return $defaultAllNew;
        }

        $parsed = json_decode($result['content'] ?? '', true);
        $decisions = is_array($parsed) ? ($parsed['decisions'] ?? null) : null;
        if (! is_array($decisions)) {
            Log::warning('decideClarifications: malformed LLM response', [
                'content' => mb_substr((string) ($result['content'] ?? ''), 0, 500),
            ]);

            return $defaultAllNew;
        }

        $validPositions = array_map(fn ($e) => (int) $e->position, $existingItems->all());
        $newIndexes = array_keys($newItems);
        $consumedAsClar = [];
        $clarifications = [];

        foreach ($decisions as $d) {
            if (! is_array($d)) {
                continue;
            }
            $idx = $d['new_item_index'] ?? null;
            $verdict = $d['verdict'] ?? null;
            if (! is_int($idx) || ! array_key_exists($idx, $newItems)) {
                continue;
            }
            if ($verdict !== 'clarification') {
                continue;
            }
            $targetPos = $d['target_position'] ?? null;
            if (! is_int($targetPos) || ! in_array($targetPos, $validPositions, true)) {
                continue; // безопасно — пусть item уйдёт как new
            }

            // Безопасное приведение confidence: только явный 'high' открывает
            // авто-применение, любое другое значение (включая отсутствие поля,
            // опечатки, null) → 'low' и ручной review.
            $confidence = ($d['confidence'] ?? null) === 'high' ? 'high' : 'low';

            $consumedAsClar[$idx] = true;
            $clarifications[] = [
                'id' => 'clr_' . substr(bin2hex(random_bytes(8)), 0, 12),
                'source_email_message_id' => $sourceEmailMessageId,
                'target_position' => $targetPos,
                'additional_article' => isset($newItems[$idx]['article']) && $newItems[$idx]['article'] !== ''
                    ? (string) $newItems[$idx]['article']
                    : null,
                'additional_brand' => isset($newItems[$idx]['brand']) && $newItems[$idx]['brand'] !== ''
                    ? (string) $newItems[$idx]['brand']
                    : null,
                // 2026-05-21: LLM может предложить более информативное имя
                // позиции (объединение текущего + контекста из reply).
                // Применяется через RequestItemPersister::applyClarificationToItems.
                'refined_name' => isset($d['refined_name']) && is_string($d['refined_name'])
                    && trim($d['refined_name']) !== ''
                    ? mb_substr(trim($d['refined_name']), 0, 200)
                    : null,
                'confidence' => $confidence,
                'reasoning' => isset($d['reasoning']) && is_string($d['reasoning'])
                    ? mb_substr($d['reasoning'], 0, 500)
                    : '',
                'created_at' => now()->toIso8601String(),
            ];
        }

        $remainingNew = array_values(array_filter($newIndexes, fn ($i) => ! isset($consumedAsClar[$i])));

        Log::info('decideClarifications: split', [
            'truly_new' => count($remainingNew),
            'clarifications' => count($clarifications),
            'usage' => $result['usage'] ?? null,
        ]);

        return [
            'new_indexes' => $remainingNew,
            'clarifications' => $clarifications,
        ];
    }

    private function isDuplicate(array $parsed, Collection $existingItems): bool
    {
        $parsedArticle = $this->normalizeArticle($parsed['article'] ?? null);
        $parsedName = mb_strtolower(trim($parsed['name'] ?? ''));

        foreach ($existingItems as $existing) {
            if (!$existing->is_active) {
                continue;
            }

            $existingArticle = $this->normalizeArticle($existing->parsed_article);

            // 1. Оба артикула заданы — решение строго по артикулу:
            //    равны → дубль; различаются → НЕ дубль (даже если имена похожи).
            //    Два пускателя "3RT2026-1BW40" и "3RT2025-1BW40" разные товары,
            //    хотя similar_text на именах даёт 95%.
            if (!empty($parsedArticle) && !empty($existingArticle)) {
                if ($parsedArticle === $existingArticle) {
                    return true;
                }
                continue;
            }

            // 2. Хотя бы у одной стороны нет артикула — сравниваем по имени.
            $existingName = mb_strtolower(trim($existing->parsed_name ?? ''));
            if (!empty($parsedName) && !empty($existingName)) {
                similar_text($parsedName, $existingName, $percent);
                if ($percent >= 70) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeArticle(?string $article): string
    {
        if (empty($article)) {
            return '';
        }

        return preg_replace('/[\s\-_.\/]/', '', mb_strtoupper(trim($article)));
    }

    // ── Text extraction (mirroring AttachmentController logic) ───────

    private function extractFromPdf(UploadedFile $file): string
    {
        $smalotError = null;

        // Attempt 1: smalot/pdfparser (fast, works for most PDFs)
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($file->getRealPath());
            $text = $this->cleanText($pdf->getText());

            if (mb_strlen($text) >= 10) {
                return $text;
            }

            $smalotError = 'extracted only ' . mb_strlen($text) . ' chars';
        } catch (\Throwable $e) {
            $smalotError = $e->getMessage();
        }

        Log::info('PdfParser failed, trying pdftotext fallback', [
            'file' => $file->getClientOriginalName(),
            'path' => $file->getRealPath(),
            'smalot_error' => $smalotError,
        ]);

        // Attempt 2: pdftotext (poppler-utils) — handles PDFs with missing catalog
        try {
            $text = $this->extractFromPdfViaPoppler($file);
            if (mb_strlen($text) >= 10) {
                return $text;
            }
        } catch (\Throwable $e) {
            Log::warning('pdftotext also failed, trying OCR fallback', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
        }

        // Attempt 3: OCR via Tesseract (for scanned PDFs or PDFs with non-extractable text)
        return $this->extractFromPdfViaOcr($file);
    }

    private function extractFromPdfViaPoppler(UploadedFile $file): string
    {
        // Check if exec/shell_exec are available
        if (in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            throw new \RuntimeException(
                'PDF не удалось прочитать (smalot/pdfparser failed). '
                . 'Функция exec() отключена в PHP — добавьте исключение в disable_functions для pdftotext'
            );
        }

        $pdftotext = '/usr/bin/pdftotext';
        if (!file_exists($pdftotext)) {
            $pdftotext = '/usr/local/bin/pdftotext';
        }

        if (!file_exists($pdftotext)) {
            throw new \RuntimeException(
                'PDF не удалось прочитать (формат не поддерживается). '
                . 'Установите poppler-utils: sudo apt install poppler-utils'
            );
        }

        $inputPath = $file->getRealPath();
        $tmpOutput = tempnam(sys_get_temp_dir(), 'pdf_') . '.txt';

        Log::info('pdftotext fallback', [
            'binary' => $pdftotext,
            'input' => $inputPath,
            'input_exists' => file_exists($inputPath),
            'input_size' => file_exists($inputPath) ? filesize($inputPath) : 0,
            'output' => $tmpOutput,
        ]);

        try {
            $cmd = sprintf(
                '%s -layout %s %s 2>&1',
                escapeshellarg($pdftotext),
                escapeshellarg($inputPath),
                escapeshellarg($tmpOutput),
            );

            $execOutput = [];
            $exitCode = -1;
            exec($cmd, $execOutput, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    "pdftotext exit code {$exitCode}: " . implode("\n", $execOutput)
                );
            }

            if (!file_exists($tmpOutput)) {
                throw new \RuntimeException('pdftotext не создал выходной файл');
            }

            $text = $this->cleanText(file_get_contents($tmpOutput));
            $len = mb_strlen($text);

            Log::info('pdftotext result', ['chars' => $len, 'preview' => mb_substr($text, 0, 200)]);

            if ($len < 10) {
                throw new \RuntimeException(
                    "pdftotext извлёк только {$len} символов. PDF может быть отсканированным (требуется OCR)"
                );
            }

            return $text;
        } finally {
            @unlink($tmpOutput);
        }
    }

    /**
     * OCR fallback: convert PDF pages to images via Ghostscript, then OCR via Tesseract.
     *
     * Requires: sudo apt install ghostscript tesseract-ocr tesseract-ocr-rus
     */
    private function extractFromPdfViaOcr(UploadedFile $file): string
    {
        $gs = $this->findBinary('gs', ['/usr/bin/gs', '/usr/local/bin/gs']);
        $tesseract = $this->findBinary('tesseract', ['/usr/bin/tesseract', '/usr/local/bin/tesseract']);

        if (!$gs || !$tesseract) {
            $missing = [];
            if (!$gs) $missing[] = 'ghostscript';
            if (!$tesseract) $missing[] = 'tesseract-ocr tesseract-ocr-rus';
            throw new \RuntimeException(
                'PDF не содержит извлекаемого текста (нужен OCR). '
                . 'Установите: sudo apt install ' . implode(' ', $missing)
            );
        }

        $inputPath = $file->getRealPath();
        $tmpDir = sys_get_temp_dir() . '/pdf_ocr_' . uniqid();
        @mkdir($tmpDir, 0755, true);

        Log::info('OCR fallback started', [
            'file' => $file->getClientOriginalName(),
            'gs' => $gs,
            'tesseract' => $tesseract,
            'tmpDir' => $tmpDir,
        ]);

        try {
            // Step 1: Convert PDF pages to PNG images via Ghostscript
            $gsCmd = sprintf(
                '%s -dNOPAUSE -dBATCH -sDEVICE=png16m -r300 -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -sOutputFile=%s %s 2>&1',
                escapeshellarg($gs),
                escapeshellarg($tmpDir . '/page_%03d.png'),
                escapeshellarg($inputPath),
            );

            $gsOutput = [];
            exec($gsCmd, $gsOutput, $gsExit);

            if ($gsExit !== 0) {
                throw new \RuntimeException('Ghostscript failed (exit ' . $gsExit . '): ' . implode("\n", array_slice($gsOutput, -5)));
            }

            $pageImages = glob($tmpDir . '/page_*.png');
            if (empty($pageImages)) {
                throw new \RuntimeException('Ghostscript не создал изображения страниц');
            }

            sort($pageImages);
            Log::info('GS converted pages', ['count' => count($pageImages)]);

            // Step 2: OCR each page with Tesseract (Russian + English)
            $allText = '';
            foreach ($pageImages as $pageImage) {
                $ocrOutput = $pageImage . '_ocr';
                $ocrCmd = sprintf(
                    '%s %s %s -l rus+eng 2>&1',
                    escapeshellarg($tesseract),
                    escapeshellarg($pageImage),
                    escapeshellarg($ocrOutput),
                );

                $tesOutput = [];
                exec($ocrCmd, $tesOutput, $tesExit);

                $ocrFile = $ocrOutput . '.txt';
                if (file_exists($ocrFile)) {
                    $allText .= file_get_contents($ocrFile) . "\n";
                    @unlink($ocrFile);
                }
                @unlink($pageImage);
            }

            $text = $this->cleanText($allText);
            $len = mb_strlen($text);

            Log::info('OCR result', ['chars' => $len, 'preview' => mb_substr($text, 0, 300)]);

            if ($len < 10) {
                throw new \RuntimeException(
                    "OCR извлёк только {$len} символов. Возможно PDF пуст или нечитаем"
                );
            }

            return $text;
        } finally {
            // Cleanup temp directory
            array_map('unlink', glob($tmpDir . '/*') ?: []);
            @rmdir($tmpDir);
        }
    }

    private function findBinary(string $name, array $commonPaths): ?string
    {
        // 1. Пробуем через shell (command -v) — работает даже если open_basedir
        // блокирует прямой file_exists к /usr/bin/*.
        if (!in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            $resolved = [];
            $code = -1;
            @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $resolved, $code);
            if ($code === 0 && !empty($resolved[0])) {
                return trim($resolved[0]);
            }
            // Fallback: which
            $resolved = [];
            @exec('which ' . escapeshellarg($name) . ' 2>/dev/null', $resolved, $code);
            if ($code === 0 && !empty($resolved[0])) {
                return trim($resolved[0]);
            }
        }

        // 2. Прямой file_exists по типичным путям — если shell недоступен
        // и open_basedir разрешает чтение.
        foreach ($commonPaths as $path) {
            if (@file_exists($path)) {
                return $path;
            }
        }

        // 3. Последний шанс: вернуть просто имя, пусть shell сам найдёт в PATH.
        // Если бинарника нет — exec вернёт non-zero и поймается ниже.
        return $name;
    }

    private function extractFromDocx(UploadedFile $file): string
    {
        // Primary: abiword → PDF → pdftotext -layout. Рендерит docx визуально
        // (включая текстбоксы/фреймы), выравнивание колонок в PDF сохраняется
        // pdftotext -layout в плоском тексте — GPT видит qty рядом с описанием.
        // На сервере: sudo apt install abiword poppler-utils
        try {
            $text = $this->abiwordDocxToLayoutText($file);
            if ($text !== null && mb_strlen(trim($text)) >= 20) {
                Log::info('DOCX extracted via abiword→PDF→pdftotext', [
                    'file' => $file->getClientOriginalName(),
                    'chars' => mb_strlen($text),
                    'preview' => mb_substr($text, 0, 1500),
                ]);
                return $text;
            }
            Log::info('DOCX abiword path empty, falling back to pandoc', [
                'file' => $file->getClientOriginalName(),
                'chars' => $text !== null ? mb_strlen(trim($text)) : 'null',
            ]);
        } catch (\Throwable $e) {
            Log::info('DOCX abiword failed, falling back to pandoc', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback 1: pandoc (без Java, читает простые docx-таблицы).
        try {
            $text = $this->pandocDocxToText($file);
            if ($text !== null && mb_strlen(trim($text)) >= 20) {
                Log::info('DOCX extracted via pandoc', [
                    'file' => $file->getClientOriginalName(),
                    'chars' => mb_strlen($text),
                    'preview' => mb_substr($text, 0, 1500),
                ]);
                return $text;
            }
            Log::info('DOCX: pandoc unavailable or empty, falling back to PhpWord', [
                'file' => $file->getClientOriginalName(),
                'chars' => $text !== null ? mb_strlen(trim($text)) : 'null',
            ]);
        } catch (\Throwable $e) {
            Log::info('DOCX pandoc failed, falling back to PhpWord', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: PhpWord (работает для простых docx с настоящими таблицами, но
        // ломается на документах с текстбоксами — количества оказываются вне контекста).
        $phpWord = WordIOFactory::load($file->getRealPath());
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->extractWordElementText($element) . "\n";
            }
        }

        $text = $this->cleanText($text);
        Log::info('DOCX extracted via PhpWord fallback', [
            'file' => $file->getClientOriginalName(),
            'chars' => mb_strlen($text),
            'preview' => mb_substr($text, 0, 1500),
        ]);
        return $text;
    }

    /**
     * Abiword рендерит docx в PDF с сохранением визуальной вёрстки,
     * затем pdftotext -layout возвращает plain text с колоночным выравниванием.
     * Это решает главную боль фреймовых docx — qty оказывается рядом с описанием.
     */
    private function abiwordDocxToLayoutText(UploadedFile $file): ?string
    {
        if (!function_exists('exec')
            || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            return null;
        }

        $abiword = $this->findBinary('abiword', ['/usr/bin/abiword', '/usr/local/bin/abiword']);
        if (!$abiword) {
            return null;
        }

        // Проверка что abiword реально работает.
        $verify = [];
        $vCode = -1;
        @exec(escapeshellcmd($abiword) . ' --version 2>&1', $verify, $vCode);
        if ($vCode !== 0) {
            return null;
        }

        $tmpDir = sys_get_temp_dir() . '/docx_abi_' . uniqid();
        if (!@mkdir($tmpDir, 0755, true)) {
            return null;
        }

        // abiword пишет профиль в $HOME — указываем отдельную временную папку.
        $pdfPath = $tmpDir . '/out.pdf';
        $cmd = sprintf(
            'HOME=%s %s --to=pdf --to-name=%s %s 2>&1',
            escapeshellarg($tmpDir),
            escapeshellarg($abiword),
            escapeshellarg($pdfPath),
            escapeshellarg($file->getRealPath()),
        );
        $out = [];
        $exit = -1;
        exec($cmd, $out, $exit);

        $cleanup = function () use ($tmpDir) {
            foreach (@glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        };

        if ($exit !== 0 || !file_exists($pdfPath) || filesize($pdfPath) < 100) {
            Log::info('abiwordDocxToLayoutText: conversion failed', [
                'exit' => $exit,
                'stdout_tail' => implode("\n", array_slice($out, -5)),
                'pdf_exists' => file_exists($pdfPath),
                'pdf_size' => file_exists($pdfPath) ? filesize($pdfPath) : 0,
            ]);
            $cleanup();
            return null;
        }

        try {
            // pdftotext -layout напрямую (без cleanText — он схлопывает
            // множественные пробелы, разрушая колоночное выравнивание, ради которого
            // мы и используем этот путь).
            $pdftotext = '/usr/bin/pdftotext';
            if (!file_exists($pdftotext)) {
                $pdftotext = '/usr/local/bin/pdftotext';
            }
            if (!file_exists($pdftotext)) {
                return null;
            }
            $tmpTxt = tempnam(sys_get_temp_dir(), 'pdf_') . '.txt';
            try {
                $pCmd = sprintf(
                    '%s -layout %s %s 2>&1',
                    escapeshellarg($pdftotext),
                    escapeshellarg($pdfPath),
                    escapeshellarg($tmpTxt),
                );
                $pOut = [];
                $pExit = -1;
                exec($pCmd, $pOut, $pExit);
                if ($pExit !== 0 || !file_exists($tmpTxt)) {
                    return null;
                }
                $text = (string) file_get_contents($tmpTxt);
            } finally {
                @unlink($tmpTxt);
            }

            if (mb_strlen(trim($text)) < 20) {
                return null;
            }
            $text = str_replace(["\r\n", "\r"], "\n", $text);
            $text = preg_replace('/\n{4,}/', "\n\n\n", $text);
            return trim($text);
        } finally {
            $cleanup();
        }
    }

    /**
     * Извлечь текст из docx через pandoc. Возвращает plain text с сохранённой
     * структурой таблиц (включая text-box фреймы). null если pandoc недоступен.
     */
    private function pandocDocxToText(UploadedFile $file): ?string
    {
        if (!function_exists('exec')
            || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            return null;
        }

        $pandoc = $this->findBinary('pandoc', ['/usr/bin/pandoc', '/usr/local/bin/pandoc']);
        if (!$pandoc) {
            return null;
        }

        // Проверка что бинарник реально исполняется (findBinary мог вернуть просто имя).
        $verify = [];
        $code = -1;
        @exec(escapeshellcmd($pandoc) . ' --version 2>&1', $verify, $code);
        if ($code !== 0) {
            return null;
        }

        $cmd = sprintf(
            '%s -f docx -t plain --wrap=none %s 2>&1',
            escapeshellarg($pandoc),
            escapeshellarg($file->getRealPath()),
        );

        $output = [];
        $exit = -1;
        exec($cmd, $output, $exit);

        if ($exit !== 0) {
            throw new \RuntimeException("pandoc exit {$exit}: " . implode("\n", array_slice($output, -3)));
        }

        // НЕ применяем cleanText — он схлопывает множественные пробелы в один,
        // а pandoc's plain-layout использует их для колоночного выравнивания.
        // Без выравнивания GPT теряет привязку qty к строке позиции.
        $text = implode("\n", $output);
        // Минимальная очистка: нормализуем переводы строк и схлопываем пустые блоки.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/\n{4,}/', "\n\n\n", $text);
        return trim($text);
    }

    /**
     * (устарело — оставлено для совместимости, не используется).
     * Конвертировать docx в pdf через LibreOffice.
     */
    private function convertDocxToPdf(UploadedFile $file): ?string
    {
        $disableFunctions = ini_get('disable_functions');
        $execDisabled = in_array('exec', array_map('trim', explode(',', $disableFunctions)));
        $tmpDirBase = sys_get_temp_dir();

        Log::info('convertDocxToPdf: start', [
            'disable_functions' => $disableFunctions,
            'exec_disabled_flag' => $execDisabled,
            'exec_function_exists' => function_exists('exec'),
            'open_basedir' => ini_get('open_basedir'),
            'sys_get_temp_dir' => $tmpDirBase,
            'whoami' => function_exists('exec') ? trim(@shell_exec('whoami 2>/dev/null') ?? 'n/a') : 'exec-off',
        ]);

        if ($execDisabled || !function_exists('exec')) {
            Log::info('convertDocxToPdf: bail — exec disabled');
            return null;
        }

        $libreoffice = $this->findBinary('libreoffice', [
            '/usr/bin/libreoffice',
            '/usr/bin/soffice',
            '/usr/local/bin/libreoffice',
            '/usr/local/bin/soffice',
        ]);
        Log::info('convertDocxToPdf: findBinary', ['libreoffice' => $libreoffice]);

        if (!$libreoffice) {
            Log::info('convertDocxToPdf: bail — !libreoffice');
            return null;
        }

        $tmpDir = $tmpDirBase . '/docx_pdf_' . uniqid();
        $mkdirOk = @mkdir($tmpDir, 0755, true);
        Log::info('convertDocxToPdf: tmpDir', ['tmpDir' => $tmpDir, 'mkdir_ok' => $mkdirOk, 'is_dir' => is_dir($tmpDir)]);
        if (!$mkdirOk) {
            Log::info('convertDocxToPdf: bail — mkdir failed');
            return null;
        }

        // Отдельный UserInstallation — под www-data нет доступа к $HOME,
        // LibreOffice иначе падает с exit 77 на попытке создать профиль в ~/.config.
        $profileDir = $tmpDir . '/lo_profile';
        @mkdir($profileDir, 0755, true);
        $userInstall = 'file://' . str_replace('\\', '/', $profileDir);

        // HOME=$tmpDir тоже не помешает — некоторые версии LO игнорируют -env и пишут в $HOME.
        $cmd = sprintf(
            'HOME=%s %s -env:UserInstallation=%s --headless --convert-to pdf --outdir %s %s 2>&1',
            escapeshellarg($tmpDir),
            escapeshellarg($libreoffice),
            escapeshellarg($userInstall),
            escapeshellarg($tmpDir),
            escapeshellarg($file->getRealPath()),
        );

        $output = [];
        $exit = -1;
        exec($cmd, $output, $exit);

        Log::info('convertDocxToPdf: libreoffice finished', [
            'exit' => $exit,
            'stdout_tail' => implode("\n", array_slice($output, -8)),
            'tmpDir_contents' => @glob($tmpDir . '/*') ?: [],
        ]);

        $cleanup = function () use ($tmpDir, $profileDir) {
            // Профиль LO удалим рекурсивно, чтобы /tmp не засорять.
            if (is_dir($profileDir)) {
                $it = new \RecursiveDirectoryIterator($profileDir, \FilesystemIterator::SKIP_DOTS);
                foreach (new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST) as $p) {
                    $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname());
                }
                @rmdir($profileDir);
            }
        };

        if ($exit !== 0) {
            $cleanup();
            @rmdir($tmpDir);
            throw new \RuntimeException("libreoffice exit {$exit}: " . implode("\n", array_slice($output, -3)));
        }

        $pdfs = glob($tmpDir . '/*.pdf');
        if (empty($pdfs)) {
            $cleanup();
            @rmdir($tmpDir);
            return null;
        }

        $cleanup();
        return $pdfs[0];
    }

    /**
     * Вариант pdftotext -layout, принимающий путь. Extract-метод для
     * UploadedFile выше оставлен как есть, тут дубляж минимальный — только для
     * цепочки docx→pdf, чтобы не оборачивать PDF обратно в UploadedFile.
     */
    private function pdftotextLayout(string $pdfPath): string
    {
        $pdftotext = '/usr/bin/pdftotext';
        if (!file_exists($pdftotext)) {
            $pdftotext = '/usr/local/bin/pdftotext';
        }
        if (!file_exists($pdftotext)) {
            return '';
        }

        $tmpOutput = tempnam(sys_get_temp_dir(), 'pdf_') . '.txt';
        try {
            $cmd = sprintf(
                '%s -layout %s %s 2>&1',
                escapeshellarg($pdftotext),
                escapeshellarg($pdfPath),
                escapeshellarg($tmpOutput),
            );
            exec($cmd, $execOutput, $exitCode);
            if ($exitCode !== 0 || !file_exists($tmpOutput)) {
                return '';
            }
            return $this->cleanText(file_get_contents($tmpOutput));
        } finally {
            @unlink($tmpOutput);
        }
    }

    private function extractWordElementText($element): string
    {
        if (method_exists($element, 'getRows')) {
            $rows = [];
            foreach ($element->getRows() as $row) {
                $rowText = $this->extractWordElementText($row);
                if (trim($rowText) !== '') {
                    $rows[] = $rowText;
                }
            }
            return implode("\n", $rows);
        }

        if (method_exists($element, 'getCells')) {
            $cells = [];
            foreach ($element->getCells() as $cell) {
                $cellText = $this->extractWordElementText($cell);
                if (trim($cellText) !== '') {
                    $cells[] = $cellText;
                }
            }
            return implode(' | ', $cells);
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $childText = $this->extractWordElementText($child);
                if (trim($childText) !== '') {
                    $parts[] = $childText;
                }
            }
            return implode(' ', $parts);
        }

        if (method_exists($element, 'getText')) {
            return $element->getText() ?? '';
        }

        return '';
    }

    private function extractFromExcel(UploadedFile $file): string
    {
        $spreadsheet = SpreadsheetIOFactory::load($file->getRealPath());
        $text = '';

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();

            for ($row = 1; $row <= $highestRow; $row++) {
                $rowData = [];
                for ($col = 'A'; $col <= $highestColumn; $col++) {
                    $cellValue = $sheet->getCell($col . $row)->getValue();
                    if ($cellValue !== null && $cellValue !== '') {
                        $rowData[] = $cellValue;
                    }
                }
                if (!empty($rowData)) {
                    $text .= implode(' | ', $rowData) . "\n";
                }
            }
        }

        return $this->cleanText($text);
    }

    private function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\t", ' ', $text);
        $text = preg_replace('/[^\S\n]+/', ' ', $text);
        $text = preg_replace('/ *\n */', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
