<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate;

/**
 * A guest's free discussions for the current period, carried in a signed
 * cookie: when the period started and which discussions they opened.
 *
 * Reopening a discussion already counted is free. The signature stops a guest
 * listing discussions they never opened as "already read"; clearing the
 * cookie only starts a fresh allowance, the same as any metered site.
 */
final class Meter
{
    private bool $dirty = false;

    /** @param list<int> $ids */
    private function __construct(
        private int $start,
        private array $ids,
        private readonly int $limit,
        private readonly int $period,
    ) {
    }

    public static function fromCookie(?string $raw, string $secret, int $now, int $limit, int $period): self
    {
        $fresh = new self($now, [], $limit, $period);

        if ($raw === null || $secret === '' || strlen($raw) > 1200
            || ! preg_match('/^(\d{1,12})\.([\d-]{0,1100})\.([a-f0-9]{32})$/', $raw, $m)) {
            return $fresh;
        }

        if (! hash_equals(self::sign($m[1].'.'.$m[2], $secret), $m[3])) {
            return $fresh;
        }

        $start = (int) $m[1];

        // Expired, or a start in the future: a new period.
        if ($start > $now + 60 || $start + $period <= $now) {
            return $fresh;
        }

        $ids = Settings::ids($m[2] === '' ? [] : explode('-', $m[2]));

        return new self($start, array_slice($ids, 0, $limit), $limit, $period);
    }

    /** Whether reading this discussion is free: already counted, or allowance left. */
    public function allows(int $discussionId): bool
    {
        return in_array($discussionId, $this->ids, true) || count($this->ids) < $this->limit;
    }

    /** Count an opened discussion. False when the allowance has run out. */
    public function visit(int $discussionId): bool
    {
        if (in_array($discussionId, $this->ids, true)) {
            return true;
        }

        if (count($this->ids) >= $this->limit) {
            return false;
        }

        $this->ids[] = $discussionId;
        $this->dirty = true;

        return true;
    }

    public function counted(int $discussionId): bool
    {
        return in_array($discussionId, $this->ids, true);
    }

    public function remaining(): int
    {
        return max(0, $this->limit - count($this->ids));
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function dirty(): bool
    {
        return $this->dirty;
    }

    public function encode(string $secret): string
    {
        $payload = $this->start.'.'.implode('-', $this->ids);

        return $payload.'.'.self::sign($payload, $secret);
    }

    /** Seconds until the period ends. */
    public function lifetime(int $now): int
    {
        return max(60, $this->start + $this->period - $now);
    }

    private static function sign(string $payload, string $secret): string
    {
        return substr(hash_hmac('sha256', $payload, $secret), 0, 32);
    }
}
