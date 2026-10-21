<?php

namespace Tests\Unit\QuoteParts;

use App\Models\Supplier;
use App\Models\User;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

/** Внесистемные поставщики: наше письмо в QuoteParts — по ключу поставщика, с подписью автора. */
class QuotePartsOffsystemLettersTest extends TestCase
{
    private static function letters(array $letters, Collection $suppliers, User $by): array
    {
        $service = (new \ReflectionClass(QuotePartsRfqService::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(QuotePartsRfqService::class, 'offsystemLetters');

        return $m->invoke($service, $letters, $suppliers, $by);
    }

    private static function supplier(int $id, ?int $org = null): Supplier
    {
        $s = new Supplier(['supplier_organization_id' => $org]);
        $s->id = $id;

        return $s;
    }

    private static function letter(string $lang, string $subject): array
    {
        return ['lang' => $lang, 'subject' => $subject, 'html' => '<p>Просим цену</p>', 'plain' => 'Просим цену'];
    }

    public function test_letters_go_by_supplier_key_with_the_authors_signature(): void
    {
        $by = new User(['name' => 'Иван Петров', 'email_signature' => "Иван Петров\nМайЗип"]);
        $out = self::letters(
            [7 => self::letter('ru', 'Запрос расценки — [M-2026-1]'), 8 => self::letter('en', 'Price request — [M-2026-1]')],
            collect([self::supplier(7), self::supplier(8, 31)]),
            $by,
        );

        $this->assertSame(['sup:7', 'org:31'], array_keys($out));
        $this->assertSame('Запрос расценки — [M-2026-1]', $out['sup:7']['subject']);
        $this->assertSame('ru', $out['sup:7']['locale']);
        $this->assertSame('en', $out['org:31']['locale']);
        $this->assertStringStartsWith('Просим цену', $out['sup:7']['text']);
        $this->assertStringContainsString('МайЗип', $out['sup:7']['text']);
        $this->assertStringContainsString('МайЗип', $out['sup:7']['html']);
    }

    public function test_one_letter_per_organization_and_none_missing(): void
    {
        $by = new User(['name' => 'Иван Петров']);
        $out = self::letters(
            [1 => self::letter('ru', 'первое'), 2 => self::letter('ru', 'второе')],
            collect([self::supplier(1, 5), self::supplier(2, 5)]),
            $by,
        );
        $this->assertSame(['org:5'], array_keys($out));
        $this->assertSame('первое', $out['org:5']['subject']);

        $this->expectException(RuntimeException::class);
        self::letters([1 => self::letter('ru', 'x')], collect([self::supplier(1), self::supplier(3)]), $by);
    }
}
