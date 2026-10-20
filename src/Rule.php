<?php

/*
 * This file is part of ernestdefoe/velvet-rope.
 */

namespace ErnestDefoe\VelvetRope;

/**
 * One gating rule: in these tags (or everywhere), anyone outside these groups
 * reads the first few posts, with chosen media locked, and the rest as a
 * preview or not at all.
 */
final class Rule
{
    /**
     * @param list<int> $tags
     * @param list<int> $readers
     * @param list<string> $lock
     */
    public function __construct(
        public readonly array $tags,
        public readonly array $readers,
        public readonly int $freePosts,
        public readonly int $preview,
        public readonly array $lock,
        public readonly ?string $upgradeUrl,
    ) {
    }

    /** @param array<mixed> $rule */
    public static function fromArray(array $rule): self
    {
        $lock = array_values(array_intersect(Settings::KINDS, is_array($rule['lock'] ?? null) ? $rule['lock'] : []));

        return new self(
            Settings::ids($rule['tags'] ?? []),
            Settings::ids($rule['readers'] ?? []),
            Settings::clamp($rule['freePosts'] ?? 1, 0, 50, 1),
            Settings::clamp($rule['preview'] ?? 0, 0, 2000, 0),
            $lock,
            self::safeUrl($rule['upgradeUrl'] ?? null),
        );
    }

    /**
     * An http(s) URL or a path on this forum, or nothing. Control characters
     * are stripped first, so "\x01javascript:" can't slip past the scheme check.
     */
    public static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim((string) preg_replace('/[\x00-\x20\x7f]+/', '', $url));

        if ($url === '' || strlen($url) > 500) {
            return null;
        }

        if (preg_match('#^/(?![/\\\\])#', $url) || preg_match('#^https?://[^/\\\\]#i', $url)) {
            return $url;
        }

        return null;
    }
}
