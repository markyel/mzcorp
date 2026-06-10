<?php

namespace App\Services\Request;

use App\Enums\EmailCategory;
use App\Enums\RequestStatus;
use App\Exceptions\Mail\TransientImapException;
use App\Jobs\Mail\RouteMailToManagerJob;
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
            // Трасса дедупа: дубли, схлопнутые dedupeWithinList в эту
            // позицию. Прокидывается через __merged_from (см.
            // RequestItemParsingService::dedupeWithinList). Менеджер видит
            // в UI «эта позиция собрана из строк ...».
            $mergedFrom = $item['__merged_from'] ?? null;
            if (is_array($mergedFrom) && empty($mergedFrom)) {
                $mergedFrom = null;
            }

            // Если dedupeWithinList склеил дубли — у победителя в
            // __qty_summed лежит сумма qty (собственный + qty съеденных).
            // Берём её приоритетно. Оригинал собственного qty победителя
            // фиксируем в merged_from[].qty_original_winner, чтобы UI мог
            // показать «было X + Y → стало Z».
            $qtyOriginalWinner = $item['qty'] ?? 1;
            $qtyToStore = $item['__qty_summed'] ?? $qtyOriginalWinner;
            if ($mergedFrom !== null) {
                foreach ($mergedFrom as &$mfEntry) {
                    if (! isset($mfEntry['qty_original_winner'])) {
                        $mfEntry['qty_original_winner'] = (string) $qtyOriginalWinner;
                    }
                }
                unset($mfEntry);
            }

            $createdItem = RequestItem::create([
                'request_id' => $existing->id,
                'position' => $maxPosition,
                'parsed_name' => $item['name'],
                'parsed_brand' => $item['brand'] ?? null,
                'parsed_article' => $item['article'] ?? null,
                'parsed_qty' => $qtyToStore,
                'parsed_unit' => $item['unit'] ?? 'шт.',
                // Мерные позиции: вторая размерность (длина/масса/объём
                // на 1 единицу qty) — структурированно от ParseItemsPrompt v6.
                'parsed_length' => $item['length'] ?? null,
                'parsed_length_unit' => $item['length_unit'] ?? null,
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
                // Провенанс позиция → письмо-источник: всегда реальное письмо,
                // из которого парсер извлёк позицию (в отличие от
                // suggestion_source_email_id, который только для pending). Драйвит
                // ручное разъединение заявки (RequestSplitService).
                'source_email_message_id' => $message->id,
                'parsing_merged_from' => $mergedFrom,
            ]);

            // Агрегируем сводку дедупа в requests.parsing_meta.dedup_dropped
            // для баннера на вкладке «Позиции» и backfill-репортинга.
            if ($mergedFrom !== null) {
                $this->appendParsingMetaDedup($existing, $createdItem->position, $mergedFrom);
            }
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
        // по проверке наличия «MZ» как имени подпапки в текущем пути.
        // Yandex IMAP использует «|» как разделитель, остальные «/» — учитываем
        // оба, иначе письма уже в MZ|Manager детектились как «нужно
        // route» и вызывали повторный IMAP MOVE.
        $folderStr = (string) $message->folder;
        $alreadyRouted = str_contains($folderStr, 'MZ/') || str_contains($folderStr, 'MZ|');
        $needsRouting = $justCreated || ! $alreadyRouted;

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
                // Сначала пробуем синхронно — быстрый happy-path. На transient
                // Yandex-flake (CLIENTBUG EXPUNGE, no-COPYUID и т.п.) router
                // бросает TransientImapException → перекладываем на async Job
                // с экспоненциальным backoff (tries=5, до 30 минут). Без этого
                // ~1 письмо из 200 застревало в INBOX (см. 2026-05-22 incident
                // с email_message=2919 / request M-2026-1487).
                try {
                    $this->folders->routeToManager($message, $manager);
                } catch (TransientImapException $e) {
                    Log::info('RequestItemPersister: transient routing failure, dispatching async retry', [
                        'email_message_id' => $message->id,
                        'manager_id' => $manager->id,
                        'error' => $e->getMessage(),
                    ]);
                    RouteMailToManagerJob::dispatch($message->id, $manager->id)
                        ->delay(now()->addSeconds(30));
                }
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

        // Phase 2.1 inheritance: если в source-message linker оставил
        // подозрение про closed_lost кандидата — async LLM-проверка
        // «это продолжение архивной заявки?». При confidence ≥ threshold
        // → RequestInheritanceService::linkChild. Шансов нет если позиций
        // нет (LLM не сможет сравнить), но job сам пропустит такие.
        $sourceMessage = $existing->emailMessage;
        $candidateHint = is_array($sourceMessage?->detected_artifacts ?? null)
            ? ($sourceMessage->detected_artifacts['inheritance_candidate_id'] ?? null)
            : null;
        if ($candidateHint !== null && $existing->inheritance_parent_id === null) {
            \App\Jobs\Request\CheckInheritanceJob::dispatch($existing->id);
        }

        // Phase 2 + 2026-05-19: clarifications от LLM делятся по confidence:
        //   high → применяем АВТОМАТИЧЕСКИ (parsed_article append + brand
        //          fill if empty) — без участия менеджера, тихо в фоне.
        //   low  → складываем в pending_clarifications (jsonb на Request),
        //          менеджер решит через UI (applyClarification / reject).
        // Правило безопасности: пустое / невалидное confidence = low.
        //
        // 2026-05-21: дополнительное правило для reparse исходного письма.
        // Если source_email_message_id совпадает с request.email_message_id,
        // то это НЕ reply от клиента, а просто более качественный разбор
        // того же первичного письма (после обновления промптов или ручного
        // request:reparse). Такие clarifications принудительно auto-apply
        // и перезаписывают brand даже если он не пуст — это улучшение
        // данных, не противоречие от клиента.
        $clarStoredCount = 0;
        $clarAutoApplied = 0;
        if (! empty($clarifications)) {
            $existing->load('items');
            $isReparseOfOriginal = $existing->email_message_id === $message->id;
            [$autoList, $pendingList] = $this->splitClarificationsByConfidence(
                $clarifications,
                $isReparseOfOriginal ? $message->id : null,
            );

            foreach ($autoList as $entry) {
                $isReparseEntry = $isReparseOfOriginal
                    && ($entry['source_email_message_id'] ?? null) === $message->id;
                if ($this->applyClarificationToItems($existing, $entry, $isReparseEntry)) {
                    $clarAutoApplied++;
                }
            }

            if (! empty($pendingList)) {
                $existingClar = is_array($existing->pending_clarifications)
                    ? $existing->pending_clarifications
                    : [];
                $merged = array_merge($existingClar, $pendingList);
                $existing->forceFill(['pending_clarifications' => $merged])->save();
                $clarStoredCount = count($pendingList);
            }
        }

        Log::info('RequestItemPersister: items persisted', [
            'email_message_id' => $message->id,
            'request_id' => $existing->id,
            'internal_code' => $existing->internal_code,
            'items_total' => count($items),
            'items_new' => count($filtered['new']),
            'items_dup' => $filtered['duplicates'],
            'clarifications_auto_applied' => $clarAutoApplied,
            'clarifications_pending' => $clarStoredCount,
            'just_created' => $justCreated,
        ]);

        // Пересчёт "возможных дублей" между активными позициями. После
        // dedupeWithinList в парсере остаются позиции, которые автоматически
        // схлопнуть нельзя (разные article'ы, или один с article, другой
        // без), но имеют близкое name — обычно это «PDF дал артикул, текст
        // тела повторил позицию без артикула». Менеджеру удобнее видеть
        // визуальный сигнал «возможно дубль #N» с одного взгляда, чем
        // самому сравнивать названия. Решение про слияние — за ним
        // (через UI «🔗 Это уточнение позиции»).
        try {
            $this->recomputePossibleDuplicates($existing);
        } catch (\Throwable $e) {
            Log::warning('RequestItemPersister: recomputePossibleDuplicates failed (non-fatal)', [
                'request_id' => $existing->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'request' => $existing->fresh(),
            'new' => count($filtered['new']),
            'dup' => $filtered['duplicates'],
            'just_created' => $justCreated,
            'clarifications' => $clarStoredCount,
        ];
    }

    /**
     * Пересчёт возможных дублей между активными позициями текущей Request.
     *
     * Логика: проходим по всем парам (i, j) активных items. Если name'ы
     * близки (lower-trim equal ИЛИ similar_text ≥ 85%), но автоматически
     * НЕ схлопнулись (разные article'ы / один null другой нет / разные
     * brand'ы) — записываем взаимную ссылку в quality_assessment_payload
     * каждой из двух позиций (`possible_duplicate_of` массив с id'шниками
     * и similarity).
     *
     * UI _position-card рендерит chip «⚠ возможно дубль #N» по этому
     * полю. Решение про слияние — менеджер через UI dropdown
     * «🔗 Это уточнение позиции».
     *
     * Идемпотентно: предыдущие записи `possible_duplicate_of` затираются
     * на каждом вызове (после reparse список items может измениться).
     */
    private function recomputePossibleDuplicates(Request $request): void
    {
        $items = $request->items()
            ->where('is_active', true)
            ->get(['id', 'parsed_name', 'parsed_article', 'parsed_brand', 'quality_assessment_payload']);

        if ($items->count() < 2) {
            // Нечего сравнивать — но сбросим у единственного, если там был хвост.
            foreach ($items as $it) {
                $payload = (array) ($it->quality_assessment_payload ?? []);
                if (isset($payload['possible_duplicate_of'])) {
                    unset($payload['possible_duplicate_of']);
                    $it->forceFill(['quality_assessment_payload' => $payload])->save();
                }
            }
            return;
        }

        // Map<item_id, array<int, {item_id, similarity}>>
        $duplicatesPerItem = [];

        $list = $items->values();
        $n = $list->count();
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $list[$i];
                $b = $list[$j];
                $similarity = $this->possibleDuplicateSimilarity($a, $b);
                if ($similarity === null) {
                    continue;
                }
                $duplicatesPerItem[$a->id][] = [
                    'item_id' => $b->id,
                    'similarity' => $similarity,
                ];
                $duplicatesPerItem[$b->id][] = [
                    'item_id' => $a->id,
                    'similarity' => $similarity,
                ];
            }
        }

        foreach ($items as $it) {
            $payload = (array) ($it->quality_assessment_payload ?? []);
            $dups = $duplicatesPerItem[$it->id] ?? null;
            if ($dups === null) {
                if (isset($payload['possible_duplicate_of'])) {
                    unset($payload['possible_duplicate_of']);
                    $it->forceFill(['quality_assessment_payload' => $payload])->save();
                }
                continue;
            }
            $payload['possible_duplicate_of'] = array_values($dups);
            $it->forceFill(['quality_assessment_payload' => $payload])->save();
        }
    }

    /**
     * Похожесть двух items как кандидатов на «возможный дубль».
     *
     * Условия:
     *   - lower-trim name'ы совпадают, ИЛИ similar_text ≥ 85%;
     *   - И НЕ оба имеют одинаковые article (если оба совпадают — это бы
     *     dedupeWithinList схлопнул раньше; раз остались — qty/inv разные,
     *     это multi-invoice case, НЕ возможный дубль);
     *   - И НЕ совпадают brand'ы при разных article'ах (если бренды
     *     разные — это разные товары даже при похожем name).
     *
     * @return float|null similarity 0.0–1.0 или null если не дубль.
     */
    private function possibleDuplicateSimilarity(\App\Models\RequestItem $a, \App\Models\RequestItem $b): ?float
    {
        $aName = mb_strtolower(trim((string) $a->parsed_name));
        $bName = mb_strtolower(trim((string) $b->parsed_name));
        if ($aName === '' || $bName === '') {
            return null;
        }

        $aArt = $this->normalizeArticleKey((string) $a->parsed_article);
        $bArt = $this->normalizeArticleKey((string) $b->parsed_article);

        // Оба с одинаковым article — это multi-invoice / разный qty случай,
        // не «возможный дубль» (намеренно сохранено dedupeWithinList).
        if ($aArt !== '' && $bArt !== '' && $aArt === $bArt) {
            return null;
        }

        // Разные ненулевые brand'ы при разных article'ах — точно разные товары.
        $aBrand = mb_strtolower(trim((string) $a->parsed_brand));
        $bBrand = mb_strtolower(trim((string) $b->parsed_brand));
        if ($aBrand !== '' && $bBrand !== '' && $aBrand !== $bBrand) {
            return null;
        }

        // Имена-«близнецы», различающиеся только числом/символом, — это
        // РАЗНЫЕ товары, а не дубли. Классика для лифтовых кнопок приказа:
        // «Кнопка приказа с символом 1» vs «…с символом 4» дают similar_text
        // ~99%, хотя это кнопки разных этажей. similar_text посимвольный —
        // он «не замечает» именно тот токен, что и различает позиции.
        // Если наборы числовых токенов (со знаком, чтобы -1 ≠ 1) различаются —
        // это не возможный дубль. Тот же принцип, что в isDuplicate
        // (3RT2026 ≠ 3RT2025 даже при 95% похожести имён).
        if ($this->numericTokens($aName) !== $this->numericTokens($bName)) {
            return null;
        }

        // Name match.
        if ($aName === $bName) {
            return 1.0;
        }
        similar_text($aName, $bName, $pct);
        if ($pct >= 85.0) {
            return round($pct / 100.0, 2);
        }
        return null;
    }

    private function normalizeArticleKey(string $article): string
    {
        return preg_replace('/[\s\-_.\/]/u', '', mb_strtoupper(trim($article))) ?? '';
    }

    /**
     * Отсортированный набор числовых токенов имени (со знаком):
     * «…с символом -1» → ['-1'], «Кабель 3x2.5» → ['2', '3', '5'].
     *
     * Служит дискриминатором в possibleDuplicateSimilarity: позиции, чьи
     * наборы номеров различаются, — разные товары, даже если посимвольная
     * похожесть имени высокая. Знак сохраняем (-1 этаж ≠ 1 этаж).
     *
     * @return list<string>
     */
    private function numericTokens(string $name): array
    {
        preg_match_all('/-?\d+/u', $name, $m);
        $tokens = $m[0] ?? [];
        sort($tokens, SORT_STRING);

        return $tokens;
    }

    /**
     * Аппендит записи о схлопнутых дублях в requests.parsing_meta.dedup_dropped.
     *
     * Каждая запись содержит позицию-победителя и описание съеденного дубля
     * (source, name, article, qty, reason). UI вкладки «Позиции» рендерит
     * на их основе баннер «N позиций было схлопнуто как дубли — показать».
     *
     * @param  list<array{source: ?string, name: string, article: ?string, qty: string, reason: string, dedup_key: string}>  $mergedFrom
     */
    private function appendParsingMetaDedup(Request $request, int $winnerPosition, array $mergedFrom): void
    {
        try {
            $meta = $request->parsing_meta ?? [];
            $dropped = $meta['dedup_dropped'] ?? [];
            $nowIso = now()->toIso8601String();

            foreach ($mergedFrom as $entry) {
                $dropped[] = [
                    'source' => $entry['source'] ?? null,
                    'name' => $entry['name'] ?? '',
                    'article' => $entry['article'] ?? null,
                    'qty' => $entry['qty'] ?? '',
                    'reason' => $entry['reason'] ?? 'same_normalized_article_inv',
                    'dedup_key' => $entry['dedup_key'] ?? null,
                    'merged_into_position' => $winnerPosition,
                    // qty_summed_into — итоговый qty победителя после слияния
                    // (учитывает все merged_from). qty_original_winner — qty
                    // победителя ДО слияния. Используются UI: «было X+Y → стало Z».
                    'qty_summed_into' => $entry['qty_summed_into'] ?? null,
                    'qty_original_winner' => $entry['qty_original_winner'] ?? null,
                    'at' => $nowIso,
                ];
            }

            $meta['dedup_dropped'] = $dropped;
            $request->parsing_meta = $meta;
            $request->save();
        } catch (\Throwable $e) {
            Log::warning('RequestItemPersister: failed to append parsing_meta dedup', [
                'request_id' => $request->id,
                'winner_position' => $winnerPosition,
                'error' => $e->getMessage(),
            ]);
        }
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

    /**
     * Split clarifications array by `confidence` field into two lists:
     * [highConfidenceList, lowConfidenceList]. Невалидное / отсутствующее
     * поле трактуется как 'low' (безопаснее).
     *
     * @param  list<array<string, mixed>>  $clarifications
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function splitClarificationsByConfidence(
        array $clarifications,
        ?int $reparseSourceMessageId = null,
    ): array {
        $high = [];
        $low = [];
        foreach ($clarifications as $c) {
            $isReparseOfOriginal = $reparseSourceMessageId !== null
                && ($c['source_email_message_id'] ?? null) === $reparseSourceMessageId;
            if ($isReparseOfOriginal || ($c['confidence'] ?? null) === 'high') {
                // Reparse того же исходного письма всегда auto-apply
                // (это серверный upgrade данных, не уточнение от клиента).
                $high[] = $c;
            } else {
                $low[] = $c;
            }
        }

        return [$high, $low];
    }

    /**
     * Тихое авто-применение high-confidence clarification к существующему
     * item: дописать additional_article в parsed_article (через запятую,
     * с проверкой дубля) + (опц.) заполнить parsed_brand если он пуст.
     *
     * Логика повторяет Detail::mutateClarification(apply: true), но без
     * UI / auth / redirect — это серверный фоновый процесс при парсинге
     * reply'я.
     *
     * @return bool true если item реально изменился (что-то добавили).
     */
    private function applyClarificationToItems(Request $request, array $entry, bool $isReparseOfOriginal = false): bool
    {
        $targetPos = (int) ($entry['target_position'] ?? 0);
        if ($targetPos <= 0) {
            return false;
        }
        $item = $request->items->firstWhere('position', $targetPos);
        if ($item === null) {
            Log::warning('RequestItemPersister: auto-clarification skipped — target position missing', [
                'request_id' => $request->id,
                'target_position' => $targetPos,
            ]);

            return false;
        }

        $addArt = $entry['additional_article'] ?? null;
        $addBrand = $entry['additional_brand'] ?? null;
        $refinedName = $entry['refined_name'] ?? null;
        $dirty = false;

        if (is_string($addArt) && $addArt !== '') {
            $existingArt = (string) ($item->parsed_article ?? '');
            if (! $this->articleAlreadyPresent($existingArt, $addArt)) {
                $item->parsed_article = $existingArt === ''
                    ? $addArt
                    : $existingArt . ', ' . $addArt;
                $dirty = true;
            }
        }
        if (is_string($addBrand) && $addBrand !== '') {
            // Reply от клиента: brand заполняем только если он был пуст
            //   (не затираем то, что менеджер мог уже подтвердить).
            // Reparse того же исходного письма: перезаписываем brand если
            //   значение отличается — это улучшение от лучшей LLM, не
            //   противоречие, менеджеру оверрайдить нечего.
            $shouldWrite = empty($item->parsed_brand)
                || ($isReparseOfOriginal && trim((string) $item->parsed_brand) !== trim($addBrand));
            if ($shouldWrite) {
                $item->parsed_brand = $addBrand;
                $dirty = true;
            }
        }
        // 2026-05-21: refined_name — обновлённое имя позиции, в которое
        // LLM объединила существующее имя и уточнение из reply
        // («масленка, направляющая 16 мм» + «масленка на противовесе» →
        // «Масленка для башмака противовеса, направляющая 16 мм»).
        if (is_string($refinedName) && trim($refinedName) !== '') {
            $current = trim((string) ($item->parsed_name ?? ''));
            $refined = trim($refinedName);
            if (mb_strtolower($refined) !== mb_strtolower($current)) {
                $item->parsed_name = $refined;
                $dirty = true;
            }
        }
        if ($dirty) {
            $item->save();
            Log::info('RequestItemPersister: clarification auto-applied', [
                'request_id' => $request->id,
                'item_id' => $item->id,
                'target_position' => $targetPos,
                'added_article' => $addArt,
                'added_brand' => $addBrand,
                'refined_name' => $refinedName,
                'is_reparse_of_original' => $isReparseOfOriginal,
                'source_email_message_id' => $entry['source_email_message_id'] ?? null,
                'reasoning' => $entry['reasoning'] ?? null,
            ]);
        }

        return $dirty;
    }

    /**
     * Дублирует логику Detail::articleAlreadyPresent один-в-один: норм =
     * UPPERCASE + удалить пробелы/-/_/./slash, сравнение поэлементное
     * через запятую. Нужно чтобы auto- и manual-применение не расходились.
     */
    private function articleAlreadyPresent(string $existing, string $candidate): bool
    {
        $norm = fn (string $s) => preg_replace('/[\s\-_.\/]/', '', mb_strtoupper(trim($s)));
        $candidateNorm = $norm($candidate);
        if ($candidateNorm === '') {
            return true;
        }
        foreach (preg_split('/\s*,\s*/', $existing) as $part) {
            if ($norm((string) $part) === $candidateNorm) {
                return true;
            }
        }

        return false;
    }
}
