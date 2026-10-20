<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate;

use Flarum\User\User;
use WeakMap;

/**
 * The visitor of each request, keyed on its actor object. Flarum hands a
 * page's internal API calls the same actor object, so they share the page's
 * meter; a `new Guest()` made elsewhere (jobs, sitemaps) shares nothing.
 */
class Visitors
{
    /** @var WeakMap<User, Visitor> */
    private WeakMap $visitors;

    /** @var \WeakReference<User>|null */
    private ?\WeakReference $current = null;

    public function __construct()
    {
        $this->visitors = new WeakMap();
    }

    public function begin(User $actor, Visitor $visitor): void
    {
        $this->visitors[$actor] = $visitor;
        $this->current = \WeakReference::create($actor);
    }

    public function for(User $actor): ?Visitor
    {
        return $this->visitors[$actor] ?? null;
    }

    /**
     * The visitor of the request being handled, for content rendered without
     * one (an SEO extension's structured data, for instance).
     *
     * @return array{0: User, 1: Visitor}|null
     */
    public function current(): ?array
    {
        $actor = $this->current?->get();
        $visitor = $actor ? $this->for($actor) : null;

        return $actor && $visitor ? [$actor, $visitor] : null;
    }
}
