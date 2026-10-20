<?php

/*
 * This file is part of ernestdefoe/velvet-rope.
 */

namespace ErnestDefoe\VelvetRope;

use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Contracts\View\Factory;

/**
 * Who a notification email is for, while it is being written.
 *
 * Flarum's NotificationMailer shares `user` (the recipient) and `blueprint`
 * with every view before rendering the email, and the email's template renders
 * the post with no request. The recipient only counts when the post being
 * rendered is the blueprint's own, so a stale share from an earlier email
 * can't decide anything else.
 */
class MailRecipient
{
    public function __construct(
        protected Factory $view
    ) {
    }

    public function for(Post $post): ?User
    {
        $user = $this->view->shared('user');
        $blueprint = $this->view->shared('blueprint');

        if (! $user instanceof User || ! is_object($blueprint)) {
            return null;
        }

        foreach (get_object_vars($blueprint) as $value) {
            if ($value instanceof Post && $value->id !== null && $value->id === $post->id) {
                return $user;
            }
        }

        return null;
    }
}
