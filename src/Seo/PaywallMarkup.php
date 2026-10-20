<?php

/*
 * This file is part of ernestdefoe/velvet-rope.
 */

namespace ErnestDefoe\VelvetRope\Seo;

use ErnestDefoe\VelvetRope\Gate;
use Flarum\Extension\ExtensionManager;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Paywall structured data on a discussion page whose content is gated for
 * guests, as Google asks of metered and lead-in content: the page says it
 * isn't free, and `.VelvetRope-paywalled` marks the parts that a search engine
 * (or a guest with free discussions left) sees and others don't. With that
 * markup the difference is a paywall, not cloaking.
 *
 * With FoF SEO enabled the properties go onto its DiscussionForumPosting
 * instead (AddToFofSeo), so the page has one description of itself.
 */
class PaywallMarkup
{
    public const SELECTOR = '.VelvetRope-paywalled';

    public function __construct(
        protected Gate $gate,
        protected ExtensionManager $extensions,
    ) {
    }

    public function __invoke(Document $document, Request $request): void
    {
        $data = $document->payload['apiDocument']->data ?? null;

        if (! is_object($data) || ($data->type ?? null) !== 'discussions' || $this->extensions->isEnabled('fof-seo')) {
            return;
        }

        if (! $this->applies((int) $data->id, $request)) {
            return;
        }

        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
        ] + self::properties(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        $document->head[] = '<script type="application/ld+json">'.$json.'</script>';
    }

    /** Whether this page's discussion is gated for a guest who has used up their allowance. */
    public function applies(int $discussionId, Request $request): bool
    {
        // Only guests' pages are crawled; members' pages say nothing about it.
        $actor = RequestUtil::getActor($request);

        return $actor->isGuest() && $this->gate->gatesGuests($actor, $discussionId);
    }

    /** @return array{isAccessibleForFree: false, hasPart: array{'@type': string, isAccessibleForFree: false, cssSelector: string}} */
    public static function properties(): array
    {
        return [
            'isAccessibleForFree' => false,
            'hasPart' => [
                '@type' => 'WebPageElement',
                'isAccessibleForFree' => false,
                'cssSelector' => self::SELECTOR,
            ],
        ];
    }
}
