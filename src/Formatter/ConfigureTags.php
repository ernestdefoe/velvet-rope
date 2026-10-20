<?php

/*
 * This file is part of ernestdefoe/guest-gate.
 */

namespace ErnestDefoe\GuestGate\Formatter;

use s9e\TextFormatter\Configurator;

/**
 * The four tags the gate writes into a post at render time. No parser rule
 * creates them, so nothing a member types can produce one; they only ever
 * come from Transformer.
 */
class ConfigureTags
{
    public const HIDE = 'GUESTGATE-HIDE';

    public const LOCK = 'GUESTGATE-LOCK';

    public const MORE = 'GUESTGATE-MORE';

    public const WALL = 'GUESTGATE-WALL';

    public function __invoke(Configurator $config): void
    {
        $hide = $config->tags->add(self::HIDE);
        $hide->attributes->add('lines')->filterChain->append('#uint');
        $hide->template = '<div class="GuestGate-veil" data-lines="{@lines}" aria-hidden="true"></div>';

        $lock = $config->tags->add(self::LOCK);
        $lock->attributes->add('kind')->filterChain->append('#regexp')->setRegexp('/^(?:image|link|file|embed)$/D');
        $lock->template = '<span class="GuestGate-lock" data-kind="{@kind}"><xsl:apply-templates/></span>';

        $more = $config->tags->add(self::MORE);
        $more->template = '<span class="GuestGate-more" aria-hidden="true">…</span>';

        $wall = $config->tags->add(self::WALL);
        $wall->template = '<div class="GuestGate-paywalled"><xsl:apply-templates/></div>';
    }
}
