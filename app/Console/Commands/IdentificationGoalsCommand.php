<?php

namespace App\Console\Commands;

use App\Models\Identification\IdentificationHint;
use App\Models\Identification\IdentificationPartType;
use App\Services\AI\OpenAIChatService;
use App\Services\Catalog\IdentificationHintService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Цели вопросов справочника опознания — какие данные вопрос должен дать (по ним IdentificationCoverageService
 * решает, есть ли ответ у позиции). Составляет модель для вопросов без цели, по типу детали за раз; поправить —
 * на странице «Опознание». Раз в день после identification:learn.
 *
 *   php artisan identification:goals
 */
class IdentificationGoalsCommand extends Command
{
    protected $signature = 'identification:goals';

    protected $description = 'Справочник опознания: цели вопросов (какие данные нужны)';

    public function handle(OpenAIChatService $chat): int
    {
        $filled = 0;
        $types = IdentificationPartType::query()->whereHas('hints', fn ($q) => $q->whereNull('goal'))->with('hints')->get();
        foreach ($types as $type) {
            $todo = $type->hints->whereNull('goal')->values();
            $list = $todo->map(fn (IdentificationHint $h, $i) => ($i + 1).') '.$h->label.' — '.$h->question)->implode("\n");
            try {
                $r = $chat->chat([
                    ['role' => 'system', 'content' => self::PROMPT],
                    ['role' => 'user', 'content' => 'ТИП ДЕТАЛИ: '.$type->name."\nВОПРОСЫ:\n".$list],
                ], 'gpt-4o-mini', ['response_format' => ['type' => 'json_object'], 'temperature' => 0, 'max_tokens' => 1500]);
                $goals = collect((array) (json_decode((string) ($r['content'] ?? ''), true)['goals'] ?? []))->keyBy(fn ($g) => (int) ($g['n'] ?? 0));
            } catch (\Throwable $e) {
                Log::warning('identification:goals failed', ['part_type_id' => $type->id, 'error' => $e->getMessage()]);

                continue;
            }
            foreach ($todo as $i => $hint) {
                $goal = trim((string) ($goals[$i + 1]['goal'] ?? ''));
                if ($goal !== '') {
                    $hint->update(['goal' => mb_substr($goal, 0, 300)]);
                    $filled++;
                }
            }
        }
        IdentificationHintService::forget();
        $this->info('Целей составлено: '.$filled.'.');

        return self::SUCCESS;
    }

    private const PROMPT = <<<'P'
Справочник вопросов клиенту, чтобы опознать запчасть для лифта или эскалатора. Для каждого вопроса (n — номер)
напиши его ЦЕЛЬ — какие данные он должен дать, коротко, 3–10 слов. У вопроса «фото …» цель — что с фото нужно
прочитать или увидеть («модель и маркировка с корпуса», «форма и крепление ролика, размеры»), а не само фото.
Цель — именно данные, одна вещь, без слов «визуальная идентификация», «оценить», «определить совместимость»:
«модель/артикул платы», «марка и модель лифта», «диаметр и число ручьёв».
Ответ строго JSON: {"goals": [{"n": 1, "goal": "..."}]}.
P;
}
