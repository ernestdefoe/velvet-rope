<?php

/*
 * This file is part of ernestdefoe/velvet-rope.
 */

namespace ErnestDefoe\VelvetRope;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What the gate knows about the visitor behind one HTTP request: their meter
 * (guests only) and whether they are a verified search engine.
 */
final class Visitor
{
    private ?bool $crawler = null;

    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly ?Meter $meter,
        private readonly Crawler $crawlers,
    ) {
    }

    public function isVerifiedCrawler(): bool
    {
        return $this->crawler ??= $this->crawlers->verify($this->request);
    }
}
