<?php

namespace Tests\Unit\QuoteParts;

use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\SupplierMirrorService;
use App\Services\Settings\SettingsService;
use PHPUnit\Framework\TestCase;

class SupplierMirrorContactsTest extends TestCase
{
    private function mirror(): SupplierMirrorService
    {
        return new SupplierMirrorService(
            (new \ReflectionClass(QuotePartsClient::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor(),
        );
    }

    public function test_our_contacts_and_the_suppliers_own_choice_merge_by_address(): void
    {
        $contacts = $this->mirror()->contacts([
            'contacts' => [
                ['email' => 'Sales@Bueno.example', 'name' => 'Володя', 'locale' => 'ru', 'topics' => ['quoting', 'general'], 'position' => 1, 'phone' => '+86 1'],
                ['email' => 'new@bueno.example', 'name' => 'new@bueno.example', 'locale' => 'en', 'topics' => ['claims'], 'position' => 2],
            ],
            'supplier_contacts' => [
                ['topic' => 'finance', 'email' => 'sales@bueno.example', 'name' => 'Vova', 'position' => 3],
                ['topic' => 'logistics', 'email' => 'cargo@bueno.example', 'name' => 'Li', 'phone' => '777', 'position' => 1],
            ],
        ]);

        $this->assertSame(['quoting', 'general', 'finance'], $contacts['sales@bueno.example']['topics']);
        $this->assertSame('Володя', $contacts['sales@bueno.example']['name']);
        $this->assertSame(1, $contacts['sales@bueno.example']['position']);
        // An address used as a placeholder name is not a name.
        $this->assertNull($contacts['new@bueno.example']['name']);
        $this->assertSame(['logistics'], $contacts['cargo@bueno.example']['topics']);
        $this->assertSame('777', $contacts['cargo@bueno.example']['phone']);
    }
}
