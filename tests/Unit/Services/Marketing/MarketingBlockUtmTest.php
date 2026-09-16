<?php

namespace Tests\Unit\Services\Marketing;

use App\Services\Marketing\MarketingBlockService;
use Tests\TestCase;

/**
 * utm-метка на ссылке рекламного блока (MarketingBlockService::withUtm).
 * Ссылки блоков — поисковые URL каталога со своими параметрами, поэтому
 * главное здесь — ничего не потерять и не переписать. БД не нужна.
 */
class MarketingBlockUtmTest extends TestCase
{
    private function params(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    public function test_adds_utm_to_plain_url(): void
    {
        $q = $this->params(MarketingBlockService::withUtm('https://mylift.ru/', 7));
        $this->assertSame(MarketingBlockService::UTM_SOURCE, $q['utm_source']);
        $this->assertSame(MarketingBlockService::UTM_MEDIUM, $q['utm_medium']);
        $this->assertSame(MarketingBlockService::UTM_CAMPAIGN, $q['utm_campaign']);
        $this->assertSame('block-7', $q['utm_content']);
    }

    public function test_keeps_existing_query_parameters(): void
    {
        $url = 'https://mylift.ru/?text=%D0%BA%D0%B0%D0%BD%D0%B0%D1%82&kind=b26bfe0a-4772-11e0-819a&stock=1&fn=find';
        $q = $this->params(MarketingBlockService::withUtm($url, 6));
        $this->assertSame('канат', $q['text']);
        $this->assertSame('b26bfe0a-4772-11e0-819a', $q['kind']);
        $this->assertSame('1', $q['stock']);
        $this->assertSame('find', $q['fn']);
        $this->assertSame('block-6', $q['utm_content']);
    }

    public function test_does_not_overwrite_manually_set_utm(): void
    {
        $q = $this->params(MarketingBlockService::withUtm('https://mylift.ru/?utm_source=own&utm_content=hand', 7));
        $this->assertSame('own', $q['utm_source']);
        $this->assertSame('hand', $q['utm_content']);
        $this->assertSame(MarketingBlockService::UTM_MEDIUM, $q['utm_medium']);
    }

    public function test_preserves_fragment(): void
    {
        $url = MarketingBlockService::withUtm('https://mylift.ru/catalog#section', 7);
        $this->assertStringEndsWith('#section', $url);
        $this->assertSame('block-7', $this->params($url)['utm_content']);
    }

    public function test_omits_block_id_when_not_given(): void
    {
        $q = $this->params(MarketingBlockService::withUtm('https://mylift.ru/'));
        $this->assertArrayNotHasKey('utm_content', $q);
    }

    /** @dataProvider untouched */
    public function test_leaves_non_http_urls_alone(string $url): void
    {
        $this->assertSame(trim($url), MarketingBlockService::withUtm($url, 7));
    }

    /** @return array<string, array{0: string}> */
    public static function untouched(): array
    {
        return [
            'пусто' => [''],
            'только пробелы' => ['   '],
            'mailto' => ['mailto:info@myzip.ru'],
            'телефон' => ['tel:+74951234567'],
            'без схемы' => ['mylift.ru/catalog'],
        ];
    }
}
