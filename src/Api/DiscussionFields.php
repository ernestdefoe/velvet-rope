<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate\Api;

use ErnestDefoe\GuestGate\Gate;
use ErnestDefoe\GuestGate\Settings;
use Flarum\Api\Context;
use Flarum\Api\Endpoint\Show;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;

/**
 * Opening a discussion counts it against a metered guest's allowance, before
 * its posts are serialised, and the response tells the page how many are left.
 * Only on Show, and only for metered guests: lists carry nothing.
 */
class DiscussionFields
{
    public function __construct(
        protected Gate $gate,
        protected Settings $settings,
    ) {
    }

    public function show(Show $endpoint): Show
    {
        return $endpoint->after(function (Context $context, mixed $discussion) {
            if ($discussion instanceof Discussion && $context->getActor()->isGuest()) {
                $this->gate->visit($context->getActor(), $discussion);
            }

            return $discussion;
        });
    }

    /** @return array<Schema\Arr> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('guestGateMeter')
                ->visible(fn (Discussion $discussion, Context $context) => $context->showing('discussions')
                    && $context->getActor()->isGuest()
                    && $this->gate->meterFor($context->getActor(), (int) $discussion->id, $discussion) !== null)
                ->get(function (Discussion $discussion, Context $context) {
                    $meter = $this->gate->meterFor($context->getActor(), (int) $discussion->id, $discussion);

                    return [
                        'limit' => $meter?->limit() ?? 0,
                        'remaining' => $meter?->remaining() ?? 0,
                        'counted' => (bool) $meter?->counted((int) $discussion->id),
                        'warn' => $this->settings->meterWarn(),
                        'period' => array_search($this->settings->meterPeriod(), Settings::PERIODS, true) ?: 'month',
                    ];
                }),
        ];
    }
}
