<?php

namespace ErnestDefoe\GuestGate\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;

/**
 * With FoF SEO: its structured data carries the post text, rendered with no
 * request. It must hold only what the visitor may read, and the paywall
 * properties go onto FoF SEO's own node.
 */
class FofSeoTest extends GateTestCase
{
    protected array $enable = ['flarum-tags', 'flarum-markdown', 'fof-seo', 'ernestdefoe-guest-gate'];

    protected function setUp(): void
    {
        parent::setUp();

        // Replies as schema.org Comment nodes, the case that renders them all.
        $this->setting('seo_post_crawler', '1');
    }

    /** @return list<array<mixed>> */
    protected function jsonLd(string $page): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $page, $m);

        $nodes = [];
        foreach ($m[1] as $json) {
            $doc = json_decode(html_entity_decode($json), true) ?? [];
            $nodes = array_merge($nodes, $doc['@graph'] ?? [$doc]);
        }

        return $nodes;
    }

    #[Test]
    public function a_guest_s_page_holds_no_gated_text_and_one_paywall_node()
    {
        $page = (string) $this->get('/d/1')->getBody();

        $this->assertStringNotContainsString(self::SECRET, $page);
        $this->assertStringContainsString('Opening words', $page, 'the free first post is still there');

        $nodes = $this->jsonLd($page);
        $marked = array_values(array_filter($nodes, fn ($n) => ($n['isAccessibleForFree'] ?? null) === false));
        $this->assertCount(1, $marked, json_encode($nodes));
        $this->assertSame('.GuestGate-paywalled', $marked[0]['hasPart']['cssSelector']);
        $this->assertNotSame('WebPage', $marked[0]['@type'], 'on FoF SEO\'s node, not a second one of ours');
    }

    #[Test]
    public function a_member_s_page_has_everything_and_no_paywall()
    {
        $page = (string) $this->get('/d/1', self::MEMBER)->getBody();

        $this->assertStringContainsString(self::SECRET, $page);
        $this->assertStringNotContainsString('isAccessibleForFree', $page);
    }

    #[Test]
    public function a_premium_first_post_stays_out_of_the_description_and_schema()
    {
        $this->rules([['tags' => [2], 'readers' => [self::VIP_GROUP], 'freePosts' => 0]]);
        $this->database()->table('posts')->where('id', 21)->update(['content' => '<t>The '.self::SECRET.' recipe in full.</t>']);

        $page = (string) $this->get('/d/2')->getBody();
        $this->assertStringNotContainsString(self::SECRET, $page);
        $this->assertStringContainsString(self::SECRET, (string) $this->get('/d/2', self::VIP)->getBody());
    }
}
