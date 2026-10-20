<?php

namespace ErnestDefoe\GuestGate\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;

/**
 * The meter: a guest's free discussions per period, then a lead-in.
 * No rule here, so only the meter gates.
 */
class MeterTest extends GateTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->rules([]);
    }

    #[Test]
    public function a_guest_reads_their_allowance_then_gets_a_lead_in()
    {
        $this->meter(2, ['meter_lead_in' => '20']);

        $this->assertSame(2, substr_count($this->readAll(1), self::SECRET));
        $this->assertSame(2, substr_count($this->readAll(2), self::SECRET));

        $posts = $this->read(4);
        $this->assertSame(['mode' => 'lead', 'reason' => 'meter'], $posts[1]['guestGate']);
        $this->assertStringContainsString('Opening words', $posts[1]['contentHtml']);
        $this->assertLessThan(120, strlen($posts[1]['contentHtml']), 'only the lead-in');
        $this->assertSame(['mode' => 'hide', 'reason' => 'meter'], $posts[2]['guestGate']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($posts));

        // Going back to a discussion already counted is free.
        $this->assertSame(2, substr_count($this->readAll(1), self::SECRET));
    }

    #[Test]
    public function the_discussion_reports_what_is_left()
    {
        $this->meter(2, ['meter_warn' => '1', 'meter_period' => 'week']);

        $first = $this->json($this->show(1))['data']['attributes']['guestGateMeter'];
        $this->assertSame(['limit' => 2, 'remaining' => 1, 'counted' => true, 'warn' => 1, 'period' => 'week'], $first);

        $this->show(2);
        $third = $this->json($this->show(4))['data']['attributes']['guestGateMeter'];
        $this->assertSame(0, $third['remaining']);
        $this->assertFalse($third['counted']);

        // Lists carry nothing; members carry nothing.
        $this->assertStringNotContainsString('guestGateMeter', (string) $this->get('/api/discussions')->getBody());
        $this->assertStringNotContainsString('guestGateMeter', (string) $this->show(1, self::MEMBER)->getBody());
    }

    #[Test]
    public function the_page_itself_counts_once_and_sets_the_cookie()
    {
        $this->meter(1);

        $page = $this->get('/d/1');
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString(self::SECRET, (string) $page->getBody());
        $this->assertNotSame([], array_filter(array_keys($this->jar), fn ($name) => str_ends_with($name, 'guest_gate')));

        // Its own data, loaded by the page script, is the same discussion: free.
        $this->assertSame(2, substr_count($this->readAll(1), self::SECRET));

        $other = (string) $this->get('/d/2')->getBody();
        $this->assertStringNotContainsString(self::SECRET, $other);
        $this->assertStringContainsString('GuestGate-veil', $other);
    }

    #[Test]
    public function a_forged_or_tampered_cookie_buys_nothing()
    {
        $this->meter(1);

        $this->readAll(1);
        $name = array_key_first(array_filter($this->jar, fn ($v, $k) => str_ends_with($k, 'guest_gate'), ARRAY_FILTER_USE_BOTH));
        [$start, $ids, $sig] = explode('.', $this->jar[$name]);

        // Claim discussions 2 and 4 were read too, keeping the old signature,
        // or signing with a guessed key: either way the cookie is thrown
        // away, and a fresh allowance of 1 is all it buys.
        foreach (["$start.$ids-2-4.$sig", "$start.2-4.".substr(hash_hmac('sha256', "$start.2-4", 'guess'), 0, 32)] as $forged) {
            $this->jar[$name] = $forged;
            $this->assertSame(2, substr_count($this->readAll(2), self::SECRET), 'the fresh allowance');
            $this->assertStringNotContainsString(self::SECRET, $this->readAll(4), 'and no more');
        }
    }

    #[Test]
    public function an_expired_period_starts_a_new_allowance()
    {
        $this->meter(1, ['meter_period' => 'day']);

        $old = (string) (time() - 90000);
        $this->jar = ['flarum_guest_gate' => "$old.1.".substr(hash_hmac('sha256', "$old.1", str_repeat('k', 64)), 0, 32)];
        $this->jarFor = null;

        $this->assertSame(2, substr_count($this->readAll(2), self::SECRET));
    }

    #[Test]
    public function exempt_tags_are_never_counted()
    {
        $this->meter(1, ['meter_exempt_tags' => '[2]']);

        $this->readAll(2);
        $this->assertSame(2, substr_count($this->readAll(1), self::SECRET), 'Premium did not use the allowance');
        $this->assertStringNotContainsString(self::SECRET, $this->readAll(4));
        $this->assertSame(2, substr_count($this->readAll(2), self::SECRET), 'Premium is always free');
    }

    #[Test]
    public function members_are_never_metered()
    {
        $this->meter(1);

        foreach ([1, 2, 4] as $id) {
            $this->assertSame(2, substr_count($this->readAll($id, self::MEMBER), self::SECRET));
        }
    }

    #[Test]
    public function the_meter_and_a_rule_together()
    {
        // Guests read every reply while their allowance lasts... no: the rule
        // still holds replies back. The meter decides the first post.
        $this->meter(1, ['meter_lead_in' => '10']);
        $this->rules([['tags' => [], 'readers' => [3], 'freePosts' => 1]]);

        $within = $this->read(1);
        $this->assertArrayNotHasKey('guestGate', $within[1]);
        $this->assertSame('hide', $within[2]['guestGate']['mode']);

        $after = $this->read(2);
        $this->assertSame(['mode' => 'lead', 'reason' => 'meter'], $after[1]['guestGate']);
    }
}
