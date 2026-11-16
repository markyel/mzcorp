<?php

namespace App\Console\Commands;

use App\Services\AI\LlmCapture;
use App\Services\AI\OpenAIChatService;
use App\Services\AI\RouterAiGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Сравнение моделей на записанных живых запросах (LlmCapture, ROUTERAI_CAPTURE): тот же запрос — кандидатам,
 * ответ — с ответом текущей модели по полям JSON. Свободный текст (пояснения, описания, уверенность)
 * не сравнивается: только значения — категории, бренды, модели, номера фото, слоты.
 * Текущая модель (openai/gpt-4o) повторно — потолок: насколько она согласна сама с собой.
 *
 *   php artisan llm:compare-captured RequestContextAnalysisService --models=openai/gpt-4o,google/gemini-3.5-flash-lite
 */
class LlmCompareCapturedCommand extends Command
{
    protected $signature = 'llm:compare-captured
        {caller : задача (класс-вызыватель), как в storage/app/llm-capture}
        {--models=openai/gpt-4o,google/gemini-3.5-flash-lite,google/gemini-3.1-flash-lite,deepseek/deepseek-v4.1-flash : кандидаты через RouterAI}
        {--show=8 : сколько расхождений показать}
        {--system= : подменить системное сообщение текущей версией промпта, «Класс::метод» (проверка правок промпта)}
        {--reference= : эталон — ответ этой модели заново (с --system), а не записанный ответ}
        {--ignore= : ещё поля не сравнивать (регулярка по имени ключа), напр. «label|raw_mention|address»}';

    protected $description = 'Прогнать записанные живые запросы на других моделях и сравнить ответы по полям JSON';

    /** Поля со свободным текстом или оценкой — не сравниваем. */
    private const SKIP_KEYS = '/reason|descr|comment|explan|note|evidence|summary|quote|confidence|rationale|why|text/i';

    public function handle(RouterAiGateway $gateway): int
    {
        $files = File::exists(LlmCapture::dir((string) $this->argument('caller'))) ? File::files(LlmCapture::dir((string) $this->argument('caller'))) : [];
        if ($files === []) {
            $this->error('Записей нет: '.LlmCapture::dir((string) $this->argument('caller')));

            return self::FAILURE;
        }
        $cases = array_map(fn ($f) => json_decode(File::get($f->getPathname()), true), $files);
        $this->info('Записей: '.count($cases).', модель в записи: '.($cases[0]['model'] ?? '?'));
        config(['services.routerai.by_caller' => '{}', 'services.routerai.models' => '{}']);
        if ($this->option('system')) {
            [$class, $method] = explode('::', (string) $this->option('system'));
            $ref = new \ReflectionMethod($class, $method);
            $ref->setAccessible(true);
            $system = (string) $ref->invoke(null);
            foreach ($cases as &$c) {
                foreach ($c['messages'] as &$m) {
                    if (($m['role'] ?? '') === 'system') {
                        $m['content'] = $system;
                    }
                }
                unset($m);
            }
            unset($c);
        }
        if ($reference = (string) $this->option('reference')) {
            config(['services.routerai.default_model' => $reference]);
            foreach ($cases as $i => $c) {
                $response = $gateway->post(array_merge($c['options'] ?? [], ['model' => 'x', 'messages' => $c['messages']]));
                $cases[$i]['content'] = RouterAiGateway::shouldFallback($response) ? '{}' : (string) $response->json('choices.0.message.content');
            }
            $this->info("Эталон пересчитан: {$reference}");
        }

        $rows = [];
        $report = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('models')))) as $variant) {
            config(['services.routerai.default_model' => $variant]);
            $sum = ['f1' => 0.0, 'exact' => 0, 'failed' => 0, 'seconds' => 0.0, 'prompt' => 0, 'completion' => 0];
            $shown = 0;
            foreach ($cases as $i => $c) {
                $t = microtime(true);
                $answer = null;
                try {
                    $response = $gateway->post(array_merge($c['options'] ?? [], ['model' => 'x', 'messages' => $c['messages']]));
                    $answer = RouterAiGateway::shouldFallback($response) ? null : (string) $response->json('choices.0.message.content');
                    $sum['prompt'] += (int) $response->json('usage.prompt_tokens');
                    $sum['completion'] += (int) $response->json('usage.completion_tokens');
                } catch (\Throwable $e) {
                    $this->warn("  [{$variant}] #{$i}: ".mb_substr($e->getMessage(), 0, 150));
                }
                $sum['seconds'] += microtime(true) - $t;
                if ($answer === null || ! is_array($b = self::decode($answer))) {
                    $sum['failed']++;

                    continue;
                }
                $a = self::facts(self::decode((string) $c['content']) ?? [], '', (string) $this->option('ignore'));
                $b = self::facts($b, '', (string) $this->option('ignore'));
                $f1 = self::f1($a, $b);
                $sum['f1'] += $f1;
                $sum['exact'] += $f1 >= 0.999 ? 1 : 0;
                $report[$i][$variant] = ['f1' => round($f1, 2), 'only_current' => array_values(array_diff($a, $b)), 'only_candidate' => array_values(array_diff($b, $a))];
                if ($f1 < 0.999 && $shown++ < (int) $this->option('show')) {
                    $this->line("  [{$variant}] #{$i} совпадение ".round($f1 * 100).'%: у текущей '.mb_substr(implode('; ', array_diff($a, $b)), 0, 220).' | у кандидата '.mb_substr(implode('; ', array_diff($b, $a)), 0, 220));
                }
            }
            $n = count($cases);
            $price = LlmCompareParsingCommand::RUB_PER_M[$variant] ?? [0, 0];
            $rows[] = [$variant, $n, round($sum['f1'] * 100 / max(1, $n - $sum['failed'])).'%', $sum['exact'].' / '.$n, $sum['failed'],
                round($sum['seconds'] / $n, 1), round(($sum['prompt'] * $price[0] + $sum['completion'] * $price[1]) / 1_000_000, 2)];
        }

        $this->newLine();
        $this->table(['модель', 'запросов', 'совпадение полей с текущей', 'ответ целиком такой же', 'сбоев', 'сек/запрос', '₽ на выборку'], $rows);
        $path = storage_path('app/llm-compare/captured-'.$this->argument('caller').'-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('Подробности: '.$path);

        return self::SUCCESS;
    }

    private static function decode(string $content): ?array
    {
        $content = trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($content)));
        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Ответ → список «путь=значение» без номеров в списках (порядок не важен) и без свободного текста.
     *
     * @return list<string>
     */
    public static function facts(array $data, string $path = '', string $ignore = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && (preg_match(self::SKIP_KEYS, $key) || ($ignore !== '' && preg_match('/'.$ignore.'/i', $key)))) {
                continue;
            }
            $p = $path.(is_int($key) ? '[]' : '.'.$key);
            if (is_array($value)) {
                $out = [...$out, ...self::facts($value, $p, $ignore)];
            } elseif ($value !== null && $value !== '' && ! (is_string($value) && mb_strlen($value) > 60)) {
                $v = is_bool($value) ? ($value ? 'true' : 'false') : mb_strtolower(trim((string) $value));
                $out[] = $p.'='.$v;
            }
        }

        return $out;
    }

    /** F1 по мультимножествам фактов; оба пустые — полное совпадение. */
    public static function f1(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 1.0;
        }
        $left = array_count_values($a);
        $common = 0;
        foreach ($b as $x) {
            if (($left[$x] ?? 0) > 0) {
                $left[$x]--;
                $common++;
            }
        }

        return 2 * $common / (count($a) + count($b));
    }
}
