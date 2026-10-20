<?php

namespace ErnestDefoe\VelvetRope\Tests\integration\api;

use Flarum\Post\CommentPost;
use Flarum\Subscriptions\Notification\NewPostBlueprint;
use Flarum\User\User;
use Illuminate\Contracts\View\Factory;
use PHPUnit\Framework\Attributes\Test;

/**
 * Notification email renders posts with no request: the gate reads them as
 * the recipient, so following a discussion can't route around it.
 */
class EmailTest extends GateTestCase
{
    protected array $enable = ['flarum-tags', 'flarum-markdown', 'flarum-subscriptions', 'ernestdefoe-velvet-rope'];

    /** What NotificationMailer does: share the recipient and blueprint, render the email. */
    protected function email(int $postId, int $recipient): string
    {
        $this->app();
        $post = CommentPost::query()->findOrFail($postId);
        $user = User::query()->findOrFail($recipient);
        $blueprint = new NewPostBlueprint($post);

        $data = ['blueprint' => $blueprint, 'user' => $user, 'unsubscribeLink' => '', 'settingsLink' => '', 'type' => 'newPost', 'forumTitle' => 'Test', 'username' => $user->display_name, 'userEmail' => $user->email, 'title' => 'x'];

        $view = $this->app()->getContainer()->make(Factory::class);
        $view->share($data);

        return $view->make('flarum-subscriptions::emails.html.newPost', $data)->render();
    }

    #[Test]
    public function a_follower_without_access_is_emailed_the_prompt_not_the_post()
    {
        $this->rules([['tags' => [2], 'readers' => [self::VIP_GROUP]]]);

        $this->assertStringNotContainsString(self::SECRET, $this->email(22, self::AUTHOR));
        $this->assertStringContainsString(self::SECRET, $this->email(22, self::VIP));
    }

    #[Test]
    public function content_rendered_outside_any_request_or_email_is_left_alone()
    {
        $this->app();
        $post = CommentPost::query()->findOrFail(12);

        $this->assertStringContainsString(self::SECRET, $post->formatContent());
    }
}
