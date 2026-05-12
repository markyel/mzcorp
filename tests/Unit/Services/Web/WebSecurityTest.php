<?php

namespace Tests\Unit\Services\Web;

use App\Services\Web\WebSecurity;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты SSRF-гарда. Без сетевых вызовов — isPublicIp проверяется
 * чисто по таблице CIDR. isHostSafe для произвольных DNS не тестируется
 * чтобы не зависеть от резолвера в CI.
 */
class WebSecurityTest extends TestCase
{
    private WebSecurity $sec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sec = new WebSecurity();
    }

    public function test_blocks_loopback_ipv4(): void
    {
        $this->assertFalse($this->sec->isPublicIp('127.0.0.1'));
        $this->assertFalse($this->sec->isPublicIp('127.255.255.254'));
    }

    public function test_blocks_rfc1918_private(): void
    {
        $this->assertFalse($this->sec->isPublicIp('10.0.0.1'));
        $this->assertFalse($this->sec->isPublicIp('10.255.255.255'));
        $this->assertFalse($this->sec->isPublicIp('172.16.0.1'));
        $this->assertFalse($this->sec->isPublicIp('172.31.255.255'));
        $this->assertFalse($this->sec->isPublicIp('192.168.0.1'));
        $this->assertFalse($this->sec->isPublicIp('192.168.255.255'));
    }

    public function test_blocks_link_local_and_metadata(): void
    {
        $this->assertFalse($this->sec->isPublicIp('169.254.0.1'));
        $this->assertFalse($this->sec->isPublicIp('169.254.169.254'), 'AWS/GCP metadata');
    }

    public function test_blocks_cgnat(): void
    {
        $this->assertFalse($this->sec->isPublicIp('100.64.0.1'));
        $this->assertFalse($this->sec->isPublicIp('100.127.255.254'));
    }

    public function test_blocks_multicast_and_reserved(): void
    {
        $this->assertFalse($this->sec->isPublicIp('224.0.0.1'));
        $this->assertFalse($this->sec->isPublicIp('239.255.255.255'));
        $this->assertFalse($this->sec->isPublicIp('240.0.0.1'));
        $this->assertFalse($this->sec->isPublicIp('255.255.255.255'));
    }

    public function test_allows_public_ipv4(): void
    {
        $this->assertTrue($this->sec->isPublicIp('8.8.8.8'));
        $this->assertTrue($this->sec->isPublicIp('1.1.1.1'));
        $this->assertTrue($this->sec->isPublicIp('77.88.55.60'));   // yandex
        $this->assertTrue($this->sec->isPublicIp('176.97.66.10'));  // mylift.ru example
    }

    public function test_blocks_ipv6_loopback_linklocal_ula(): void
    {
        $this->assertFalse($this->sec->isPublicIp('::1'));
        $this->assertFalse($this->sec->isPublicIp('::'));
        $this->assertFalse($this->sec->isPublicIp('fe80::1'));
        $this->assertFalse($this->sec->isPublicIp('fc00::1'));
        $this->assertFalse($this->sec->isPublicIp('fd00::1'));
        $this->assertFalse($this->sec->isPublicIp('ff02::1'));
    }

    public function test_allows_public_ipv6(): void
    {
        $this->assertTrue($this->sec->isPublicIp('2001:4860:4860::8888'));
        $this->assertTrue($this->sec->isPublicIp('2606:4700::1111'));
    }

    public function test_ipv4_mapped_ipv6_inherits_v4_rules(): void
    {
        $this->assertFalse($this->sec->isPublicIp('::ffff:127.0.0.1'));
        $this->assertFalse($this->sec->isPublicIp('::ffff:10.0.0.1'));
        $this->assertFalse($this->sec->isPublicIp('::ffff:169.254.169.254'));
        $this->assertTrue($this->sec->isPublicIp('::ffff:8.8.8.8'));
    }

    public function test_localhost_aliases_are_unsafe(): void
    {
        $this->assertFalse($this->sec->isHostSafe('localhost'));
        $this->assertFalse($this->sec->isHostSafe('foo.localhost'));
        $this->assertFalse($this->sec->isHostSafe('printer.local'));
        $this->assertFalse($this->sec->isHostSafe(''));
    }

    public function test_literal_ip_host_inherits_isPublicIp(): void
    {
        $this->assertFalse($this->sec->isHostSafe('127.0.0.1'));
        $this->assertFalse($this->sec->isHostSafe('169.254.169.254'));
        $this->assertFalse($this->sec->isHostSafe('[::1]'));
    }

    public function test_normalize_url_strips_fragment_sorts_query(): void
    {
        $this->assertSame(
            'https://example.com/path?a=1&b=2',
            $this->sec->normalizeUrl('https://Example.com/path?b=2&a=1#anchor'),
        );
    }

    public function test_normalize_url_rejects_bad_schemes(): void
    {
        $this->assertNull($this->sec->normalizeUrl('file:///etc/passwd'));
        $this->assertNull($this->sec->normalizeUrl('javascript:alert(1)'));
        $this->assertNull($this->sec->normalizeUrl('gopher://x.example/'));
        $this->assertNull($this->sec->normalizeUrl('data:text/html,foo'));
        $this->assertNull($this->sec->normalizeUrl(''));
        $this->assertNull($this->sec->normalizeUrl('not a url'));
    }

    public function test_normalize_url_drops_default_ports(): void
    {
        $this->assertSame(
            'https://example.com/',
            $this->sec->normalizeUrl('https://example.com:443/'),
        );
        $this->assertSame(
            'http://example.com/',
            $this->sec->normalizeUrl('http://example.com:80/'),
        );
        $this->assertSame(
            'https://example.com:8443/',
            $this->sec->normalizeUrl('https://example.com:8443/'),
        );
    }
}
