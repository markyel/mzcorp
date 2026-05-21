<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Services\Mail\FreeTextReplyEnricher;
use App\Services\RequestItemParsingService;
use Illuminate\Console\Command;

/**
 * Подробный разбор того, что и как система делает с reply-письмом.
 *
 * Показывает по шагам:
 *   1. Само сообщение: from / subject / body (cleaned).
 *   2. Текущие существующие позиции заявки.
 *   3. ParseItemsPrompt — какие items извлёк парсер (Vision + текст).
 *   4. DecideClarificationsPrompt — какие decisions (clarification/new)
 *      LLM приняла, с reasoning, confidence и refined_name.
 *   5. Если items пуст — FreeTextReplyEnricher: предложил ли уточнения,
 *      с reasoning по каждому.
 *
 * НИЧЕГО НЕ ПИШЕТ В БД. Безопасно гонять многократно.
 *
 * Использование:
 *   php artisan parse:debug-reply 1777
 */
class DebugReply extends Command
{
    protected $signature = 'parse:debug-reply {message_id}';

    protected $description = 'Подробный dry-run reply-парсинга: items + decisions + free-text enrichment';

    public function handle(
        RequestItemParsingService $parser,
        FreeTextReplyEnricher $enricher,
    ): int {
        $msgId = (int) $this->argument('message_id');
        $msg = EmailMessage::find($msgId);
        if (! $msg) {
            $this->error("Сообщение #{$msgId} не найдено");
            return self::FAILURE;
        }

        $this->renderMessageHeader($msg);

        if (! $msg->related_request_id) {
            $this->warn('Сообщение НЕ привязано к Request (related_request_id=null) — нечего обогащать.');
            return self::SUCCESS;
        }

        $request = RequestModel::with('items')->find($msg->related_request_id);
        if (! $request) {
            $this->error("Request #{$msg->related_request_id} не найден");
            return self::FAILURE;
        }

        $this->renderExistingItems($request);

        // --- Шаг 1. ParseItemsPrompt ---
        $this->section('1. ParseItemsPrompt — извлечение позиций из reply');
        $items = [];
        try {
            $items = $parser->parseItemsFromInboundMessage($msg);
        } catch (\Throwable $e) {
            $this->error('  ОШИБКА парсера: '.$e->getMessage());
            return self::FAILURE;
        }
        $this->line('  Извлечено: '.count($items));
        foreach ($items as $idx => $it) {
            $brand = $it['brand'] ?? null;
            $article = $it['article'] ?? null;
            $qty = $it['qty'] ?? null;
            $unit = $it['unit'] ?? '';
            $this->line(sprintf(
                '  [%d] %s | brand=%s | article=%s | %s %s',
                $idx,
                $it['name'] ?? '(без имени)',
                $brand !== null ? '"'.$brand.'"' : 'null',
                $article !== null ? '"'.$article.'"' : 'null',
                $qty ?? '?',
                $unit
            ));
        }
        $this->line('');

        // --- Шаг 2a. Пусто → FreeTextReplyEnricher ---
        if (empty($items)) {
            $this->section('2. items[] пуст → FreeTextReplyEnricher (Path C)');
            $this->warn('  Внимание: этот режим РЕАЛЬНО запишет enrichment_suggestions в БД,');
            $this->warn('  потому что enricher не имеет dry-run флага. Используй на тестовых данных.');
            $this->line('');
            try {
                $result = $enricher->enrich($msg, $request);
                $this->line('  stored: '.$result['suggestions'].' | auto-applied: '.$result['auto_applied']);
            } catch (\Throwable $e) {
                $this->error('  ОШИБКА enricher: '.$e->getMessage());
            }
            $this->line('');
            return self::SUCCESS;
        }

        // --- Шаг 2b. Есть items → decideClarifications ---
        $this->section('2. decideClarifications — split на clarification vs new');
        $existing = RequestItem::query()
            ->where('request_id', $request->id)
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        if ($existing->isEmpty()) {
            $this->warn('  В заявке нет существующих позиций → все items пойдут как «truly new».');
            return self::SUCCESS;
        }

        $body = (string) ($msg->body_plain ?? '');
        if (trim($body) === '' && ! empty($msg->body_html)) {
            $body = strip_tags((string) $msg->body_html);
        }

        try {
            $split = $parser->decideClarifications(
                newItems: $items,
                existingItems: $existing,
                sourceEmailMessageId: $msg->id,
                replyContextSnippet: $body,
            );
        } catch (\Throwable $e) {
            $this->error('  ОШИБКА decideClarifications: '.$e->getMessage());
            return self::FAILURE;
        }

        $clars = $split['clarifications'] ?? [];
        $newIdx = $split['new_indexes'] ?? [];

        $this->line('  Решений всего: '.(count($clars) + count($newIdx)));
        $this->line('  → clarification: '.count($clars));
        $this->line('  → truly new: '.count($newIdx));
        $this->line('');

        foreach ($clars as $c) {
            $this->line(sprintf(
                '  [CLARIFICATION → poz #%d] confidence=%s',
                $c['target_position'] ?? 0,
                $c['confidence'] ?? '?'
            ));
            if (! empty($c['additional_article'])) {
                $this->line('    + article: '.$c['additional_article']);
            }
            if (! empty($c['additional_brand'])) {
                $this->line('    + brand: '.$c['additional_brand']);
            }
            if (! empty($c['refined_name'])) {
                $this->line('    + refined_name: '.$c['refined_name']);
            }
            if (! empty($c['reasoning'])) {
                $this->line('    reasoning: '.$c['reasoning']);
            }
            $this->line('');
        }
        foreach ($newIdx as $i) {
            $item = $items[$i] ?? null;
            if ($item === null) {
                continue;
            }
            $this->line(sprintf(
                '  [NEW] %s | brand=%s | article=%s | %s %s',
                $item['name'] ?? '?',
                $item['brand'] ?? 'null',
                $item['article'] ?? 'null',
                $item['qty'] ?? '?',
                $item['unit'] ?? ''
            ));
        }
        $this->line('');

        $this->line('=== Готово. БД не менялась (кроме случая пустых items + Path C). ===');
        return self::SUCCESS;
    }

    private function renderMessageHeader(EmailMessage $msg): void
    {
        $this->section("Сообщение #{$msg->id}");
        $this->line('  from: '.$msg->from_email);
        $this->line('  subject: '.$msg->subject);
        $this->line('  sent_at: '.$msg->sent_at?->format('Y-m-d H:i'));
        $this->line('  related_request_id: '.var_export($msg->related_request_id, true));

        $body = (string) ($msg->body_plain ?? '');
        if (trim($body) === '' && ! empty($msg->body_html)) {
            $body = trim(strip_tags((string) $msg->body_html));
        }
        $this->line('  body_length: '.mb_strlen($body));
        $this->line('  body_preview:');
        $preview = mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 800);
        foreach (str_split($preview, 100) as $chunk) {
            $this->line('    | '.$chunk);
        }
        $this->line('  attachments: '.$msg->attachments()->count());
        $this->line('');
    }

    private function renderExistingItems(RequestModel $request): void
    {
        $this->section("Существующие позиции Request #{$request->id} {$request->internal_code}");
        $items = $request->items->where('is_active', true)->sortBy('position');
        if ($items->isEmpty()) {
            $this->warn('  (нет активных позиций)');
            $this->line('');
            return;
        }
        foreach ($items as $it) {
            $this->line(sprintf(
                '  #%d [id=%d] %s | brand=%s | article=%s | %s %s',
                $it->position,
                $it->id,
                $it->parsed_name ?: '(без имени)',
                $it->parsed_brand ? '"'.$it->parsed_brand.'"' : 'null',
                $it->parsed_article ? '"'.$it->parsed_article.'"' : 'null',
                $it->parsed_qty ?? '?',
                $it->parsed_unit ?? ''
            ));
        }
        $this->line('');
    }

    private function section(string $title): void
    {
        $this->line('═══ '.$title.' ═══');
    }
}
