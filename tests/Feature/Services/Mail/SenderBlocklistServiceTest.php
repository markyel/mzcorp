<?php

namespace Tests\Feature\Services\Mail;

use App\Enums\BlocklistEntrySource;
use App\Enums\BlocklistEntryType;
use App\Models\SenderBlocklistEntry;
use App\Models\User;
use App\Services\Mail\SenderBlocklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration тесты сервиса стоп-листа: матчинг, dедуп, hit-counter.
 *
 * Главный кейс — суффикс-матч по домену не должен ловить
 * `paulschaab.de.evil.com` записью `paulschaab.de`.
 */
class SenderBlocklistServiceTest extends TestCase
{
    use RefreshDatabase;

    private SenderBlocklistService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(SenderBlocklistService::class);
    }

    public function test_blocks_by_exact_email(): void
    {
        $this->svc->block(
            'Spam@Bad.com',
            BlocklistEntryType::Email,
            BlocklistEntrySource::Manual,
        );

        $this->assertTrue($this->svc->isBlocked('spam@bad.com'));
        $this->assertTrue($this->svc->isBlocked('SPAM@BAD.COM'));
        // Plus-addressing: пытаемся обойти — не выйдет.
        $this->assertTrue($this->svc->isBlocked('spam+anything@bad.com'));

        $this->assertFalse($this->svc->isBlocked('other@bad.com'));
        $this->assertFalse($this->svc->isBlocked('spam@good.com'));
    }

    public function test_blocks_by_domain_with_subdomain_suffix_match(): void
    {
        $this->svc->block(
            'paulschaab.de',
            BlocklistEntryType::Domain,
            BlocklistEntrySource::Manual,
        );

        // Точный домен.
        $this->assertTrue($this->svc->isBlocked('any@paulschaab.de'));
        // Поддомен.
        $this->assertTrue($this->svc->isBlocked('any@mail.paulschaab.de'));
        $this->assertTrue($this->svc->isBlocked('any@deep.nested.paulschaab.de'));

        // Главное: не должен ловить «суффиксное» имя похожего домена.
        $this->assertFalse($this->svc->isBlocked('any@paulschaab.de.evil.com'));
        // И «префиксное» совпадение тоже не должно срабатывать.
        $this->assertFalse($this->svc->isBlocked('any@evilpaulschaab.de'));
    }

    public function test_block_is_idempotent_by_normalized_value(): void
    {
        $first = $this->svc->block(
            'Foo@Example.com',
            BlocklistEntryType::Email,
            BlocklistEntrySource::Manual,
        );

        $second = $this->svc->block(
            'foo+anything@example.com',
            BlocklistEntryType::Email,
            BlocklistEntrySource::Manual,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SenderBlocklistEntry::count());
    }

    public function test_block_throws_on_invalid_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->block('not-an-email', BlocklistEntryType::Email, BlocklistEntrySource::Manual);
    }

    public function test_hit_counter_increments_on_match(): void
    {
        $entry = $this->svc->block(
            'paulschaab.de',
            BlocklistEntryType::Domain,
            BlocklistEntrySource::Manual,
        );

        $this->assertSame(0, $entry->hit_count);
        $this->assertNull($entry->last_hit_at);

        $this->svc->isBlocked('a@paulschaab.de');
        $this->svc->isBlocked('b@mail.paulschaab.de');

        $entry->refresh();
        $this->assertSame(2, $entry->hit_count);
        $this->assertNotNull($entry->last_hit_at);
    }

    public function test_unblock_removes_entry(): void
    {
        $entry = $this->svc->block(
            'paulschaab.de',
            BlocklistEntryType::Domain,
            BlocklistEntrySource::Manual,
        );

        $this->assertTrue($this->svc->isBlocked('a@paulschaab.de'));

        $this->assertTrue($this->svc->unblock($entry->id));
        $this->assertFalse($this->svc->isBlocked('a@paulschaab.de'));
        $this->assertSame(0, SenderBlocklistEntry::count());
    }

    public function test_bulk_block_creates_skips_invalid(): void
    {
        $by = User::factory()->create();

        $result = $this->svc->bulkBlock(
            [
                'spam1@bad.com',
                'spam2@bad.com',
                'paulschaab.de',
                'spam1@bad.com', // дубль — skipped
                'not_an_email_nor_domain', // invalid
                '', // пропускается тихо
                '  https://other.com/  ', // нормализуется в other.com
            ],
            BlocklistEntrySource::Manual,
            $by,
        );

        $this->assertSame(4, $result['created']); // 2 email + 2 domain
        $this->assertSame(1, $result['skipped']); // дубль
        $this->assertSame(['not_an_email_nor_domain'], $result['invalid']);

        $this->assertTrue($this->svc->isBlocked('spam1@bad.com'));
        $this->assertTrue($this->svc->isBlocked('x@paulschaab.de'));
        $this->assertTrue($this->svc->isBlocked('x@other.com'));
    }

    public function test_normalized_value_is_persisted(): void
    {
        $entry = $this->svc->block(
            'Foo+x@Example.COM',
            BlocklistEntryType::Email,
            BlocklistEntrySource::Manual,
        );

        $this->assertSame('Foo+x@Example.COM', $entry->value);
        $this->assertSame('foo@example.com', $entry->normalized_value);
    }
}
