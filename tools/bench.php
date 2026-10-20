<?php
/*
 * Query counts through the full HTTP stack (middleware included), 1 vs 20
 * items, guest and admin, on a forum with tag rules and the meter on.
 *
 *   cd FORUM && php -d memory_limit=1G ~/github/velvet-rope/tools/bench.php [seed]
 *
 * "seed" adds 25 discussions with 3 replies and one with 30 replies, once.
 */
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\Uri;

$app = (require getcwd().'/site.php')->bootApp();
$c = $app->getContainer();
$db = $c->make(Illuminate\Database\ConnectionInterface::class);
$handler = $app->getRequestHandler();

if (($argv[1] ?? '') === 'seed') {
    $now = date('Y-m-d H:i:s');
    $tag = (int) $db->table('tags')->value('id');
    $user = (int) $db->table('users')->orderByDesc('id')->value('id');
    $make = function (string $title, int $replies) use ($db, $now, $tag, $user) {
        $id = $db->table('discussions')->insertGetId(['title' => $title, 'slug' => 'bench', 'user_id' => $user, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => $replies + 1, 'participant_count' => 1, 'last_post_number' => $replies + 1, 'is_private' => 0]);
        for ($n = 1; $n <= $replies + 1; $n++) {
            $pid = $db->table('posts')->insertGetId(['discussion_id' => $id, 'user_id' => $user, 'type' => 'comment', 'number' => $n, 'created_at' => $now, 'is_private' => 0,
                'content' => '<r><p>Bench post '.$n.' with <URL url="https://example.com/x">a link</URL> and words.</p></r>']);
            if ($n === 1) $db->table('discussions')->where('id', $id)->update(['first_post_id' => $pid]);
            $db->table('discussions')->where('id', $id)->update(['last_post_id' => $pid]);
        }
        $db->table('discussion_tag')->insert(['discussion_id' => $id, 'tag_id' => $tag]);
        return $id;
    };
    for ($i = 0; $i < 25; $i++) $make("Bench discussion $i", 3);
    $long = $make('Bench long discussion', 30);
    echo "seeded, long = $long\n";
    exit;
}

$long = (int) $db->table('discussions')->where('title', 'Bench long discussion')->value('id');
$admin = 1;
$count = 0;
$db->listen(function () use (&$count) { $count++; });

function send($handler, string $path, array $query, ?int $userId, array $cookies = []) {
    $request = ServerRequestFactory::fromGlobals(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'velvetrope.localhost:8098'], $query, null, $cookies)
        ->withUri(new Uri('http://velvetrope.localhost:8098'.$path))->withMethod('GET');
    if ($userId) {
        $token = Flarum\Http\AccessToken::query()->where('user_id', $userId)->where('type', 'developer')->value('token')
            ?? Flarum\Http\DeveloperAccessToken::generate($userId)->tap(fn ($t) => $t->save())->token;
        $request = $request->withHeader('Authorization', 'Token '.$token);
    }
    return $handler->handle($request);
}

$cases = [
    'posts of one discussion' => fn ($n) => ['/api/posts', ['filter' => ['discussion' => $long], 'page' => ['limit' => $n]]],
    'discussion list + posts' => fn ($n) => ['/api/discussions', ['include' => 'user,lastPostedUser,firstPost,tags,mostRelevantPost', 'page' => ['limit' => $n]]],
    'search (mostRelevantPost)' => fn ($n) => ['/api/discussions', ['filter' => ['q' => 'Bench'], 'include' => 'mostRelevantPost', 'page' => ['limit' => $n]]],
    'users' => fn ($n) => ['/api/users', ['page' => ['limit' => $n]]],
];

foreach (['guest' => null, 'admin' => $admin] as $who => $uid) {
    foreach ($cases as $name => $case) {
        $row = [];
        foreach ([1, 20] as $n) {
            [$path, $query] = $case($n);
            send($handler, $path, $query, $uid); // warm
            $count = 0;
            $status = send($handler, $path, $query, $uid)->getStatusCode();
            $row[] = "{$n} → {$count} ($status)";
        }
        printf("%-6s %-28s %s\n", $who, $name, implode('   ', $row));
    }
}

foreach (['guest' => null, 'admin' => $admin] as $who => $uid) {
    foreach (['/' => 'forum index', "/d/$long" => 'discussion page', '/api/discussions' => 'api discussions'] as $path => $label) {
        send($handler, $path, [], $uid);
        $count = 0;
        $status = send($handler, $path, [], $uid)->getStatusCode();
        printf("%-6s %-28s %d queries (%d)\n", $who, $label, $count, $status);
    }
}
