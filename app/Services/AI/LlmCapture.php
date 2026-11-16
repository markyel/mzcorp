<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Запись живых запросов к модели для сравнения моделей (llm:compare-captured, 16.11.2026).
 *
 * Задачи, которые сами пишут результат в базу (контекст заявки, фото, категории позиций), вхолостую
 * не вызвать — поэтому пишем их настоящие запросы вместе с ответом текущей модели и потом прогоняем
 * те же запросы на кандидатах. Включается списком задач (класс-вызыватель) в ROUTERAI_CAPTURE,
 * не больше ROUTERAI_CAPTURE_LIMIT файлов на задачу. Сбой записи работу не ломает.
 */
class LlmCapture
{
    public static function record(string $model, array $messages, array $options, string $content): void
    {
        try {
            $callers = array_filter(array_map('trim', explode(',', (string) config('services.routerai.capture'))));
            if ($callers === []) {
                return;
            }
            $caller = AiUsageMeter::caller();
            if (! in_array($caller, $callers, true)) {
                return;
            }
            $dir = self::dir($caller);
            File::ensureDirectoryExists($dir);
            if (count(File::files($dir)) >= (int) config('services.routerai.capture_limit', 40)) {
                return;
            }
            File::put($dir.'/'.now()->format('Ymd-His').'-'.Str::random(6).'.json', json_encode([
                'caller' => $caller,
                'model' => $model,
                'messages' => $messages,
                'options' => $options,
                'content' => $content,
                'captured_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::debug('LlmCapture: not recorded', ['error' => $e->getMessage()]);
        }
    }

    public static function dir(string $caller): string
    {
        return storage_path('app/llm-capture/'.$caller);
    }
}
