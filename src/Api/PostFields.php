<?php

/*
 * This file is part of ernestdefoe/velvet-rope.
 */

namespace ErnestDefoe\VelvetRope\Api;

use ErnestDefoe\VelvetRope\Gate;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;

/**
 * `velvetRope` on a post the reader gets less than all of: which prompt to show
 * and how much was held back. Absent on every post read in full, so members
 * with access pay nothing for it.
 */
class PostFields
{
    public function __construct(
        protected Gate $gate
    ) {
    }

    /** @return array<Schema\Arr> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('velvetRope')
                ->visible(fn (Post $post, Context $context) => $post instanceof CommentPost && $this->gate->forPost($context->getActor(), $post, $context->request)->gated())
                ->get(fn (Post $post, Context $context) => $this->gate->forPost($context->getActor(), $post, $context->request)->toArray()),
        ];
    }
}
