<?php

namespace Tests\Unit\Services\DocumentDetector;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Prompts\Mail\ClassifyClientResponsePrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\DocumentDetector\InboundIntentClassifier;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit тест гейта isApplicable(): какие статусы заявки допускают
 * классификацию клиентского ответа. Без БД и без вызова OpenAI —
 * isApplicable() от зависимостей не зависит.
 *
 * Регресс на M-2026-2389: отказ клиента после счёта (status=invoiced)
 * должен попадать в классификатор, иначе auto-close lost не сработает.
 */
class InboundIntentClassifierEligibilityTest extends TestCase
{
    private InboundIntentClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new InboundIntentClassifier(
            Mockery::mock(OpenAIChatService::class),
            new ClassifyClientResponsePrompt,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function requestWithStatus(RequestStatus $status): Request
    {
        $request = new Request;
        $request->setRawAttributes(['status' => $status->value], sync: true);

        return $request;
    }

    #[DataProvider('eligibleStatuses')]
    public function test_eligible_statuses_are_applicable(RequestStatus $status): void
    {
        $this->assertTrue(
            $this->classifier->isApplicable($this->requestWithStatus($status)),
            "Статус {$status->value} должен быть eligible для классификации ответа клиента",
        );
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_ineligible_statuses_are_not_applicable(RequestStatus $status): void
    {
        $this->assertFalse(
            $this->classifier->isApplicable($this->requestWithStatus($status)),
            "Статус {$status->value} НЕ должен запускать классификацию ответа клиента",
        );
    }

    public static function eligibleStatuses(): array
    {
        return [
            'quoted' => [RequestStatus::Quoted],
            'under_review' => [RequestStatus::UnderReview],
            'postponed_until' => [RequestStatus::PostponedUntil],
            'awaiting_client_clarification' => [RequestStatus::AwaitingClientClarification],
            'awaiting_invoice' => [RequestStatus::AwaitingInvoice],
            'invoiced' => [RequestStatus::Invoiced],
        ];
    }

    public static function ineligibleStatuses(): array
    {
        return [
            'pending' => [RequestStatus::Pending],
            'new' => [RequestStatus::New],
            'assigned' => [RequestStatus::Assigned],
            'in_progress' => [RequestStatus::InProgress],
            'paid' => [RequestStatus::Paid],
            'paused' => [RequestStatus::Paused],
            'closed_won' => [RequestStatus::ClosedWon],
            'closed_lost' => [RequestStatus::ClosedLost],
        ];
    }
}
