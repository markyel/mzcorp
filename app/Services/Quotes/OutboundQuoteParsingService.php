<?php

namespace App\Services\Quotes;

use App\Models\Request;
use App\Services\AI\OpenAIChatService;
use App\Services\Catalog\CatalogImportService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Парсер исходящих КП/счетов (Foundation §7, расширение DocumentDetector'а).
 *
 * Source: LazyLift @ 1ea8147d, app/Services/QuoteParsingService.php — drop-in
 * движка extractContent + parseWithGPT + reclassifyMisparsedDimensional +
 * normalizeItemsVat + extractJSON. Modifications:
 *  - parsed_quantity → parsed_qty (MyLift schema)
 *  - убрана piece-логика (effectivePiece() в MyLift RequestItem нет; в кейсах
 *    разные единицы измерения встречаются крайне редко, fallback на 'м')
 *  - промпт адаптирован под наш кейс «КП/счёт ОТ нас клиенту»: убран supplier-
 *    контекст, добавлена подсказка про M-артикулы из нашего каталога
 *  - возврат сужен до {document, items, raw} (supplier секция игнорируется)
 *
 * Никакой записи в БД — это чистая трансформация (PDF/XLSX → JSON-структура).
 * Результат потребляется ParseOutboundQuoteJob, который пишет outbound_quotes
 * + outbound_quote_items и потом отдаёт matcher'у.
 */
class OutboundQuoteParsingService
{
    public function __construct(
        private readonly OpenAIChatService $chatService
    ) {
    }

    /**
     * Извлечение текста + изображений из файла (по расширению).
     *
     * @param  string  $filePath  относительный путь в storage (для Storage::path)
     *                            или абсолютный (тогда $isAbsolute=true).
     * @return array{text: ?string, images: array<int, string>}
     *
     * @throws RuntimeException
     */
    public function extractContent(string $filePath, string $fileType, bool $isAbsolute = false): array
    {
        $fullPath = $isAbsolute ? $filePath : Storage::path($filePath);

        return match ($fileType) {
            'pdf' => $this->extractFromPdf($fullPath),
            'xlsx', 'xls' => $this->extractFromExcel($fullPath),
            'docx', 'doc' => $this->extractFromWord($fullPath),
            'image', 'png', 'jpg', 'jpeg' => $this->extractFromImage($fullPath),
            default => throw new RuntimeException('Unsupported file type: '.$fileType),
        };
    }

    /**
     * PDF → текст (smalot/pdfparser) + страницы в PNG (pdftoppm → Vision).
     */
    private function extractFromPdf(string $path): array
    {
        $text = null;
        $images = [];

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $text = $pdf->getText();
        } catch (\Exception $e) {
            Log::warning('OutboundQuoteParser: PDF text extraction failed', ['error' => $e->getMessage()]);
        }

        // Конвертируем в PNG всегда — Vision на колоночных таблицах КП точнее текстового слоя.
        try {
            $tempDir = storage_path('app/temp');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $outputPrefix = $tempDir.'/outbound_quote_'.uniqid();
            $command = sprintf(
                'pdftoppm -png -r 150 %s %s 2>&1',
                escapeshellarg($path),
                escapeshellarg($outputPrefix)
            );

            exec($command, $output, $returnVar);

            if ($returnVar === 0) {
                $files = glob($outputPrefix.'-*.png') ?: [];
                foreach ($files as $file) {
                    $imageData = file_get_contents($file);
                    if ($imageData !== false) {
                        $images[] = 'data:image/png;base64,'.base64_encode($imageData);
                    }
                    @unlink($file);
                }
            } else {
                Log::warning('OutboundQuoteParser: pdftoppm failed', [
                    'path' => $path,
                    'return_code' => $returnVar,
                    'output' => implode("\n", $output),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('OutboundQuoteParser: PDF→PNG conversion failed', ['error' => $e->getMessage()]);
        }

        return ['text' => $text, 'images' => $images];
    }

    private function extractFromExcel(string $path): array
    {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $text = '';

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rowData = [];
                    foreach ($row->getCellIterator() as $cell) {
                        $rowData[] = $cell->getValue();
                    }
                    $text .= implode("\t", $rowData)."\n";
                }
            }

            return ['text' => $text, 'images' => []];
        } catch (\Exception $e) {
            Log::error('OutboundQuoteParser: Excel extraction failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to extract Excel content: '.$e->getMessage());
        }
    }

    /**
     * Word → текст (antiword → catdoc fallback, для DOCX можно phpword).
     */
    private function extractFromWord(string $path): array
    {
        $text = '';
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        // DOCX — через phpword (без системных утилит).
        if ($ext === 'docx') {
            try {
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
                $parts = [];
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        $parts[] = $this->extractWordElementText($element);
                    }
                }
                $text = trim(implode("\n", array_filter($parts, static fn ($s) => $s !== '')));
            } catch (\Throwable $e) {
                Log::warning('OutboundQuoteParser: phpword extraction failed', ['error' => $e->getMessage()]);
            }
        }

        // .doc (старый) — antiword → catdoc.
        if ($text === '' && in_array($ext, ['doc', 'docx'], true)) {
            try {
                $output = shell_exec('antiword -m UTF-8.txt '.escapeshellarg($path));
                if (! empty($output)) {
                    $text = $output;
                }
            } catch (\Exception $e) {
                Log::warning('OutboundQuoteParser: antiword failed', ['error' => $e->getMessage()]);
            }
        }
        if ($text === '') {
            try {
                $output = shell_exec('catdoc '.escapeshellarg($path));
                if (! empty($output)) {
                    $text = $output;
                }
            } catch (\Exception $e) {
                Log::warning('OutboundQuoteParser: catdoc failed', ['error' => $e->getMessage()]);
            }
        }

        if ($text === '') {
            throw new RuntimeException('Failed to extract Word content from: '.$path);
        }

        return ['text' => $text, 'images' => []];
    }

    private function extractWordElementText(object $element): string
    {
        if (method_exists($element, 'getText')) {
            $value = $element->getText();
            if (is_string($value)) {
                return $value;
            }
        }
        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $parts[] = $this->extractWordElementText($child);
            }

            return implode(' ', array_filter($parts, static fn ($s) => $s !== ''));
        }

        return '';
    }

    private function extractFromImage(string $path): array
    {
        $imageData = file_get_contents($path);
        if ($imageData === false) {
            throw new RuntimeException('Failed to read image file: '.$path);
        }
        $mimeType = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/png') : 'image/png';

        return [
            'text' => null,
            'images' => ['data:'.$mimeType.';base64,'.base64_encode($imageData)],
        ];
    }

    /**
     * Парсинг контента через GPT-4o (text + Vision).
     *
     * @return array{document: array, items: array<int, array>, raw: array}
     *
     * @throws RuntimeException
     */
    public function parseWithGPT(?string $text, array $images, Request $request, ?string $emailBody = null): array
    {
        // M-артикулы регулярно «съезжают» в PDF КП — в шаблоне МЗ-NNNNNN
        // колонка наименования узкая и последний символ артикула попадает
        // на следующую строку («M0943\n1» вместо «M09431»). Лечим ДО инжекта
        // в промпт, чтобы и Vision и text-fallback видели единый артикул.
        $repairedText = $text !== null ? $this->repairBrokenMSkus($text) : null;
        $basePrompt = $this->buildParsingPrompt($repairedText, $request, $emailBody);

        $model = (string) config('services.openai.quote_parser_model', 'gpt-4o');

        try {
            // Первый проход.
            $first = $this->runOnePass($basePrompt, $images, $model, $request, $repairedText);

            // Если есть warning «Σ items.total != document.total_amount» > 5% —
            // делаем второй проход с feedback'ом. Vision иногда галлюцинирует на
            // одной из строк (см. quote_id=2 МЗ-355534: row 1 завышен ровно на
            // 6 986.80 ₽ = подвальная скидка). Второй проход с явной указкой на
            // расхождение и подсказкой «не складывай подвальную скидку с per-row»
            // даёт LLM шанс самостоятельно исправить.
            $needRetry = $this->shouldRetry($first['document'], $first['items']);
            if (! $needRetry) {
                return $first;
            }

            $feedbackPrompt = $this->buildRetryFeedbackPrompt(
                $basePrompt,
                $first['items'],
                $first['document'],
                $repairedText
            );
            $second = $this->runOnePass($feedbackPrompt, $images, $model, $request, $repairedText);

            // Выбираем лучший проход — у кого |Σ items.total - total_amount| меньше.
            $best = $this->pickBest($first, $second);
            $best['document']['_attempts'] = [
                'first_sum_delta' => $this->sumDelta($first['document'], $first['items']),
                'second_sum_delta' => $this->sumDelta($second['document'], $second['items']),
                'chosen' => $best === $first ? 'first' : 'second',
            ];

            return $best;
        } catch (\Exception $e) {
            Log::error('OutboundQuoteParser: GPT parsing failed', [
                'error' => $e->getMessage(),
                'request_id' => $request->id,
            ]);
            throw new RuntimeException('Failed to parse outbound quote: '.$e->getMessage());
        }
    }

    /**
     * Один проход LLM: чат → JSON → post-process → валидация.
     */
    private function runOnePass(string $prompt, array $images, string $model, Request $request, ?string $repairedText): array
    {
        $options = [
            'temperature' => 0,
            'max_tokens' => 16384,
            'response_format' => ['type' => 'json_object'],
        ];

        $result = ! empty($images)
            ? $this->chatService->analyzeMultipleImages($prompt, $images, $model, $options)
            : $this->chatService->chat([['role' => 'user', 'content' => $prompt]], $model, $options);

        $finishReason = $result['raw']['choices'][0]['finish_reason'] ?? null;
        if ($finishReason === 'length') {
            Log::warning('OutboundQuoteParser: GPT response truncated', [
                'request_id' => $request->id,
                'usage' => $result['usage'] ?? [],
            ]);
        }

        $json = $this->extractJSON((string) $result['content']);
        if ($json === null) {
            throw new RuntimeException('No JSON in GPT response');
        }

        $parsed = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON: '.json_last_error_msg());
        }

        $document = is_array($parsed['document'] ?? null) ? $parsed['document'] : [];
        $items = is_array($parsed['items'] ?? null) ? $parsed['items'] : [];

        $items = $this->reclassifyMisparsedDimensional($items, $request);
        $items = $this->normalizeItemsVat($items, $document, $request);
        $items = $this->repairItemArticles($items, $repairedText, $request);

        // Auto-fix per-row arithmetic: если |unit_price × qty − total| > 2%,
        // доверяем unit_price × qty. Vision чаще правильно читает per-unit
        // цену из колонки «Цена со скидкой», но путает агрегаты (например
        // в split-delivery дублирует общий total в каждую из split-строк).
        $items = $this->autoFixRowArithmetic($items, $request);

        $warnings = $this->validateLineTotals($items, $document, $request);
        if (! empty($warnings)) {
            $document['_warnings'] = $warnings;
        }

        return [
            'document' => $document,
            'items' => $items,
            'raw' => $result['raw'],
        ];
    }

    /**
     * Авто-фикс per-row арифметики. Если в строке `|unit_price × qty − total| > 2%`,
     * пересчитываем `total = unit_price × qty` (или `unit_price × unit_quantity × qty`
     * для мерных). Audit пишем в item['_corrections'] чтобы UI/log могли показать.
     *
     * Когда применять fix:
     *  • unit_price > 0 (мы доверяем per-unit цене больше чем агрегату);
     *  • quantity > 0;
     *  • расхождение > 2% (мелкая копеечная неточность не трогаем).
     *
     * Кейс split-delivery: Vision дублирует общий total в каждую split-строку
     * (Поз 4 и 5 = 5 шт × 560.83 = 2804.15 каждая, но Vision вернул total=5608.30
     * в обе). Trust `unit_price × qty` чинит обе → Σ items.total сходится с
     * document.total_amount.
     */
    private function autoFixRowArithmetic(array $items, Request $request): array
    {
        foreach ($items as $idx => &$item) {
            $up = isset($item['unit_price']) && is_numeric($item['unit_price']) ? (float) $item['unit_price'] : null;
            $qty = isset($item['quantity']) && is_numeric($item['quantity']) ? (float) $item['quantity'] : null;
            $tot = isset($item['total']) && is_numeric($item['total']) ? (float) $item['total'] : null;
            $uq = isset($item['unit_quantity']) && is_numeric($item['unit_quantity']) ? (float) $item['unit_quantity'] : null;

            if ($up === null || $qty === null || $tot === null || $up <= 0 || $qty <= 0 || $tot <= 0) {
                continue;
            }

            $expected = $uq !== null && $uq > 0 ? $up * $uq * $qty : $up * $qty;
            if (abs($expected - $tot) / max($tot, 1.0) <= 0.02) {
                continue;
            }

            // Корректируем. Сохраняем оригинальный total в _corrections для аудита.
            $corrections = is_array($item['_corrections'] ?? null) ? $item['_corrections'] : [];
            $corrections[] = [
                'field' => 'total',
                'reason' => 'row_arithmetic_mismatch',
                'before' => $tot,
                'after' => round($expected, 2),
                'formula' => $uq !== null && $uq > 0
                    ? sprintf('%.2f × %.3f × %.3f', $up, $uq, $qty)
                    : sprintf('%.2f × %.3f', $up, $qty),
            ];
            $item['_corrections'] = $corrections;
            $item['total'] = round($expected, 2);
            // price для штучного = unit_price; для мерного = unit_price × unit_quantity.
            // Обновляем чтобы Job записал согласованные line_price/line_total.
            if ($uq !== null && $uq > 0) {
                $item['price'] = round($up * $uq, 2);
            } else {
                $item['price'] = $up;
            }

            Log::info('OutboundQuoteParser: auto-fixed row arithmetic', [
                'request_id' => $request->id,
                'item_index' => $idx,
                'name' => mb_substr((string) ($item['name'] ?? ''), 0, 60),
                'before_total' => $tot,
                'after_total' => round($expected, 2),
                'unit_price' => $up,
                'quantity' => $qty,
            ]);
        }
        unset($item);

        return $items;
    }

    /**
     * Нужен ли второй проход с retry-feedback'ом? Срабатывает только на крупное
     * расхождение Σ items.total vs document.total_amount (> 5%), когда
     * prices_include_vat=true. Per-row arithmetic mismatch не триггерит retry —
     * с ним post-process справится / оператор сам поправит.
     */
    private function shouldRetry(array $document, array $items): bool
    {
        if (($document['prices_include_vat'] ?? null) !== true) {
            return false;
        }
        $totalAmount = isset($document['total_amount']) && is_numeric($document['total_amount'])
            ? (float) $document['total_amount'] : null;
        if ($totalAmount === null || $totalAmount <= 0) {
            return false;
        }
        $sumTotals = 0.0;
        foreach ($items as $item) {
            if (isset($item['total']) && is_numeric($item['total'])) {
                $sumTotals += (float) $item['total'];
            }
        }
        if ($sumTotals <= 0) {
            return false;
        }

        return abs($sumTotals - $totalAmount) / $totalAmount > 0.05;
    }

    /**
     * Строит retry-промпт: базовый + явный feedback о расхождении первой попытки
     * + ПОЛНЫЙ raw-text PDF (без 8000-лимита basePrompt'а) с инструкцией
     * «сверь свои числа с этим текстом, ищи каждое поле».
     *
     * Vision на первом проходе мог проигнорировать text-слой PDF и галлюцинировать
     * по image. На retry'е мы вставляем text повторно, в начале feedback'а — там
     * Vision уделит ему больше внимания.
     */
    private function buildRetryFeedbackPrompt(string $basePrompt, array $items, array $document, ?string $repairedText): string
    {
        $sumTotals = 0.0;
        $rows = [];
        foreach ($items as $idx => $item) {
            $tot = isset($item['total']) && is_numeric($item['total']) ? (float) $item['total'] : 0.0;
            $sumTotals += $tot;
            $rows[] = sprintf(
                '%d) %s: qty=%s × unit_price=%s = total=%s',
                $idx + 1,
                mb_substr((string) ($item['name'] ?? ''), 0, 50),
                $item['quantity'] ?? '?',
                $item['unit_price'] ?? '?',
                $item['total'] ?? '?'
            );
        }
        $totalAmount = (float) ($document['total_amount'] ?? 0);
        $diff = $sumTotals - $totalAmount;

        $feedback = "\n\n═══ ПОВТОРНЫЙ ПРОХОД — ИСПРАВЬ АРИФМЕТИКУ ═══\n"
            ."Прошлый раз ты вернул вот это:\n"
            .implode("\n", $rows)
            ."\n"
            .sprintf("Σ items.total = %.2f\n", $sumTotals)
            .sprintf("document.total_amount = %.2f\n", $totalAmount)
            .sprintf("РАСХОЖДЕНИЕ = %.2f (%.1f%%) — это НЕДОПУСТИМО при prices_include_vat=true\n", $diff, abs($diff) / max($totalAmount, 1) * 100)
            ."\n"
            ."ВОЗМОЖНЫЕ ПРИЧИНЫ ОШИБКИ (проверь по порядку):\n"
            ."\n"
            ."1) РУССКИЙ РАЗДЕЛИТЕЛЬ ТЫСЯЧ ПРОБЕЛОМ (самая частая причина):\n"
            ."   Ты увидел в таблице «Сумма = 11 461.20» (одно число с пробелом-разделителем) "
            ."и взял часть «461.20» как unit_price из колонки «Цена со скидкой». Это ошибка: "
            ."«11 461.20» — это ОДНО число (11461.20) из колонки «Сумма», а реальная «Цена со "
            ."скидкой» в той же строке — другое число (например 286.53).\n"
            ."   Проверь: КАЖДОЕ число твоего ответа должно встречаться в текстовом слое (ниже) "
            ."как САМОСТОЯТЕЛЬНОЕ число. Если число `X` есть в тексте только как часть большего "
            ."(`11 461,20` содержит подстроку `461.20`, но это НЕ значит что 461.20 — отдельное "
            ."число) — ты ошибся, перечитай таблицу.\n"
            ."\n"
            ."2) ПУТАНИЦА КОЛОНОК:\n"
            ."   unit_price = строго колонка «Цена со скидкой» (НЕ «Цена», НЕ «Сумма»).\n"
            ."   total = строго колонка «Сумма» (НЕ «Сумма + НДС», НЕ «Цена со скидкой»).\n"
            ."\n"
            ."3) ПОДВАЛЬНАЯ СКИДКА (реже):\n"
            ."   Не складывай подвальную «Скидка: -Y₽» с per-row значениями — она информационная.\n"
            ."\n"
            ."После пересчёта Σ items.total ОБЯЗАТЕЛЬНО ≈ document.total_amount.\n";

        // Также: для retry даём ПОЛНЫЙ raw-text без 8000-лимита basePrompt'а.
        // Vision получит ground truth для сверки своих cifr.
        if ($repairedText !== null && $repairedText !== '') {
            // Жёстче лимит чтобы не вылететь из max_tokens (16384 = ~50k chars input).
            $textForRetry = mb_substr($repairedText, 0, 24000);
            $feedback .= "\n═══ ТЕКСТОВЫЙ СЛОЙ PDF (используй для сверки) ═══\n"
                ."Ниже — текст, извлечённый из PDF через pdfparser. Каждое число которое ты "
                ."возвращаешь должно встречаться в этом тексте. Если ты НЕ ВИДИШЬ число в "
                ."тексте — значит ты его выдумал, перепиши.\n"
                ."\n"
                ."------- BEGIN RAW PDF TEXT -------\n"
                .$textForRetry."\n"
                ."------- END RAW PDF TEXT -------\n";
        }

        return $basePrompt.$feedback;
    }

    /**
     * Какая попытка ближе к Σ items.total ≈ total_amount? Возвращает её result.
     */
    private function pickBest(array $first, array $second): array
    {
        $d1 = $this->sumDelta($first['document'], $first['items']);
        $d2 = $this->sumDelta($second['document'], $second['items']);
        // Если первая лучше или равна (при равенстве предпочтение первой как «default»).
        if ($d1 !== null && $d2 !== null) {
            return $d1 <= $d2 ? $first : $second;
        }

        return $d2 !== null ? $second : $first;
    }

    /**
     * |Σ items.total - document.total_amount|. null если нет данных.
     */
    private function sumDelta(array $document, array $items): ?float
    {
        $total = isset($document['total_amount']) && is_numeric($document['total_amount'])
            ? (float) $document['total_amount'] : null;
        if ($total === null) {
            return null;
        }
        $sum = 0.0;
        foreach ($items as $item) {
            if (isset($item['total']) && is_numeric($item['total'])) {
                $sum += (float) $item['total'];
            }
        }

        return abs($sum - $total);
    }

    /**
     * Sanity-проверка размерных позиций: GPT иногда ошибочно помечает штучный
     * товар как мерный или наоборот. Проверяем арифметику unit_price × ... ≈ total.
     *
     * Адаптация из LazyLift (Source: 1ea8147d): убрана зависимость от piece-логики
     * (effectivePiece()) — в MyLift размерные позиции редки, fallback на 'м'.
     */
    private function reclassifyMisparsedDimensional(array $items, Request $request): array
    {
        foreach ($items as &$item) {
            $unitQuantity = $item['unit_quantity'] ?? null;
            $unitPrice = $item['unit_price'] ?? null;
            $quantity = $item['quantity'] ?? null;
            $total = $item['total'] ?? null;
            $unitMeasure = trim((string) ($item['unit_measure'] ?? ''));

            if ($unitQuantity === null || $unitPrice === null || $quantity === null || $total === null) {
                continue;
            }

            $unitQuantity = (float) $unitQuantity;
            $unitPrice = (float) $unitPrice;
            $quantity = (float) $quantity;
            $total = (float) $total;

            if ($unitQuantity <= 0 || $unitPrice <= 0 || $quantity <= 0 || $total <= 0) {
                continue;
            }

            $expectedDimensional = $unitPrice * $unitQuantity * $quantity;
            $expectedPerPiece = $unitPrice * $quantity;
            $tol = max(1.0, $total * 0.02);

            $matchesDimensional = abs($expectedDimensional - $total) <= $tol;
            $matchesPerPiece = abs($expectedPerPiece - $total) <= $tol;

            $isPieceLabeled = in_array($unitMeasure, ['шт.', 'шт'], true);

            // A: помечен мерным, но арифметика штучная → переключаем в шт.
            if (! $isPieceLabeled && ! $matchesDimensional && $matchesPerPiece) {
                Log::info('OutboundQuoteParser: reclassified dim→piece', [
                    'request_id' => $request->id,
                    'name' => $item['name'] ?? null,
                ]);
                $item['unit_quantity'] = null;
                $item['unit_measure'] = 'шт.';
                $item['price'] = $unitPrice;

                continue;
            }

            // B: помечен штучным, но unit_quantity>1 и арифметика мерная → переключаем в мерный.
            if ($isPieceLabeled && $unitQuantity > 1 && $matchesDimensional && ! $matchesPerPiece) {
                Log::info('OutboundQuoteParser: reclassified piece→dim', [
                    'request_id' => $request->id,
                    'name' => $item['name'] ?? null,
                ]);
                $item['unit_measure'] = 'м'; // fallback для MyLift (нет piece-метаданных)
                $item['price'] = round($unitPrice * $unitQuantity, 2);
            }
        }
        unset($item);

        return $items;
    }

    /**
     * Привести цены к виду «с НДС», если документ показывает их без НДС.
     * Source: LazyLift @ 1ea8147d normalizeItemsVat (без изменений).
     */
    private function normalizeItemsVat(array $items, array &$document, Request $request): array
    {
        $pricesIncludeVat = $document['prices_include_vat'] ?? null;
        if ($pricesIncludeVat !== false) {
            return $items;
        }

        $subtotal = isset($document['subtotal']) && is_numeric($document['subtotal'])
            ? (float) $document['subtotal'] : null;
        $vatAmount = isset($document['vat_amount']) && is_numeric($document['vat_amount'])
            ? (float) $document['vat_amount'] : null;
        $totalAmount = isset($document['total_amount']) && is_numeric($document['total_amount'])
            ? (float) $document['total_amount'] : null;
        $vatRate = isset($document['vat_rate']) && is_numeric($document['vat_rate'])
            ? (float) $document['vat_rate'] : null;

        $factor = null;
        $factorSource = null;

        if ($subtotal !== null && $subtotal > 0 && $totalAmount !== null && $totalAmount > $subtotal) {
            $factor = $totalAmount / $subtotal;
            $factorSource = 'total/subtotal';
        } elseif ($subtotal !== null && $subtotal > 0 && $vatAmount !== null && $vatAmount > 0) {
            $factor = 1 + ($vatAmount / $subtotal);
            $factorSource = 'vat_amount/subtotal';
        } elseif ($vatRate !== null && $vatRate > 0) {
            $factor = 1 + ($vatRate / 100);
            $factorSource = 'vat_rate';
        }

        if ($factor === null || $factor <= 1.0001) {
            Log::warning('OutboundQuoteParser: VAT normalization skipped (no factor)', [
                'request_id' => $request->id,
                'document' => array_intersect_key($document, array_flip(['subtotal', 'vat_amount', 'total_amount', 'vat_rate'])),
            ]);

            return $items;
        }

        // НДС в РФ с 2026 — 22%. Защита от мусорных множителей.
        if ($factor > 1.30) {
            Log::warning('OutboundQuoteParser: VAT factor clamped to 22%', [
                'request_id' => $request->id,
                'factor_before' => $factor,
                'source' => $factorSource,
            ]);
            $factor = 1.22;
            $factorSource .= ' (clamped)';
        }

        $vatPercent = round(($factor - 1) * 100, 2);

        foreach ($items as &$item) {
            // base_unit_price тоже нормализуем, чтобы он и unit_price оставались
            // в одной системе (обе с НДС), иначе UI «-X%» неверно посчитает скидку.
            foreach (['unit_price', 'base_unit_price', 'price', 'total'] as $field) {
                if (isset($item[$field]) && is_numeric($item[$field])) {
                    $item[$field] = round(((float) $item[$field]) * $factor, 2);
                }
            }
            $existing = trim((string) ($item['notes'] ?? ''));
            $marker = "НДС {$vatPercent}% добавлен при парсинге";
            $item['notes'] = $existing !== '' ? $existing.'; '.$marker : $marker;
            $item['vat_applied'] = true;
        }
        unset($item);

        $document['prices_include_vat'] = true;
        $document['vat_normalized'] = true;
        $document['vat_rate'] = $vatPercent;

        Log::info('OutboundQuoteParser: VAT normalized', [
            'request_id' => $request->id,
            'factor' => $factor,
            'factor_source' => $factorSource,
            'vat_percent' => $vatPercent,
            'items_count' => count($items),
            'total_amount' => $document['total_amount'] ?? null,
        ]);

        return $items;
    }

    /**
     * Промпт парсера. Адаптирован под наш кейс «КП/счёт ОТ нас клиенту»:
     *  - убраны supplier-извлечение и аналог-логика;
     *  - подсказка: M-артикулы (M\d{4,}) — это наши SKU из catalog_items;
     *  - всё остальное (количества штучные/мерные, НДС, split delivery) — как в LazyLift.
     */
    private function buildParsingPrompt(?string $text, Request $request, ?string $emailBody = null): string
    {
        $itemsContext = $request->items
            ->where('is_active', true)
            ->map(function ($item) {
                return sprintf(
                    '- %s (бренд: %s, артикул: %s, кол-во: %s)',
                    $item->parsed_name,
                    $item->parsed_brand ?? 'не указан',
                    $item->parsed_article ?? 'не указан',
                    $item->parsed_qty !== null ? (string) $item->parsed_qty : '1'
                );
            })->join("\n");

        $today = now()->format('Y-m-d');

        $prompt = <<<PROMPT
Ты — парсер коммерческих предложений и счетов, которые НАША компания (MyZip / Лифт-ZIP)
отправляет клиенту. Документ — наш исходящий КП или счёт. Тебе нужно извлечь из
него позиции с ценами/количествами и метаданные документа.
Сегодняшняя дата: {$today}

**У тебя есть ДВА источника данных:**
1. Изображение PDF-страницы (приложено как image).
2. Извлечённый текстовый слой PDF (в конце этого промпта, секция «Извлечённый текст»).

КАЖДОЕ число которое ты возвращаешь в JSON ОБЯЗАТЕЛЬНО должно встречаться в текстовом
слое (как fallback на случай если ты неверно прочитал image). Если в твоём ответе есть
цена/сумма которой нет в тексте — значит ты её ВЫДУМАЛ, перечитай оба источника. Image
полезен для понимания структуры таблицы (какая колонка где), text — для надёжных цифр.

**Контекст заявки клиента:**
Код заявки: M-{$request->code}
Позиции заявки клиента (как они пришли от клиента):
{$itemsContext}

**M-артикулы (M\d{5,})** в документе — это НАШИ внутренние SKU из корпоративного каталога,
а не артикулы поставщика. Сохраняй их в поле `article` как есть. Пример: M07014, M02016, M09431.

КРИТИЧЕСКИ ВАЖНО — РАЗРЫВЫ M-АРТИКУЛОВ:
В шаблоне нашего PDF КП колонка наименования узкая, и M-артикул часто переносится:
последние одна-две цифры съезжают на следующую строку. Если видишь в ячейке/строке
наименования что-то вида:
   «Втулка ступени для оси цепи Kone 13KV (L=90 мм)   M0943»
   «D=12,8 мм X=57,8 мм с тремя отверстиями             1»
— это ОДИН артикул `M09431`, а не `M0943` без последней цифры. M-артикул всегда
имеет 5+ цифр после буквы M, без пробелов и переносов в финальном виде. При парсинге
склеивай разорванный артикул в одну строку. Это критично — `M0943` в каталоге может
не существовать, тогда как `M09431` — реальный товар.

**Твоя задача:**
1. Извлечь метаданные документа (тип: quote/invoice, номер, дата, итоговая сумма, НДС).
2. Извлечь все позиции (название, артикул, бренд, цена, количество, сумма, срок поставки).
Поле `supplier` в ответе можно оставить пустым объектом (это наш собственный документ).

**ВАЖНЫЕ ПРАВИЛА:**

Количество (quantity) — КРИТИЧЕСКИ ВАЖНО:
- quantity: ФАКТИЧЕСКОЕ количество товара из колонки "Кол-во" документа.
- Для штучного товара: если в колонке Кол-во указано "2,00 шт" → quantity: 2.
- Для мерного товара (м, кг): quantity = число СТРОК (после агрегации одинаковых), а unit_quantity = значение из колонки Кол-во.
- Агрегация: если несколько ИДЕНТИЧНЫХ строк (одинаковое название, артикул, цена) — объедини в одну и суммируй количество.
- ПРОВЕРКА: price × quantity должно быть ≈ total. Если не сходится — ты неправильно определил quantity.

**АЛГОРИТМ ОПРЕДЕЛЕНИЯ ШТУЧНЫЙ/МЕРНЫЙ:**

Шаг 1. Смотри на колонку "Кол-во":
- "X шт" / "X штук" / число без единиц → ШТУЧНЫЙ.
- "X м" / "X кг" / "X компл." / "X п.м." → МЕРНЫЙ.

Шаг 2. ШТУЧНЫЙ:
- unit_measure = "шт.", unit_quantity = null.
- unit_price = цена из колонки "Цена" (за 1 штуку).
- price = unit_price, total = unit_price × quantity.

Шаг 3. МЕРНЫЙ:
- unit_measure = единица из "Кол-во" ("м"/"кг"/...), unit_quantity = число.
- unit_price = цена за 1 единицу.
- price = unit_price × unit_quantity, total = price × quantity.

Шаг 4. Финальная арифметика — ОБЯЗАТЕЛЬНО:
- Штучный: unit_price × quantity ≈ total.
- Мерный: unit_price × unit_quantity × quantity ≈ total.

Шаг 5. Инвариант штучного: unit_measure='шт.' → unit_quantity ОБЯЗАТЕЛЬНО = null.

**Цена (price / unit_price):**
- Бери ФИНАЛЬНУЮ цену (со скидкой, если есть колонка «Цена со скидкой»).
- Передавай РОВНО как в документе — без самостоятельного добавления/вычитания НДС.

**КРИТИЧЕСКИ ВАЖНО — РУССКИЕ РАЗДЕЛИТЕЛИ ТЫСЯЧ И ДЕСЯТИЧНЫЕ:**
В российских PDF (особенно Liftway/MyZip) числа форматируются как
«11 461,20» или «11 461.20» — где **ПРОБЕЛ это разделитель тысяч**,
а запятая/точка — десятичный разделитель. Это ОДНО число = 11461.20,
а НЕ два числа («11» и «461.20»).
Это самая частая ловушка Vision на узких колонках:
- НЕВЕРНО: прочитать «11 461.20» как два числа и взять «461.20» как
  unit_price из колонки «Цена со скидкой» — это часть СУММЫ из соседней
  колонки, а не «Цена со скидкой».
- ВЕРНО: «11 461.20» это одно число (11461.20), которое стоит в колонке
  «Сумма». «Цена со скидкой» в той же строке — другое число (например
  286.53), которое стоит левее.
Чтобы не ошибиться: сверь каждое число с текстовым слоем PDF (внизу
этого промпта). Текст содержит каждое число целиком — если в твоём
ответе число `X` встречается в тексте только как часть большего числа
(например ты вернул `461.20`, а в тексте есть только `11 461,20`) —
значит ты обрезал чужое число, перечитай таблицу.

**ТИПОВОЙ ШАБЛОН КП Liftway / партнёрских PDF — ВНИМАНИЕ:**
В таблице позиций часто 5+ числовых колонок подряд:
   «Цена» | «% Скидка» | «Цена со скидкой» | «Сумма» | «НДС в т.ч.»
Правила выбора значений:
- `unit_price` = значение из колонки «Цена со скидкой» (НЕ из «Цена» — это базовая до скидки).
- `base_unit_price` = значение из колонки «Цена» (розничная/базовая, ДО скидки), если такая
  колонка есть. Если колонки «Цена» нет (скидка не применялась) — null.
- `discount_percent` = значение из колонки «% Скидка» как число (например 45.24, не "45,24%"
  и не 0.4524). Если колонка отсутствует — null.
- Инвариант: если оба известны, `base_unit_price * (1 - discount_percent/100) ≈ unit_price` (±1%).
- `total` = значение из колонки «Сумма» (это per-row итог = Цена со скидкой × Кол-во).
- НИКОГДА не складывай «Сумму» строки со скидкой из подвала документа: подвальная скидка
  («Скидка %X: -Y руб») уже встроена в построчные «Цена со скидкой» / «Сумма»; она там
  только для информации и не должна добавляться повторно. Если в подвале есть строка
  «Скидка: -6 986.80» и одновременно построчные «Цена со скидкой» — `total` строк это
  «Сумма», не «Сумма + скидка».
- НИКОГДА не складывай «Сумму» строки с её «НДС в т.ч.»: НДС уже включён в Сумму
  (если prices_include_vat=true).

**КРОСС-ПРОВЕРКА АРИФМЕТИКИ — ОБЯЗАТЕЛЬНО:**
После выбора всех `unit_price`/`total` для items проверь:
   Σ items[*].total ≈ document.total_amount   (с допуском 2%)
если prices_include_vat=true. Если расхождение БОЛЬШЕ 5% — где-то ошибка:
- проверь, не прибавил ли ты подвальную скидку к одной из строк (типичный hallucination
  pattern: первая строка завышена ровно на величину подвальной скидки);
- проверь, не взял ли ты «Цена» (базовая) вместо «Цена со скидкой» в какой-то строке;
- переиграй цены и проверь снова.

Если в документе есть подвальная общая скидка («Скидка: -X руб») И построчные «Цена со
скидкой» — это означает что построчная скидка УЖЕ применена, подвальная строка чисто
информационная (показывает совокупную сумму скидки по всем строкам).

**НДС — извлеки три суммы раздельно:**
- subtotal: "Итого" / "Итого без НДС" / "Сумма без НДС". null если нет.
- vat_amount: "Сумма НДС" / "НДС" / "В том числе НДС". null если не выделен.
- total_amount: "Всего к оплате" / "К оплате" / "Итого с НДС". Если есть и "Итого" и "Всего к оплате" — бери "Всего к оплате".
- vat_rate: процент только если явно написан ("НДС 22%"). Иначе null. С 2026 в РФ стандартная ставка — 22%, но полагайся на документ.
- prices_include_vat: true/false/null. false если над таблицей "Цены без НДС" или субтотал+vat_amount < total. true если "в т.ч. НДС" или цены сразу с НДС.

**Срок поставки (delivery_days) — в РАБОЧИХ днях:**
- "В наличии" → 0
- "Под заказ X нед." → X × 5
- "X дней" → ceil(X × 5 / 7)
- Дата "ДД.ММ.ГГГГ" — разница с {$today} в рабочих днях.
- null если не указано.

**Срок действия документа (valid_until) — КАЛЕНДАРНАЯ дата YYYY-MM-DD:**
Это дата, ДО которой счёт действителен / зарезервированы позиции на складе / нужно
оплатить (для КП — до которой действует предложение/цены). Ищи формулировки:
«счёт действителен до», «срок действия счёта», «действителен до», «оплатить до»,
«оплата до», «резерв до», «бронь до», «срок резерва», «позиции зарезервированы до»,
«цены действительны до», «предложение действительно до».
- Источник — И сам документ, И сопроводительное письмо (секция «Текст сопроводительного
  письма» ниже). Если дата есть в письме — используй её.
- НЕ путай со сроком поставки (delivery_days) и с датой выставления документа (date).
- Возвращай valid_until ТОЛЬКО если в тексте есть явная КАЛЕНДАРНАЯ дата. Если указан
  лишь период («действителен 5 рабочих/банковских дней», «оплатить в течение 3 дней»),
  но без конкретной даты — верни null, срок посчитает сама система.
- null если явной даты нет.

**Формат ответа (строго JSON):**
```json
{
  "supplier": {},
  "document": {
    "type": "quote|invoice",
    "number": "Номер документа",
    "date": "YYYY-MM-DD",
    "valid_until": "YYYY-MM-DD",
    "subtotal": 130000,
    "vat_amount": 28600,
    "total_amount": 158600,
    "vat_rate": null,
    "prices_include_vat": false
  },
  "items": [
    {
      "name": "Ролик направляющий ARO",
      "article": "M07014",
      "brand": "ARO",
      "quantity": 11,
      "unit_quantity": null,
      "unit_measure": "шт.",
      "unit_price": 9630.31,
      "base_unit_price": 12037.89,
      "discount_percent": 20.00,
      "price": 9630.31,
      "total": 105933.41,
      "delivery_days": 0,
      "notes": "Скидка 20%",
      "is_analog": false,
      "qty_available": 11
    }
  ]
}
```

**SPLIT DELIVERY** — если для одной позиции указано «X шт в наличии, Y шт под заказ» —
создавай ДВЕ записи (quantity=X, delivery_days=0 + quantity=Y, delivery_days=срок).

**Правила формата:**
- Только валидный JSON (без markdown, без комментариев).
- Не найдено — `null`.
- Цены и суммы — числа, не строки.
- Дата — YYYY-MM-DD.

PROMPT;

        if (! empty($text)) {
            $prompt .= "\n\n**Извлечённый текст:**\n".mb_substr($text, 0, 8000);
        }

        // Сопроводительное письмо — второй источник для valid_until (срок резерва
        // нередко пишут в теле письма, а не в самом счёте). Только для извлечения
        // даты действия/резерва; позиции и суммы берём из документа.
        if ($emailBody !== null && trim($emailBody) !== '') {
            $prompt .= "\n\n**Текст сопроводительного письма:**\n".mb_substr(trim($emailBody), 0, 4000);
        }

        return $prompt;
    }

    /**
     * Склеить разорванные переносом M-артикулы в raw тексте.
     *
     * Паттерн PDF МЗ-NNNNNN: M-артикул может разорваться на колонку наименования,
     * последние 1-2 цифры съезжают на следующую строку. После cyrillic-fold
     * («М» → «M») мы ищем `M\d{4,6}` за которым через optional whitespace +
     * перенос идёт 1-2 цифры, и слепляем их. Не трогаем артикул если за
     * символом-цифрой не следует word-boundary — иначе можно случайно склеить
     * с цифрой из соседней колонки.
     *
     * Идемпотентно — повторное применение к уже склеенному не меняет результат.
     */
    private function repairBrokenMSkus(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $folded = CatalogImportService::cyrillicLookalikeFold($text);
        // До 3 итераций — на случай если артикул разорван дважды (очень редко,
        // но дёшево перестраховаться). Останавливаемся когда нет изменений.
        for ($i = 0; $i < 3; $i++) {
            $new = preg_replace(
                '/(M\d{4,6})[ \t\xC2\xA0]*\r?\n[ \t\xC2\xA0]*(\d{1,2})(?=[^0-9A-Za-zА-Яа-я]|$)/u',
                '$1$2',
                $folded
            );
            if ($new === null || $new === $folded) {
                break;
            }
            $folded = $new;
        }

        return $folded;
    }

    /**
     * Если LLM вернул article в виде «M+4 цифры», а в исходном (отремонтированном)
     * тексте есть удлинённый вариант с тем же префиксом (`M\d{5,}` начинающийся
     * с возвращённой строки) — заменяем. Безопасная страховка на случай если
     * хинт в промпте не сработал.
     */
    private function repairItemArticles(array $items, ?string $text, Request $request): array
    {
        if ($text === null || $text === '') {
            return $items;
        }

        foreach ($items as &$item) {
            $art = isset($item['article']) ? (string) $item['article'] : '';
            if ($art === '') {
                continue;
            }
            $folded = CatalogImportService::cyrillicLookalikeFold($art);
            if (preg_match('/^M\d{4}$/u', $folded) !== 1) {
                continue;
            }
            // Ищем M{folded}\d+ в исходном тексте.
            $pattern = '/(?<![0-9A-Za-zА-Яа-я])'.preg_quote($folded, '/').'(\d{1,3})(?![0-9A-Za-zА-Яа-я])/u';
            if (preg_match($pattern, $text, $m) === 1) {
                $extended = $folded.$m[1];
                Log::info('OutboundQuoteParser: extended truncated M-SKU from raw text', [
                    'request_id' => $request->id,
                    'before' => $art,
                    'after' => $extended,
                ]);
                $item['article'] = $extended;
            }
        }
        unset($item);

        return $items;
    }

    /**
     * Кросс-валидатор арифметики: Σ items[].total vs document.total_amount.
     *
     * Кейс quote_id=2 (МЗ-355534): Vision прилетел с row 1 завышенным ровно на
     * величину подвальной общей скидки. Σ items.total = 47 181.70, document.total
     * = 40 194.90, расхождение 6 986.80 = ровно подвальная скидка. Это надёжный
     * сигнал «Vision переложил подвальную скидку на одну из строк».
     *
     * Здесь мы только ДЕТЕКТИМ — не правим. Решение за оператором (ручной
     * доматчинг + correction в Phase следующая, либо reparse).
     *
     * @return list<array{type: string, message: string, expected?: float, got?: float, diff?: float, diff_pct?: float, suspect_item_index?: int}>
     */
    private function validateLineTotals(array $items, array $document, Request $request): array
    {
        $warnings = [];

        $totalAmount = isset($document['total_amount']) && is_numeric($document['total_amount'])
            ? (float) $document['total_amount'] : null;
        $pricesIncludeVat = $document['prices_include_vat'] ?? null;

        // 1) Per-row check: |unit_price × quantity - total| ≤ 2%.
        foreach ($items as $idx => $item) {
            $up = isset($item['unit_price']) && is_numeric($item['unit_price']) ? (float) $item['unit_price'] : null;
            $qty = isset($item['quantity']) && is_numeric($item['quantity']) ? (float) $item['quantity'] : null;
            $tot = isset($item['total']) && is_numeric($item['total']) ? (float) $item['total'] : null;
            // Мерный товар считается по unit_price × unit_quantity × quantity.
            $uq = isset($item['unit_quantity']) && is_numeric($item['unit_quantity']) ? (float) $item['unit_quantity'] : null;

            if ($up === null || $qty === null || $tot === null || $up <= 0 || $qty <= 0) {
                continue;
            }
            $expected = $uq !== null && $uq > 0 ? $up * $uq * $qty : $up * $qty;
            if ($tot <= 0) {
                continue;
            }
            $diff = abs($expected - $tot);
            if ($diff / max($tot, 1.0) > 0.02) {
                $warnings[] = [
                    'type' => 'row_arithmetic_mismatch',
                    'message' => sprintf(
                        'Поз %d (%s): unit_price × qty = %.2f, total = %.2f, расхождение %.2f',
                        $idx + 1,
                        mb_substr((string) ($item['name'] ?? ''), 0, 40),
                        $expected,
                        $tot,
                        $diff
                    ),
                    'suspect_item_index' => $idx,
                    'expected' => round($expected, 2),
                    'got' => round($tot, 2),
                    'diff' => round($diff, 2),
                ];
            }
        }

        // 2) Document check: Σ items.total ≈ document.total_amount (если prices_include_vat=true).
        if ($totalAmount !== null && $totalAmount > 0 && $pricesIncludeVat === true) {
            $sumTotals = 0.0;
            foreach ($items as $item) {
                if (isset($item['total']) && is_numeric($item['total'])) {
                    $sumTotals += (float) $item['total'];
                }
            }
            if ($sumTotals > 0) {
                $diff = $sumTotals - $totalAmount;
                $diffPct = abs($diff) / $totalAmount;
                if ($diffPct > 0.02) {
                    $warnings[] = [
                        'type' => 'sum_vs_total_mismatch',
                        'message' => sprintf(
                            'Σ items.total (%.2f) ≠ document.total_amount (%.2f); расхождение %.2f (%.1f%%)',
                            $sumTotals,
                            $totalAmount,
                            $diff,
                            $diffPct * 100
                        ),
                        'expected' => round($totalAmount, 2),
                        'got' => round($sumTotals, 2),
                        'diff' => round($diff, 2),
                        'diff_pct' => round($diffPct * 100, 1),
                    ];
                }
            }
        }

        if (! empty($warnings)) {
            Log::warning('OutboundQuoteParser: validation warnings', [
                'request_id' => $request->id,
                'warnings' => $warnings,
            ]);
        }

        return $warnings;
    }

    /**
     * Извлечение JSON из ответа LLM (response_format=json_object обычно возвращает чистый JSON,
     * но иногда модель оборачивает в ```json блок).
     * Source: LazyLift @ 1ea8147d extractJSON (без изменений).
     */
    private function extractJSON(string $content): ?string
    {
        $trimmed = trim($content);
        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            return $trimmed;
        }

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            return $matches[1];
        }

        $start = strpos($content, '{');
        if ($start !== false) {
            $depth = 0;
            $len = strlen($content);
            for ($i = $start; $i < $len; $i++) {
                if ($content[$i] === '{') {
                    $depth++;
                } elseif ($content[$i] === '}') {
                    $depth--;
                }
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
