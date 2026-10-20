<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate;

/**
 * What one reader gets of one post.
 *
 * - full: the post as written. `wall` marks it as content a guest pays for
 *   with their allowance, so search engines can tell it from cloaking.
 * - lock: the post, with the chosen kinds of media replaced by a prompt.
 * - lead: the first `chars` characters, then a prompt.
 * - hide: nothing but a blurred placeholder and a prompt.
 *
 * `generic` marks a verdict made for a guest with no visit behind it, such
 * as a reply pushed live to everyone: it assumes the allowance is used up.
 *
 * `reason` says which prompt: "meter" (free discussions used up), "guest"
 * (sign up to read) or "member" (a group this reader isn't in).
 */
final class Verdict
{
    public const FULL = 'full';

    public const LOCK = 'lock';

    public const LEAD = 'lead';

    public const HIDE = 'hide';

    /** @param list<string> $lock */
    public function __construct(
        public readonly string $mode,
        public readonly array $lock = [],
        public readonly int $chars = 0,
        public readonly ?string $reason = null,
        public readonly ?string $upgradeUrl = null,
        public readonly bool $wall = false,
        public readonly bool $generic = false,
    ) {
    }

    /** The same verdict, marked as made for no visitor in particular. */
    public function generic(): self
    {
        return new self($this->mode, $this->lock, $this->chars, $this->reason, $this->upgradeUrl, $this->wall, true);
    }

    public static function full(bool $wall = false): self
    {
        return new self(self::FULL, wall: $wall);
    }

    public function gated(): bool
    {
        return $this->mode !== self::FULL;
    }

    /** @return array{mode: string, reason: string|null, upgradeUrl?: string, generic?: true} */
    public function toArray(): array
    {
        $data = ['mode' => $this->mode, 'reason' => $this->reason];

        if ($this->upgradeUrl !== null) {
            $data['upgradeUrl'] = $this->upgradeUrl;
        }

        // Rendered for no one in particular (a realtime push): the page
        // fetches it again as its reader.
        if ($this->generic) {
            $data['generic'] = true;
        }

        return $data;
    }
}
