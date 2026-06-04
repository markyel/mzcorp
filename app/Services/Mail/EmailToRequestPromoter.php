<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Enums\RequestStatus;
use App\Jobs\Mail\ParseRequestItemsJob;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Request\InternalCodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Принудительное создание Request из входящего письма — ручной override
 * AI-классификации. Письмо, ушедшее в post_sale / irrelevant / thread_reply
 * без заявки (например, PostSaleFulfillmentDetector сработал на слово
 * «комплектация»), привилегированный пользователь поднимает в заявку руками.
 *
 * Используется из:
 *   - MailReview\Index — экран нерелевантных (category=irrelevant);
 *   - Mail\Index — общий листинг «Почта», любая не-заявочная категория.
 *
 * После создания Request дёргается ParseRequestItemsJob — дальше обычный
 * pipeline: парсер позиций → RequestItemPersister → autoAssign → MailFolderRouter.
 *
 * Запись о ручном override фиксируется в email_messages.detected_artifacts
 * (единое поле под audit AI-overrides, как у DocumentDetector).
 */
class EmailToRequestPromoter
{
    public function __construct(
        private readonly InternalCodeGenerator $codeGen,
    ) {
    }

    /**
     * @param  EmailMessage  $email        Входящее письмо без связанной заявки.
     * @param  int|null      $actorUserId  Кто инициировал (для audit).
     * @param  string        $auditType    Тип записи в detected_artifacts.
     *
     * @throws \DomainException  письмо исходящее ИЛИ уже связано с заявкой.
     */
    public function promote(
        EmailMessage $email,
        ?int $actorUserId,
        string $auditType = 'manual_create_request_from_mail',
    ): Request {
        $direction = $email->direction instanceof MailDirection
            ? $email->direction
            : MailDirection::tryFrom((string) $email->direction);
        if ($direction !== MailDirection::Inbound) {
            throw new \DomainException('Создать заявку можно только из входящего письма.');
        }
        if ($email->related_request_id) {
            throw new \DomainException(
                'Это письмо уже связано с заявкой #' . $email->related_request_id . '.'
            );
        }

        $request = DB::transaction(function () use ($email, $actorUserId, $auditType) {
            $req = Request::create([
                'internal_code' => $this->codeGen->next(),
                'email_message_id' => $email->id,
                'status' => RequestStatus::Pending,
                'client_email' => $email->from_email ?: '',
                'client_name' => $email->from_name,
                'subject' => $email->subject,
            ]);
            $email->forceFill(['related_request_id' => $req->id])->save();

            // Audit: ручной override AI-классификации.
            $existing = is_array($email->detected_artifacts ?? null)
                ? $email->detected_artifacts
                : [];
            $existing[] = [
                'type' => $auditType,
                'overrode_category' => $email->category,
                'created_at' => now()->toIso8601String(),
                'created_by_user_id' => $actorUserId,
                'request_id' => $req->id,
            ];
            $email->forceFill(['detected_artifacts' => $existing])->save();

            return $req;
        });

        ParseRequestItemsJob::dispatch($email->id);

        Log::info('EmailToRequestPromoter: email promoted to Request', [
            'email_message_id' => $email->id,
            'request_id' => $request->id,
            'overrode_category' => $email->category,
            'audit_type' => $auditType,
            'by_user_id' => $actorUserId,
        ]);

        return $request;
    }
}
