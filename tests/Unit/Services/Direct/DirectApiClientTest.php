<?php

namespace Tests\Unit\Services\Direct;

use App\Livewire\Direct\Index as DirectIndex;
use App\Services\Direct\DirectApiClient;
use Tests\TestCase;

/**
 * Клиент API Директа: разбор заголовка с баллами и границы настройки
 * «сколько объявлений держим». Без сети.
 */
class DirectApiClientTest extends TestCase
{
    public function test_misleading_sandbox_error_gets_a_hint(): void
    {
        // 513 в песочнице говорит «нет доступа», а на деле песочница просто
        // не заведена под наш логин — боевой контур при этом работает.
        $this->assertStringContainsString('YANDEX_DIRECT_SANDBOX=false', DirectApiClient::hint(513, true));
        $this->assertSame('', DirectApiClient::hint(513, false));
        $this->assertStringContainsString('не одобрена', DirectApiClient::hint(58, false));
        $this->assertSame('', DirectApiClient::hint(0, true));
    }

    public function test_units_header_is_parsed(): void
    {
        // «потрачено/осталось/суточный лимит» — так Директ отдаёт расход баллов.
        $this->assertSame(
            ['spent' => 11, 'rest' => 159989, 'limit' => 160000],
            DirectApiClient::parseUnits('11/159989/160000')
        );
    }

    public function test_units_header_tolerates_spaces(): void
    {
        $this->assertSame(
            ['spent' => 5, 'rest' => 100, 'limit' => 200],
            DirectApiClient::parseUnits(' 5/100/200 ')
        );
    }

    public function test_missing_or_broken_units_header_is_null(): void
    {
        $this->assertNull(DirectApiClient::parseUnits(null));
        $this->assertNull(DirectApiClient::parseUnits(''));
        $this->assertNull(DirectApiClient::parseUnits('нет данных'));
        $this->assertNull(DirectApiClient::parseUnits('11/159989'));
    }

    public function test_call_without_token_fails_without_network(): void
    {
        config(['services.yandex_direct.token' => '']);

        $res = app(DirectApiClient::class)->call('campaigns', 'get');

        $this->assertFalse($res['ok']);
        $this->assertSame(0, $res['http']);
        $this->assertStringContainsString('токен', $res['error']['message']);
    }

    public function test_ads_limit_is_clamped(): void
    {
        $this->assertSame(DirectIndex::MIN_ADS_LIMIT, DirectIndex::clamp(0));
        $this->assertSame(DirectIndex::MIN_ADS_LIMIT, DirectIndex::clamp(-5));
        $this->assertSame(DirectIndex::MAX_ADS_LIMIT, DirectIndex::clamp(10_000));
        $this->assertSame(10, DirectIndex::clamp(10));
    }
}
