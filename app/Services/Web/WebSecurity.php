<?php

namespace App\Services\Web;

/**
 * SSRF guard и нормализатор URL для веб-фетча из недоверенных источников
 * (входящие письма).
 *
 * Проверяем, что:
 *  - схема — http/https (никаких file://, gopher://, ftp://, javascript:, data:);
 *  - резолвленные IP-адреса хоста — публичные (не loopback, не приватные
 *    RFC1918, не link-local, не CGNAT, не cloud-metadata, не reserved);
 *  - всё это и для IPv4, и для IPv6.
 *
 * Метод isPublicIp используется и при первичной проверке URL, и при
 * каждом редиректе (целевой хост может вернуть Location: http://10.x).
 */
class WebSecurity
{
    /**
     * IPv4-диапазоны, в которые мы НЕ ходим. Перечислены как CIDR, парсим
     * сами (без зависимости на ext-pcntl или внешние пакеты).
     *
     * @var list<array{0: string, 1: int}> [base, prefix_length]
     */
    private const IPV4_BLOCKED = [
        ['0.0.0.0',         8],   // "this network"
        ['10.0.0.0',        8],   // RFC1918 private
        ['100.64.0.0',     10],   // CGNAT
        ['127.0.0.0',       8],   // loopback
        ['169.254.0.0',    16],   // link-local (incl. 169.254.169.254 AWS/GCP metadata)
        ['172.16.0.0',     12],   // RFC1918 private
        ['192.0.0.0',      24],   // IETF protocol
        ['192.0.2.0',      24],   // TEST-NET-1
        ['192.168.0.0',    16],   // RFC1918 private
        ['198.18.0.0',     15],   // benchmark
        ['198.51.100.0',   24],   // TEST-NET-2
        ['203.0.113.0',    24],   // TEST-NET-3
        ['224.0.0.0',       4],   // multicast
        ['240.0.0.0',       4],   // reserved
        ['255.255.255.255', 32],  // broadcast
    ];

    /**
     * Проверка адреса (IPv4 или IPv6) на принадлежность публичному интернету.
     */
    public function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isPublicIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isPublicIpv6($ip);
        }

        return false;
    }

    /**
     * Нормализация URL для cache key: lowercase host, удаление якоря,
     * сортировка query (детерминированно). Невалидный URL → null.
     */
    public function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if ($query !== '') {
            parse_str($query, $qs);
            ksort($qs);
            $query = http_build_query($qs);
        }

        $rebuilt = $scheme . '://' . $host;
        if ($port && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $rebuilt .= ':' . $port;
        }
        $rebuilt .= $path;
        if ($query !== '') {
            $rebuilt .= '?' . $query;
        }

        return $rebuilt;
    }

    public function hashUrl(string $normalizedUrl): string
    {
        return hash('sha256', $normalizedUrl);
    }

    /**
     * Резолвим хост → возвращаем все A/AAAA-записи. Используется чтобы:
     *   а) проверить, что хотя бы один адрес публичный (отсечь приватные DNS);
     *   б) передать конкретный IP в CURLOPT_RESOLVE и защититься от
     *      DNS-rebinding между нашим резолвом и GET-запросом.
     *
     * @return array{ipv4: list<string>, ipv6: list<string>}
     */
    public function resolveHost(string $host): array
    {
        $ipv4 = [];
        $ipv6 = [];

        // gethostbynamel — IPv4 синхронно. Для IPv6 — dns_get_record.
        $a = @gethostbynamel($host);
        if (is_array($a)) {
            $ipv4 = array_values(array_filter($a, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (! empty($rec['ipv6']) && filter_var($rec['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $ipv6[] = $rec['ipv6'];
                }
            }
        }

        return ['ipv4' => $ipv4, 'ipv6' => $ipv6];
    }

    /**
     * Хост безопасен, если он резолвится и ВСЕ резолвленные адреса публичные.
     * Если хоть один адрес приватный — отказ (DNS-rebind на mixed A-set).
     */
    public function isHostSafe(string $host): bool
    {
        // Пустой / явные приватные псевдонимы.
        $hostLower = strtolower($host);
        if ($hostLower === '' || $hostLower === 'localhost' || str_ends_with($hostLower, '.localhost') || str_ends_with($hostLower, '.local')) {
            return false;
        }

        // Если в URL уже передан IP — проверяем напрямую.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host);
        }

        $resolved = $this->resolveHost($host);
        $all = array_merge($resolved['ipv4'], $resolved['ipv6']);
        if (empty($all)) {
            return false;
        }
        foreach ($all as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIpv4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        foreach (self::IPV4_BLOCKED as [$base, $prefix]) {
            $baseLong = ip2long($base);
            $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix)) & 0xFFFFFFFF;
            if ((($long & $mask) & 0xFFFFFFFF) === (($baseLong & $mask) & 0xFFFFFFFF)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIpv6(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        $hex = bin2hex($packed);

        // ::1 loopback
        if ($hex === str_repeat('0', 31) . '1') {
            return false;
        }
        // :: unspecified
        if ($hex === str_repeat('0', 32)) {
            return false;
        }
        // fe80::/10 link-local
        $first16 = hexdec(substr($hex, 0, 4));
        if (($first16 & 0xFFC0) === 0xFE80) {
            return false;
        }
        // fc00::/7 ULA
        $firstByte = hexdec(substr($hex, 0, 2));
        if (($firstByte & 0xFE) === 0xFC) {
            return false;
        }
        // ff00::/8 multicast
        if ($firstByte === 0xFF) {
            return false;
        }
        // IPv4-mapped ::ffff:x.x.x.x → проверяем как IPv4
        if (str_starts_with($hex, '00000000000000000000ffff')) {
            $v4hex = substr($hex, 24, 8);
            $v4 = implode('.', array_map(fn ($i) => hexdec(substr($v4hex, $i * 2, 2)), [0, 1, 2, 3]));

            return $this->isPublicIpv4($v4);
        }

        return true;
    }
}
