<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestItem;
use App\Prompts\Kb\RequestContextAnalysisPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\RequestItemParsingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Dry-run перепарсинга заявки.
 *
 * Берёт оригинальное входящее письмо (или указанный source_body заявки),
 * прогоняет через текущие версии промптов (ParseItemsPrompt + Request
 * ContextAnalysisPrompt), показывает результат. В БД НИЧЕГО НЕ ПИШЕТ.
 *
 * Используется чтобы убедиться что новые версии промптов извлекают
 * brand / equipment_units корректно ДО реального backfill.
 *
 * Запуск: php artisan parse:dry-run M-2026-1147
 */
class ParseDryRun extends Command
{
    protected $signature = 'parse:dry-run {code} {--skip-items : пропустить ParseItemsPrompt} {--skip-context : пропустить RequestContextAnalysisPrompt}';

    protected $description = 'Dry-run: прогнать письмо заявки через текущие промпты, БД не трогать';

    public function handle(
        RequestItemParsingService $parser,
        OpenAIChatService $openai,
    ): int {
        $code = (string) $this->argument('code');
        $req = Request::where('internal_code', $code)->first();
        if (! $req) {
            $this->error("Заявка {$code} не найдена");
            return self::FAILURE;
        }

        $this->line("=== Dry-run для {$req->internal_code} (id={$req->id}) ===");
        $this->line('');

        // ---------- 1. ParseItemsPrompt: items из inbound-сообщения ----------
        if (! $this->option('skip-items')) {
            $this->line('--- 1. ParseItemsPrompt (parseItemsFromInboundMessage) ---');

            $message = EmailMessage::find($req->email_message_id);
            if (! $message) {
                $this->warn('  email_message_id у заявки = '.var_export($req->email_message_id, true).' — не найдено сообщение');
            } else {
                $this->line("  source: msg #{$message->id} | from {$message->from_email} | subject «{$message->subject}»");
                $this->line('');
                try {
                    $items = $parser->parseItemsFromInboundMessage($message);
                    $this->line('  Извлечено позиций: '.count($items));
                    foreach ($items as $idx => $it) {
                        $this->line("  [{$idx}] ".json_encode($it, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    }
                } catch (\Throwable $e) {
                    $this->error('  ОШИБКА parseItemsFromInboundMessage: '.$e->getMessage());
                    Log::warning('parse:dry-run parse failure', ['exception' => $e]);
                }
            }
            $this->line('');
        }

        // ---------- 2. RequestContextAnalysisPrompt ----------
        if (! $this->option('skip-context')) {
            $this->line('--- 2. RequestContextAnalysisPrompt (только LLM-вызов, без записи) ---');

            $itemsBrief = RequestItem::query()
                ->where('request_id', $req->id)
                ->where('is_active', true)
                ->orderBy('position')
                ->get()
                ->map(fn (RequestItem $i) => [
                    'parsed_name' => (string) $i->parsed_name,
                    'parsed_qty' => (float) ($i->parsed_qty ?? 1),
                    'parsed_unit' => (string) ($i->parsed_unit ?? 'шт.'),
                ])
                ->all();

            // 2026-05-21: source_body у Request не существует как поле —
            // тянем body / subject из связанного inbound сообщения. Этот
            // же путь использует RequestContextAnalysisService.
            $body = '';
            $subject = $req->subject;
            $msg = $req->email_message_id ? EmailMessage::find($req->email_message_id) : null;
            if ($msg) {
                $body = (string) ($msg->body_plain ?? '');
                if (trim($body) === '' && ! empty($msg->body_html)) {
                    $body = trim(strip_tags((string) $msg->body_html));
                }
                $subject = $msg->subject ?: $subject;
            }

            $this->line('  body length: '.mb_strlen($body));
            $this->line('  subject: '.var_export($subject, true));
            $this->line('  itemsBrief count: '.count($itemsBrief));
            $this->line('');

            try {
                $messages = RequestContextAnalysisPrompt::build($body, $subject, $itemsBrief);
                $response = $openai->chat($messages, 'gpt-4o', [
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.2,
                    'max_tokens' => 2000,
                ]);
                $raw = (string) ($response['content'] ?? '');
                $parsed = json_decode($raw, true);
                if (is_array($parsed)) {
                    $this->line('  LLM JSON (decoded):');
                    $this->line(json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                } else {
                    $this->warn('  LLM вернула не-JSON или пусто. Сырое содержимое:');
                    $this->line('  '.mb_substr($raw, 0, 2000));
                }
            } catch (\Throwable $e) {
                $this->error('  ОШИБКА LLM: '.$e->getMessage());
            }
            $this->line('');
        }

        $this->line('=== Dry-run завершён. БД не изменена. ===');
        return self::SUCCESS;
    }
}
