<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate;

use Flarum\Discussion\Discussion;
use Illuminate\Database\ConnectionInterface;

/**
 * The tag ids of discussions, for rules and meter exemptions that name tags.
 *
 * Posts note their discussion as they are loaded, so the first question
 * answers every discussion of the response in one query instead of one each.
 */
class TagLookup
{
    /** @var array<int, list<int>> */
    private array $tags = [];

    /** @var array<int, true> */
    private array $pending = [];

    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    public function note(int $discussionId): void
    {
        if (! isset($this->tags[$discussionId]) && count($this->pending) < 1000) {
            $this->pending[$discussionId] = true;
        }
    }

    /** @return list<int> */
    public function for(int $discussionId, ?Discussion $discussion = null): array
    {
        if (isset($this->tags[$discussionId])) {
            return $this->tags[$discussionId];
        }

        if ($discussion?->relationLoaded('tags')) {
            return $this->tags[$discussionId] = array_values(array_map('intval', $discussion->getRelation('tags')->pluck('id')->all()));
        }

        $ids = array_keys($this->pending + [$discussionId => true]);
        $this->pending = [];

        foreach ($ids as $id) {
            $this->tags[$id] = [];
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $this->db->table('discussion_tag')->whereIn('discussion_id', $chunk)->get(['discussion_id', 'tag_id']);

            foreach ($rows as $row) {
                $this->tags[(int) $row->discussion_id][] = (int) $row->tag_id;
            }
        }

        return $this->tags[$discussionId];
    }

    /** Forget everything: a discussion's tags changed. */
    public function flush(): void
    {
        $this->tags = [];
        $this->pending = [];
    }
}
