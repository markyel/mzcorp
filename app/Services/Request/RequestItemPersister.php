<?php

namespace App\Services\Request;

use App\Enums\EmailCategory;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Mail\MailFolderRouter;
use App\Services\RequestItemParsingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Идемпотентная фиксация распарсенных items в Request (Phase 1.8b).
 *
 * Триггер для создания Request — items.count > 0 (content-driven detection),
 * вместо старого ai_classification=request (см. IncomingMailProcessor).
 *
 * Поведение:
 *  - items пустые → null, ничего не пишем.
 *  - EmailMessage уже привязан к Request → берём существующий, добавляем
 *    только новые items (filterNewItems сравнивает по article+name).
 *  - EmailMessage без Request → создаём новый Request + назначаем менеджера
 *    + ставим IMAP-метку (повторяет логику IncomingMailProcessor::processIfRequest).
 *
 * Source-tag для items: 'inbound_message' — общий ярлык, дальше можно делить
 * на email_attachment / email_image / email_body, если понадобится.
 */
class RequestItemPersister
{
    public function __construct(
        private readonly InternalCodeGenerator $codeGenerator,
        private readonly AssignmentService $assignment,
        private readonly MailFolderRouter $folders,
        private readonly RequestItemParsingService $parser,
    ) {
    }

    /**
     * @param  array<array{name: string, brand: ?string, article: ?string, qty: float, unit: string, note: ?string}>  $items
     * @param  list<array{id: string, source_email_message_id: ?int, target_position: int, additional_article: ?string, additional_brand: ?string, reasoning: string, created_at: string}> $clarifications
     *        — LLM-предположения «это уточнение существующей позиции», складываются в Request->pending_clarifications.
     *        Применяет оператор вручную через UI карточки (applyClarification).
     * @return array{request: ?Request, new: int, dup: int, just_created: bool, clarifications: int}
     */
    public function persist(EmailMessage $message, array $items, array $clarifications = []): array
    {
        if (empty($items) && empty($clarifications)) {
            return ['request' => null, 'new' => 0, 'dup' => 0, 'just_created' => false, 'clarifications' => 0];
        }

        // Phase 1.8c: триггер для создания Request — только client_request.
        // Если письмо категоризовано как thread_reply / irrelevant — обычно
        // НЕ создаём новую Request (мог увидеть «items» в supplier offer
        // или newsletter).
        //
        // Phase 1.9: ИСКЛЮЧЕНИЕ — если письмо уже привязано к существующей
        // Request через `InboundReplyLinker` (related_request_id !== null),
        // это reply клиента к открытой заявке. В таком reply могут быть
        // ДОПОЛНИТЕЛЬНЫЕ позиции («забыл указать ещё M-1234 - 3 шт»).
        // Тогда category-гейт пропускаем и добавляем items к существующей
        // Request (filterNewItems в любом случае дедупит уже сохранённые).
        if ($message->category !== null
            && $message->category !== EmailCategory::ClientRequest->value
            && ! $message->related_request_id) {
            Log::info('RequestItemPersister: skipped by category', [
                'email_message_id' => $message->id,
                'category' => $message->category,
                'confidence' => $message->category_confidence,
            ]);

            return ['request' => null, 'new' => 0, 'dup' => 0, 'just_created' => false, 'clarifications' => 0];
        }

        $existing = $message->related_request_id
            ? Request::find($message->related_request_id)
            : null;
        $justCreated = false;

        if (! $existing) {
            $existing = DB::transaction(function () use ($message) {
                $req = Request::create([
                    'internal_code' => $this->codeGenerator->next(),
                    'email_message_id' => $message->id,
                    'status' => RequestStatus::New,
                    'client_email' => $message->from_email ?: '',
                    'client_name' => $message->from_name,
                    'subject' => $message->subject,
                ]);
                $message->forceFill(['related_request_id' => $req->id])->save();

                return $req;
            });
            $justCreated = true;
        }

        // Дедуп против уже сохранённых позиций (filterNewItems читает
        // is_active, parsed_article, parsed_name).
        $existingItems = $existing->items()->get();
        $filtered = $this->parser->filterNewItems($items, $existingItems);

        // Phase reply-suggestion: для reply-контекста (не initial email
        // Request'а) считаем confidence и решаем — auto-apply / suggest / skip.
        $isReplyContext = $existing->email_message_id !== $message->id;
        $autoThreshold = (float) config('services.parser.reply_auto_apply_threshold', 0.95);
        $suggestThreshold = (float) config('services.parser.reply_suggest_threshold', 0.70);

        $maxPosition = (int) ($existingItems->max('position') ?? 0);
        $newCountActive = 0;
        $newCountPending = 0;
        $newCountSkipped = 0;

        foreach ($filtered['new'] as $item) {
            $suggestionStatus = null;
            $finalConfidence = null;

            if ($isReplyContext) {
                $vision = (float) ($item['confidence'] ?? 1.0);
                $sim = $this->bestArticleSimilarity(
                    (string) ($item['article'] ?? ''),
                    $existingItems,
                );
                // penalty 0..1: 0 при sim<0.4, 1 при sim≈1.0.
                $penalty = max(0.0, min(1.0, ($sim - 0.4) * 1.7));
                $finalConfidence = $vision * (1.0 - $penalty);

                if ($finalConfidence < $suggestThreshold) {
                    $newCountSkipped++;
                    Log::info('RequestItemPersister: reply item skipped (low confidence)', [
                        'email_message_id' => $message->id,
                        'article' => $item['article'] ?? null,
                        'confidence' => $finalConfidence,
                        'similarity_to_existing' => $sim,
                    ]);
                    continue;
                }
                if ($finalConfidence < $autoThreshold) {
                    $suggestionStatus = 'pending';
                    $newCountPending++;
                } else {
                    $newCountActive++;
                }
            } else {
                $newCountActive++;
            }

            $maxPosition++;
            RequestItem::create([
                'request_id' => $existing->id,
                'position' => $maxPosition,
                'parsed_name' => $item['name'],
                'parsed_brand' => $item['brand'] ?? null,
                'parsed_article' => $item['article'] ?? null,
                'parsed_qty' => $item['qty'] ?? 1,
                'parsed_unit' => $item['unit'] ?? 'шт.',
                'category' => $item['category'] ?? null,
                'supplier_note' => $item['note'] ?? null,
                'data_source' => 'inbound_message',
                'image_attachment_id' => $item['email_attachment_id'] ?? null,
                'status' => 'parsed',
                // Pending suggestion → не активна, ждёт apply/reject менеджера.
                'is_active' => $suggestionStatus !== 'pending',
                'suggestion_status' => $suggestionStatus,
                'suggestion_confidence' => $finalConfidence,
                'suggestion_source_email_id' => $suggestionStatus !== null ? $message->id : null,
            ]);
        }

        // Audit для reply-парсинга: state_change с подсчётами, чтобы менеджер
        // видел в Activity «парсер из reply добавил/предложил/отклонил позиции».
        if ($isReplyContext && ($newCountActive + $newCountPending + $newCountSkipped) > 0) {
            try {
                \App\Models\RequestStateChange::create([
                    'request_id' => $existing->id,
                    'from_status' => $existing->status->value,
                    'to_status' => $existing->status->value,
                    'by_user_id' => null,
                    'event' => 'items_parsed_from_reply',
                    'comment' => sprintf(
                        'Парсер из ответа клиента: +%d, предложений %d, пропущено %d',
                        $newCountActive,
                        $newCountPending,
                        $newCountSkipped,
                    ),
                    'payload' => [
                        'email_message_id' => $message->id,
                        'items_added_active' => $newCountActive,
                        'items_added_pending' => $newCountPending,
                        'items_skipped_low_confidence' => $newCountSkipped,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('RequestItemPersister: failed to record reply-parse audit', [
                    'request_id' => $existing->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // MOVE в INBOX/MZ/{Lastname} — при первом создании ИЛИ если письмо
        // ещё не маршрутизировано (backfill: Request создан старым AI-classify
        // pipeline до того, как добавили folder routing). Идемпотентность —
        // по проверке наличия «/MZ/» в текущем пути.
        $needsRouting = $justCreated
            || ! str_contains((string) $message->folder, '/MZ/');

        // Phase 1.8d-pending fix: parser-driven activation. Если items
        // добавлены к существующему Pending-Request у которого письмо уже
        // лежит в /MZ/ (парсинг прошёл не сразу — retry / force-rebake),
        // $needsRouting=false → autoAssign не вызывался и Request оставался
        // Pending навсегда. Запускаем autoAssign явно при появлении новых
        // позиций у Pending-без-менеджера, независимо от папки.
        $needsAssign = ! $existing->assigned_user_id
            && count($filtered['new']) > 0
            && $existing->status === RequestStatus::Pending;

        if ($needsRouting || $needsAssign) {
            $wasUnassigned = ! $existing->assigned_user_id;
            $manager = $existing->assigned_user_id
                ? $existing->assignedUser
                : $this->assignment->autoAssign($existing);
            if ($manager && $needsRouting) {
                $this->folders->routeToManager($message, $manager);
            }
            // Phase 1.10: первый auto-assign записываем как initial-event
            // в request_state_changes (Pending → Assigned).
            if ($manager && $wasUnassigned) {
                try {
                    app(\App\Services\Request\RequestStateService::class)
                        ->recordSystemInitial(
                            $existing->fresh(),
                            $manager,
                            'Авто-распределение после парсинга позиций.',
                        );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        'RequestItemPersister: failed to record system_initial state-change',
                        ['request_id' => $existing->id, 'error' => $e->getMessage()],
                    );
                }
            }
        }

        // Phase 2.0 KB: после persist + assign — асинхронный KB-резолв.
        // Дёргаем только если появились новые items (count('new') > 0). Job
        // сам разберётся с per-item статусом (idempotent через переписывание
        // quality_assessment_status). LLM-стоимость ~$0.05-0.10 на Request.
        if (count($filtered['new']) > 0) {
            \App\Jobs\Kb\ResolveKbJob::dispatch($existing->id);
        }

        // Phase 2: складываем LLM-предположения по уточнениям в очередь
        // pending_clarifications (jsonb на Request). Применяет оператор
        // вручную через UI (applyClarification/rejectClarification).
        $clarStoredCount = 0;
        if (! empty($clarifications)) {
            $existingClar = is_array($existing->pending_clarifications)
                ? $existing->pending_clarifications
                : [];
            $merged = array_merge($existingClar, $clarifications);
            $existing->forceFill(['pending_clarifications' => $merged])->save();
            $clarStoredCount = count($clarifications);
        }

        Log::info('RequestItemPersister: items persisted', [
            'email_message_id' => $message->id,
            'request_id' => $existing->id,
            'internal_code' => $existing->internal_code,
            'items_total' => count($items),
            'items_new' => count($filtered['new']),
            'items_dup' => $filtered['duplicates'],
            'clarifications_added' => $clarStoredCount,
            'just_created' => $justCreated,
        ]);

        return [
            'request' => $existing->fresh(),
            'new' => count($filtered['new']),
            'dup' => $filtered['duplicates'],
            'just_created' => $justCreated,
            'clarifications' => $clarStoredCount,
        ];
    }

    /**
     * Максимальная похожесть нового article на articles существующих
     * активных позиций (Levenshtein normalized 0..1).
     */
    private function bestArticleSimilarity(string $newArticle, \Illuminate\Support\Collection $existingItems): float
    {
        $a = mb_strtolower(trim($newArticle));
        if ($a === '') {
            return 0.0;
        }

        $best = 0.0;
        foreach ($existingItems as $ex) {
            if (! $ex->is_active) {
                continue;
            }
            $b = mb_strtolower(trim((string) $ex->parsed_article));
            if ($b === '') {
                continue;
            }
            $len = max(mb_strlen($a), mb_strlen($b));
            if ($len === 0) {
                continue;
            }
            // levenshtein — ASCII-only; для не-ASCII (кириллица в article)
            // graceful fallback на substring-match через similar_text.
            if (preg_match('/^[\x20-\x7E]+$/', $a . $b)) {
                $dist = levenshtein($a, $b);
                $sim = 1.0 - ($dist / $len);
            } else {
                similar_text($a, $b, $pct);
                $sim = $pct / 100.0;
            }
            if ($sim > $best) {
                $best = $sim;
            }
        }

        return $best;
    }
}
