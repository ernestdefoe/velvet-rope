<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate\Http;

use ErnestDefoe\GuestGate\Crawler;
use ErnestDefoe\GuestGate\Meter;
use ErnestDefoe\GuestGate\Settings;
use ErnestDefoe\GuestGate\TagLookup;
use ErnestDefoe\GuestGate\Visitor;
use ErnestDefoe\GuestGate\Visitors;
use Flarum\Http\CookieFactory;
use Flarum\Http\RequestUtil;
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
    public const COOKIE = 'guest_gate';

    public function __construct(
        protected Settings $settings,
        protected Visitors $visitors,
        protected Crawler $crawlers,
        protected CookieFactory $cookies,
        protected TagLookup $tags,
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
        }

        $this->visitors->begin($actor, new Visitor($request, $meter, $this->crawlers));

        $response = $handler->handle($request);

        if ($meter?->dirty()) {
            $cookie = $this->cookies->make(self::COOKIE, $meter->encode($this->settings->secret()), $meter->lifetime(time()));
            $response = $response->withAddedHeader('Set-Cookie', $cookie->__toString());
        }

        return $response;
    }
}
