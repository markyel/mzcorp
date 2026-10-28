<?php

namespace App\Services\Marketing;

use App\Models\MarketingAssignment;
use App\Prompts\Marketing\WriteAssignmentPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Подробная инструкция к поручению по короткой постановке (модель).
 */
class MarketingAssignmentWriter
{
    public function __construct(private readonly OpenAIChatService $openai)
    {
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function write(MarketingAssignment $assignment): array
    {
        $brief = trim((string) $assignment->brief);
        if ($brief === '') {
            return ['ok' => false, 'message' => 'Опишите коротко, что нужно сделать: из этого пишется инструкция.'];
        }

        $assignment->loadMissing('files');
        $files = $assignment->files
            ->map(fn ($f) => $f->filename.($f->note ? ' — '.$f->note : ''))
            ->values()->all();

        $model = (string) config('services.marketing.assignment_model', 'gpt-4o');
        $messages = [
            ['role' => 'system', 'content' => WriteAssignmentPrompt::systemMessage()],
            ['role' => 'user', 'content' => WriteAssignmentPrompt::userMessage(
                (string) $assignment->title,
                $brief,
                $assignment->executor,
                $assignment->due_on?->format('d.m.Y'),
                $files,
            )],
        ];

        try {
            $response = $this->openai->chat($messages, $model, ['response_format' => ['type' => 'json_object'], 'temperature' => 0.3]);
        } catch (\Throwable $e) {
            Log::error('MarketingAssignmentWriter: модель не ответила', ['assignment_id' => $assignment->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Не удалось написать инструкцию: '.$e->getMessage()];
        }

        $parsed = json_decode((string) ($response['content'] ?? ''), true);
        if (! is_array($parsed) || trim((string) ($parsed['instructions'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'Модель ответила не по форме — попробуйте ещё раз.'];
        }

        $assignment->forceFill([
            'instructions' => trim((string) $parsed['instructions']),
            'model' => $model,
        ])->save();

        $notes = trim((string) ($parsed['notes'] ?? ''));

        return ['ok' => true, 'message' => 'Инструкция готова.'.($notes !== '' ? ' Уточните перед передачей: '.$notes : '')];
    }
}
