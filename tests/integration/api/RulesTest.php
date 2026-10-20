<?php

namespace ErnestDefoe\GuestGate\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;

/**
 * Rules: who reads what, per tag and group, and what everyone else gets.
 */
class RulesTest extends GateTestCase
{
    #[Test]
    public function by_default_guests_read_the_first_post_and_no_reply()
    {
        $posts = $this->read(1);
        $this->assertStringContainsString('Opening words', $posts[1]['contentHtml']);
        $this->assertArrayNotHasKey('guestGate', $posts[1]);

        foreach ([2, 3] as $n) {
            $this->assertStringNotContainsString(self::SECRET, $posts[$n]['contentHtml']);
            $this->assertStringContainsString('GuestGate-veil', $posts[$n]['contentHtml']);
            $this->assertSame(['mode' => 'hide', 'reason' => 'guest'], $posts[$n]['guestGate']);
        }

        $this->assertStringNotContainsString(self::SECRET, json_encode($posts));
    }

    #[Test]
    public function members_read_everything_and_carry_nothing_extra()
    {
        $body = $this->readAll(1, self::MEMBER);

        $this->assertSame(2, substr_count($body, self::SECRET));
        $this->assertStringNotContainsString('guestGate', $body);
        $this->assertStringNotContainsString('GuestGate', $body);
    }

    #[Test]
    public function an_unconfirmed_account_is_asked_to_confirm_its_email()
    {
        $posts = $this->read(1, self::UNCONFIRMED);

        $this->assertSame('confirm', $posts[2]['guestGate']['reason']);
        $this->assertStringNotContainsString(self::SECRET, $posts[2]['contentHtml']);
    }

    #[Test]
    public function a_premium_tag_is_for_its_group_only_with_an_upgrade_link()
    {
        $this->rules([
            ['tags' => [2], 'readers' => [self::VIP_GROUP], 'freePosts' => 1, 'upgradeUrl' => '/store'],
            ['tags' => [], 'readers' => [3], 'freePosts' => 1],
        ]);

        $member = $this->read(2, self::MEMBER);
        $this->assertSame(['mode' => 'hide', 'reason' => 'member', 'upgradeUrl' => '/store'], $member[3]['guestGate']);
        $this->assertStringNotContainsString(self::SECRET, $member[3]['contentHtml']);
        $this->assertArrayNotHasKey('guestGate', $member[2], 'their own reply');

        $this->assertSame(2, substr_count($this->readAll(2, self::VIP), self::SECRET), 'the group reads it all');

        // The member still reads the other tags in full.
        $this->assertSame(2, substr_count($this->readAll(1, self::MEMBER), self::SECRET));

        // A guest is asked to sign up, never shown the members' upgrade link.
        $guest = $this->read(2);
        $this->assertSame(['mode' => 'hide', 'reason' => 'guest'], $guest[3]['guestGate']);
    }

    #[Test]
    public function an_author_always_reads_their_own_post()
    {
        $this->rules([['tags' => [], 'readers' => [self::VIP_GROUP], 'freePosts' => 0]]);

        $posts = $this->read(1, self::MEMBER);
        $this->assertArrayNotHasKey('guestGate', $posts[2], 'MEMBER wrote post 2');
        $this->assertStringContainsString(self::SECRET, $posts[2]['contentHtml']);
        $this->assertSame('hide', $posts[3]['guestGate']['mode']);
        $this->assertSame('hide', $posts[1]['guestGate']['mode'], 'no free posts');
    }

    #[Test]
    public function a_preview_shows_the_start_of_each_reply()
    {
        $this->rules([['tags' => [], 'readers' => [3], 'freePosts' => 1, 'preview' => 12]]);

        $posts = $this->read(1);
        $this->assertSame('lead', $posts[2]['guestGate']['mode']);
        $this->assertStringContainsString('A reply that', $posts[2]['contentHtml']);
        $this->assertStringNotContainsString(self::SECRET, $posts[2]['contentHtml']);
        $this->assertStringContainsString('GuestGate-more', $posts[2]['contentHtml']);
        // Cut inside markup: the HTML stays well formed and the bold word is gone.
        $this->assertStringNotContainsString(self::SECRET, $posts[3]['contentHtml']);
    }

    #[Test]
    public function chosen_media_is_locked_in_posts_guests_can_read()
    {
        $this->rules([['tags' => [], 'readers' => [3], 'freePosts' => 1, 'lock' => ['image', 'link', 'embed']]]);
        $this->flushFormatter();

        $html = $this->read(3)[1]['contentHtml'];

        $this->assertStringNotContainsString('cdn.example.com', $html);
        $this->assertStringNotContainsString(self::SECRET, $html, 'a bare address is dropped with its link');
        $this->assertStringNotContainsString('example.com/x', $html);
        $this->assertStringContainsString('the docs', $html, 'link words stay');
        $this->assertStringNotContainsString('youtu', $html);
        $this->assertStringContainsString('href="/d/1"', $html, 'links within the forum stay');
        $this->assertSame(1, substr_count($html, 'data-kind="image"'));
        $this->assertSame(2, substr_count($html, 'data-kind="link"'));
        $this->assertSame(1, substr_count($html, 'data-kind="embed"'));

        $member = $this->read(3, self::MEMBER)[1]['contentHtml'];
        $this->assertStringContainsString('<img src="https://cdn.example.com/a.png"', $member);
        $this->assertStringContainsString('https://example.com/'.self::SECRET, $member);
    }

    #[Test]
    public function a_rule_open_to_guests_gates_nothing()
    {
        $this->rules([['tags' => [], 'readers' => [2]]]);

        $this->assertSame(2, substr_count($this->readAll(1), self::SECRET));
    }

    #[Test]
    public function tags_outside_every_rule_are_not_gated()
    {
        $this->rules([['tags' => [2], 'readers' => [3]]]);

        $this->assertSame(2, substr_count($this->readAll(1), self::SECRET));
        $this->assertSame(0, substr_count($this->readAll(2), self::SECRET));
    }

    #[Test]
    public function every_way_of_reading_posts_is_gated()
    {
        // The posts list (scrolling a discussion), one post (a mention preview).
        $list = (string) $this->get('/api/posts', null, ['filter' => ['discussion' => 1]])->getBody();
        $this->assertStringContainsString('Opening words', $list);
        $this->assertStringNotContainsString(self::SECRET, $list);

        $one = (string) $this->get('/api/posts/12')->getBody();
        $this->assertStringNotContainsString(self::SECRET, $one);
        $this->assertStringContainsString('guestGate', $one);

        // Search results carry the most relevant post.
        $search = (string) $this->get('/api/discussions', null, ['filter' => ['q' => self::SECRET], 'include' => 'mostRelevantPost'])->getBody();
        $this->assertStringNotContainsString('says '.self::SECRET, $search);

        // The page itself, as a browser without JavaScript or a crawler gets it.
        $page = (string) $this->get('/d/1')->getBody();
        $this->assertStringContainsString('Opening words', $page);
        $this->assertStringNotContainsString(self::SECRET, $page);
    }

    #[Test]
    public function rules_garbage_in_the_database_fails_safe()
    {
        $this->setting(self::P.'rules', '{"not":"a list"');

        $this->assertSame(2, substr_count($this->readAll(1), self::SECRET), 'no valid rule: nothing gated');
    }
}
