<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\MailboxType;
use App\Enums\MailDirection;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Request;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use App\Services\Request\ReassignService;
use App\Services\Request\RequestStateService;
use Illuminate\Support\Facades\Log;

/**
 * Секретарь переслал письмо клиента из общего ящика (info@) в личный ящик
 * менеджера — это распределение, сделанное человеком. Заявка без ответственного
 * получает владельца ящика.
 *
 * Без этого шага копия пересылки (отправитель info@ — наш домен) уходит в
 * «не по делу» и до правила личного ящика в LinkedThreadHandler не доходит:
 * 06.11.2026 из 76 пересланных заявок ни одна не стала назначенной.
 *
 * Шаг ничего не решает за письмо (всегда null): категория, привязка и дедуп
 * копий идут дальше своим порядком. Стоит до CrossMailboxCopyHandler — тот
 * останавливает цепочку, если копия из «Отправленных» info@ пришла раньше.
 * Заявку с ответственным не трогает: пересылка второму менеджеру бывает
 * «для сведения», а переподчинение — решение РОПа.
 */
final class ForwardedToManagerHandler implements InboundRoutingHandler
{
    private const FORWARD_SUBJECT = '/^\s*(fwd?|fw|пересл\w*)\s*:/iu';

    public function __construct(
        private readonly ReassignService $reassign,
        private readonly RequestStateService $state,
    ) {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        try {
            $this->assignForwarded($ctx->message);
        } catch (\Throwable $e) {
            Log::warning('MailRouter: forwarded-to-manager assignment failed (non-fatal)', [
                'email_message_id' => $ctx->message->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    public function assignForwarded(EmailMessage $message): ?Request
    {
        if ($message->direction !== MailDirection::Inbound
            || preg_match(self::FORWARD_SUBJECT, (string) $message->subject) !== 1) {
            return null;
        }

        $mailbox = $message->mailbox;
        $owner = $mailbox?->owner;
        if ($mailbox === null || $mailbox->type !== MailboxType::Personal || $owner === null
            || $owner->archived_at !== null || ! $owner->hasAnyRole(Role::requestHandlerRoles())) {
            return null;
        }

        $from = mb_strtolower(trim((string) $message->from_email));
        $sharedFrom = Mailbox::query()->where('type', MailboxType::Shared->value)->where('is_active', true)
            ->whereRaw('lower(email) = ?', [$from])->exists();
        $toOwner = collect((array) $message->to_recipients)
            ->contains(fn ($r) => mb_strtolower((string) ($r['email'] ?? '')) === mb_strtolower((string) $owner->email));
        if (! $sharedFrom || ! $toOwner) {
            return null;
        }

        // Пересылка ссылается на письмо клиента (In-Reply-To/References) — по нему и заявка.
        $refs = array_values(array_filter(array_merge([$message->in_reply_to], (array) $message->references_header)));
        if ($refs === []) {
            return null;
        }
        $requestId = EmailMessage::query()->whereIn('message_id', $refs)->whereNotNull('related_request_id')
            ->orderBy('id')->value('related_request_id');
        $request = $requestId ? Request::find($requestId) : null;
        if ($request === null || $request->assigned_user_id !== null || $request->status->isTerminal()) {
            return null;
        }

        $this->reassign->reassign(
            request: $request,
            newAssignee: $owner,
            reason: 'forwarded_from_shared_mailbox email_message_id='.$message->id,
            by: null,
        );

        // Разбор позиций уже прошёл, а «В обработке» держала только неназначенность.
        $request = $request->fresh();
        if ($request->status === RequestStatus::Pending && ! empty(($request->parsing_meta ?? [])['parser_finished_at'])) {
            $this->state->transitionTo($request, RequestStatus::Assigned, null, [
                'event' => 'forwarded_to_manager',
                'comment' => 'Письмо клиента переслано из '.$from.' в ящик '.$owner->email,
            ], systemTransition: true);
        }

        Log::info('MailRouter: request assigned by forward from shared mailbox', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'to_user_id' => $owner->id,
        ]);

        return $request->fresh();
    }
}
