<?php

namespace App\Services\Mail;

use App\Models\EmailAttachment;
use App\Prompts\Mail\ExtractAttachmentMetaPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\RequestItemParsingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Извлекает справочную инфу из ВЛОЖЕНИЯ заявки (xlsx / pdf / docx):
 * серийник лифта, модель, объект, договор, контактное лицо, желаемая дата,
 * ссылки.
 *
 * Результат складывается в requests.parsing_meta.attachment_extracted[]
 * через RequestItemPersister (когда тот сохраняет позиции из того же
 * вложения).
 *
 * Fail-soft: любая ошибка логируется, возвращает пустой массив.
 * Дешёвый focused-промпт, gpt-4o-mini.
 */
class AttachmentMetaExtractionService
{
    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly RequestItemParsingService $parser,
    ) {
    }

    /**
     * Извлечь справку из одного вложения.
     *
     * @return array{filename: string, source: string, fields: array<string,mixed>}|null
     */
    public function extractFromAttachment(EmailAttachment $att): ?array
    {
        if (! config('services.openai.attachment_meta_enabled', true)) {
            return null;
        }

        $filename = (string) ($att->filename ?? '');
        if ($filename === '') {
            return null;
        }
        if (preg_match('/\.(xlsx|xls|pdf|docx)$/i', $filename) !== 1) {
            return null;
        }

        try {
            $absolutePath = Storage::disk($att->disk)->path($att->file_path);
            if (! file_exists($absolutePath)) {
                return null;
            }
            $upload = new UploadedFile(
                $absolutePath,
                $filename,
                $att->mime_type,
                null,
                true,
            );
            $text = $this->parser->extractTextFromFile($upload);
        } catch (\Throwable $e) {
            Log::info('AttachmentMetaExtraction: extract text failed', [
                'attachment_id' => $att->id,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (mb_strlen(trim($text)) < 20) {
            return null;
        }

        try {
            $result = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => ExtractAttachmentMetaPrompt::systemMessage()],
                    ['role' => 'user',   'content' => ExtractAttachmentMetaPrompt::userMessage($text, $filename)],
                ],
                config('services.openai.attachment_meta_model', 'gpt-4o-mini'),
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0],
            );
        } catch (\Throwable $e) {
            Log::warning('AttachmentMetaExtraction: LLM call failed', [
                'attachment_id' => $att->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $parsed = json_decode($result['content'] ?? '', true);
        $fields = is_array($parsed) ? ($parsed['fields'] ?? null) : null;
        if (! is_array($fields)) {
            return null;
        }

        $fields = $this->normalizeFields($fields);
        if (empty($fields)) {
            return null;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return [
            'filename' => $filename,
            'source' => "{$ext}_attachment_{$att->id}",
            'fields' => $fields,
        ];
    }

    /**
     * Нормализация: убираем пустые строки, обрезаем длину текстовых полей,
     * фильтруем links (валидные URL, unique).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function normalizeFields(array $fields): array
    {
        $out = [];
        $stringKeys = [
            'lift_serial', 'lift_model', 'lift_brand',
            'object_address', 'object_name',
            'contract_number', 'desired_date',
            'contact_person', 'contact_phone',
            'notes',
        ];
        foreach ($stringKeys as $k) {
            $v = $fields[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $out[$k] = mb_substr(trim($v), 0, 500);
            }
        }
        if (isset($fields['links']) && is_array($fields['links'])) {
            $links = array_values(array_unique(array_filter(array_map(
                fn ($u) => is_string($u) ? trim($u) : null,
                $fields['links'],
            ), fn ($u) => $u !== null && $u !== '' && filter_var($u, FILTER_VALIDATE_URL))));
            if (! empty($links)) {
                $out['links'] = array_slice($links, 0, 20);
            }
        }
        return $out;
    }
}
