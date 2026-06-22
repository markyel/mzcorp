<?php

namespace App\Services\Invoices;

use App\Enums\DetectorType;
use App\Enums\MailDirection;
use App\Enums\RequestStatus;
use App\Jobs\Quotes\ParseOutboundQuoteJob;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\User;
use App\Services\Clients\RequestOrganizationResolver;
use App\Services\Request\InternalCodeGenerator;
use App\Services\Request\RequestStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Создание заявки ИЗ ИСХОДЯЩЕГО СЧЁТА — для счетов, у которых реально нет
 * заявки (раздел «Непривязанные счета», Слой B триажа). В отличие от
 * EmailToRequestPromoter (создаёт из ВХОДЯЩЕГО письма клиента), здесь источник
 * — наше исходящее письмо-счёт: клиент = внешний получатель, менеджер =
 * владелец ящика-отправителя, позиции = строки счёта.
 *
 * Заявка сразу получает статус «Счёт отправлен» (Invoiced). Позиции
 * подтягиваются асинхронно: ParseOutboundQuoteJob распарсит счёт в
 * OutboundQuote/Invoice и посеет request_items (см. seedRequestItemsFromInvoice
 * в самом job'е — гард «у заявки нет активных позиций»).
 *
 * См. [[unlinked-invoices]], [[lost-invoices-diagnosis]].
 */
class InvoiceToRequestService
{
    public function __construct(
        private readonly InternalCodeGenerator $codeGen,
        private readonly RequestOrganizationResolver $orgResolver,
        private readonly RequestStateService $stateService,
    ) {
    }

    /**
     * @param  EmailMessage      $email        Исходящее письмо-счёт (ещё не привязано).
     * @param  EmailAttachment|null $invoiceAtt Вложение-счёт (для разбора позиций).
     * @param  string            $clientEmail  E-mail клиента (внешний получатель).
     * @param  int|null          $managerId    Менеджер-отправитель (владелец ящика).
     * @param  User|null         $by           Кто инициировал (audit).
     *
     * @throws \DomainException  письмо входящее / уже связано / нет клиента.
     */
    public function create(
        EmailMessage $email,
        ?EmailAttachment $invoiceAtt,
        string $clientEmail,
        ?int $managerId,
        ?User $by,
    ): Request {
        $direction = $email->direction instanceof MailDirection
            ? $email->direction
            : MailDirection::tryFrom((string) $email->direction);
        if ($direction !== MailDirection::Outbound) {
            throw new \DomainException('Создать заявку из счёта можно только для исходящего письма.');
        }
        if ($email->related_request_id) {
            throw new \DomainException('Это письмо уже связано с заявкой #' . $email->related_request_id . '.');
        }
        $clientEmail = mb_strtolower(trim($clientEmail));
        if ($clientEmail === '') {
            throw new \DomainException('Не удалось определить клиента (внешнего получателя счёта).');
        }

        $clientName = $this->recipientName($email, $clientEmail);
        // Менеджер известен → создаём сразу Assigned, иначе New (РОП назначит).
        $initialStatus = $managerId ? RequestStatus::Assigned : RequestStatus::New;

        $request = DB::transaction(function () use ($email, $invoiceAtt, $clientEmail, $clientName, $managerId, $by, $initialStatus) {
            $req = Request::create([
                'internal_code' => $this->codeGen->next(),
                'email_message_id' => $email->id,
                'status' => $initialStatus,
                'client_email' => $clientEmail,
                'client_name' => $clientName,
                'subject' => $email->subject ?: 'Счёт',
                'assigned_user_id' => $managerId,
            ]);

            // Привязка письма-счёта + audit (как manual_attach в Unlinked::attach).
            $artifacts = is_array($email->detected_artifacts ?? null) ? $email->detected_artifacts : [];
            $artifacts[] = [
                'type' => 'manual_create_request_from_invoice',
                'request_id' => $req->id,
                'invoice_attachment_id' => $invoiceAtt?->id,
                'created_at' => now()->toIso8601String(),
                'created_by_user_id' => $by?->id,
            ];
            $email->forceFill([
                'related_request_id' => $req->id,
                'detected_artifacts' => $artifacts,
            ])->save();

            // Точная привязка к организации (раздел «Клиенты»), если клиент уже в реестре.
            $this->orgResolver->attach($req);

            // Initial audit-event (как ParseRequestItemsJob после autoAssign).
            $this->stateService->recordSystemInitial(
                $req,
                $managerId ? User::find($managerId) : null,
                'Создана из счёта (триаж непривязанных)',
            );

            return $req;
        });

        // Статус → «Счёт отправлен». Assigned/New → Invoiced разрешён картой
        // переходов. systemTransition: инициатор — привилегированный (РОП/секретарь),
        // не обязательно «владелец» заявки; человека фиксируем в payload + artifacts.
        $this->stateService->transitionTo(
            $request,
            RequestStatus::Invoiced,
            $by,
            [
                'event' => 'manual_create_from_invoice',
                'payload' => [
                    'created_by_user_id' => $by?->id,
                    'invoice_attachment_id' => $invoiceAtt?->id,
                ],
            ],
            systemTransition: true,
        );

        // Разбор счёта → OutboundQuote/Invoice + посев request_items из позиций
        // счёта (внутри job'а, гард «у заявки нет активных позиций»).
        if ($invoiceAtt) {
            ParseOutboundQuoteJob::dispatch($invoiceAtt->id, DetectorType::OutboundInvoice->value, true);
        }

        Log::info('InvoiceToRequestService: request created from invoice', [
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'email_message_id' => $email->id,
            'invoice_attachment_id' => $invoiceAtt?->id,
            'client_email' => $clientEmail,
            'manager_id' => $managerId,
            'by_user_id' => $by?->id,
        ]);

        return $request->refresh();
    }

    /** Имя клиента из получателя письма по его email (или null). */
    private function recipientName(EmailMessage $email, string $clientEmail): ?string
    {
        foreach ((array) $email->to_recipients as $r) {
            $rEmail = is_array($r) ? mb_strtolower(trim((string) ($r['email'] ?? ''))) : '';
            if ($rEmail === $clientEmail) {
                $name = is_array($r) ? trim((string) ($r['name'] ?? '')) : '';

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }
}
