<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Post;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * Decides what a reader gets of a post. Every output goes through here, the
 * API, the server-rendered page, SEO structured data and notification email,
 * so they can't disagree.
 *
 * Decisions are made per discussion and remembered per actor object, so a
 * page of posts costs one decision, and nothing is decided at all for
 * requests that show no post content.
 */
class Gate
{
    /** @var WeakMap<User, array<int, array{rule: ?Rule, ruleGates: bool, meterApplies: bool, meterBlocked: bool, crawler: bool, audience: string, generic: bool}>> */
    private WeakMap $decisions;

    private ?bool $tagsEnabled = null;

    public function __construct(
        protected Settings $settings,
        protected Visitors $visitors,
        protected TagLookup $tags,
        protected ExtensionManager $extensions,
    ) {
        $this->decisions = new WeakMap();
    }

    public function forPost(User $actor, Post $post, ?ServerRequestInterface $request = null): Verdict
    {
        if ($actor->isAdmin() || (! $actor->isGuest() && $post->user_id !== null && (int) $post->user_id === (int) $actor->id)) {
            return Verdict::full();
        }

        $discussion = $post->relationLoaded('discussion') ? $post->getRelation('discussion') : null;
        $discussion = $discussion instanceof Discussion ? $discussion : null;
        $d = $this->decide($actor, (int) $post->discussion_id, $discussion);
        $number = (int) $post->number;

        // Reading a discussion's posts counts it, however they were asked
        // for: a scraper skipping the discussion page pays the same as a reader.
        if ($d['meterBlocked'] && $request !== null && self::reads($request)) {
            $meter = $this->visitors->for($actor)?->meter;

            if ($meter?->visit((int) $post->discussion_id)) {
                $this->forget($actor, (int) $post->discussion_id);
                $d = $this->decide($actor, (int) $post->discussion_id, $discussion);
            }
        }

        if ($d['crawler']) {
            return Verdict::full($this->verdict($d, $d['meterApplies'], $number)->gated());
        }

        $verdict = $this->verdict($d, $d['meterBlocked'], $number);

        if ($d['generic']) {
            return $verdict->gated() ? $verdict->generic() : $verdict;
        }

        // A guest reading on their allowance gets what a search engine gets.
        if (! $verdict->gated() && $d['meterApplies']) {
            return Verdict::full($this->verdict($d, true, $number)->gated());
        }

        return $verdict;
    }

    /**
     * Whether a guest out of free discussions would find anything gated in
     * this discussion: the page then carries paywall structured data.
     */
    public function gatesGuests(User $guest, int $discussionId, ?Discussion $discussion = null): bool
    {
        $d = $this->decide($guest, $discussionId, $discussion);

        return $d['meterApplies'] || $d['ruleGates'];
    }

    /** The meter, if this actor is a metered guest in this discussion. */
    public function meterFor(User $actor, int $discussionId, ?Discussion $discussion = null): ?Meter
    {
        $d = $this->decide($actor, $discussionId, $discussion);

        return $d['meterApplies'] && ! $d['crawler'] ? $this->visitors->for($actor)?->meter : null;
    }

    /** Count a guest opening a discussion against their allowance. */
    public function visit(User $actor, Discussion $discussion): void
    {
        $meter = $this->meterFor($actor, (int) $discussion->id, $discussion);

        if ($meter !== null && $meter->visit((int) $discussion->id)) {
            $this->forget($actor, (int) $discussion->id);
        }
    }

    /**
     * Whether a request reads a discussion, so counts it: opening it, or
     * loading its posts as its page does (by discussion, or by ids). Lists,
     * search and previews only show a counted discussion's posts, and the
     * lead-in of others, without spending the allowance.
     */
    public static function reads(ServerRequestInterface $request): bool
    {
        $route = $request->getAttribute('routeName');

        if ($route === 'discussions.show') {
            return true;
        }

        $filter = $request->getQueryParams()['filter'] ?? null;

        return $route === 'posts.index' && is_array($filter) && ! isset($filter['q'])
            && (isset($filter['discussion']) || isset($filter['id']));
    }

    /** Decide this discussion again. (A WeakMap entry can't be changed in place.) */
    private function forget(User $actor, int $discussionId): void
    {
        $decisions = $this->decisions[$actor] ?? [];
        unset($decisions[$discussionId]);
        $this->decisions[$actor] = $decisions;
    }

    /** @return array{rule: ?Rule, ruleGates: bool, meterApplies: bool, meterBlocked: bool, crawler: bool, audience: string, generic: bool} */
    private function decide(User $actor, int $discussionId, ?Discussion $discussion): array
    {
        if (isset($this->decisions[$actor][$discussionId])) {
            return $this->decisions[$actor][$discussionId];
        }

        $tagIds = $this->settings->needsTags() && $this->tagsEnabled() ? $this->tags->for($discussionId, $discussion) : [];
        $rule = $this->settings->ruleFor($tagIds);
        $ruleGates = $rule !== null && ! $actor->isAdmin() && array_intersect($rule->readers, $actor->permissionGroupIds()) === [];

        $meterApplies = $actor->isGuest()
            && $this->settings->meterEnabled()
            && array_intersect($this->settings->meterExemptTags(), $tagIds) === [];

        $visitor = $this->visitors->for($actor);
        $crawler = $actor->isGuest() && $visitor !== null && $this->settings->crawlers() && ($ruleGates || $meterApplies) && $visitor->isVerifiedCrawler();
        // A guest with no visit behind them (a reply rendered once for
        // everyone, to push live) gets what a guest out of allowance gets.
        $generic = $meterApplies && $visitor === null;
        $meterBlocked = $generic || ($meterApplies && ! $crawler && $visitor?->meter !== null && ! $visitor->meter->counted($discussionId));

        // Who the prompt speaks to: sign up, confirm your email, or join a group.
        $audience = $actor->isGuest() ? 'guest' : ($actor->is_email_confirmed ? 'member' : 'confirm');

        $decisions = $this->decisions[$actor] ?? [];
        $decisions[$discussionId] = compact('rule', 'ruleGates', 'meterApplies', 'meterBlocked', 'crawler', 'audience', 'generic');
        $this->decisions[$actor] = $decisions;

        return $decisions[$discussionId];
    }

    /** @param array{rule: ?Rule, ruleGates: bool, audience: string} $d */
    private function verdict(array $d, bool $meterBlocked, int $number): Verdict
    {
        $rule = $d['ruleGates'] ? $d['rule'] : null;
        $lock = $rule ? $rule->lock : [];
        $reason = $d['audience'];
        $upgradeUrl = $reason === 'member' ? $rule?->upgradeUrl : null;

        if ($meterBlocked) {
            $leadIn = $this->settings->meterLeadIn();

            return $number <= 1 && $leadIn > 0
                ? new Verdict(Verdict::LEAD, $lock, $leadIn, 'meter')
                : new Verdict(Verdict::HIDE, reason: 'meter');
        }

        if (! $rule) {
            return Verdict::full();
        }

        if ($number <= $rule->freePosts) {
            return $lock === [] ? Verdict::full() : new Verdict(Verdict::LOCK, $lock, 0, $reason, $upgradeUrl);
        }

        return $rule->preview > 0
            ? new Verdict(Verdict::LEAD, $lock, $rule->preview, $reason, $upgradeUrl)
            : new Verdict(Verdict::HIDE, reason: $reason, upgradeUrl: $upgradeUrl);
    }

    private function tagsEnabled(): bool
    {
        return $this->tagsEnabled ??= $this->extensions->isEnabled('flarum-tags');
    }
}
