<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate\Formatter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ErnestDefoe\GuestGate\Verdict;

/**
 * Applies a verdict to a post's parsed XML before it is rendered, so what is
 * gated never reaches the HTML, the API or anything built from them.
 *
 * Works on the XML rather than the HTML because that is the one form every
 * output shares; media is recognised by tag, which covers Markdown, BBCode,
 * HTML elements (Scribe), FoF Upload and MediaEmbed sites alike.
 */
class Transformer
{
    /** Tags that render an image. */
    private const IMAGES = ['IMG', 'HTML:IMG', 'HTML:PICTURE', 'HTML:SVG'];

    /** Links: never locked (for that, see ramon/guest-cta), and never mistaken for an embed. */
    private const LINKS = ['URL', 'LINK', 'EMAIL', 'HTML:A'];

    /** Tags that render a player or a frame. */
    private const EMBEDS = ['HTML:IFRAME', 'HTML:VIDEO', 'HTML:AUDIO', 'HTML:OBJECT', 'HTML:EMBED'];

    /** Markup that renders nothing itself: start and end marks, ignored text. */
    private const SILENT = ['s', 'e', 'i'];

    public function apply(string $xml, Verdict $verdict): string
    {
        if (! $verdict->gated() && ! $verdict->wall) {
            return $xml;
        }

        if ($verdict->mode === Verdict::HIDE) {
            return '<r><'.ConfigureTags::HIDE.' lines="'.$this->lines($xml).'"/></r>';
        }

        $dom = new DOMDocument();

        // s9e XML: no DTD, so no entities to expand; LIBXML_NONET for good measure.
        if ($xml === '' || ! @$dom->loadXML($xml, LIBXML_NONET) || ! $dom->documentElement) {
            // Unreadable: fail closed for gated content, open for full.
            return $verdict->gated() ? '<r><'.ConfigureTags::HIDE.' lines="3"/></r>' : $xml;
        }

        $root = $this->richRoot($dom);

        if ($verdict->mode === Verdict::FULL) {
            $wall = $dom->createElement(ConfigureTags::WALL);

            while ($root->firstChild) {
                $wall->appendChild($root->firstChild);
            }

            $root->appendChild($wall);

            return (string) $dom->saveXML($root);
        }

        if ($verdict->lock !== []) {
            $this->lock($dom, $verdict->lock);
        }

        if ($verdict->mode === Verdict::LEAD) {
            $this->truncate($dom, $root, $verdict->chars);
        }

        return (string) $dom->saveXML($root);
    }

    /** A plain-text post (`<t>`) can't hold tags: make it rich (`<r>`). */
    private function richRoot(DOMDocument $dom): DOMElement
    {
        $root = $dom->documentElement;

        if ($root->nodeName === 'r') {
            return $root;
        }

        $rich = $dom->createElement('r');

        while ($root->firstChild) {
            $rich->appendChild($root->firstChild);
        }

        $dom->replaceChild($rich, $root);

        return $rich;
    }

    /** @param list<string> $kinds */
    private function lock(DOMDocument $dom, array $kinds): void
    {
        $elements = iterator_to_array((new DOMXPath($dom))->query('//*') ?: []);

        // Innermost first: an image inside a link is locked, the link kept.
        foreach (array_reverse($elements) as $element) {
            if (! $element instanceof DOMElement || ! $element->parentNode) {
                continue;
            }

            $kind = $this->kind($element);

            if ($kind === null || ! in_array($kind, $kinds, true)) {
                continue;
            }

            $placeholder = $dom->createElement(ConfigureTags::LOCK);
            $placeholder->setAttribute('kind', $kind);

            $element->parentNode->replaceChild($placeholder, $element);
        }
    }

    private function kind(DOMElement $element): ?string
    {
        $name = strtoupper($element->nodeName);

        if (str_starts_with($name, 'GUESTGATE-') || in_array($element->nodeName, self::SILENT, true)) {
            return null;
        }

        if (in_array($name, self::LINKS, true)) {
            return null;
        }

        if (in_array($name, self::IMAGES, true) || str_contains($name, 'IMAGE')) {
            return 'image';
        }

        if (str_starts_with($name, 'UPL-')) {
            return 'file';
        }

        if (in_array($name, self::EMBEDS, true)) {
            return 'embed';
        }

        // MediaEmbed sites (YOUTUBE, VIMEO…) keep the address they embed.
        if (! str_starts_with($name, 'HTML:') && ($element->hasAttribute('url') || $element->hasAttribute('src'))) {
            return 'embed';
        }

        return null;
    }

    /** Keep the first `$chars` characters of text, cut at a word, then a "…". */
    private function truncate(DOMDocument $dom, DOMElement $root, int $chars): void
    {
        $budget = $chars;
        $cut = null;

        foreach (iterator_to_array((new DOMXPath($dom))->query('.//text()', $root) ?: []) as $node) {
            if ($cut !== null || $this->silent($node)) {
                continue;
            }

            $length = mb_strlen((string) $node->nodeValue);

            if ($length <= $budget) {
                $budget -= $length;

                continue;
            }

            // Cut at the last word boundary, unless the cut already falls on one.
            $text = mb_substr((string) $node->nodeValue, 0, $budget);
            $space = mb_strrpos($text, ' ');
            $atBoundary = preg_match('/^\s/u', mb_substr((string) $node->nodeValue, $budget, 1)) === 1;
            $node->nodeValue = rtrim(! $atBoundary && $space !== false && $space > $budget / 2 ? mb_substr($text, 0, $space) : $text);
            $cut = $node;
        }

        if ($cut === null) {
            // Shorter than the preview: the post is shown whole, then the prompt.
            $root->appendChild($dom->createElement(ConfigureTags::MORE));

            return;
        }

        // Drop everything after the cut, at every level up to the root.
        for ($node = $cut; $node && $node !== $root; $node = $node->parentNode) {
            while ($node->nextSibling) {
                $node->parentNode?->removeChild($node->nextSibling);
            }
        }

        $cut->parentNode?->insertBefore($dom->createElement(ConfigureTags::MORE), $cut->nextSibling);
    }

    private function silent(DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array($parent->nodeName, self::SILENT, true)) {
                return true;
            }
        }

        return false;
    }

    private function text(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->nodeName, self::SILENT, true)) {
                continue;
            }

            $text .= $child instanceof DOMElement ? $this->text($child) : $child->textContent;
        }

        return $text;
    }

    /** How tall the blurred placeholder is: about one line per 90 characters. */
    private function lines(string $xml): int
    {
        $length = mb_strlen(trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1)));

        return max(1, min(8, (int) ceil($length / 90)));
    }
}
