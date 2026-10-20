<?php

namespace ErnestDefoe\GuestGate\Tests\integration\api;

use Carbon\Carbon;
use Dflydev\FigCookies\SetCookie;
use Flarum\Formatter\Formatter;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Four discussions by AUTHOR, each with a first post and two replies:
 *   1 "General" — tag 1 (General)
 *   2 "Premium" — tag 2 (Premium)
 *   3 "Media"   — tag 1, and a first post full of media
 *   4 "Spare"   — tag 1
 * Members: AUTHOR (2), MEMBER (3), VIP (4, in group 5 "VIP"), UNCONFIRMED (5).
 */
abstract class GateTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected const AUTHOR = 2;
    protected const MEMBER = 3;
    protected const VIP = 4;
    protected const UNCONFIRMED = 5;
    protected const VIP_GROUP = 5;

    protected const P = 'ernestdefoe-guest-gate.';

    /** Words only a reader with access may ever receive. */
    protected const SECRET = 'Zanzibar';

    protected array $enable = ['flarum-tags', 'flarum-markdown', 'ernestdefoe-guest-gate'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension(...$this->enable);

        $users = [$this->normalUser()];
        foreach ([self::MEMBER => 'member', self::VIP => 'vip', self::UNCONFIRMED => 'unconfirmed'] as $id => $name) {
            // The same hash as normalUser(): the password is "too-obscure".
            $users[] = ['id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'password' => $this->normalUser()['password'], 'is_email_confirmed' => (int) ($id !== self::UNCONFIRMED)];
        }

        $now = Carbon::now();
        $discussions = $posts = $tags = [];
        $media = '<r><p>Look <IMG alt="pic" src="https://cdn.example.com/a.png"><s>![</s>pic<e>](https://cdn.example.com/a.png)</e></IMG> and '
            .'<URL url="https://example.com/'.self::SECRET.'">https://example.com/'.self::SECRET.'</URL> and '
            .'<URL url="https://example.com/x"><s>[</s>the docs<e>](https://example.com/x)</e></URL> and '
            .'<URL url="/d/1"><s>[</s>our thread<e>](/d/1)</e></URL></p>'
            .'<YOUTUBE id="abc" url="https://youtu.be/abc">https://youtu.be/abc</YOUTUBE></r>';

        foreach ([1 => 'General', 2 => 'Premium', 3 => 'Media', 4 => 'Spare'] as $id => $title) {
            $first = $id * 10;
            $discussions[] = ['id' => $id, 'title' => $title, 'slug' => strtolower($title), 'user_id' => self::AUTHOR, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 3, 'participant_count' => 1, 'first_post_id' => $first + 1, 'last_post_id' => $first + 3, 'last_post_number' => 3];
            $posts[] = ['id' => $first + 1, 'discussion_id' => $id, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => $id === 3 ? $media : '<t>'.str_repeat('Opening words of the first post. ', 20).'</t>', 'number' => 1, 'created_at' => $now];
            $posts[] = ['id' => $first + 2, 'discussion_id' => $id, 'user_id' => self::MEMBER, 'type' => 'comment', 'content' => '<t>A reply that says '.self::SECRET.' in discussion '.$id.'</t>', 'number' => 2, 'created_at' => $now];
            $posts[] = ['id' => $first + 3, 'discussion_id' => $id, 'user_id' => self::VIP, 'type' => 'comment', 'content' => '<r><p>Another <STRONG><s>**</s>'.self::SECRET.'<e>**</e></STRONG> reply</p></r>', 'number' => 3, 'created_at' => $now];
            $tags[] = ['discussion_id' => $id, 'tag_id' => $id === 2 ? 2 : 1];
        }

        $this->prepareDatabase([
            'users' => $users,
            'groups' => [['id' => self::VIP_GROUP, 'name_singular' => 'VIP', 'name_plural' => 'VIPs', 'is_hidden' => 0]],
            'group_user' => [['user_id' => self::VIP, 'group_id' => self::VIP_GROUP]],
            'tags' => [
                ['id' => 1, 'name' => 'General', 'slug' => 'general', 'position' => 0],
                ['id' => 2, 'name' => 'Premium', 'slug' => 'premium', 'position' => 1],
            ],
            'discussions' => $discussions,
            'discussion_tag' => $tags,
            'posts' => $posts,
        ]);
    }

    /** @param array<mixed> $rules */
    protected function rules(array $rules): void
    {
        $this->setting(self::P.'rules', json_encode($rules));
    }

    protected function meter(int $limit = 2, array $extra = []): void
    {
        $this->setting(self::P.'meter_enabled', '1');
        $this->setting(self::P.'meter_limit', (string) $limit);
        $this->setting(self::P.'secret', str_repeat('k', 64));
        foreach ($extra as $key => $value) {
            $this->setting(self::P.$key, $value);
        }
    }

    /** A browser's cookies: what earlier responses set, sent with later requests. @var array<string, string> */
    protected array $jar = [];

    /** Whose cookies the jar holds: a different reader starts a new browser. */
    protected int|false|null $jarFor = false;

    protected function get(string $path, ?int $actor = null, array $query = [], array $headers = []): ResponseInterface
    {
        if ($this->jarFor !== $actor) {
            $this->jar = [];
            $this->jarFor = $actor;
        }

        $request = $this->request('GET', $path, $actor ? ['authenticatedAs' => $actor] : [])->withQueryParams($query);

        if ($this->jar !== []) {
            $request = $request->withCookieParams($this->jar + $request->getCookieParams());
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->send($request);

        foreach ($response->getHeader('Set-Cookie') as $line) {
            $cookie = SetCookie::fromSetCookieString($line);
            $this->jar[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    /** @return array<mixed> */
    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function show(int $discussion, ?int $actor = null, array $headers = []): ResponseInterface
    {
        return $this->get("/api/discussions/$discussion", $actor, [], $headers);
    }

    /**
     * Open a discussion the way the forum does, then load its posts: their
     * attributes by number.
     *
     * @return array<int, array<mixed>>
     */
    protected function read(int $discussion, ?int $actor = null, array $headers = []): array
    {
        $show = $this->show($discussion, $actor, $headers);
        $this->assertSame(200, $show->getStatusCode(), (string) $show->getBody());

        $list = $this->get('/api/posts', $actor, ['filter' => ['discussion' => $discussion]], $headers);
        $this->assertSame(200, $list->getStatusCode(), (string) $list->getBody());

        $posts = [];
        foreach ($this->json($list)['data'] as $item) {
            $posts[(int) $item['attributes']['number']] = $item['attributes'];
        }
        ksort($posts);

        return $posts;
    }

    /** The rendered posts of a discussion, as one string. */
    protected function readAll(int $discussion, ?int $actor = null): string
    {
        return implode("\n", array_column($this->read($discussion, $actor), 'contentHtml'));
    }

    protected function flushFormatter(): void
    {
        $this->app()->getContainer()->make(Formatter::class)->flush();
    }
}
