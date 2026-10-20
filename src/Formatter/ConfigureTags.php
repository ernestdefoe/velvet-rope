<?php

/*
 * This file is part of ernestdefoe/velvet-rope.
 */

namespace ErnestDefoe\VelvetRope\Formatter;

use s9e\TextFormatter\Configurator;

/**
 * The four tags the gate writes into a post at render time. No parser rule
 * creates them, so nothing a member types can produce one; they only ever
 * come from Transformer.
 */
class ConfigureTags
{
    public const HIDE = 'VELVETROPE-HIDE';

    public const LOCK = 'VELVETROPE-LOCK';

    public const MORE = 'VELVETROPE-MORE';

    public const WALL = 'VELVETROPE-WALL';

    public function __invoke(Configurator $config): void
    {
        $hide = $config->tags->add(self::HIDE);
        $hide->attributes->add('lines')->filterChain->append('#uint');
        $hide->template = '<div class="VelvetRope-veil" style="--gg-lines:{@lines}" aria-hidden="true"></div>';

        $lock = $config->tags->add(self::LOCK);
        $lock->attributes->add('kind')->filterChain->append('#regexp')->setRegexp('/^(?:image|file|embed)$/D');
        $lock->template = '<span class="VelvetRope-lock" data-kind="{@kind}"><xsl:apply-templates/></span>';

        $more = $config->tags->add(self::MORE);
        $more->template = '<span class="VelvetRope-more" aria-hidden="true">…</span>';

        $wall = $config->tags->add(self::WALL);
        $wall->template = '<div class="VelvetRope-paywalled"><xsl:apply-templates/></div>';
    }
}
