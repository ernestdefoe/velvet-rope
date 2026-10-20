<?php

/*
 * This file is part of ernestdefoe/velvet-rope.
 */

namespace ErnestDefoe\VelvetRope\Http;

use ErnestDefoe\VelvetRope\Crawler;
use ErnestDefoe\VelvetRope\Meter;
use ErnestDefoe\VelvetRope\Settings;
use ErnestDefoe\VelvetRope\TagLookup;
use ErnestDefoe\VelvetRope\Visitor;
use ErnestDefoe\VelvetRope\Visitors;
use Flarum\Http\CookieFactory;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * On the forum and API stacks, after authentication: records who this
 * request's visitor is and, for a guest while the meter is on, reads their
 * allowance from its cookie and writes it back if a discussion was counted.
 *
 * Internal API calls (a page loading its own data) share the outer request's
 * visitor through its actor object, so a page view counts once.
 */
class TrackVisitor implements MiddlewareInterface
{
    public const COOKIE = 'velvet_rope';

    public function __construct(
        protected Settings $settings,
        protected Visitors $visitors,
        protected Crawler $crawlers,
        protected CookieFactory $cookies,
        protected TagLookup $tags,
        protected Cache $cache,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (RequestUtil::isInternal($request)) {
            return $handler->handle($request);
        }

        // A new request: tags looked up for the last one may have changed.
        $this->tags->flush();

        $actor = RequestUtil::getActor($request);
        $meter = null;

        if ($actor->isGuest() && $this->settings->meterEnabled() && $this->settings->secret() !== '') {
            $raw = $request->getCookieParams()[$this->cookies->getName(self::COOKIE)] ?? null;
            $meter = Meter::fromCookie(is_string($raw) ? $raw : null, $this->settings->secret(), time(), $this->settings->meterLimit(), $this->settings->meterPeriod());

            if ($this->settings->meterByAddress()) {
                $meter->absorb(fn () => $this->byAddress($request)['ids']);
            }
        }

        $this->visitors->begin($actor, new Visitor($request, $meter, $this->crawlers));

        $response = $handler->handle($request);

        if ($meter?->dirty()) {
            $cookie = $this->cookies->make(self::COOKIE, $meter->encode($this->settings->secret()), $meter->lifetime(time()));
            $response = $response->withAddedHeader('Set-Cookie', $cookie->__toString());

            if ($this->settings->meterByAddress()) {
                $entry = $this->byAddress($request);
                $this->cache->put($entry['key'], ['start' => $entry['start'], 'ids' => $meter->ids()], max(60, $entry['start'] + $this->settings->meterPeriod() - time()));
            }
        }

        return $response;
    }

    /**
     * What this network address has read this period. The address itself is
     * never stored: only a keyed hash of it, as the cache key.
     *
     * @return array{key: string, start: int, ids: list<int>}
     */
    protected function byAddress(Request $request): array
    {
        $key = 'ernestdefoe-velvet-rope.ip.'.substr(hash_hmac('sha256', (string) $request->getAttribute('ipAddress'), $this->settings->secret()), 0, 32);
        $entry = $this->cache->get($key);
        $fresh = ! is_array($entry) || ! is_int($entry['start'] ?? null) || $entry['start'] + $this->settings->meterPeriod() <= time();

        return [
            'key' => $key,
            'start' => $fresh ? time() : $entry['start'],
            'ids' => $fresh ? [] : Settings::ids($entry['ids'] ?? []),
        ];
    }
}
