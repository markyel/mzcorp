<?php

namespace App\Notifications;

use App\Models\OrganizationLinkRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Колокольчик: привязка контрагента к адресу заказчика ждёт подтверждения
 * (см. OrganizationLinkGuard). Письмо уходит отдельно — OrganizationLinkPendingMail.
 *
 * Database channel only.
 */
class OrganizationLinkPendingNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $linkRequestId,
        public readonly ?int $requestId,
        public readonly ?string $internalCode,
        public readonly string $organizationName,
        public readonly string $email,
    ) {}

    public static function from(OrganizationLinkRequest $pending): self
    {
        return new self(
            linkRequestId: $pending->id,
            requestId: $pending->request_id,
            internalCode: $pending->request?->internal_code,
            organizationName: (string) $pending->organization?->name,
            email: (string) $pending->contact?->email,
        );
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'organization_link_pending',
            'link_request_id' => $this->linkRequestId,
            'request_id' => $this->requestId,
            'internal_code' => $this->internalCode,
            'organization_name' => mb_substr($this->organizationName, 0, 120),
            'email' => $this->email,
        ];
    }
}
