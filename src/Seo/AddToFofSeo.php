<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate\Seo;

/**
 * FoF SEO's PreparingPageMeta: put the paywall properties on the schema.org
 * node FoF SEO writes for the discussion (DiscussionForumPosting or QAPage),
 * rather than a second node of our own.
 *
 * The post text FoF SEO puts into that node is rendered through the gate
 * already (GateContent), so it holds what the visitor may read.
 */
class AddToFofSeo
{
    public function __construct(
        protected PaywallMarkup $markup
    ) {
    }

    /** @param \FoF\Seo\Event\PreparingPageMeta $event */
    public function handle(object $event): void
    {
        $data = $event->document->payload['apiDocument']->data ?? null;

        if (! is_object($data) || ($data->type ?? null) !== 'discussions') {
            return;
        }

        if ($this->markup->applies((int) $data->id, $event->request)) {
            foreach (PaywallMarkup::properties() as $key => $value) {
                $event->properties->setSchemaJson($key, $value);
            }
        }
    }
}
