<?php

namespace ErnestDefoe\Predictions\Provider;

use ErnestDefoe\Predictions\Award\BadgeAward;
use ErnestDefoe\Predictions\Award\OracleBadge;
use Flarum\Foundation\AbstractServiceProvider;

/** Registered only while FoF Badges is enabled (extend.php): Oracles get its badge. */
class BadgesServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(OracleBadge::class, BadgeAward::class);
    }
}
