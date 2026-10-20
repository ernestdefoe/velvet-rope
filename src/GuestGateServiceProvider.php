<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Post\Post;

class GuestGateServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // One of each per request: they hold that request's decisions.
        foreach ([Settings::class, Visitors::class, TagLookup::class, Gate::class, Crawler::class] as $class) {
            $this->container->singleton($class);
        }
    }

    public function boot(TagLookup $tags): void
    {
        // Posts note their discussion as they load, so a rule that names tags
        // looks up every discussion of a response in one query (TagLookup).
        Post::retrieved(fn (Post $post) => $tags->note((int) $post->discussion_id));
    }
}
