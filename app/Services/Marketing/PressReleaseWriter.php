<?php

namespace App\Services\Marketing;

use App\Models\MediaProfileEntry;
use App\Models\PressRelease;
use App\Prompts\Marketing\WritePressReleasePrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Черновик пресс-релиза по инфоповоду: заголовок, тема письма,
 * сопроводительный текст и сам релиз. Без фактов инфоповода не пишет —
 * релиз «ни о чём» придётся выдумать.
 */
class PressReleaseWriter
{
    public function __construct(private readonly OpenAIChatService $openai)
    {
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function write(PressRelease $release): array
    {
        $occasion = trim((string) $release->occasion);
        if ($occasion === '') {
            return ['ok' => false, 'message' => 'Опишите инфоповод: что произошло, когда, цифры, кто может прокомментировать. Без фактов релиз получится выдуманным.'];
        }

        $profile = MediaProfileEntry::asBrief();
        if ($profile === '') {
            return ['ok' => false, 'message' => 'Медиапрофиль пуст — справку о компании писать не от чего.'];
        }

        $model = (string) config('services.marketing.press.model', 'gpt-4o');
        $messages = [
            ['role' => 'system', 'content' => WritePressReleasePrompt::systemMessage()],
            ['role' => 'user', 'content' => WritePressReleasePrompt::userMessage($profile, (string) $release->title, $occasion)],
        ];

        try {
            $response = $this->openai->chat($messages, $model, ['response_format' => ['type' => 'json_object'], 'temperature' => 0.3]);
        } catch (\Throwable $e) {
            Log::error('PressReleaseWriter: модель не ответила', ['press_release_id' => $release->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Не удалось написать релиз: '.$e->getMessage()];
        }

        $parsed = json_decode((string) ($response['content'] ?? ''), true);
        if (! is_array($parsed) || trim((string) ($parsed['body'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'Модель ответила не по форме — попробуйте ещё раз.'];
        }

        $release->forceFill([
            'title' => mb_substr(trim((string) ($parsed['title'] ?? '')) ?: (string) $release->title, 0, 300),
            'subject' => mb_substr(trim((string) ($parsed['subject'] ?? '')), 0, 300) ?: null,
            'cover_note' => trim((string) ($parsed['cover_note'] ?? '')) ?: null,
            'body' => trim((string) $parsed['body']),
            'status' => $release->status === 'idea' ? 'draft' : $release->status,
            'model' => $model,
        ])->save();

        $notes = trim((string) ($parsed['notes'] ?? ''));

        return ['ok' => true, 'message' => 'Черновик готов.'.($notes !== '' ? ' Проверьте: '.$notes : '')];
    }
}
