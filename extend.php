<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 *
 * Turn readers into members: a set number of free discussions for guests,
 * then a lead-in and a sign-up prompt; replies, images, links, files and
 * embeds held back for guests or chosen groups, per tag; and paywall
 * structured data so search engines can tell it from cloaking.
 */

use ErnestDefoe\GuestGate\Api\DiscussionFields;
use ErnestDefoe\GuestGate\Api\PostFields;
use ErnestDefoe\GuestGate\Formatter\ConfigureTags;
use ErnestDefoe\GuestGate\Formatter\GateContent;
use ErnestDefoe\GuestGate\GuestGateServiceProvider;
use ErnestDefoe\GuestGate\Http\TrackVisitor;
use ErnestDefoe\GuestGate\Seo\AddToFofSeo;
use ErnestDefoe\GuestGate\Seo\PaywallMarkup;
use Flarum\Api\Endpoint\Show;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Extend;
use FoF\Seo\Event\PreparingPageMeta;

$prefix = 'ernestdefoe-guest-gate.';

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->content(PaywallMarkup::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\ServiceProvider())
        ->register(GuestGateServiceProvider::class),

    (new Extend\Middleware('forum'))->add(TrackVisitor::class),
    (new Extend\Middleware('api'))->add(TrackVisitor::class),

    (new Extend\Formatter())
        ->configure(ConfigureTags::class)
        ->render(GateContent::class),

    (new Extend\ApiResource(PostResource::class))
        ->fields(PostFields::class),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(DiscussionFields::class)
        ->endpoint(Show::class, fn (Show $endpoint) => resolve(DiscussionFields::class)->show($endpoint)),

    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-seo', fn () => [
            (new Extend\Event())->listen(PreparingPageMeta::class, AddToFofSeo::class),
        ]),

    (new Extend\Settings())
        ->default($prefix.'meter_enabled', false)
        ->default($prefix.'meter_limit', 5)
        ->default($prefix.'meter_period', 'month')
        ->default($prefix.'meter_warn', 2)
        ->default($prefix.'meter_lead_in', 280)
        ->default($prefix.'meter_exempt_tags', '[]')
        ->default($prefix.'meter_by_ip', false)
        ->default($prefix.'crawlers', true)
        // Out of the box: guests read each discussion's first post, and a
        // prompt in place of the replies.
        ->default($prefix.'rules', '[{"tags":[],"readers":[3],"freePosts":1,"preview":0,"lock":[]}]'),
];
