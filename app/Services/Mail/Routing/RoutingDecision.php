<?php

namespace App\Services\Mail\Routing;

/**
 * Итоговое решение обработчика: маршрутизация письма закончена на стадии
 * `stage` (код из MailDecisionRecorder::STAGES). Записывается в mail_decisions.
 */
final class RoutingDecision
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $stage,
        public readonly ?int $requestId = null,
        public readonly array $payload = [],
    ) {
    }
}
