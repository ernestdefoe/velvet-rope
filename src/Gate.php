<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Post;
use Flarum\User\User;
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
    /** @var WeakMap<User, array<int, array{rule: ?Rule, ruleGates: bool, meterApplies: bool, meterBlocked: bool, crawler: bool, audience: string}>> */
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

    public function forPost(User $actor, Post $post): Verdict
    {
        if ($actor->isAdmin() || (! $actor->isGuest() && $post->user_id !== null && (int) $post->user_id === (int) $actor->id)) {
            return Verdict::full();
        }

        $discussion = $post->relationLoaded('discussion') ? $post->getRelation('discussion') : null;
        $d = $this->decide($actor, (int) $post->discussion_id, $discussion instanceof Discussion ? $discussion : null);
        $number = (int) $post->number;

        if ($d['crawler']) {
            return Verdict::full($this->verdict($d, $d['meterApplies'], $number)->gated());
        }

        $verdict = $this->verdict($d, $d['meterBlocked'], $number);

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

        if ($meter !== null) {
            $meter->visit((int) $discussion->id);

            // Decide this discussion again, now it has been counted. (A WeakMap
            // entry can't be changed in place: copy, change, put back.)
            $decisions = $this->decisions[$actor] ?? [];
            unset($decisions[(int) $discussion->id]);
            $this->decisions[$actor] = $decisions;
        }
    }

    /** @return array{rule: ?Rule, ruleGates: bool, meterApplies: bool, meterBlocked: bool, crawler: bool, audience: string} */
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
        $meterBlocked = $meterApplies && ! $crawler && $visitor?->meter !== null && ! $visitor->meter->allows($discussionId);

        // Who the prompt speaks to: sign up, confirm your email, or join a group.
        $audience = $actor->isGuest() ? 'guest' : ($actor->is_email_confirmed ? 'member' : 'confirm');

        $decisions = $this->decisions[$actor] ?? [];
        $decisions[$discussionId] = compact('rule', 'ruleGates', 'meterApplies', 'meterBlocked', 'crawler', 'audience');
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
