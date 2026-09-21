<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Enums\RequestStatus;
use App\Jobs\Mail\ParseRequestItemsJob;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Clients\RequestOrganizationResolver;
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
        private readonly RequestOrganizationResolver $orgResolver,
        private readonly ForwardedRequestParser $forwarded,
        private readonly WebFormSubmissionParser $webForm,
    ) {}

    /**
     * @param  EmailMessage  $email  Входящее письмо без связанной заявки.
     * @param  int|null  $actorUserId  Кто инициировал (для audit).
     * @param  string  $auditType  Тип записи в detected_artifacts.
     *
     * @throws \DomainException письмо исходящее ИЛИ уже связано с заявкой.
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
                'Это письмо уже связано с заявкой #'.$email->related_request_id.'.'
            );
        }

        // Заявка с сайта или пересылка — реальный клиент в теле, а не в From.
        $client = $this->resolveClient($email);
        $clientEmail = (string) $client['client_email'];

        $request = DB::transaction(function () use ($email, $actorUserId, $auditType, $client) {
            $req = Request::create(array_merge([
                'internal_code' => $this->codeGen->next(),
                'email_message_id' => $email->id,
                'status' => RequestStatus::Pending,
                'subject' => $email->subject,
            ], $client));
            $email->forceFill(['related_request_id' => $req->id])->save();

            // Точная привязка к организации (раздел «Клиенты»), если клиент
            // уже в реестре (повторный email / совпадение client_company).
            $this->orgResolver->attach($req);

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

        if ($clientEmail !== ($email->from_email ?: '')) {
            Log::info('EmailToRequestPromoter: real client resolved from body', [
                'email_message_id' => $email->id,
                'relay_from' => $email->from_email,
                'client_email' => $clientEmail,
            ]);
        }

        Log::info('EmailToRequestPromoter: email promoted to Request', [
            'email_message_id' => $email->id,
            'request_id' => $request->id,
            'overrode_category' => $email->category,
            'audit_type' => $auditType,
            'by_user_id' => $actorUserId,
        ]);

        return $request;
    }

    /**
     * Контакты клиента для заявки.
     *
     * Три случая, и From годится только в третьем:
     *   · заявка с сайта (order@) — покупатель указан в теле формы;
     *   · пересланное письмо (noreply@) — отправитель в блоке пересылки;
     *   · обычное письмо — From.
     *
     * Веб-форму здесь разбирать ОБЯЗАТЕЛЬНО, хотя автоматический путь
     * (IncomingMailProcessor) это уже умеет: письмо могло приехать сюда после
     * ручного «сделать заявкой», и тогда в client_email оставался адрес самой
     * формы. Кейс M-2026-16283: КП ушло покупателю, но заявка считала клиентом
     * order@myzip.ru — из-за этого детектор не признал письмо адресованным
     * клиенту, документ не распознался и статус остался «в работе».
     *
     * @return array<string, mixed> поля клиента для Request::create
     */
    private function resolveClient(EmailMessage $email): array
    {
        if ($this->webForm->isWebFormSubmission($email)) {
            $parsed = $this->webForm->parse($email);
            if ($parsed !== null && ($parsed['email'] ?? '') !== '') {
                return [
                    'client_email' => $parsed['email'],
                    'client_name' => $parsed['name'] ?: ($parsed['company'] ?: $email->from_name),
                    'client_phone' => $parsed['phone'] ?? null,
                    'client_company' => $parsed['company'] ?? null,
                    'client_address' => $parsed['address'] ?? null,
                ];
            }
        }

        if ($this->forwarded->isForwarded($email)) {
            $parsed = $this->forwarded->parse($email);
            if ($parsed !== null) {
                return [
                    'client_email' => $parsed['email'],
                    'client_name' => $parsed['name'] ?: $email->from_name,
                ];
            }
        }

        return [
            'client_email' => $email->from_email ?: '',
            'client_name' => $email->from_name,
        ];
    }
}
