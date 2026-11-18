<?php

namespace App\Services\Quotes;

use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestAssignment;
use App\Models\RequestItem;
use App\Models\RequestItemLink;
use App\Models\RequestStateChange;
use App\Services\Request\AttentionService;
use App\Services\Request\InternalCodeGenerator;
use App\Services\Request\ManagerUnavailabilityService;
use App\Services\Request\RequestActivityService;
use App\Services\Request\RequestInheritanceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Частичное КП», этап 2 (18.11.2026): клиент оплатил счёт, а часть позиций заявки так и осталась без КП. Счёт
 * выставляется на основании КП и частично не оплачивается — сделка по выданному КП состоялась (заявка закрыта
 * успехом), а позиции без КП (и без отметки «мы отказали» / «закрыто заменой») переезжают в ДОЧЕРНЮЮ заявку-остаток:
 * тот же клиент, тот же менеджер, статус «В работе».
 *
 * Родитель остатка — исходная заявка цепочки: если оплачена сама дочерняя, остаток вешается на её родителя (цепочки
 * наследования запрещены, RequestInheritanceService). Позиции копируются, оригиналы помечаются «перенесено в
 * заявку-остаток» (quote_resolution = carried_over) — в закрытой заявке видно, куда они ушли.
 *
 * Почта после оплаты приходит в тред закрытой заявки. Письмо клиента про остаток (называет позицию остатка, «по
 * остальным позициям», цитирует КП остатка) и наше КП на остаток в этом треде уходят в заявку-остаток —
 * PartialRemainderThreadHandler (входящие) и OutboundHandler (исходящие). Остальное — постпродажа закрытой заявки.
 */
class PartialQuoteRemainderService
{
    /** Событие создания заявки-остатка (request_state_changes дочерней; payload.source_request_id — оплаченная). */
    public const EVENT = 'created_partial_quote_remainder';

    public const LINKED_BY = 'partial_quote_remainder';

    /** «по остальным позициям», «недостающие товары», «остальное». */
    private const REST_PHRASE = '/(?<!\p{L})(?:(?:остальн|оставш|недостающ)\p{L}*\s+(?:позиц|товар|запчаст|детал|издели|наименован)|остально[ей](?!\p{L})|по\s+остальн)/iu';

    /** Слово названия позиции, по которому узнаём её в письме: не короче 4 букв и не общее. */
    private const NAME_STOP_WORDS = ['для', 'комплект', 'набор', 'запчасть', 'деталь', 'изделие', 'лифт', 'лифта', 'эскалатора', 'эскалатор', 'новый', 'новая', 'штук'];

    public function __construct(
        private readonly QuoteCoverageService $coverage,
        private readonly InternalCodeGenerator $codeGenerator,
        private readonly RequestInheritanceService $inheritance,
        private readonly RequestActivityService $activity,
    ) {
    }

    /**
     * Оплаченная заявка закрыта успехом, а позиции без КП остались — завести заявку-остаток. Повторный вызов (второй
     * счёт, повторный импорт оплат) возвращает уже созданную. Нет непокрытых позиций или КП не выдавали — null.
     */
    public function spinOff(Request $paid, string $trigger): ?Request
    {
        $paid->refresh();
        if ($paid->status !== RequestStatus::ClosedWon || $paid->merged_into_id !== null) {
            return null;
        }
        $existing = $this->remainderOf($paid, openOnly: false);
        if ($existing !== null) {
            return $existing;
        }
        if (! $this->coverage->hasQuote($paid)) {
            return null;
        }
        $open = $this->coverage->uncovered($paid);
        if ($open->isEmpty()) {
            return null;
        }

        $root = $paid->inheritance_parent_id !== null ? (Request::find($paid->inheritance_parent_id) ?? $paid) : $paid;
        if ($root->isInheritanceChild()) {
            Log::warning('PartialQuoteRemainderService: root of the chain is itself a child — remainder not created', ['request_id' => $paid->id, 'root_id' => $root->id]);

            return null;
        }

        try {
            $child = DB::transaction(function () use ($paid, $root, $open, $trigger) {
                $child = Request::create([
                    'internal_code' => $this->codeGenerator->next(),
                    'email_message_id' => $paid->email_message_id,
                    'status' => RequestStatus::InProgress,
                    'subject' => mb_substr(sprintf('Остаток по %s', $paid->internal_code).($paid->subject ? ' — '.$paid->subject : ''), 0, 250),
                    'client_email' => $paid->client_email,
                    'client_name' => $paid->client_name,
                    'client_phone' => $paid->client_phone,
                    'client_company' => $paid->client_company,
                    'client_address' => $paid->client_address,
                    'organization_id' => $paid->organization_id,
                    'source' => $paid->source,
                    'messenger_chat_id' => $paid->messenger_chat_id,
                    'assigned_user_id' => $paid->assigned_user_id,
                    'assigned_at' => $paid->assigned_user_id ? now() : null,
                ]);

                $mappings = [];
                $position = 0;
                foreach ($open as $item) {
                    /** @var RequestItem $item */
                    $copy = $item->replicate(['quote_resolution', 'quote_resolved_by_user_id', 'quote_resolved_at']);
                    $copy->request_id = $child->id;
                    $copy->position = ++$position;
                    $copy->save();
                    $parentItemId = $root->id === $paid->id
                        ? $item->id
                        : RequestItemLink::query()->where('child_item_id', $item->id)->where('is_active', true)->value('parent_item_id');
                    if ($parentItemId !== null) {
                        $mappings[] = ['child_item_id' => $copy->id, 'parent_item_id' => (int) $parentItemId, 'source' => self::LINKED_BY, 'confidence' => 1.0];
                    }
                }

                // Оригиналы — «перенесено в заявку-остаток» (запросом: наблюдатель позиций трогал бы закрытую заявку).
                RequestItem::query()->whereIn('id', $open->pluck('id'))->update([
                    'quote_resolution' => QuoteCoverageService::RESOLUTION_CARRIED_OVER,
                    'quote_resolved_by_user_id' => null,
                    'quote_resolved_at' => now(),
                ]);

                if ($paid->assigned_user_id !== null) {
                    RequestAssignment::create([
                        'request_id' => $child->id,
                        'user_id' => $paid->assigned_user_id,
                        'by_user_id' => null,
                        'reason' => 'partial_quote_remainder:'.$paid->internal_code,
                        'assigned_at' => now(),
                    ]);
                }

                $this->inheritance->linkChild($root, $child, $mappings, linkedBy: self::LINKED_BY);

                RequestStateChange::create([
                    'request_id' => $child->id,
                    'from_status' => null,
                    'to_status' => RequestStatus::InProgress->value,
                    'by_user_id' => null,
                    'event' => self::EVENT,
                    'comment' => sprintf('Остаток по %s: счёт по частичному КП оплачен, по этим позициям КП ещё не выдано — %s.',
                        $paid->internal_code, $open->map(fn (RequestItem $i) => mb_strimwidth((string) $i->parsed_name, 0, 60, '…'))->implode('; ')),
                    'payload' => [
                        'source_request_id' => $paid->id,
                        'source_internal_code' => $paid->internal_code,
                        'root_request_id' => $root->id,
                        'source_item_ids' => $open->pluck('id')->all(),
                        'trigger' => $trigger,
                    ],
                ]);

                $this->activity->touch($child, RequestActivityType::RequestCreated);

                return $child;
            });
        } catch (\Throwable $e) {
            Log::error('PartialQuoteRemainderService: remainder request not created', ['request_id' => $paid->id, 'error' => $e->getMessage()]);

            return null;
        }

        try {
            app(AttentionService::class)->onAssigned($child->fresh());
        } catch (\Throwable $e) {
            Log::warning('PartialQuoteRemainderService: attention onAssigned failed (non-fatal)', ['request_id' => $child->id, 'error' => $e->getMessage()]);
        }
        // Менеджер в отпуске — остаток ведёт коллега, как при обычном назначении.
        app(ManagerUnavailabilityService::class)->delegateIfUnavailable($child);

        Log::info('PartialQuoteRemainderService: remainder request created', [
            'paid_request_id' => $paid->id,
            'remainder_request_id' => $child->id,
            'remainder_code' => $child->internal_code,
            'items' => $open->count(),
            'trigger' => $trigger,
        ]);

        return $child->fresh();
    }

    /** Заявка-остаток оплаченной заявки (openOnly — только не закрытая). */
    public function remainderOf(Request $paid, bool $openOnly = true): ?Request
    {
        $childId = RequestStateChange::query()->where('event', self::EVENT)
            ->where('payload->source_request_id', (string) $paid->id)->orderByDesc('id')->value('request_id');
        $child = $childId !== null ? Request::find($childId) : null;
        if ($child === null || $child->merged_into_id !== null || ($openOnly && $child->status->isTerminal())) {
            return null;
        }

        return $child;
    }

    /**
     * Письмо в треде закрытой успехом заявки — про её остаток? Тогда вернуть открытую заявку-остаток, иначе null.
     * $ownText — собственный текст письма (без цитаты), $quotationAttached — к нашему письму приложено КП.
     */
    public function remainderForMessage(Request $closed, string $ownText, bool $quotationAttached = false): ?Request
    {
        if ($closed->status !== RequestStatus::ClosedWon) {
            return null;
        }
        $child = $this->remainderOf($closed);
        if ($child === null) {
            return null;
        }
        if ($quotationAttached) {
            return $child;
        }
        $items = RequestItem::query()->where('request_id', $child->id)->where('is_active', true)->get(['parsed_name', 'parsed_article']);

        return self::textConcernsItems($items, $ownText) ? $child : null;
    }

    /**
     * Текст называет одну из позиций (артикул или первые значимые слова названия) или говорит об «остальных позициях».
     * Чистая функция — покрыта unit-тестом.
     *
     * @param  Collection<int, object>  $items  позиции с parsed_name / parsed_article
     */
    public static function textConcernsItems(Collection $items, string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }
        if (preg_match(self::REST_PHRASE, $text) === 1) {
            return true;
        }

        $normalized = \App\Services\Request\ItemTokenizer::normalize($text);
        foreach (\App\Services\Request\ItemTokenizer::tokensFor($items) as $token) {
            if ($token !== '' && str_contains($normalized, $token)) {
                return true;
            }
        }

        $haystack = mb_strtolower($text);
        foreach ($items as $item) {
            $stems = self::nameStems((string) ($item->parsed_name ?? ''));
            if ($stems === []) {
                continue;
            }
            $all = true;
            foreach ($stems as $stem) {
                if (preg_match('/(?<!\p{L})'.preg_quote($stem, '/').'/u', $haystack) !== 1) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    /** Основы первых двух значимых слов названия: «Центрирующий ролик» → [центрир, ролик]. Одно слово — не узнаём. */
    private static function nameStems(string $name): array
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stems = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 4 || in_array($word, self::NAME_STOP_WORDS, true)) {
                continue;
            }
            // Падежи: «ролик» → «ролику», «центрирующий» → «центрирующему» — сравниваем без окончания.
            $stems[] = mb_strlen($word) > 5 ? mb_substr($word, 0, mb_strlen($word) - 2) : $word;
            if (count($stems) === 2) {
                break;
            }
        }

        return count($stems) === 2 ? $stems : [];
    }

    /** Исходящее в треде закрытой заявки: приложено ли КП (по имени файла, как OutboundDocumentDetector). */
    public static function hasQuotationAttachment(EmailMessage $message): bool
    {
        $detector = app(\App\Services\DocumentDetector\OutboundDocumentDetector::class);
        foreach ($message->attachments as $att) {
            if ($detector->classifyAttachmentByFilename((string) $att->filename) === \App\Enums\DetectorType::OutboundQuotationFull) {
                return true;
            }
        }

        return false;
    }
}
