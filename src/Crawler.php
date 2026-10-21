<?php

/*
 * This file is part of ernestdefoe/velvet-rope.
 */

namespace ErnestDefoe\VelvetRope;

use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tells a real search engine from anything that only calls itself one.
 *
 * The user agent alone proves nothing, so a claimed crawler is checked the way
 * the search engines document: the address's reverse DNS name must belong to
 * the engine, and that name must resolve back to the same address. Results
 * are cached per address for a day, and lookups are capped per minute so a
 * flood of fake user agents can't keep the server waiting on DNS.
 */
class Crawler
{
    /**
     * User-agent token => the hostname suffixes its crawlers reverse-resolve to,
     * as each engine documents them. Only crawlers that index, never a host a
     * stranger can control: Google's googleusercontent.com (App Engine and
     * Cloud VMs) and its google.com proxies (user-triggered fetchers such as
     * translation) are left out on purpose.
     */
    public const ENGINES = [
        'googlebot' => ['.googlebot.com'],
        'google-inspectiontool' => ['.googlebot.com'],
        'bingbot' => ['.search.msn.com'],
        'applebot' => ['.applebot.apple.com'],
        'yandex' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
        'baiduspider' => ['.crawl.baidu.com', '.crawl.baidu.jp'],
    ];

    public const LOOKUPS_PER_MINUTE = 30;

    /** @var \Closure(string): (string|false) */
    protected \Closure $reverse;

    /** @var \Closure(string): list<string> */
    protected \Closure $forward;

    public function __construct(
        protected Cache $cache
    ) {
        $this->reverse = fn (string $ip) => @gethostbyaddr($ip);
        $this->forward = function (string $host): array {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

            return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
        };
    }

    /**
     * Replace the DNS lookups (tests).
     *
     * @param \Closure(string): (string|false) $reverse
     * @param \Closure(string): list<string> $forward
     */
    public function resolveWith(\Closure $reverse, \Closure $forward): void
    {
        $this->reverse = $reverse;
        $this->forward = $forward;
    }

    public function verify(ServerRequestInterface $request): bool
    {
        $suffixes = $this->claimed($request->getHeaderLine('User-Agent'));
        $ip = (string) $request->getAttribute('ipAddress');

        if ($suffixes === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $key = 'ernestdefoe-velvet-rope.crawler.'.md5($ip);
        $known = $this->cache->get($key);

        if (is_string($known)) {
            return self::matches($known, $suffixes);
        }

        $budget = 'ernestdefoe-velvet-rope.crawler-lookups.'.intdiv(time(), 60);
        $this->cache->add($budget, 0, 120);

        if ((int) $this->cache->increment($budget) > self::LOOKUPS_PER_MINUTE) {
            return false;
        }

        $host = rtrim(strtolower((string) ($this->reverse)($ip)), '.');
        $verified = $host !== '' && $host !== $ip && in_array(inet_pton($ip), array_map(fn ($a) => @inet_pton($a), ($this->forward)($host)), true);

        // The verified hostname, or '' for "not a crawler": both are cached.
        $this->cache->put($key, $verified ? $host : '', 86400);

        return $verified && self::matches($host, $suffixes);
    }

    /** @return list<string>|null */
    protected function claimed(string $userAgent): ?array
    {
        $userAgent = strtolower($userAgent);

        foreach (self::ENGINES as $token => $suffixes) {
            if (str_contains($userAgent, $token)) {
                return $suffixes;
            }
        }

        return null;
    }

    /** @param list<string> $suffixes */
    protected static function matches(string $host, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
