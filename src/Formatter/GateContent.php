<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate\Formatter;

use ErnestDefoe\GuestGate\Gate;
use ErnestDefoe\GuestGate\MailRecipient;
use ErnestDefoe\GuestGate\Visitors;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;

/**
 * The one seam every rendered post passes through: the API's contentHtml, the
 * server-rendered page, search results, SEO structured data and email.
 *
 * The reader is the request's actor; without a request, the recipient of the
 * email being written, or else the visitor of the request being handled (an
 * SEO extension building structured data). With none of those (a console
 * command, a queued job) the post is left as written.
 */
class GateContent
{
    private ?Transformer $transformer = null;

    public function __construct(
        protected Gate $gate,
        protected Visitors $visitors,
        protected MailRecipient $mail,
        protected UrlGenerator $url,
    ) {
    }

    public function __invoke(Renderer $renderer, mixed $context, string $xml, ?ServerRequestInterface $request = null): string
    {
        if (! $context instanceof CommentPost) {
            return $xml;
        }

        $actor = $this->reader($context, $request);

        if ($actor === null) {
            return $xml;
        }

        return $this->transformer()->apply($xml, $this->gate->forPost($actor, $context));
    }

    private function reader(CommentPost $post, ?ServerRequestInterface $request): ?User
    {
        if ($request !== null) {
            return RequestUtil::getActor($request);
        }

        return $this->mail->for($post) ?? $this->visitors->current()[0] ?? null;
    }

    private function transformer(): Transformer
    {
        return $this->transformer ??= new Transformer((string) parse_url($this->url->to('forum')->base(), PHP_URL_HOST));
    }
}
