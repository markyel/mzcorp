<?php

namespace Tests\Unit\Services\Marketing;

use App\Models\EmailMessage;
use App\Services\Marketing\MarketingBlockService;
use Tests\TestCase;

/**
 * Рендер блока и правила «кому вставлять». Без записи в БД: SupplierRegistry
 * ходит в таблицу suppliers только на чтение (пустой ответ = не поставщик).
 */
class MarketingBlockServiceTest extends TestCase
{
    private MarketingBlockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.mail.internal_domains', ['myzip.ru', 'mylift.ru']);
        $this->service = app(MarketingBlockService::class);
    }

    public function test_render_produces_email_safe_html_and_plain(): void
    {
        $r = $this->service->render([
            'title' => 'Платы KONE <со склада>',
            'text' => "Первая строка\nвторая",
            'url' => 'https://myzip.ru/kone?a=1&b=2',
            'image_url' => 'https://mzcorp.ru/dashboard/marketing-blocks/7/image?v=1',
            'image_path' => '/tmp/x.png',
        ]);

        $this->assertStringContainsString('<table role="presentation"', $r['html']);
        $this->assertStringContainsString('Платы KONE &lt;со склада&gt;', $r['html']);
        $this->assertStringContainsString('Первая строка<br', $r['html']);
        $this->assertStringContainsString('href="https://myzip.ru/kone?a=1&amp;b=2"', $r['html']);
        $this->assertStringContainsString('src="https://mzcorp.ru/dashboard/marketing-blocks/7/image?v=1"', $r['html']);
        $this->assertStringContainsString('Подробнее', $r['html']);
        $this->assertStringNotContainsString('<style', $r['html']);

        $this->assertSame("\n\nПлаты KONE <со склада>\nПервая строка\nвторая\nhttps://myzip.ru/kone?a=1&b=2", $r['plain']);
        $this->assertSame('/tmp/x.png', $r['image_path']);
    }

    public function test_render_without_image_has_no_img(): void
    {
        $r = $this->service->render(['title' => 'T', 'text' => 'X', 'url' => 'https://myzip.ru']);

        $this->assertStringNotContainsString('<img', $r['html']);
        $this->assertNull($r['image_url']);
    }

    public function test_empty_block_renders_nothing(): void
    {
        $r = $this->service->render(['title' => '', 'text' => '', 'url' => 'https://myzip.ru']);

        $this->assertSame('', $r['html']);
        $this->assertSame('', $r['plain']);
    }

    public function test_internal_only_recipients_are_not_eligible(): void
    {
        $m = new EmailMessage;
        $m->to_recipients = [['email' => 'Ilya.Kurzaev@MYZIP.ru']];
        $m->cc_recipients = [['email' => 'info@mylift.ru']];

        $this->assertFalse($this->service->isEligible($m));
    }

    public function test_external_client_is_eligible_even_with_internal_cc(): void
    {
        $m = new EmailMessage;
        $m->to_recipients = [['email' => 'client@example.com']];
        $m->cc_recipients = [['email' => 'info@myzip.ru']];

        $this->assertTrue($this->service->isEligible($m));
    }

    public function test_supplier_inquiry_draft_is_not_eligible(): void
    {
        $m = new EmailMessage;
        $m->to_recipients = [['email' => 'client@example.com']];
        $m->supplier_inquiry_id = 5;

        $this->assertFalse($this->service->isEligible($m));
    }

    public function test_no_recipients_not_eligible(): void
    {
        $m = new EmailMessage;
        $m->to_recipients = [];

        $this->assertFalse($this->service->isEligible($m));
    }

    public function test_test_payload_in_artifacts_wins_over_selection(): void
    {
        $m = new EmailMessage;
        $m->to_recipients = [['email' => 'client@example.com']];
        $m->detected_artifacts = [MarketingBlockService::ARTIFACT_TEST => [
            'title' => 'Тест', 'text' => 'Текст', 'url' => 'https://myzip.ru',
        ]];

        $r = $this->service->resolveForDraft($m);

        $this->assertNotNull($r);
        $this->assertStringContainsString('Тест', $r['html']);
        // Выбор не фиксировался — тестовый payload не трогает marketing_block_id.
        $this->assertArrayNotHasKey(MarketingBlockService::ARTIFACT_BLOCK_ID, (array) $m->detected_artifacts);
    }
}
