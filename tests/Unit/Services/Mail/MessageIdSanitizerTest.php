<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\OutgoingMailMimeBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Mime\Header\Headers;

/**
 * minudo.ru (тендерная площадка) шлёт Message-ID «uuid@» без домена: он
 * попадал в In-Reply-To/References ответа, Symfony отвергал его как
 * addr-spec, и ответы из ящика 8 не собирались (12–14.10.2026).
 */
class MessageIdSanitizerTest extends TestCase
{
    private function sanitize(array $ids): array
    {
        $m = new ReflectionMethod(OutgoingMailMimeBuilder::class, 'sanitizeMessageIds');
        $builder = (new \ReflectionClass(OutgoingMailMimeBuilder::class))->newInstanceWithoutConstructor();

        return $m->invoke($builder, $ids);
    }

    public function test_id_with_empty_domain_is_dropped(): void
    {
        $this->assertSame([], $this->sanitize(['3bc9802d-e5a5-4587-9970-8230ddc4843e@']));
        $this->assertSame([], $this->sanitize(['<6201f7c4-cd0b-4b0e-890a-9ac092a0a054@>']));
    }

    public function test_id_with_empty_local_part_is_dropped(): void
    {
        $this->assertSame([], $this->sanitize(['@minudo.ru']));
    }

    public function test_only_broken_id_is_dropped_valid_ones_kept(): void
    {
        $out = $this->sanitize(['<a1@mzcorp.ru>', '3bc9802d@', 'b2@mail.360.yandex.ru', '<a1@mzcorp.ru>']);

        $this->assertSame(['a1@mzcorp.ru', 'b2@mail.360.yandex.ru'], $out);
    }

    public function test_sanitized_ids_are_accepted_by_symfony_id_header(): void
    {
        $refs = $this->sanitize(['x@', 'ok-1@minudo.ru', '@y', 'ok-2@mzcorp.ru']);

        $headers = new Headers();
        $headers->addIdHeader('References', $refs);

        $this->assertSame(['ok-1@minudo.ru', 'ok-2@mzcorp.ru'], $headers->get('References')->getIds());
    }
}
