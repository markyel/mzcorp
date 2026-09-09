<?php

namespace App\Services\Mail\Routing;

use App\Enums\RequestStatus;
use App\Jobs\Mail\DeliverToManagerInboxJob;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Request\AttentionService;
use Illuminate\Support\Facades\Log;

/**
 * Общее для обработчиков постпродажи (ClosedWonThreadHandler,
 * PostSaleOrderHandler): к какому заказу относится письмо и что с ним делать.
 * Перенесено из MailRouter без изменений логики (2026-09-09); доставка и
 * перенос в папку менеджера — цепочкой Deliver → Route, как везде.
 */
final class PostSaleRoutingSupport
{
    public function __construct(private readonly AttentionService $attention)
    {
    }

    /**
     * Найти заказ клиента, к которому относится постпродажное письмо
     * (тикет M-2026-2706). «Оплаченный/закрытый» заказ = статус
     * awaiting_invoice / invoiced / paid / closed_won.
     *
     * Привязываем post_sale письмо к заказу ТОЛЬКО при надёжном совпадении
     * линкера по заголовкам/коду (In-Reply-To / References / subject-code)
     * и только если этот заказ «оплачен/закрыт». Нечёткий поиск «последнего
     * оплаченного заказа по from_email» УБРАН: он угадывал не тот заказ
     * (тикет M-2026-2762). Нет надёжной привязки — письмо остаётся в ящике.
     */
    public function resolvePostSaleRequest(?Request $linkedRequest): ?Request
    {
        if ($linkedRequest === null) {
            return null;
        }

        $postSaleStatuses = [
            RequestStatus::AwaitingInvoice->value,
            RequestStatus::Invoiced->value,
            RequestStatus::Paid->value,
            RequestStatus::ClosedWon->value,
        ];

        return in_array($linkedRequest->status?->value, $postSaleStatuses, true)
            ? $linkedRequest
            : null;
    }

    /**
     * Постпродажное письмо по оформленному/выигранному заказу.
     *
     * Заявку НЕ реанимируем (сделка состоялась). Делаем две вещи:
     *   1. attention=PostSale — закрытая заявка всплывёт в отдельной секции
     *      Pool менеджера «Постпродажная переписка».
     *   2. Доставляем письмо в личный ящик менеджера + раскладываем в подпапку
     *      MZ|{Lastname} — теми же job'ами, что и для обычного reply'я.
     *
     * Парсер позиций НЕ запускаем — новых позиций в постпродаже нет.
     */
    public function handlePostSaleMessage(EmailMessage $message, Request $request): void
    {
        try {
            $this->attention->onPostSaleMessage($request);
        } catch (\Throwable $e) {
            Log::warning('MailRouter: attention onPostSaleMessage failed (non-fatal)', [
                'email_message_id' => $message->id,
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($request->assigned_user_id) {
            try {
                DeliverToManagerInboxJob::chainWithRouting($message->id, $request->assigned_user_id);
            } catch (\Throwable $e) {
                Log::warning('MailRouter: dispatch post-sale routing/delivery failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'request_id' => $request->id,
                    'manager_id' => $request->assigned_user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('MailRouter: post-sale message handled on closed_won request', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'manager_id' => $request->assigned_user_id,
        ]);
    }
}
