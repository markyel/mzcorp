<?php

namespace Tests\Unit\Models;

use App\Enums\MarketingSection;
use App\Models\MarketingEntry;
use App\Models\MarketingReport;
use App\Models\MarketingService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Раздел «Маркетинг»: шифрование доступов, нормализация отчётного периода и
 * соответствие рубрикатора форме Приложения № 1. Без БД.
 */
class MarketingWorkspaceTest extends TestCase
{
    /* --------------------------- Доступы --------------------------- */

    public function test_secrets_round_trip_through_encryption(): void
    {
        $service = new MarketingService;
        $service->writeSecrets(['password' => 'p@ss w0rd', 'api_key' => 'AQAA-123', 'extra' => 'коды 2FA']);

        $this->assertSame('p@ss w0rd', $service->secret('password'));
        $this->assertSame('AQAA-123', $service->secret('api_key'));
        $this->assertSame('коды 2FA', $service->secret('extra'));
    }

    public function test_plaintext_secret_never_stored_in_the_column(): void
    {
        $service = new MarketingService;
        $service->writeSecrets(['password' => 'sekret']);

        $this->assertNotNull($service->encrypted_secrets);
        $this->assertStringNotContainsString('sekret', (string) $service->encrypted_secrets);
    }

    public function test_secrets_are_hidden_from_array_form(): void
    {
        $service = new MarketingService(['name' => 'Директ']);
        $service->writeSecrets(['password' => 'sekret']);

        $this->assertArrayNotHasKey('encrypted_secrets', $service->toArray());
    }

    public function test_empty_values_are_not_stored(): void
    {
        $service = new MarketingService;
        $service->writeSecrets(['password' => '  ', 'api_key' => null, 'extra' => '']);

        $this->assertNull($service->encrypted_secrets);
        $this->assertFalse($service->hasSecrets());
        $this->assertSame([], $service->secrets());
    }

    public function test_corrupted_ciphertext_does_not_throw(): void
    {
        $service = new MarketingService;
        $service->encrypted_secrets = 'не шифротекст';

        $this->assertSame([], $service->secrets());
        $this->assertNull($service->secret('password'));
    }

    public function test_category_label_falls_back_to_other(): void
    {
        $service = new MarketingService(['category' => 'ads']);
        $this->assertSame('Реклама', $service->categoryLabel());

        $service->category = 'несуществующая';
        $this->assertSame('Прочее', $service->categoryLabel());
    }

    /* ---------------------------- Период ---------------------------- */

    public function test_period_normalizes_to_first_day_of_month(): void
    {
        $this->assertSame('2026-09-01', MarketingEntry::normalizePeriod('2026-09-17')->toDateString());
        $this->assertSame('2026-09-01', MarketingEntry::normalizePeriod(Carbon::parse('2026-09-30 23:59'))->toDateString());
    }

    public function test_month_label_is_russian(): void
    {
        $this->assertSame('Сентябрь 2026', MarketingReport::monthLabel('2026-09-01'));
        $this->assertSame('Январь 2027', MarketingReport::monthLabel('2027-01-15'));
        $this->assertSame('Декабрь 2026', MarketingReport::monthLabel(Carbon::parse('2026-12-31')));
    }

    /* ------------------- Что попадает в отчёт ------------------- */

    public function test_work_entry_always_counts_as_done(): void
    {
        $entry = new MarketingEntry(['kind' => MarketingEntry::KIND_WORK, 'status' => MarketingEntry::STATUS_PLANNED]);

        $this->assertTrue($entry->countsAsDone());
    }

    public function test_plan_entry_counts_only_when_done(): void
    {
        $planned = new MarketingEntry(['kind' => MarketingEntry::KIND_PLAN, 'status' => MarketingEntry::STATUS_PLANNED]);
        $done = new MarketingEntry(['kind' => MarketingEntry::KIND_PLAN, 'status' => MarketingEntry::STATUS_DONE]);
        $dropped = new MarketingEntry(['kind' => MarketingEntry::KIND_PLAN, 'status' => MarketingEntry::STATUS_DROPPED]);

        $this->assertFalse($planned->countsAsDone());
        $this->assertTrue($done->countsAsDone());
        $this->assertFalse($dropped->countsAsDone());
    }

    /* ----------------------- Форма отчёта ----------------------- */

    public function test_sections_match_the_contract_form_numbering(): void
    {
        $numbers = array_map(fn (MarketingSection $s) => $s->formNumber(), MarketingSection::ordered());

        // Пункты 2–8 формы, по порядку и без пропусков.
        $this->assertSame([2, 3, 4, 5, 6, 7, 8], $numbers);
    }

    public function test_every_section_has_fields_and_labels(): void
    {
        foreach (MarketingSection::ordered() as $section) {
            $this->assertNotSame('', $section->label(), $section->value);
            $this->assertNotSame('', $section->shortLabel(), $section->value);
            $this->assertNotEmpty($section->fields(), $section->value);
        }
    }

    public function test_entry_section_label_survives_unknown_value(): void
    {
        $entry = new MarketingEntry(['section' => 'ads']);
        $this->assertSame('Реклама', $entry->sectionLabel());

        $entry->section = 'выпилили_раздел';
        $this->assertSame('—', $entry->sectionLabel());
        $this->assertNull($entry->sectionEnum());
    }
}
