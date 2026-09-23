<?php

namespace App\Services\Marketing;

use App\Models\MediaProfileEntry;
use App\Models\MediaProfileGuide;
use App\Models\User;
use App\Prompts\Marketing\BuildGuidePrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Памятка по фирменному стилю из медиапрофиля.
 *
 * Профиль удобен машине — плоский список проверяемых утверждений. Человеку
 * нужен связный текст по жанрам: как писать новость, каким быть объявлению,
 * что недопустимо никогда. Собираем одно из другого и храним версиями:
 * памятку отдают подрядчику, и потом важно знать, какую именно редакцию он
 * получил.
 */
class MediaProfileGuideService
{
    public function __construct(private readonly OpenAIChatService $openai) {}

    /**
     * @return array{ok: bool, guide: ?MediaProfileGuide, message: string}
     */
    public function build(?User $author): array
    {
        $profile = MediaProfileEntry::asBrief();
        if ($profile === '') {
            return ['ok' => false, 'guide' => null, 'message' => 'Медиапрофиль пуст — собирать памятку не из чего.'];
        }

        $model = (string) config('services.openai.media_profile_model', 'gpt-4o');

        try {
            $response = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => BuildGuidePrompt::systemMessage()],
                    ['role' => 'user', 'content' => BuildGuidePrompt::userMessage($profile)],
                ],
                $model,
                ['temperature' => 0.3],
            );
        } catch (\Throwable $e) {
            Log::error('MediaProfileGuideService: модель не ответила', ['error' => $e->getMessage()]);

            return ['ok' => false, 'guide' => null, 'message' => 'Не удалось собрать памятку: '.$e->getMessage()];
        }

        $body = trim((string) ($response['content'] ?? ''));
        if ($body === '') {
            return ['ok' => false, 'guide' => null, 'message' => 'Модель вернула пустой ответ — попробуйте ещё раз.'];
        }

        $guide = MediaProfileGuide::create([
            'body' => $body,
            'profile_snapshot' => $profile,
            'entries_count' => MediaProfileEntry::query()->active()->count(),
            'model' => $model,
            'created_by_user_id' => $author?->id,
        ]);

        return ['ok' => true, 'guide' => $guide, 'message' => 'Памятка собрана из '.$guide->entries_count.' утверждений профиля.'];
    }

    public function latest(): ?MediaProfileGuide
    {
        return MediaProfileGuide::query()->with('author:id,name')->orderByDesc('id')->first();
    }
}
