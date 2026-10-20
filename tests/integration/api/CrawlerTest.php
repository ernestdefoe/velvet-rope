<?php

namespace ErnestDefoe\VelvetRope\Tests\integration\api;

use ErnestDefoe\VelvetRope\Crawler;
use PHPUnit\Framework\Attributes\Test;

/**
 * Search engines: proved by DNS, not by user agent, and given gated content
 * only inside paywall markup.
 */
class CrawlerTest extends GateTestCase
{
    protected const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /** @var list<string> */
    protected array $lookups = [];

    protected function dns(string $host, array $forward): void
    {
        $this->app()->getContainer()->make(Crawler::class)->resolveWith(
            function (string $ip) use ($host) {
                $this->lookups[] = $ip;

                return $host;
            },
            fn (string $name) => $name === $host ? $forward : [],
        );
    }

    #[Test]
    public function a_verified_search_engine_reads_gated_content_marked_as_paywalled()
    {
        $this->dns('crawl-66-249-66-1.googlebot.com', ['127.0.0.1']);

        $posts = $this->read(1, null, ['User-Agent' => self::GOOGLEBOT]);
        $this->assertStringContainsString(self::SECRET, $posts[2]['contentHtml']);
        $this->assertStringContainsString('<div class="VelvetRope-paywalled">', $posts[2]['contentHtml']);
        $this->assertArrayNotHasKey('velvetRope', $posts[2]);
        $this->assertStringNotContainsString('VelvetRope-paywalled', $posts[1]['contentHtml'], 'the free first post is not marked');

        $page = (string) $this->get('/d/1', null, [], ['User-Agent' => self::GOOGLEBOT])->getBody();
        $this->assertStringContainsString(self::SECRET, $page);
        $this->assertStringContainsString('"isAccessibleForFree":false', $page);
        $this->assertStringContainsString('"cssSelector":".VelvetRope-paywalled"', $page);

        $this->assertSame(1, count(array_unique($this->lookups)), 'one address, looked up once and cached');
        $this->assertLessThanOrEqual(1, count($this->lookups));
    }

    #[Test]
    public function a_user_agent_alone_proves_nothing()
    {
        // Reverse DNS says Google, forward DNS doesn't agree.
        $this->dns('crawl-66-249-66-1.googlebot.com', ['66.249.66.1']);
        $this->assertStringNotContainsString(self::SECRET, $this->readAll(1, null));
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->read(1, null, ['User-Agent' => self::GOOGLEBOT])));

        // A residential name that does resolve back, but isn't Google's.
        $this->app()->getContainer()->make('cache.store')->flush();
        $this->dns('host-1.example-isp.net', ['127.0.0.1']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->read(1, null, ['User-Agent' => self::GOOGLEBOT])));
    }

    #[Test]
    public function with_search_engines_turned_off_they_get_what_guests_get()
    {
        $this->setting(self::P.'crawlers', '0');
        $this->dns('crawl-66-249-66-1.googlebot.com', ['127.0.0.1']);

        $this->assertStringNotContainsString(self::SECRET, json_encode($this->read(1, null, ['User-Agent' => self::GOOGLEBOT])));
        $this->assertSame([], $this->lookups, 'no DNS lookups at all');
    }

    #[Test]
    public function the_markup_is_the_same_for_guests_and_absent_for_members()
    {
        $guest = (string) $this->get('/d/1')->getBody();
        $this->assertStringContainsString('"isAccessibleForFree":false', $guest);
        $this->assertStringNotContainsString(self::SECRET, $guest);

        $this->assertStringNotContainsString('isAccessibleForFree', (string) $this->get('/d/1', self::MEMBER)->getBody());
    }

    #[Test]
    public function an_ungated_discussion_has_no_paywall_markup()
    {
        $this->rules([['tags' => [2], 'readers' => [3]]]);

        $this->assertStringNotContainsString('isAccessibleForFree', (string) $this->get('/d/1')->getBody());
        $this->assertStringContainsString('isAccessibleForFree', (string) $this->get('/d/2')->getBody());
    }

    #[Test]
    public function a_guest_reading_on_their_allowance_gets_the_crawler_s_page()
    {
        $this->rules([]);
        $this->meter(1);

        $posts = $this->read(1);
        $this->assertStringContainsString('<div class="VelvetRope-paywalled">', $posts[2]['contentHtml']);
        $this->assertStringContainsString(self::SECRET, $posts[2]['contentHtml']);
        $this->assertStringContainsString('"isAccessibleForFree":false', (string) $this->get('/d/1')->getBody());
    }
}
