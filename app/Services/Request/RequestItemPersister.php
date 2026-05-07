<?php

namespace App\Services\Request;

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
     * @return array{request: ?Request, new: int, dup: int, just_created: bool}
     */
    public function persist(EmailMessage $message, array $items): array
    {
        if (empty($items)) {
            return ['request' => null, 'new' => 0, 'dup' => 0, 'just_created' => false];
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

        $maxPosition = (int) ($existingItems->max('position') ?? 0);
        foreach ($filtered['new'] as $item) {
            $maxPosition++;
            RequestItem::create([
                'request_id' => $existing->id,
                'position' => $maxPosition,
                'parsed_name' => $item['name'],
                'parsed_brand' => $item['brand'] ?? null,
                'parsed_article' => $item['article'] ?? null,
                'parsed_qty' => $item['qty'] ?? 1,
                'parsed_unit' => $item['unit'] ?? 'шт.',
                'supplier_note' => $item['note'] ?? null,
                'data_source' => 'inbound_message',
                'status' => 'parsed',
            ]);
        }

        // MOVE в INBOX/MZ/{Lastname} — при первом создании ИЛИ если письмо
        // ещё не маршрутизировано (backfill: Request создан старым AI-classify
        // pipeline до того, как добавили folder routing). Идемпотентность —
        // по проверке наличия «/MZ/» в текущем пути.
        $needsRouting = $justCreated
            || ! str_contains((string) $message->folder, '/MZ/');

        if ($needsRouting) {
            $manager = $existing->assigned_user_id
                ? $existing->assignedUser
                : $this->assignment->autoAssign($existing);
            if ($manager) {
                $this->folders->routeToManager($message, $manager);
            }
        }

        Log::info('RequestItemPersister: items persisted', [
            'email_message_id' => $message->id,
            'request_id' => $existing->id,
            'internal_code' => $existing->internal_code,
            'items_total' => count($items),
            'items_new' => count($filtered['new']),
            'items_dup' => $filtered['duplicates'],
            'just_created' => $justCreated,
        ]);

        return [
            'request' => $existing->fresh(),
            'new' => count($filtered['new']),
            'dup' => $filtered['duplicates'],
            'just_created' => $justCreated,
        ];
    }
}
