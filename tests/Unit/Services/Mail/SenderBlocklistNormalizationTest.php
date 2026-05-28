<?php

namespace Tests\Unit\Services\Mail;

use App\Enums\BlocklistEntryType;
use App\Services\Mail\SenderBlocklistService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit тесты нормализации стоп-листа: без БД, без Laravel-bootstrap.
 *
 * Покрывают `normalizeEmail`, `normalizeDomain`, `normalizeFor` — публичные
 * методы, которые гарантируют, что одна и та же запись не появится в БД
 * дважды из-за регистра / plus-addressing / URL-обёрток.
 */
class SenderBlocklistNormalizationTest extends TestCase
{
    private SenderBlocklistService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SenderBlocklistService;
    }

    public function test_normalizes_email_to_lowercase(): void
    {
        $this->assertSame('foo@example.com', $this->svc->normalizeEmail('Foo@Example.COM'));
    }

    public function test_strips_plus_addressing(): void
    {
        $this->assertSame('foo@example.com', $this->svc->normalizeEmail('foo+anything@example.com'));
        $this->assertSame('foo@example.com', $this->svc->normalizeEmail('FOO+bar+baz@Example.com'));
    }

    public function test_rejects_obviously_invalid_email(): void
    {
        $this->assertNull($this->svc->normalizeEmail(null));
        $this->assertNull($this->svc->normalizeEmail(''));
        $this->assertNull($this->svc->normalizeEmail('not-an-email'));
        $this->assertNull($this->svc->normalizeEmail('@example.com'));
        $this->assertNull($this->svc->normalizeEmail('foo@'));
        // plus-addressing съел весь local-part
        $this->assertNull($this->svc->normalizeEmail('+bar@example.com'));
    }

    public function test_trims_whitespace(): void
    {
        $this->assertSame('foo@example.com', $this->svc->normalizeEmail("  foo@example.com\n"));
    }

    public function test_normalizes_domain_basic(): void
    {
        $this->assertSame('paulschaab.de', $this->svc->normalizeDomain('Paulschaab.DE'));
        $this->assertSame('paulschaab.de', $this->svc->normalizeDomain('  paulschaab.de  '));
    }

    public function test_normalizes_domain_strips_url_wrappers(): void
    {
        $this->assertSame('paulschaab.de', $this->svc->normalizeDomain('https://paulschaab.de/'));
        $this->assertSame('paulschaab.de', $this->svc->normalizeDomain('http://paulschaab.de/path/here'));
        $this->assertSame('paulschaab.de', $this->svc->normalizeDomain('@paulschaab.de'));
        $this->assertSame('paulschaab.de', $this->svc->normalizeDomain('paulschaab.de.'));
    }

    public function test_rejects_invalid_domain(): void
    {
        $this->assertNull($this->svc->normalizeDomain(null));
        $this->assertNull($this->svc->normalizeDomain(''));
        // нет точки — не выглядит доменом
        $this->assertNull($this->svc->normalizeDomain('localhost'));
    }

    public function test_normalize_for_dispatches_by_type(): void
    {
        $this->assertSame(
            'foo@bar.com',
            $this->svc->normalizeFor(BlocklistEntryType::Email, 'Foo+x@BAR.com')
        );
        $this->assertSame(
            'bar.com',
            $this->svc->normalizeFor(BlocklistEntryType::Domain, 'https://BAR.com/')
        );
    }
}
